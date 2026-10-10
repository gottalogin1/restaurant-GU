<?php
/**
 * WP-CLI commands: `wp guam seed` and `wp guam villages assign`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Geo\Village_Tagger;
use GuamRestaurants\Core\Seeder;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Guam restaurant directory commands.
 */
final class Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam seed', array( self::class, 'seed' ) );
		WP_CLI::add_command( 'guam villages assign', array( self::class, 'assign_villages' ) );
	}

	/**
	 * Seeds taxonomy terms. Safe to run again: existing terms and meta are kept.
	 *
	 * ## OPTIONS
	 *
	 * [<what>]
	 * : villages, malls, cuisines, price-levels, or all.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - villages
	 *   - malls
	 *   - cuisines
	 *   - price-levels
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam seed
	 *     wp guam seed malls
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public static function seed( array $args ): void {
		$what = $args[0] ?? 'all';

		$results = 'all' === $what
			? Seeder::seed_all()
			: array( $what => call_user_func( array( Seeder::class, 'seed_' . str_replace( '-', '_', $what ) ) ) );

		$rows = array();
		foreach ( $results as $name => $counts ) {
			$rows[] = array( 'taxonomy' => $name ) + $counts;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'taxonomy', 'created', 'unchanged', 'failed' ) );

		if ( 'all' === $what || 'malls' === $what ) {
			WP_CLI::warning( 'Malls are seeded unverified and stay hidden from visitors until an editor ticks "Verified" on each one.' );
		}
		WP_CLI::success( 'Seeding finished.' );
	}

	/**
	 * Sets each restaurant's village from its coordinates (Census boundaries).
	 *
	 * Restaurants whose point falls outside every village are added to the
	 * review queue. Logs an ingestion run with created, updated, unchanged
	 * and failed counts.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report counts without changing anything.
	 *
	 * [--only-missing]
	 * : Skip restaurants that already have a village.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam villages assign --dry-run
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function assign_villages( array $args, array $assoc_args ): void {
		$dry_run      = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$only_missing = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'only-missing', false );

		$query = array(
			'post_type'      => Content_Types::POST_TYPE,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		);
		if ( $only_missing ) {
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Content_Types::TAX_VILLAGE,
					'operator' => 'NOT EXISTS',
				),
			);
		}
		$ids = get_posts( $query );

		$run_id = $dry_run ? null : Ingestion_Runs::start( 'census' );
		$tagger = new Village_Tagger();
		$counts = array_fill_keys( array( Village_Tagger::CREATED, Village_Tagger::UPDATED, Village_Tagger::UNCHANGED, Village_Tagger::FAILED ), 0 );

		foreach ( $ids as $id ) {
			++$counts[ $tagger->tag( (int) $id, $run_id, $dry_run ) ];
		}

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $counts, $only_missing ? 'villages assign --only-missing' : 'villages assign' );
		}

		WP_CLI::log(
			sprintf(
				'%s: created %d, updated %d, unchanged %d, failed %d.',
				$dry_run ? 'Dry run' : 'Done',
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$counts['failed']
			)
		);
	}
}
