<?php
/**
 * Renewal reminder emails.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Billing;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the ad's billing contact 14 days and 3 days before the ad's end
 * date (Guam), from the daily `renewal-reminders` job (Monitoring\Jobs), so
 * it runs on the same server cron or WP-Cron as the other jobs.
 *
 * Each reminder is sent once per end date: the ad remembers what it sent
 * as "end date:days", so extending the end date starts over. A missed day
 * is caught up: an ad found 10 days out with no 14-day reminder gets it.
 * When both are due, only the 3-day reminder is sent.
 *
 * Only published, active ads with an end date and a billing email get
 * reminders. The email states the end date and asks the advertiser to
 * reply to renew; it quotes no price and promises no placement.
 *
 * Premium profiles (run_premium) work the same way on the restaurant's
 * gr_premium_until, emailing the claimed owner a link to My restaurant,
 * where they can buy more time.
 */
final class Reminders {

	public const META_SENT    = 'gr_ad_reminders_sent';
	public const PREMIUM_SENT = 'gr_premium_reminders_sent';
	public const DAYS         = array( 14, 3 );

	/**
	 * Sends the reminders due today.
	 *
	 * @param string|null $today Y-m-d (Guam); defaults to today.
	 * @return array{sent: int, skipped_no_email: int, failed: int}
	 */
	public static function run( ?string $today = null ): array {
		$today  = $today ?? Server::today();
		$counts = array(
			'sent'             => 0,
			'skipped_no_email' => 0,
			'failed'           => 0,
		);
		foreach ( Invoices::expiring( $today, max( self::DAYS ) ) as $row ) {
			$ad   = $row['ad'];
			$days = self::due( (int) $row['days_left'], self::sent( (int) $ad['id'], $ad['end'] ) );
			if ( null === $days ) {
				continue;
			}
			$email = $row['billing']['email'];
			if ( ! is_email( $email ) ) {
				++$counts['skipped_no_email'];
				continue;
			}
			if ( self::send( $ad, $row['billing'], (int) $row['days_left'], $email ) ) {
				self::mark( (int) $ad['id'], $ad['end'], $days );
				++$counts['sent'];
			} else {
				++$counts['failed'];
			}
		}
		return $counts;
	}

