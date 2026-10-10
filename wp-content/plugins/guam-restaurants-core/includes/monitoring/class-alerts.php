<?php
/**
 * Email alerts to the site owner.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Sends plain-text alerts: a job that missed its window, and runs in which
 * more restaurants than the threshold looked permanently closed.
 */
final class Alerts {

	/**
	 * Recipient: GUAM_RESTAURANTS_ALERT_EMAIL, else the admin email.
	 *
	 * @return string
	 */
	public static function recipient(): string {
		$email = sanitize_email( Config::get( Config::ALERT_EMAIL ) );
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Sends an alert.
	 *
	 * @param string   $subject Subject, without the site name.
	 * @param string[] $lines   Body lines.
	 * @return bool Whether wp_mail() accepted it.
	 */
	public static function send( string $subject, array $lines ): bool {
		$to = self::recipient();
		if ( '' === $to ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return (bool) wp_mail( $to, sprintf( '[%s] %s', $site, $subject ), implode( "\n", $lines ) );
	}

	/**
	 * Closure threshold.
	 *
	 * @return int
	 */
	public static function closure_threshold(): int {
		$value = Config::get( Config::CLOSURE_ALERT_THRESHOLD );
		return is_numeric( $value ) && (int) $value >= 0 ? (int) $value : Config::DEFAULT_CLOSURE_ALERT_THRESHOLD;
	}

	/**
	 * Emails when more restaurants than the threshold looked closed in one run.
	 *
	 * @param string   $source Source label, for example "OpenStreetMap import".
	 * @param int[]    $closed Restaurants that looked closed in this run.
	 * @param int[]    $queued Restaurants now awaiting closure approval.
	 * @param int|null $run_id Ingestion run.
	 * @return bool Whether an alert was sent.
	 */
	public static function closures_in_run( string $source, array $closed, array $queued, ?int $run_id ): bool {
		$threshold = self::closure_threshold();
		if ( count( $closed ) <= $threshold ) {
			return false;
		}

		$lines = array(
			sprintf( '%d restaurants looked permanently closed in one %s run (alert threshold: %d).', count( $closed ), $source, $threshold ),
			sprintf( '%d of them have now been reported closed twice in a row and await your approval in the review queue.', count( $queued ) ),
			'No restaurant is marked closed until an administrator approves it.',
			'',
			'A large number at once can mean a data problem (for example a partial download) rather than real closures.',
			'',
		);
		foreach ( array_slice( $closed, 0, 50 ) as $id ) {
			$lines[] = sprintf( '- %s (#%d)%s', html_entity_decode( get_the_title( $id ), ENT_QUOTES ), $id, in_array( $id, $queued, true ) ? ', awaiting approval' : '' );
		}
		if ( count( $closed ) > 50 ) {
			$lines[] = sprintf( '…and %d more.', count( $closed ) - 50 );
		}
		$lines[] = '';
		$lines[] = 'Review queue: ' . admin_url( 'edit.php?post_type=gr_restaurant&page=' . \GuamRestaurants\Core\Admin\Review_Queue_Screen::PAGE . '&type=closure_signal' );
		if ( $run_id ) {
			$lines[] = 'Import runs: ' . admin_url( 'edit.php?post_type=gr_restaurant&page=' . \GuamRestaurants\Core\Admin\Ingestion_Runs_Screen::PAGE );
		}

		return self::send( sprintf( '%d restaurants look closed after the %s', count( $closed ), $source ), $lines );
	}
}
