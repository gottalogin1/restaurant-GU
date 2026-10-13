<?php
/**
 * WP-CLI: `wp guam-restaurants search-log`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Discovery\Search_Log;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Seed_Data;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Shows and edits the list of discovery searches: what has been searched,
 * when, what it found, and what runs next.
 */
final class Search_Log_Command {

	/**
	 * Totals: searches never run, due, run this week, new places found,
	 * and the free Text Search calls left this month.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants search-log status
	 *
	 * @return void
	 */
	public function status(): void {
		Search_Log::sync();
		$summary = Search_Log::summary();

		WP_CLI::log( sprintf( 'Searches: %d (%d active).', $summary['queries'], $summary['active'] ) );
		WP_CLI::log( sprintf( 'Never run: %d. Due for their weekly re-check: %d.', $summary['never_run'], $summary['due'] ) );
		WP_CLI::log( sprintf( 'Run in the last 7 days: %d, which found %d new place(s).', $summary['searched_7d'], $summary['new_7d'] ) );
		WP_CLI::log( sprintf( 'New places found in total: %d.', $summary['total_new'] ) );
		WP_CLI::log( sprintf( 'Filled every result page (narrowed further): %d. Last attempt failed: %d.', $summary['saturated'], $summary['errors'] ) );
		WP_CLI::log( sprintf( 'Free Text Search calls left this month: %d.', Budget::free_left( Pricing::GOOGLE_TEXT_SEARCH_PRO ) ) );
	}

	/**
	 * Lists the searches.
	 *
	 * ## OPTIONS
	 *
	 * [--due]
	 * : Only searches that the next run would do.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants search-log list --due
	 *     wp guam-restaurants search-log list --format=csv > search-log.csv
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		Search_Log::sync();
		$rows   = WP_CLI\Utils\get_flag_value( $assoc_args, 'due', false ) ? Search_Log::due() : Search_Log::all();
		$fields = array( 'id', 'query', 'origin', 'active', 'runs', 'last_run_at', 'next_due_at', 'last_results', 'last_new', 'total_new', 'saturated', 'last_error' );
		foreach ( $rows as &$row ) {
			$row['active']    = $row['active'] ? 'yes' : 'no';
			$row['saturated'] = $row['saturated'] ? 'yes' : 'no';
		}
		unset( $row );
		WP_CLI\Utils\format_items( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, $fields );
	}

	/**
	 * Adds a search of your own, run by the next `discover`.
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Text Search query, for example "ramen in Tumon, Guam".
	 *
	 * [--village=<slug>]
	 * : Village the query is about, so places already listed there are
	 * recognised by name. Without it the search counts as island-wide.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants search-log add "ramen in Tumon, Guam" --village=tamuning
	 *
	 * @param string[]             $args       Query.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public function add( array $args, array $assoc_args ): void {
		$village = sanitize_title( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'village', '' ) );
		if ( '' !== $village && ! isset( Seed_Data::villages()[ $village ] ) ) {
			WP_CLI::error( "Unknown village: {$village}." );
			return;
		}
		$added = Search_Log::add( (string) $args[0], Search_Log::ORIGIN_CUSTOM, '' === $village ? 'island' : 'village', $village, $village );
		if ( $added ) {
			WP_CLI::success( 'Added; the next discover run searches it.' );
		} else {
			WP_CLI::log( 'That search is already in the log.' );
		}
	}

	/**
	 * Stops running a search (it stays in the log).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Search ID from `search-log list`.
	 *
	 * @param string[] $args ID.
	 * @return void
	 */
	public function disable( array $args ): void {
		Search_Log::set_active( (int) $args[0], false );
		WP_CLI::success( 'Disabled.' );
	}

	/**
	 * Runs a disabled search again.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Search ID from `search-log list`.
	 *
	 * @param string[] $args ID.
	 * @return void
	 */
	public function enable( array $args ): void {
		Search_Log::set_active( (int) $args[0], true );
		WP_CLI::success( 'Enabled.' );
	}
}
