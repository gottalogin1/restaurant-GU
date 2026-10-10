<?php
/**
 * Renewal reminder emails.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Billing;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;

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
 */
final class Reminders {

	public const META_SENT = 'gr_ad_reminders_sent';
	public const DAYS      = array( 14, 3 );

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
	 * @param int    $ad_id Ad.
	 * @param string $end   End date.
	 * @return int[]
	 */
	public static function sent( int $ad_id, string $end ): array {
		$out = array();
		foreach ( array_filter( explode( ',', (string) get_post_meta( $ad_id, self::META_SENT, true ) ) ) as $entry ) {
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
	 * @param int    $ad_id Ad.
	 * @param string $end   End date.
	 * @param int    $days  Reminder.
	 * @return void
	 */
	private static function mark( int $ad_id, string $end, int $days ): void {
		$entries = array();
		foreach ( self::sent( $ad_id, $end ) as $sent ) {
			$entries[] = $end . ':' . $sent;
		}
		$entries[] = $end . ':' . $days;
		update_post_meta( $ad_id, self::META_SENT, implode( ',', array_unique( $entries ) ) );
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
