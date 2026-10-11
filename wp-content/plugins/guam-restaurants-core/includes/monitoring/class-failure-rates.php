<?php
/**
 * Rising failure rates per ingestion source.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Database\Ingestion_Runs;

defined( 'ABSPATH' ) || exit;

/**
 * Looks at each source's latest ingestion runs and flags one that is
 * failing or slowly degrading:
 *
 * - more than MAX_FAILED_SHARE of the records in its last WINDOW runs failed;
 * - MAX_OUTRIGHT or more of those runs failed before doing any work;
 * - each of its last RETRY_RUNS runs needed RETRY_THRESHOLD or more retried
 *   requests (the API is struggling even though the run succeeded).
 *
 * The daily health check emails about a source once per new run that
 * still shows a problem.
 */
final class Failure_Rates {

	public const WINDOW           = 4;
	public const MAX_FAILED_SHARE = 0.05;
	public const MAX_OUTRIGHT     = 2;
	public const RETRY_RUNS       = 2;
	public const RETRY_THRESHOLD  = 3;

	/**
	 * Option: source => latest run ID already alerted about.
	 */
	public const ALERTED_OPTION = 'guam_restaurants_core_failure_alerts';

	/**
	 * Problems per source, with the latest run ID they are based on.
	 *
	 * @return array<string, array{run_id: int, problems: string[]}>
	 */
	public static function problems(): array {
		$out = array();
		foreach ( Ingestion_Runs::sources() as $source ) {
			$runs = array_values(
				array_filter(
					Ingestion_Runs::recent( self::WINDOW, 0, $source ),
					static function ( array $run ): bool {
						return null !== $run['finished_at'];
					}
				)
			);
			if ( ! $runs ) {
				continue;
			}
			$problems = self::problems_in( $runs );
			if ( $problems ) {
				$out[ $source ] = array(
					'run_id'   => (int) $runs[0]['id'],
					'problems' => $problems,
				);
			}
		}
		return $out;
	}

	/**
	 * Problems in a source's runs, newest first.
	 *
	 * @param array<int, array<string, mixed>> $runs Finished runs, newest first.
	 * @return string[]
	 */
	public static function problems_in( array $runs ): array {
		$problems = array();
		$outright = 0;
		$failed   = 0;
		$total    = 0;
		foreach ( $runs as $run ) {
			if ( Ingestion_Runs::failed_to_start( $run ) ) {
				++$outright;
				continue;
			}
			$failed += (int) $run['failed'];
			$total  += (int) $run['created'] + (int) $run['updated'] + (int) $run['unchanged'] + (int) $run['failed'];
		}

		if ( $outright >= self::MAX_OUTRIGHT ) {
			$last_error = '';
			foreach ( $runs as $run ) {
				if ( Ingestion_Runs::failed_to_start( $run ) && $run['errors'] ) {
					$last_error = (string) $run['errors'][0];
					break;
				}
			}
			$problems[] = sprintf( '%d of the last %d runs failed outright%s', $outright, count( $runs ), '' !== $last_error ? ' (latest: ' . $last_error . ')' : '' );
		}
		if ( $total > 0 && $failed / $total > self::MAX_FAILED_SHARE ) {
			$problems[] = sprintf( '%d of %d records failed (%.1f%%) in the last %d runs', $failed, $total, 100 * $failed / $total, count( $runs ) );
		}

		$recent = array_slice( $runs, 0, self::RETRY_RUNS );
		if ( count( $recent ) === self::RETRY_RUNS ) {
			$retries = array_map(
				static function ( array $run ): int {
					return (int) ( $run['retries'] ?? 0 );
				},
				$recent
			);
			if ( min( $retries ) >= self::RETRY_THRESHOLD ) {
				$problems[] = sprintf( 'the last %d runs needed %s retried requests', self::RETRY_RUNS, implode( ' and ', array_reverse( $retries ) ) );
			}
		}

		return $problems;
	}

	/**
	 * Emails about sources with a problem not yet reported.
	 *
	 * @return string Summary; '' when no source has a problem.
	 */
	public static function run(): string {
		$problems = self::problems();
		if ( ! $problems ) {
			return '';
		}

		$alerted = (array) get_option( self::ALERTED_OPTION, array() );
		$new     = array();
		foreach ( $problems as $source => $details ) {
			if ( (int) ( $alerted[ $source ] ?? 0 ) !== $details['run_id'] ) {
				$new[ $source ] = $details;
			}
		}
		if ( ! $new ) {
			return sprintf( '%d source(s) with rising failures; already reported.', count( $problems ) );
		}

		$lines = array( 'These import sources are failing more than usual:', '' );
		foreach ( $new as $source => $details ) {
			$lines[] = sprintf( '- %s: %s.', $source, implode( '; ', $details['problems'] ) );
		}
		$lines[] = '';
		$lines[] = 'Each run\'s errors and retried requests: ' . admin_url( 'edit.php?post_type=gr_restaurant&page=' . \GuamRestaurants\Core\Admin\Ingestion_Runs_Screen::PAGE );

		$sent = Alerts::send( sprintf( '%d import source(s) failing more than usual', count( $new ) ), $lines );
		if ( $sent ) {
			foreach ( $new as $source => $details ) {
				$alerted[ $source ] = $details['run_id'];
			}
			update_option( self::ALERTED_OPTION, $alerted, false );
		}

		return sprintf( '%d source(s) with rising failures; %s.', count( $problems ), $sent ? 'alert sent' : 'alert could not be sent' );
	}
}
