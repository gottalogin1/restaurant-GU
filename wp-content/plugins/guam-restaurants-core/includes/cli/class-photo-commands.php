<?php
/**
 * WP-CLI command: `wp guam-restaurants cuisine-photos`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Photos\Cuisine_Photos;
use GuamRestaurants\Core\Photos\Openverse_Importer;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Illustrative cuisine photos from Openverse.
 */
final class Photo_Commands {

	/**
	 * Registers the command.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants cuisine-photos', array( self::class, 'cuisine_photos' ) );
	}

	/**
	 * Gives each cuisine an openly licensed food photo from Openverse
	 * (CC0, public domain, CC BY or CC BY-SA), shown with its credit and the
	 * label "Illustrative photo" on restaurants that have no photo of their
	 * own. Cuisines that already have one are left alone, so running it
	 * again changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--cuisine=<slug>]
	 * : Only this cuisine. Default: every cuisine that has restaurants.
	 *
	 * [--refresh]
	 * : Search again and replace photos the cuisines already have.
	 *
	 * [--id=<openverse-id>]
	 * : Use this Openverse image (from its openverse.org page address). Needs --cuisine.
	 *
	 * [--query=<words>]
	 * : Search words to use and remember for this cuisine, instead of "<cuisine> food". Needs --cuisine.
	 *
	 * [--remove]
	 * : Take the photo off this cuisine. Needs --cuisine.
	 *
	 * [--dry-run]
	 * : Search and show what would be picked, but download and save nothing.
	 *
	 * [--delay=<seconds>]
	 * : Wait between cuisines (anonymous Openverse use is rate limited).
	 * ---
	 * default: 3
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants cuisine-photos --dry-run
	 *     wp guam-restaurants cuisine-photos
	 *     wp guam-restaurants cuisine-photos --cuisine=chamorro --query="kelaguen chicken" --refresh
	 *     wp guam-restaurants cuisine-photos --cuisine=japanese --id=4bc43a04-ef46-4544-a0c1-63c63f56e276
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function cuisine_photos( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		$slug  = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'cuisine', '' );
		$id    = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'id', '' );
		$query = WP_CLI\Utils\get_flag_value( $assoc_args, 'query', null );

		if ( '' === $slug && ( '' !== $id || null !== $query || isset( $assoc_args['remove'] ) ) ) {
			WP_CLI::error( '--id, --query and --remove need --cuisine.' );
			return;
		}
		$cuisines = Cuisine_Photos::used_cuisines();
		if ( '' !== $slug ) {
			$term = get_term_by( 'slug', $slug, Content_Types::TAX_CUISINE );
			if ( ! $term instanceof \WP_Term ) {
				WP_CLI::error( "No cuisine with the slug {$slug}." );
				return;
			}
			$cuisines = array( $term );
		}

		if ( isset( $assoc_args['remove'] ) ) {
			$removed = Cuisine_Photos::remove( $cuisines[0]->term_id );
			WP_CLI::success( $removed ? "Removed the photo from {$slug}. The image stays in the media library." : "{$slug} had no photo." );
			return;
		}

		$dry_run = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$result  = ( new Openverse_Importer( null, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'delay', 3 ) ) )->run(
			$cuisines,
			(bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'refresh', false ),
			$dry_run,
			$id,
			null === $query ? null : (string) $query
		);

		foreach ( $result['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		$fields = array( 'cuisine', 'action', 'openverse_id', 'creator', 'license', 'source' );
		if ( $result['rows'] ) {
			$blank = array_fill_keys( $fields, '' );
			WP_CLI\Utils\format_items(
				'table',
				array_map(
					static function ( array $row ) use ( $blank ): array {
						return $row + $blank;
					},
					$result['rows']
				),
				$fields
			);
		}
		WP_CLI::log(
			sprintf(
				'%s: created %d, updated %d, unchanged %d, failed %d.%s',
				$dry_run ? 'Dry run' : 'Done',
				$result['counts']['created'],
				$result['counts']['updated'],
				$result['counts']['unchanged'],
				$result['counts']['failed'],
				$result['run_id'] ? " Ingestion run #{$result['run_id']}." : ''
			)
		);
		if ( ! $dry_run && ( $result['counts']['created'] || $result['counts']['updated'] ) ) {
			WP_CLI::log( 'Look at each photo on a restaurant of that cuisine. To swap one, run again with --cuisine and --refresh, --query or --id.' );
		}
	}
}
