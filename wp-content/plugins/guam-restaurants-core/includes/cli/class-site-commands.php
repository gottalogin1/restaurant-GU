<?php
/**
 * WP-CLI commands for the public site.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Site\Open_Hours;
use GuamRestaurants\Core\Site\Pages;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants reindex-hours` and `wp guam-restaurants site-pages`.
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
}
