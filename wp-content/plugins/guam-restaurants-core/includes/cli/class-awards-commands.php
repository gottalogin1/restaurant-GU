<?php
/**
 * WP-CLI commands: `wp guam-restaurants import-awards` and `wp guam-restaurants awards-report`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Awards\Importer;
use GuamRestaurants\Core\Awards\Report;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Award result commands.
 */
final class Awards_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants import-awards', array( self::class, 'import_awards' ) );
		WP_CLI::add_command( 'guam-restaurants awards-report', array( self::class, 'awards_report' ) );
	}

	/**
	 * Imports award results from a CSV file shaped like data/awards-template.csv.
	 *
	 * Stores facts only (program, year, category, placement, restaurant,
	 * source URL, and an optional note in our own words). Rows without a
	 * source_url are rejected. Names are matched to restaurants by their
	 * current or former names; possible matches go to the review queue and
	 * unknown names become draft restaurants so past winners stay listed.
	 * Running the same file again changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file.
	 *
	 * [--dry-run]
	 * : Validate and match, but write nothing.
	 *
	 * [--unmatched-status=<status>]
	 * : Business status for restaurants created for unmatched names.
	 * ---
	 * default: unknown
	 * options:
	 *   - unknown
	 *   - permanently_closed
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants import-awards awards-2023.csv --dry-run
	 *     wp guam-restaurants import-awards awards-2023.csv
	 *
	 * @param string[]             $args       File path.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function import_awards( array $args, array $assoc_args ): void {
		$file    = (string) ( $args[0] ?? '' );
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$status  = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'unmatched-status', 'unknown' );

		try {
			$result = ( new Importer( $status ) )->import_file( $file, $dry_run );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( $result['rejected'] as $rejected ) {
			WP_CLI::warning( sprintf( 'Rejected line %d (%s): %s.', $rejected['line'], '' === $rejected['restaurant_name'] ? 'no name' : $rejected['restaurant_name'], $rejected['reason'] ) );
		}
		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		foreach ( $result['former_name_matches'] as $line ) {
			WP_CLI::log( 'Matched by former name: ' . $line );
		}
		if ( $result['restaurants_created'] ) {
			WP_CLI::log( sprintf( '%s %d draft restaurant(s) for unmatched names (status %s):', $dry_run ? 'Would create' : 'Created', count( $result['restaurants_created'] ), $status ) );
			foreach ( $result['restaurants_created'] as $created ) {
				WP_CLI::log( $created['id'] ? sprintf( '  #%d  %s', $created['id'], $created['name'] ) : '  ' . $created['name'] );
			}
		}
		if ( $result['queued'] ) {
			WP_CLI::log( sprintf( '%d row(s) are possible matches and %s the review queue (match_conflict, kind award_match). Add the name to the restaurant\'s former names, or fix it in the CSV, then run again.', $result['queued'], $dry_run ? 'would go to' : 'are in' ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Years and categories in this file:' );
		if ( empty( $result['imported'] ) ) {
			WP_CLI::log( '  none' );
		}
		foreach ( $result['imported'] as $program => $years ) {
			foreach ( $years as $year => $categories ) {
				WP_CLI::log( sprintf( '  %s %d: %d categor%s', $program, $year, count( $categories ), 1 === count( $categories ) ? 'y' : 'ies' ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Coverage and gaps (whole table):' );
		WP_CLI::log( Report::markdown( Report::build() ) );

		$counts = $result['counts'];
		WP_CLI::log(
			sprintf(
				'%s: created %d, updated %d, unchanged %d, failed %d (rejected %d); queued for review %d.%s',
				$dry_run ? 'Dry run' : 'Done',
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$counts['failed'],
				count( $result['rejected'] ),
				$result['queued'],
				$result['run_id'] ? " Ingestion run #{$result['run_id']}." : ''
			)
		);
	}

	/**
	 * Prints which years and categories are recorded and what is missing.
	 *
	 * ## OPTIONS
	 *
	 * [--program=<name>]
	 * : Only this program.
	 *
	 * [--since=<year>]
	 * : First year to check for missing years.
	 *
	 * [--until=<year>]
	 * : Last year to check.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: markdown
	 * options:
	 *   - markdown
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants awards-report
	 *     wp guam-restaurants awards-report --program="Pika's Best of Guam" --format=json
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function awards_report( array $args, array $assoc_args ): void {
		$program = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'program', '' );
		$since   = WP_CLI\Utils\get_flag_value( $assoc_args, 'since', null );
		$until   = WP_CLI\Utils\get_flag_value( $assoc_args, 'until', null );
		$format  = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'markdown' );

		$report = Report::build( $program, null === $since ? null : (int) $since, null === $until ? null : (int) $until );
		WP_CLI::log( 'json' === $format ? (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : Report::markdown( $report ) );
	}
}
