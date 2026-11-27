<?php
/**
 * WP-CLI commands for the public site.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Site\Buffets;
use GuamRestaurants\Core\Site\Open_Hours;
use GuamRestaurants\Core\Site\Pages;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants reindex-hours`, `wp guam-restaurants site-pages` and
 * `wp guam-restaurants buffets apply`.
 */
final class Site_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants reindex-hours', array( self::class, 'reindex_hours' ) );
		WP_CLI::add_command( 'guam-restaurants site-pages', array( self::class, 'site_pages' ) );
		WP_CLI::add_command( 'guam-restaurants buffets apply', array( self::class, 'buffets_apply' ) );
	}

	/**
	 * Rebuilds the "open now" index from every restaurant's hours. Runs by
	 * itself once after an upgrade; use this after bulk database edits.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants reindex-hours
	 *
	 * @return void
	 */
	public static function reindex_hours(): void {
		WP_CLI::success( sprintf( 'Indexed hours for %d restaurants.', Open_Hours::rebuild_all() ) );
	}

	/**
	 * Creates any missing site pages (map, recent changes, claim, correction,
	 * contact, terms) and lists them with their status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants site-pages
	 *
	 * @return void
	 */
	public static function site_pages(): void {
		$rows = array();
		foreach ( Pages::ensure() as $role => $id ) {
			$rows[] = array(
				'role'   => $role,
				'id'     => $id,
				'status' => get_post_status( $id ),
				'url'    => get_permalink( $id ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'role', 'id', 'status', 'url' ) );
	}

	/**
	 * Puts the hand-researched buffets (data/buffets.php) on the places they
	 * name, matched by name within their village. Runs by itself once after
	 * the update; run it again after an import adds a missing place. Facts an
	 * owner or editor set are kept unless --overwrite.
	 *
	 * ## OPTIONS
	 *
	 * [--overwrite]
	 * : Also replace buffets set by owners or editors.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants buffets apply
	 *
	 * @param string[]              $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function buffets_apply( array $args, array $assoc_args ): void {
		unset( $args );
		$counts = Buffets::apply_research( null, isset( $assoc_args['overwrite'] ) );
		foreach ( $counts['missing'] as $name ) {
			WP_CLI::warning( sprintf( 'Not found (add the place or fix its name, then run this again): %s', $name ) );
		}
		WP_CLI::success( sprintf( 'Buffets: created %1$d, updated %2$d, unchanged %3$d, failed %4$d, not found %5$d.', $counts['created'], $counts['updated'], $counts['unchanged'], $counts['failed'], count( $counts['missing'] ) ) );
	}
}
