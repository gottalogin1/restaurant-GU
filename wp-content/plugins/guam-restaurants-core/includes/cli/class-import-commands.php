<?php
/**
 * WP-CLI commands: `wp guam-restaurants import-osm` and `wp guam-restaurants discover`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Discovery\Discoverer;
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Monitoring\Alerts;
use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Osm\Element_Mapper;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Osm\Overpass_Client;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Data import commands.
 */
final class Import_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants import-osm', array( self::class, 'import_osm' ) );
		WP_CLI::add_command( 'guam-restaurants discover', array( self::class, 'discover' ) );
	}

	/**
	 * Imports Guam's restaurants, cafes, fast food places, bars, pubs and
	 * food courts from OpenStreetMap (Overpass API).
	 *
	 * Upserts by OSM id, so running it again never creates duplicates. Sets
	 * cuisine (data/osm-cuisine-map.json), village (Census boundaries) and
	 * mall (OSM outline, or a radius around the mall's point). Logs changes
	 * to existing restaurants in the change log and the run in the ingestion
	 * runs table. Run `wp guam seed` first.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Fetch and compare, but write nothing.
	 *
	 * [--input=<file>]
	 * : Read a saved Overpass JSON response instead of calling the API.
	 *
	 * [--save-response=<file>]
	 * : Save the raw Overpass response to this file.
	 *
	 * [--post-status=<status>]
	 * : Status for new restaurants.
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - pending
	 * ---
	 *
	 * [--mall-radius=<metres>]
	 * : For malls OSM maps as a point, how close a restaurant must be.
	 * ---
	 * default: 150
	 * ---
	 *
	 * [--no-closure-check]
	 * : Do not count restaurants missing from this data as possibly closed
	 * (use with a partial --input file).
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants import-osm --dry-run
	 *     wp guam-restaurants import-osm
	 *     wp guam-restaurants import-osm --input=guam-overpass.json
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function import_osm( array $args, array $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$input   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'input', '' );
		$save    = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'save-response', '' );
		$status  = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'post-status', 'publish' );
		$radius  = (float) WP_CLI\Utils\get_flag_value( $assoc_args, 'mall-radius', 150 );
		$closure = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'closure-check', true );

		$attempts = array();
		try {
			if ( '' !== $input ) {
				$json = is_readable( $input ) ? file_get_contents( $input ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false === $json ) {
					WP_CLI::error( "Cannot read {$input}." );
				}
				$elements = Overpass_Client::decode( $json );
				WP_CLI::log( sprintf( 'Read %d elements from %s.', count( $elements ), $input ) );
			} else {
				WP_CLI::log( 'Querying Overpass (one request; waits and retries on errors)...' );
				$client = new Overpass_Client();
				try {
					$fetched = $client->fetch();
				} catch ( Request_Failed $e ) {
					if ( ! $dry_run ) {
						// Logged so failures count toward the source's failure rate (Failure_Rates).
						Ingestion_Runs::log_failure( 'osm', 'Overpass: ' . $e->getMessage(), $e->attempt_log() );
					}
					WP_CLI::error( $e->getMessage() );
					return;
				}
				$attempts = $client->attempt_log();
				foreach ( $attempts as $line ) {
					WP_CLI::warning( $line );
				}
				$elements = $fetched['elements'];
				if ( '' !== $save ) {
					file_put_contents( $save, $fetched['raw'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
				WP_CLI::log( sprintf( 'Overpass returned %d elements.', count( $elements ) ) );
			}

			$importer = ( new Importer( new Element_Mapper( Cuisine_Map::from_file() ), null, $radius, $status ) )->with_closure_checks( $closure );
			$result   = $importer->run( $elements, $dry_run );
			if ( $result['run_id'] ) {
				Ingestion_Runs::record_retries( $result['run_id'], $attempts );
			}
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( $error );
		}
		foreach ( $result['skipped'] as $reason => $count ) {
			WP_CLI::log( sprintf( 'Skipped %d element(s): %s.', $count, $reason ) );
		}
		if ( $result['village_unresolved'] ) {
			WP_CLI::log( sprintf( '%d restaurant(s) fall outside every village (queued for review): %s', count( $result['village_unresolved'] ), implode( ', ', $result['village_unresolved'] ) ) );
		}
		if ( $result['unmatched_malls'] ) {
			WP_CLI::log( 'OSM malls with no mall term (add the term, or its OSM id, to use them):' );
			foreach ( $result['unmatched_malls'] as $osm_id => $name ) {
				WP_CLI::log( sprintf( '  %s  %s', $osm_id, '' === $name ? '(no name)' : $name ) );
			}
		}
		if ( $result['unmapped'] ) {
			WP_CLI::log( 'Unmapped cuisine values (add them to data/osm-cuisine-map.json):' );
			$rows = array();
			foreach ( $result['unmapped'] as $value => $count ) {
				$rows[] = array(
					'cuisine'     => $value,
					'restaurants' => $count,
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'cuisine', 'restaurants' ) );
		}

		if ( $result['no_cuisine_tag'] ) {
			WP_CLI::log( sprintf( '%d restaurant(s) have no cuisine tag in OSM (Restaurants > No cuisine lists those still untagged).', $result['no_cuisine_tag'] ) );
		}

		if ( $result['closures']['checked'] ) {
			WP_CLI::log( sprintf( '%d restaurant(s) missing from OSM this run; %d now await closure approval in the review queue.', count( $result['closures']['closed'] ), count( $result['closures']['queued'] ) ) );
			if ( Alerts::closures_in_run( 'OpenStreetMap import', $result['closures']['closed'], $result['closures']['queued'], $result['run_id'] ) ) {
				WP_CLI::warning( 'More restaurants than the alert threshold look closed; an alert email was sent.' );
			}
		} elseif ( '' !== $result['closures']['note'] ) {
			WP_CLI::warning( ucfirst( $result['closures']['note'] ) . '.' );
		}

		self::log_counts( $dry_run, $result['counts'], $result['run_id'] );
	}

	/**
	 * Finds restaurants missing from the directory with Google Places Text
	 * Search ("restaurants in <village or mall>, Guam") and adds them to the
	 * review queue as candidates. Never creates restaurant posts.
	 *
	 * Stores only each candidate's place ID (Google allows nothing else to
	 * be kept). Needs the GUAM_RESTAURANTS_GOOGLE_PLACES_KEY constant or
	 * environment variable. Each request is billed as Text Search Pro: 19
	 * villages plus the malls, up to 3 pages each. Requests count against
	 * the free monthly allowance; with the default $0 run cap it stops
	 * rather than make a paid request.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Call the API and report, but queue nothing.
	 *
	 * [--scope=<scope>]
	 * : Which areas to search.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - villages
	 *   - malls
	 * ---
	 *
	 * [--max-pages=<n>]
	 * : Result pages per query (20 results each), 1 to 3.
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--delay=<seconds>]
	 * : Wait between requests.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants discover --dry-run --scope=malls
	 *     wp guam-restaurants discover
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function discover( array $args, array $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$scope   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', 'all' );
		$pages   = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'max-pages', 3 );
		$delay   = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'delay', 1 );

		$client = Places_Client::from_config();
		if ( null === $client ) {
			WP_CLI::error( 'No Google Places key. Define GUAM_RESTAURANTS_GOOGLE_PLACES_KEY in wp-config.php or the environment.' );
			return;
		}

		$areas = Discoverer::areas( $scope );
		WP_CLI::log( sprintf( 'Searching %d area(s)...', count( $areas ) ) );

		$result = ( new Discoverer( $client, $delay, $pages ) )->run( $areas, $dry_run );

		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		if ( $result['stopped_at_cap'] ) {
			WP_CLI::warning( 'Stopped: this month\'s free Text Search allowance is used up and GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD allows no paid calls.' );
		}
		WP_CLI::log( sprintf( '%d queries, %d requests.', $result['queries'], $result['requests'] ) );
		WP_CLI::log( sprintf( '%s %d new candidate(s) to the review queue; %d result(s) already listed, queued or permanently closed; %d failed queries.', $dry_run ? 'Would add' : 'Added', $result['counts']['created'], $result['counts']['unchanged'], $result['counts']['failed'] ) );
		if ( $result['run_id'] ) {
			WP_CLI::log( sprintf( 'Ingestion run #%d.', $result['run_id'] ) );
		}
	}

	/**
	 * Prints the CLAUDE.md rule 9 counts.
	 *
	 * @param bool               $dry_run Dry run.
	 * @param array<string, int> $counts  Counts.
	 * @param int|null           $run_id  Run ID.
	 * @return void
	 */
	private static function log_counts( bool $dry_run, array $counts, ?int $run_id ): void {
		WP_CLI::log(
			sprintf(
				'%s: created %d, updated %d, unchanged %d, failed %d.%s',
				$dry_run ? 'Dry run' : 'Done',
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$counts['failed'],
				$run_id ? " Ingestion run #{$run_id}." : ''
			)
		);
	}
}