	/**
	 * Sends the premium profile reminders due today.
	 *
	 * @param string|null $today Y-m-d (Guam); defaults to today.
	 * @return array{sent: int, skipped_no_email: int, failed: int}
	 */
	public static function run_premium( ?string $today = null ): array {
		$today  = $today ?? Server::today();
		$counts = array(
			'sent'             => 0,
			'skipped_no_email' => 0,
			'failed'           => 0,
		);
		foreach ( Premium::ending( $today, max( self::DAYS ) ) as $row ) {
			$id   = $row['restaurant_id'];
			$days = self::due( $row['days_left'], self::sent( $id, $row['until'], self::PREMIUM_SENT ) );
			if ( null === $days ) {
				continue;
			}
			$owner = get_userdata( (int) get_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) );
			if ( ! $owner || ! is_email( $owner->user_email ) ) {
				++$counts['skipped_no_email'];
				continue;
			}
			if ( self::send_premium( $id, $row['until'], $row['days_left'], $owner ) ) {
				self::mark( $id, $row['until'], $days, self::PREMIUM_SENT );
				++$counts['sent'];
			} else {
				++$counts['failed'];
			}
		}
		return $counts;
	}

	/**
	 * Which reminder (14 or 3) is due, or null. When the 3-day one is due
	 * the 14-day one is not sent late.
	 *
	 * @param int   $days_left Days until the end date.
	 * @param int[] $sent      Reminders already sent for this end date.
	 * @return int|null
	 */
	public static function due( int $days_left, array $sent ): ?int {
		if ( $days_left < 0 ) {
			return null;
		}
		$due = null;
		foreach ( self::DAYS as $days ) {
			if ( $days_left <= $days ) {
				$due = $days; // DAYS is in descending order, so the last match is the closest.
			}
		}
		if ( null === $due || in_array( $due, $sent, true ) ) {
			return null;
		}
		return $due;
	}

	/**
	 * Reminders already sent for an end date.
	 *
	 * @param int    $ad_id Ad (or restaurant, with PREMIUM_SENT).
	 * @param string $end   End date.
	 * @param string $meta  META_SENT or PREMIUM_SENT.
	 * @return int[]
	 */
	public static function sent( int $ad_id, string $end, string $meta = self::META_SENT ): array {
		$out = array();
		foreach ( array_filter( explode( ',', (string) get_post_meta( $ad_id, $meta, true ) ) ) as $entry ) {
			list( $date, $days ) = array_pad( explode( ':', $entry, 2 ), 2, '' );
			if ( $date === $end ) {
				$out[] = (int) $days;
			}
		}
		return $out;
	}

	/**
	 * Records a sent reminder, dropping entries for older end dates.
	 *
	 * @param int    $ad_id Ad (or restaurant).
	 * @param string $end   End date.
	 * @param int    $days  Reminder.
	 * @param string $meta  META_SENT or PREMIUM_SENT.
	 * @return void
	 */
	private static function mark( int $ad_id, string $end, int $days, string $meta = self::META_SENT ): void {
		$entries = array();
		foreach ( self::sent( $ad_id, $end, $meta ) as $sent ) {
			$entries[] = $end . ':' . $sent;
		}
		$entries[] = $end . ':' . $days;
		update_post_meta( $ad_id, $meta, implode( ',', array_unique( $entries ) ) );
	}

	/**
	 * Sends one premium profile reminder.
	 *
	 * @param int      $restaurant_id Restaurant.
	 * @param string   $until         Last day.
	 * @param int      $days_left     Days until then.
	 * @param \WP_User $owner         Claimed owner.
	 * @return bool
	 */
	private static function send_premium( int $restaurant_id, string $until, int $days_left, \WP_User $owner ): bool {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$end  = wp_date( get_option( 'date_format' ), (int) strtotime( $until . ' 12:00:00' ) );
		$name = wp_specialchars_decode( get_the_title( $restaurant_id ), ENT_QUOTES );
		/* translators: 1: site name, 2: restaurant, 3: end date. */
		$subject = sprintf( __( '[%1$s] The premium profile for %2$s ends on %3$s', 'guam-restaurants-core' ), $site, $name, $end );
		$lines   = array(
			/* translators: %s: owner's name. */
			sprintf( __( 'Hello %s,', 'guam-restaurants-core' ), $owner->display_name ),
			'',
			sprintf(
				/* translators: 1: restaurant, 2: end date, 3: number of days. */
				_n( 'The premium profile for %1$s ends on %2$s, in %3$d day. It does not renew by itself.', 'The premium profile for %1$s ends on %2$s, in %3$d days. It does not renew by itself.', $days_left, 'guam-restaurants-core' ),
				$name,
				$end,
				$days_left
			),
			__( 'To keep it, log in and choose a plan under My restaurant. New time starts the day after the current end date, so nothing you paid for is lost:', 'guam-restaurants-core' ),
			add_query_arg( 'restaurant', $restaurant_id, admin_url( 'admin.php?page=' . Dashboard::PAGE ) ),
			'',
			__( 'If you do not renew, your listing stays as it is without the button, the extra photos and the ad-free page.', 'guam-restaurants-core' ),
			'',
			$site,
		);
		$reply   = sanitize_email( (string) get_option( 'admin_email' ) );
		/**
		 * Filters the premium profile reminder email.
		 *
		 * @param array{to: string, subject: string, message: string, headers: string[]} $mail          Email.
		 * @param int                                                                    $restaurant_id Restaurant.
		 */
		$mail = (array) apply_filters(
			'guam_restaurants_premium_reminder_email',
			array(
				'to'      => $owner->user_email,
				'subject' => $subject,
				'message' => implode( "\n", $lines ),
				'headers' => '' === $reply ? array() : array( 'Reply-To: ' . $reply ),
			),
			$restaurant_id
		);
		return (bool) wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'] );
	}

	/**
	 * Sends one reminder.
	 *
	 * @param array<string, mixed>  $ad        Ad.
	 * @param array<string, string> $billing   Billing fields.
	 * @param int                   $days_left Days until the end date.
	 * @param string                $email     Recipient.
	 * @return bool
	 */
	private static function send( array $ad, array $billing, int $days_left, string $email ): bool {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$end  = wp_date( get_option( 'date_format' ), (int) strtotime( $ad['end'] . ' 12:00:00' ) );
		/* translators: 1: site name, 2: end date. */
		$subject = sprintf( __( '[%1$s] Your Sponsored placement ends on %2$s', 'guam-restaurants-core' ), $site, $end );
		$lines   = array(
			/* translators: %s: advertiser name. */
			sprintf( __( 'Hello %s,', 'guam-restaurants-core' ), $ad['advertiser'] ),
			'',
			sprintf(
				/* translators: 1: ad name, 2: product, 3: end date, 4: number of days. */
				_n( 'Your placement "%1$s" (%2$s) ends on %3$s, in %4$d day.', 'Your placement "%1$s" (%2$s) ends on %3$s, in %4$d days.', $days_left, 'guam-restaurants-core' ),
				$ad['title'],
				wp_strip_all_tags( (string) ( Ads::products()[ $ad['product'] ]['label'] ?? $ad['product'] ) ),
				$end,
				$days_left
			),
			__( 'To renew it, reply to this email and we will send the details. If you do not want to renew, no action is needed; the placement simply stops on its end date.', 'guam-restaurants-core' ),
		);
		if ( '' !== $billing['number'] ) {
			/* translators: %s: invoice number. */
			$lines[] = sprintf( __( 'Current invoice: %s', 'guam-restaurants-core' ), $billing['number'] );
		}
		$lines[] = '';
		$lines[] = $site;

		$reply = sanitize_email( (string) get_option( 'admin_email' ) );
		/**
		 * Filters the renewal reminder email.
		 *
		 * @param array{to: string, subject: string, message: string, headers: string[]} $mail Email.
		 * @param array<string, mixed>                                                   $ad   Ad.
		 */
		$mail = (array) apply_filters(
			'guam_restaurants_renewal_reminder_email',
			array(
				'to'      => $email,
				'subject' => $subject,
				'message' => implode( "\n", $lines ),
				'headers' => '' === $reply ? array() : array( 'Reply-To: ' . $reply ),
			),
			$ad
		);
		return (bool) wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'] );
	}
}
