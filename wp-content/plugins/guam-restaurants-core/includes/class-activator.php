<?php
/**
 * Activation routine.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.6';

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) || version_compare( get_bloginfo( 'version' ), self::MIN_WP, '<' ) ) {
			deactivate_plugins( plugin_basename( GUAM_RESTAURANTS_CORE_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
						__( 'Guam Restaurants Core needs PHP %1$s and WordPress %2$s or newer.', 'guam-restaurants-core' ),
						self::MIN_PHP,
						self::MIN_WP
					)
				),
				'',
				array( 'back_link' => true )
			);
		}

		Database\Schema::install();
		Highlights\Roles::install();

		// Register the content model before seeding and flushing rewrite rules.
		Content_Types::register();
		Term_Meta::register();
		Seeder::seed_all();
		Awards\Pages::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( Awards\Pages::ROUTES_OPTION, Awards\Pages::ROUTES_VERSION );
		Highlights\Policy_Page::ensure();

		update_option( 'guam_restaurants_core_version', GUAM_RESTAURANTS_CORE_VERSION );
		add_option( 'guam_restaurants_core_activated_at', time() );
	}
}
