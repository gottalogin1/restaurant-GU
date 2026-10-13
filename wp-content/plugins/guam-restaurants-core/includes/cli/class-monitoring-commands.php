<?php
/**
 * WP-CLI commands for scheduled jobs, tagging and monitoring.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Monitoring\Google_Status_Source;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Monitoring\Status_Checker;
use GuamRestaurants\Core\Monitoring\Yelp_Status_Source;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Tagging\Cuisine_Tagger;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;
use GuamRestaurants\Core\Tagging\Mall_Tagger;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants run-job|jobs|find-duplicates|retag|check-status|recent|changes`.
 */
final class Monitoring_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants run-job', array( self::class, 'run_job' ) );
		WP_CLI::add_command( 'guam-restaurants jobs', array( self::class, 'jobs' ) );
		WP_CLI::add_command( 'guam-restaurants find-duplicates', array( self::class, 'find_duplicates' ) );
		WP_CLI::add_command( 'guam-restaurants retag', array( self::class, 'retag' ) );
		WP_CLI::add_command( 'guam-restaurants check-status', array( self::class, 'check_status' ) );
		WP_CLI::add_command( 'guam-restaurants recent', array( self::class, 'recent' ) );
		WP_CLI::add_command( 'guam-restaurants changes', array( self::class, 'changes' ) );
	}

	/**
	 * Runs a scheduled job now. This is what the server crontab calls
	 * (docs/scheduling.md). Exits with an error when the job fails, so cron
	 * can report it.
	 *
	 * ## OPTIONS
	 *
	 * <job>
	 * : osm (weekly import + duplicates), discover (daily Google discovery from the search log), newsletter (weekly draft), google-status, yelp-status (monthly), or health-check (daily).
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants run-job osm
	 *     wp guam-restaurants run-job health-check
	 *
	 * @param string[]             $args       Job name.
	 * @param array<string, mixed> $assoc_args Flags (unused).
	 * @return void
	 */
	public static function run_job( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- command signature.
		$job = (string) ( $args[0] ?? '' );
		if ( ! isset( Jobs::definitions()[ $job ] ) ) {
			WP_CLI::error( 'Unknown job. Use one of: ' . implode( ', ', array_keys( Jobs::definitions() ) ) );
		}
		$outcome = Jobs::run( $job );
		if ( ! $outcome['ok'] ) {
			WP_CLI::error( sprintf( '%s failed: %s%s', $job, $outcome['error'], $outcome['run_id'] ? " (ingestion run #{$outcome['run_id']})" : '' ) );
		}
		WP_CLI::success( sprintf( '%s: %s%s', $job, $outcome['summary'], $outcome['run_id'] ? " Ingestion run #{$outcome['run_id']}." : '' ) );
	}

	/**
	 * Lists scheduled jobs with their last success and deadline.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants jobs
	 *
	 * @return void
	 */
	public static function jobs(): void {
		WP_CLI::log( sprintf( 'Mode: %s (%s=server for a real crontab; see docs/scheduling.md).', Jobs::mode(), Config::CRON_MODE ) );
		$rows = array();
		foreach ( Jobs::definitions() as $job => $definition ) {
			$enabled = Jobs::enabled( $job );
			$last    = Jobs::last_success( $job );
			$next    = wp_next_scheduled( Jobs::HOOK, array( $job ) );
			$rows[]  = array(
				'job'          => $job,
				'enabled'      => $enabled ? 'yes' : 'no',
				'crontab'      => $definition['cron'],
				'last_success' => $last ? gmdate( 'Y-m-d H:i', $last ) : 'never',
				'due_by'       => $enabled && Jobs::HEALTH_CHECK !== $job ? gmdate( 'Y-m-d H:i', Jobs::deadline( $job ) ) : '-',
				'wp_cron_next' => $next ? gmdate( 'Y-m-d H:i', $next ) : '-',
				'last_error'   => (string) ( Jobs::state( $job )['last_error'] ?? '' ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Finds probable duplicate restaurants by normalized name, phone and
	 * distance. Merges only near-certain pairs (same phone, near-identical
	 * name, within 50 m); queues the rest for review.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List pairs without merging or queueing.
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function find_duplicates( array $args, array $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$result  = Mall_Verification::with_unverified(
			static function () use ( $dry_run ) {
				return ( new Duplicate_Detector() )->run( $dry_run );
			}
		);
		$rows    = array();
		foreach ( $result['pairs'] as $pair ) {
			$rows[] = array(
				'action'     => $pair['action'],
				'ids'        => implode( ' + ', $pair['ids'] ),
				'names'      => implode( ' | ', $pair['names'] ),
				'confidence' => $pair['confidence'],
				'name'       => $pair['name_similarity'],
				'phone'      => null === $pair['phone_match'] ? '?' : ( $pair['phone_match'] ? 'same' : 'different' ),
				'distance_m' => $pair['distance_m'] ?? '?',
			);
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
		WP_CLI::success( ( $dry_run ? 'Dry run: ' : '' ) . Duplicate_Detector::summary( $result['counts'] ) . ( $result['run_id'] ? " Ingestion run #{$result['run_id']}." : '' ) );
	}

	/**
	 * Re-applies the cuisine rules (data/cuisine-rules.json) and mall
	 * membership (saved outlines and address text) to every restaurant,
	 * without calling any API. Hand-set tags are kept.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report changes without writing.
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function retag( array $args, array $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		try {
			$cuisines = Cuisine_Tagger::from_file();
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$changes = Mall_Verification::with_unverified(
			static function () use ( $cuisines, $dry_run ) {
				return Change_Tracker::suppress(
					static function () use ( $cuisines, $dry_run ) {
						$malls = Mall_Tagger::from_saved();
						$rows  = array();
						$ids   = get_posts(
							array(
								'post_type'      => Content_Types::POST_TYPE,
								'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
								'fields'         => 'ids',
								'posts_per_page' => -1,
								'no_found_rows'  => true,
							)
						);
						foreach ( $ids as $id ) {
							$cuisine = $cuisines->apply( (int) $id, null, null, $dry_run );
							$mall    = $malls->apply( (int) $id, null, null, $dry_run );
							if ( $cuisine['old'] !== $cuisine['new'] || $mall['old'] !== $mall['new'] ) {
								if ( ! $dry_run && $cuisine['old'] !== $cuisine['new'] ) {
									Change_Log::record( (int) $id, 'tax:' . Content_Types::TAX_CUISINE, implode( ',', $cuisine['old'] ), implode( ',', $cuisine['new'] ), 'system' );
								}
								if ( ! $dry_run && $mall['old'] !== $mall['new'] ) {
									Change_Log::record( (int) $id, 'tax:' . Content_Types::TAX_MALL, $mall['old'], $mall['new'], 'rule' === $mall['origin'] ? 'system' : 'osm' );
								}
								$rows[] = array(
									'id'       => $id,
									'name'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES ),
									'cuisines' => implode( ',', $cuisine['old'] ) . ' -> ' . implode( ',', $cuisine['new'] ),
									'mall'     => $mall['old'] . ' -> ' . $mall['new'],
								);
							}
						}
						return $rows;
					}
				);
			}
		);

		if ( $changes ) {
			WP_CLI\Utils\format_items( 'table', $changes, array( 'id', 'name', 'cuisines', 'mall' ) );
		}
		WP_CLI::success( sprintf( '%s%d restaurant(s) changed.', $dry_run ? 'Dry run: ' : '', count( $changes ) ) );
	}

	/**
	 * Checks whether restaurants are still open (Google Place Details,
	 * business status only). Stays inside the free monthly allowance with
	 * the default $0 cap. Two "closed" answers in a row put the restaurant
	 * in the review queue; nothing is marked closed without approval.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Source to ask.
	 * ---
	 * default: google
	 * options:
	 *   - google
	 *   - yelp
	 * ---
	 *
	 * [--dry-run]
	 * : Ask the source but record nothing.
	 *
	 * [--estimate]
	 * : Print the request count and cost, then stop.
	 *
	 * @param string[]             $args       Unused.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function check_status( array $args, array $assoc_args ): void {
		$source_key = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'source', 'google' );
		$key        = Config::get( 'yelp' === $source_key ? Config::YELP_KEY : Config::GOOGLE_PLACES_KEY );
		if ( '' === $key ) {
			WP_CLI::error( 'No API key set for ' . $source_key . '.' );
		}
		$source   = 'yelp' === $source_key ? new Yelp_Status_Source( $key ) : new Google_Status_Source( $key );
		$estimate = Status_Checker::estimate( $source );
		WP_CLI::log( sprintf( '%d restaurant(s) to check: %d free this month, %s if all were made.', $estimate['calls'], $estimate['free'], Pricing::format( $estimate['cost'] ) ) );
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'estimate', false ) ) {
			return;
		}

		$result = ( new Status_Checker( $source, Budget::for_run() ) )->run( null, (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) );
		foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( $error );
		}
		WP_CLI::success( Status_Checker::notes( $source_key, $result ) . ( $result['run_id'] ? ". Ingestion run #{$result['run_id']}." : '.' ) );
	}

	/**
	 * Lists recent openings or closures.
	 *
	 * ## OPTIONS
	 *
	 * <kind>
	 * : openings or closures.
	 *
	 * [--days=<days>]
	 * : How far back.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args       Kind.
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function recent( array $args, array $assoc_args ): void {
		$days = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'days', 30 );
		$rows = 'closures' === ( $args[0] ?? '' ) ? Queries::recent_closures( $days ) : Queries::recent_openings( $days );
		foreach ( $rows as &$row ) {
			$row['village'] = implode( ',', $row['village'] );
			$row['mall']    = implode( ',', $row['mall'] );
		}
		unset( $row );
		if ( ! $rows ) {
			WP_CLI::log( 'None.' );
			return;
		}
		WP_CLI\Utils\format_items( (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Prints a restaurant's change history.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Restaurant post ID.
	 *
	 * @param string[]             $args       ID.
	 * @param array<string, mixed> $assoc_args Unused.
	 * @return void
	 */
	public static function changes( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- command signature.
		$rows = array();
		foreach ( Queries::changes_for( (int) ( $args[0] ?? 0 ) ) as $change ) {
			foreach ( array( 'old_value', 'new_value' ) as $key ) {
				$change[ $key ] = is_array( $change[ $key ] ) ? wp_json_encode( $change[ $key ] ) : (string) $change[ $key ];
			}
			$rows[] = $change;
		}
		if ( ! $rows ) {
			WP_CLI::log( 'No changes recorded.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'detected_at', 'field', 'old_value', 'new_value', 'source', 'run_id' ) );
	}
}
