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
		Highlights\Roles::maybe_install();

		Village_Aliases::register();
		Mall_Verification::register();
		Ratings\Refresher::register();
		Ratings\Rest_Controller::register();
		Ratings\Display::register();
		Reviews\Rest_Controller::register();
		Reviews\Display::register();
		Highlights\Roles::register();
		Highlights\Moderation_Screen::register();
		Highlights\Submit_Screen::register();
		Highlights\Display::register();
		Highlights\Policy_Page::register();
		Advertising\Leads::register();
		Advertising\Rest_Controller::register();
		Advertising\Admin::register();
		Advertising\Display::register();
		Ads\Ads::register();
		Ads\Guard::register();
		Ads\Tracking::register();
		Ads\Admin::register();
		Ads\Report::register();
		Awards\Display::register();
		Awards\Pages::register();
		Structured_Data::register();
		Site\Assets::register();
		Site\Blocks::register();
		Site\Directory_Query::register();
		Site\Open_Hours::register();
		Site\Rest_Controller::register();
		Site\Pages::register();
		Site\Seo::register();
		Monitoring\Change_Tracker::register();
		Monitoring\Jobs::register();
		Tagging\Tag_Store::register();
		Admin\Ingestion_Runs_Screen::register();
		Admin\Review_Queue_Screen::register();
		Admin\Tag_Origins_Box::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Commands::register();
			Cli\Import_Commands::register();
			Cli\Ratings_Commands::register();
			Cli\Awards_Commands::register();
			Cli\Monitoring_Commands::register();
			Cli\Site_Commands::register();
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
