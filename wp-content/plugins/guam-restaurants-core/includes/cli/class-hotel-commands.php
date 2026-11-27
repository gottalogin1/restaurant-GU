<?php
/**
 * WP-CLI commands for the hotel pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Site\Hotel_Pages;
use GuamRestaurants\Core\Site\Hotels;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants hotels import` and `wp guam-restaurants hotels list`.
 */
final class Hotel_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants hotels import', array( self::class, 'import' ) );
		WP_CLI::add_command( 'guam-restaurants hotels list', array( self::class, 'list_hotels' ) );
	}

	/**
	 * Loads Guam's hotels from OpenStreetMap (one Overpass request) for the
	 * "Restaurants near" pages. Safe to run again: hidden hotels stay hidden,
	 * hotels added by hand are kept, hotels gone from OSM are removed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count what would change; save nothing.
	 *
	 * [--input=<file>]
	 * : Read a saved Overpass JSON response instead of calling the API.
	 *
	 * [--save-response=<file>]
	 * : Save the raw Overpass response to this file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants hotels import
	 *     wp guam-restaurants hotels import --input=guam-hotels.json
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function import( array $args, array $assoc_args ): void {
		unset( $args );
		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$input   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'input', '' );
		$save    = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'save-response', '' );

		try {
			if ( '' !== $input ) {
				$json = is_readable( $input ) ? file_get_contents( $input ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false === $json ) {
					WP_CLI::error( "Cannot read {$input}." );
				}
				$elements = Overpass_Client::decode( (string) $json );
			} else {
				WP_CLI::log( 'Querying Overpass for hotels (one request; waits and retries on errors)...' );
				$client = new Overpass_Client();
				try {
					$fetched = $client->fetch( Overpass_Client::hotels_query() );
				} catch ( Request_Failed $e ) {
					if ( ! $dry_run ) {
						Ingestion_Runs::log_failure( Hotels::RUN_SOURCE, 'Overpass: ' . $e->getMessage(), $e->attempt_log() );
					}
					WP_CLI::error( $e->getMessage() );
					return;
				}
				foreach ( $client->attempt_log() as $line ) {
					WP_CLI::warning( $line );
				}
				$elements = $fetched['elements'];
				if ( '' !== $save ) {
					file_put_contents( $save, $fetched['raw'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				}
			}
			$counts = Hotels::import( $elements, $dry_run );
		} catch ( \Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'%sHotels: created %d, updated %d, unchanged %d, removed %d, failed %d. %d now have a page.',
				$dry_run ? '[dry run] ' : '',
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$counts['removed'],
				$counts['failed'],
				$dry_run ? 0 : count( Hotels::with_pages() )
			)
		);
	}

	/**
	 * Lists every hotel with its places nearby and its page.
	 *
	 * ## OPTIONS
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
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function list_hotels( array $args, array $assoc_args ): void {
		unset( $args );
		$rows = array();
		foreach ( Hotels::all() as $slug => $hotel ) {
			$places = Hotels::places( $hotel );
			$page   = ! $hotel['hidden'] && Hotels::has_page( $hotel );
			$rows[] = array(
				'slug'    => $slug,
				'name'    => $hotel['name'],
				'village' => $hotel['village'],
				'walk'    => count( $places['walk'] ),
				'drive'   => count( $places['drive'] ),
				'status'  => $hotel['hidden'] ? 'hidden' : ( $page ? 'page' : 'too few places' ),
				'url'     => $page ? Hotel_Pages::url( (string) $slug ) : '',
			);
		}
		WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'slug', 'name', 'village', 'walk', 'drive', 'status', 'url' ) );
	}
}
