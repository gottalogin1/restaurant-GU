<?php
/**
 * WP-CLI commands: `wp guam-restaurants import-osm`, `import-fsq`, `discover`, `search-log` and `branch-names`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Discovery\Discoverer;
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Discovery\Search_Log;
use GuamRestaurants\Core\Fsq\Importer as Fsq_Importer;
use GuamRestaurants\Core\Fsq\Place_Mapper;
use GuamRestaurants\Core\Fsq\Reader as Fsq_Reader;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Monitoring\Alerts;
use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Osm\Element_Mapper;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Tagging\Branch_Names;
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
		WP_CLI::add_command( 'guam-restaurants import-fsq', array( self::class, 'import_fsq' ) );
		WP_CLI::add_command( 'guam-restaurants discover', array( self::class, 'discover' ) );
		WP_CLI::add_command( 'guam-restaurants branch-names', array( self::class, 'branch_names' ) );
		WP_CLI::add_command( 'guam-restaurants search-log', Search_Log_Command::class );
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
		if ( null !== $result['branch_names'] ) {
			self::log_branch_names( $result['branch_names'], false );
		}

		self::log_counts( $dry_run, $result['counts'], $result['run_id'] );
	}

	/**
	 * Adds Guam's eating and drinking places from Foursquare Open Source
	 * Places, a free business listings dataset (Apache 2.0).
	 *
	 * Reads the Guam extract made by `make fsq-extract` (JSON Lines, a JSON
	 * array, or CSV with Foursquare's column names). Each place is matched
	 * to the listings we already have first, by name, phone and distance: a
	 * clear match is linked and only gets the address, phone, website, email
	 * or social links it is missing; anything else becomes a draft for an
	 * administrator to check and publish. A new draft that looks like an
	 * existing listing is also queued as a possible duplicate. Places that
	 * Foursquare marks closed or flags are skipped. Run it again with a new
	 * extract at any time: it never creates the same place twice.
	 *
	 * Then run `wp guam-restaurants match-ratings --source=google` to find
	 * each new draft on Google Maps, so listings show Google's live rating,
	 * hours, phone and website.
	 *
	 * ## OPTIONS
	 *
	 * --input=<file>
	 * : The Guam extract.
	 *
	 * [--dry-run]
	 * : Compare, but write nothing.
	 *
	 * [--max-age-days=<days>]
	 * : Skip places Foursquare has not refreshed in this many days (likely
	 * stale). 0 keeps all.
	 * ---
	 * default: 1095
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants import-fsq --input=/project/.cache/fsq-guam.jsonl --dry-run
	 *     wp guam-restaurants import-fsq --input=/project/.cache/fsq-guam.jsonl
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function import_fsq( array $args, array $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$input   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'input', '' );
		$max_age = max( 0, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'max-age-days', 1095 ) );
		$since   = $max_age ? gmdate( 'Y-m-d', time() - $max_age * DAY_IN_SECONDS ) : '';

		try {
			$rows = Fsq_Reader::read( $input );
			WP_CLI::log( sprintf( 'Read %d places from %s.', count( $rows ), $input ) );
			$importer = new Fsq_Importer( new Place_Mapper( Cuisine_Map::from_file() ), $since );
			$result   = $importer->run( $rows, $dry_run );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( $error );
		}
		foreach ( $result['skipped'] as $reason => $count ) {
			WP_CLI::log( sprintf( 'Skipped %d place(s): %s.', $count, $reason ) );
		}
		WP_CLI::log( sprintf( '%d matched to listings we already had; %d new draft(s) look like an existing listing and are in the review queue.', $result['linked'], $result['queued'] ) );
		if ( $result['unmapped'] ) {
			WP_CLI::log( 'Categories with no cuisine (add them to data/osm-cuisine-map.json to tag them):' );
			$rows = array();
			foreach ( $result['unmapped'] as $value => $count ) {
				$rows[] = array(
					'cuisine' => $value,
					'places'  => $count,
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'cuisine', 'places' ) );
		}
		if ( null !== $result['branch_names'] ) {
			self::log_branch_names( $result['branch_names'], false );
		}

		self::log_counts( $dry_run, $result['counts'], $result['run_id'] );
	}

	/**
	 * Names branches "<name>, <village>": every restaurant whose name another
	 * listing also has (a chain such as McDonald's) gets its village after
	 * the name, so "McDonald's" in Mangilao becomes "McDonald's, Mangilao".
	 * A restaurant with a name of its own keeps it. Branches with no village
	 * keep their name and are added to the review queue (Village unknown);
	 * once their village is set they are renamed. The OSM and Foursquare
	 * imports and the review queue do this on their own; run this once for
	 * the listings you already have, or after renaming listings by hand.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the new names, but change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants branch-names --dry-run
	 *     wp guam-restaurants branch-names
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function branch_names( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		self::log_branch_names( Branch_Names::apply( $dry_run ), $dry_run, true );
	}

	/**
	 * Reports Branch_Names::apply().
	 *
	 * @param array{renamed: array<int, array{old: string, new: string}>, no_village: int[], same_village: int[]} $result  Result.
	 * @param bool                                                                                                $dry_run Dry run.
	 * @param bool                                                                                                $details List every rename.
	 * @return void
	 */
	private static function log_branch_names( array $result, bool $dry_run, bool $details = false ): void {
		if ( $details && $result['renamed'] ) {
			$rows = array();
			foreach ( $result['renamed'] as $id => $names ) {
				$rows[] = array(
					'id'  => $id,
					'old' => $names['old'],
					'new' => $names['new'],
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'old', 'new' ) );
		}
		WP_CLI::log( sprintf( '%s %d branch name(s) to "Name, Village".', $dry_run ? 'Would rename' : 'Renamed', count( $result['renamed'] ) ) );
		if ( $result['no_village'] ) {
			WP_CLI::warning( sprintf( '%d branch(es) have no single village, so their names were left as they are%s: %s', count( $result['no_village'] ), $dry_run ? '' : ' (queued for review as Village unknown)', implode( ', ', self::titles( $result['no_village'] ) ) ) );
		}
		if ( $result['same_village'] ) {
			WP_CLI::warning( sprintf( 'Branches with the same name in the same village still share a name; rename them by hand (for example with the mall or street): %s', implode( ', ', self::titles( $result['same_village'] ) ) ) );
		}
	}

	/**
	 * "Title (#ID)" for each restaurant.
	 *
	 * @param int[] $ids Restaurants.
	 * @return string[]
	 */
	private static function titles( array $ids ): array {
		return array_map(
			static function ( int $id ): string {
				return html_entity_decode( (string) get_post_field( 'post_title', $id, 'raw' ), ENT_QUOTES ) . ' (#' . $id . ')';
			},
			$ids
		);
	}

	/**
	 * Finds restaurants missing from the directory with Google Places Text
	 * Search and adds them to the review queue as candidates. Never creates
	 * restaurant posts.
	 *
	 * Works through the search log (`wp guam-restaurants search-log`):
	 * searches that have never run come first, then searches whose weekly
	 * re-check is due. It keeps going until nothing is due or the next
	 * request would pass the cost cap, so a run never repeats a search that
	 * was just done. A village whose search fills every result page gets
	 * narrower cuisine searches for the next run.
	 *
	 * Stores only each candidate's place ID (Google allows nothing else to
	 * be kept). Needs the GUAM_RESTAURANTS_GOOGLE_PLACES_KEY constant or
	 * environment variable. Each request is billed as Text Search Pro and
	 * counts against the free monthly allowance; with the default $0 run
	 * cap (GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD) it stops rather than make a
	 * paid request.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Call the API and report, but queue nothing and leave the log alone.
	 *
	 * [--scope=<scope>]
	 * : Which searches to run.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - villages
	 *   - malls
	 *   - island
	 * ---
	 *
	 * [--max-requests=<n>]
	 * : Stop after this many requests (default: no limit besides the cap).
	 *
	 * [--recheck-days=<days>]
	 * : Days before a finished search is due again.
	 * ---
	 * default: 7
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
	 *     wp guam-restaurants discover --dry-run --max-requests=10
	 *     wp guam-restaurants discover
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function discover( array $args, array $assoc_args ): void {
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$scope   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', 'all' );
		$max     = WP_CLI\Utils\get_flag_value( $assoc_args, 'max-requests', null );
		$recheck = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'recheck-days', Search_Log::DEFAULT_RECHECK_DAYS );
		$pages   = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'max-pages', 3 );
		$delay   = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'delay', 1 );

		$client = Places_Client::from_config();
		if ( null === $client ) {
			WP_CLI::error( 'No Google Places key. Define GUAM_RESTAURANTS_GOOGLE_PLACES_KEY in wp-config.php or the environment (for the Docker setup, in .env, then `docker compose up -d`).' );
			return;
		}

		$result = ( new Discoverer( $client, $delay, $pages ) )->run_from_log( $scope, $dry_run, null === $max ? null : max( 1, (int) $max ), max( 1, $recheck ) );

		self::report_discovery( $result, $dry_run );
	}

	/**
	 * Prints a discovery result.
	 *
	 * @param array<string, mixed> $result  Discoverer::run_from_log() result.
	 * @param bool                 $dry_run Dry run.
	 * @return void
	 */
	private static function report_discovery( array $result, bool $dry_run ): void {
		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		if ( $result['added_queries'] ) {
			WP_CLI::log( sprintf( 'Added %d new search(es) to the search log.', $result['added_queries'] ) );
		}
		if ( $result['stopped_at_cap'] ) {
			WP_CLI::warning( 'Stopped: this month\'s free Text Search allowance is used up and GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD allows no paid calls. The remaining searches run next month (or raise the cap).' );
		}
		WP_CLI::log( sprintf( '%d queries, %d requests; %d search(es) still due.', $result['queries'], $result['requests'], $result['left'] ) );
		WP_CLI::log( sprintf( '%s %d new candidate(s) to the review queue; %d result(s) already listed, queued or permanently closed; %d failed queries.', $dry_run ? 'Would add' : 'Added', $result['counts']['created'], $result['counts']['unchanged'], $result['counts']['failed'] ) );
		if ( $result['counts']['created'] && ! $dry_run ) {
			WP_CLI::log( 'Review them under Restaurants > Review queue (New place): "Add as restaurant" creates a draft.' );
		}
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
