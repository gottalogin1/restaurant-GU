<?php
/**
 * Deactivation routine.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated. Keeps all data; uninstall.php handles removal.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		unregister_post_type( Content_Types::POST_TYPE );
		Ratings\Refresher::unschedule();
		flush_rewrite_rules();
	}
}
