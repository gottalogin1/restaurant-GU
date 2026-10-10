<?php
/**
 * WP-CLI commands for deploying and running the live site.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Health_Endpoint;
use GuamRestaurants\Core\Monitoring\Spend_Report;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Security\Preflight;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants db status|upgrade`, `preflight`, `health` and
 * `spend-report` (docs/deployment.md, docs/monitoring.md).
 */
final class Operations_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants db status', array( self::class, 'db_status' ) );
		WP_CLI::add_command( 'guam-restaurants db upgrade', array( self::class, 'db_upgrade' ) );
		WP_CLI::add_command( 'guam-restaurants preflight', array( self::class, 'preflight' ) );
		WP_CLI::add_command( 'guam-restaurants health', array( self::class, 'health' ) );
		WP_CLI::add_command( 'guam-restaurants spend-report', array( self::class, 'spend_report' ) );
	}

	/**
	 * Shows the custom tables' schema version and whether each table exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants db status
	 *
	 * @return void
	 */
	public static function db_status(): void {
		global $wpdb;
		$rows = array();
		foreach ( array_keys( Schema::definitions() ) as $table ) {
			$name   = Schema::table( $table );
			$exists = $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows[] = array(
				'table'  => $name,
				'exists' => $exists ? 'yes' : 'no',
				'rows'   => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ) : 0, // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema.
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'exists', 'rows' ) );
		$stored = (string) get_option( Schema::VERSION_OPTION, '0' );
		if ( Schema::is_current() ) {
			WP_CLI::success( sprintf( 'Schema version %s is current.', $stored ) );
		} else {
			WP_CLI::warning( sprintf( 'Schema version %s is stored; the code needs %s. Run: wp guam-restaurants db upgrade', $stored, Schema::VERSION ) );
		}
	}

	/**
	 * Creates or upgrades the custom tables (dbDelta, additive only). Safe
	 * to run on every deploy.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants db upgrade
	 *
	 * @return void
	 */
	public static function db_upgrade(): void {
		$before = (string) get_option( Schema::VERSION_OPTION, '0' );
		if ( ! Schema::upgrade() ) {
			WP_CLI::error( 'Another upgrade is running (lock option ' . Schema::LOCK_OPTION . '). Try again in a few minutes.' );
		}
		WP_CLI::success( sprintf( 'Schema upgraded from %s to %s.', $before, Schema::VERSION ) );
	}

	/**
	 * Checks the settings a live site needs and prints ok, warn or fail.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--strict]
	 * : Exit with an error when any check fails (used by bin/deploy.sh).
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants preflight
	 *     wp guam-restaurants preflight --strict
	 *
	 * @param array<int, string>    $args       Positional.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function preflight( array $args, array $assoc_args ): void {
		unset( $args );
		$results = Preflight::run();
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $results, array( 'status', 'check', 'detail' ) );
		$counts = array_count_values( array_column( $results, 'status' ) );
		$line   = sprintf( '%d ok, %d warn, %d fail.', $counts[ Preflight::OK ] ?? 0, $counts[ Preflight::WARN ] ?? 0, $counts[ Preflight::FAIL ] ?? 0 );
		if ( Preflight::has_failures( $results ) && isset( $assoc_args['strict'] ) ) {
			WP_CLI::error( $line );
		}
		WP_CLI::log( $line );
	}

	/**
	 * Prints what the public health endpoint answers.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants health
	 *
	 * @return void
	 */
	public static function health(): void {
		$checks = Health_Endpoint::checks();
		WP_CLI::log( (string) wp_json_encode( $checks ) );
		if ( 'error' === $checks['status'] ) {
			WP_CLI::error( 'Unhealthy.' );
		}
	}

	/**
	 * API calls and estimated spend per source for a month.
	 *
	 * ## OPTIONS
	 *
	 * [--month=<month>]
	 * : YYYY-MM (UTC). Defaults to the current month.
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--email]
	 * : Also email it to the alert address.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants spend-report
	 *     wp guam-restaurants spend-report --month=2026-09 --email
	 *
	 * @param array<int, string>    $args       Positional.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function spend_report( array $args, array $assoc_args ): void {
		unset( $args );
		$month = (string) ( $assoc_args['month'] ?? gmdate( 'Y-m' ) );
		if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			WP_CLI::error( 'Use --month=YYYY-MM.' );
		}
		$report = Spend_Report::build( $month );
		$rows   = array_map(
			static function ( array $row ): array {
				$row['estimated_cost'] = Pricing::format( $row['estimated_cost'] );
				return $row;
			},
			$report['rows']
		);
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'source', 'calls', 'free_calls', 'billable_calls', 'estimated_cost' ) );
		WP_CLI::log( sprintf( 'Total estimated for %s: %s.', $month, Pricing::format( $report['total_cost'] ) ) );
		if ( isset( $assoc_args['email'] ) ) {
			$sent = \GuamRestaurants\Core\Monitoring\Alerts::send( sprintf( 'API spend report for %s: %s estimated', $month, Pricing::format( $report['total_cost'] ) ), Spend_Report::lines( $report ) );
			$sent ? WP_CLI::success( 'Emailed.' ) : WP_CLI::warning( 'The email could not be sent.' );
		}
	}
}
