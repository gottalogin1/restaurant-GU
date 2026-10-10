<?php
/**
 * Main plugin class.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the plugin instance, booting it on first call.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	private function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Content_Types::class, 'register' ) );
		add_action( 'init', array( Restaurant_Meta::class, 'register' ) );
		add_action( 'init', array( Term_Meta::class, 'register' ) );
		add_action( 'before_delete_post', array( Database\Schema::class, 'delete_restaurant_rows' ) );

		// Boot runs on plugins_loaded, so tables are upgraded before anything uses them.
		Database\Schema::maybe_upgrade();

		Village_Aliases::register();
		Mall_Verification::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Commands::register();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'guam-restaurants-core', false, dirname( plugin_basename( GUAM_RESTAURANTS_CORE_FILE ) ) . '/languages' );
	}
}
