<?php
/**
 * Daily check that every scheduled job ran in its window.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

defined( 'ABSPATH' ) || exit;

/**
 * Emails the owner when an enabled job has not succeeded within its
 * interval plus grace period (OSM: 8 days; status checks: 34 days). Each
 * missed window is reported once; the next report comes only after the job
 * succeeds and then misses again.
 */
final class Health_Check {

	/**
	 * Jobs past their deadline: job => details.
	 *
	 * @param int|null $now Unix time; defaults to now.
	 * @return array<string, array{label: string, last_success: int, deadline: int, last_error: string}>
	 */
	public static function overdue( ?int $now = null ): array {
		$now = $now ?? time();
		$out = array();
		foreach ( Jobs::definitions() as $job => $definition ) {
			if ( Jobs::HEALTH_CHECK === $job || ! Jobs::enabled( $job ) ) {
				continue;
			}
			$deadline = Jobs::deadline( $job );
			if ( $now > $deadline ) {
				$out[ $job ] = array(
					'label'        => $definition['label'],
					'last_success' => Jobs::last_success( $job ),
					'deadline'     => $deadline,
					'last_error'   => (string) ( Jobs::state( $job )['last_error'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Runs the check and emails about newly missed jobs.
	 *
	 * @param int|null $now Unix time; defaults to now.
	 * @return string Summary.
	 */
	public static function run( ?int $now = null ): string {
		$overdue = self::overdue( $now );
		$new     = array();
		foreach ( $overdue as $job => $details ) {
			if ( (int) ( Jobs::state( $job )['alerted_deadline'] ?? 0 ) !== $details['deadline'] ) {
				$new[ $job ] = $details;
			}
		}

		if ( ! $overdue ) {
			return 'All jobs ran in their windows.';
		}
		if ( ! $new ) {
			return sprintf( '%d job(s) still overdue; already reported.', count( $overdue ) );
		}

		$lines = array( 'These scheduled jobs missed their window:', '' );
		foreach ( $new as $job => $details ) {
			$lines[] = sprintf(
				'- %s (%s): last succeeded %s; was due by %s.%s',
				$details['label'],
				$job,
				$details['last_success'] ? gmdate( 'Y-m-d H:i', $details['last_success'] ) . ' UTC' : 'never',
				gmdate( 'Y-m-d H:i', $details['deadline'] ) . ' UTC',
				'' !== $details['last_error'] ? ' Last error: ' . $details['last_error'] : ''
			);
		}
		$lines[] = '';
		if ( Jobs::MODE_SERVER === Jobs::mode() ) {
			$lines[] = 'Jobs run from the server crontab (GUAM_RESTAURANTS_CRON_MODE=server). Check that the crontab entries in docs/scheduling.md are installed, or run a job by hand:';
		} else {
			$lines[] = 'Jobs run on WP-Cron, which only runs when the site gets visits. A server cron is more reliable (docs/scheduling.md). To run a job by hand:';
		}
		foreach ( array_keys( $new ) as $job ) {
			$lines[] = '  wp guam-restaurants run-job ' . $job;
		}
		$lines[] = '';
		$lines[] = 'Import runs: ' . admin_url( 'edit.php?post_type=gr_restaurant&page=' . \GuamRestaurants\Core\Admin\Ingestion_Runs_Screen::PAGE );

		$sent = Alerts::send( sprintf( '%d scheduled job(s) missed their window', count( $new ) ), $lines );
		if ( $sent ) {
			foreach ( $new as $job => $details ) {
				Jobs::update_state( $job, array( 'alerted_deadline' => $details['deadline'] ) );
			}
		}

		return sprintf( '%d job(s) overdue; %s.', count( $overdue ), $sent ? 'alert sent' : 'alert could not be sent' );
	}
}
