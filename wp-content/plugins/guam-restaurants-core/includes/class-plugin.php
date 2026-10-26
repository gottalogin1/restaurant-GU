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
		Database\Migrations::maybe_run();
		Highlights\Roles::maybe_install();
		add_action( 'admin_notices', array( Database\Schema::class, 'admin_notice' ) );

		Security\Headers::register();
		Security\Hardening::register();
		Security\Two_Factor_Policy::register();

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
		Corrections\Rest_Controller::register();
		Corrections\Display::register();
		Newsletter\Admin::register();
		Analytics\Listing_Views::register();
		Analytics\Tracker::register();
		Ads\Ads::register();
		Ads\Guard::register();
		Ads\Tracking::register();
		Ads\Admin::register();
		Ads\Report::register();
		Billing\Admin::register();
		Claims\Claim_Form::register();
		Claims\Admin::register();
		Owners\Owner_Content::register();
		Owners\Listing_Stats::register();
		Owners\Dashboard::register();
		Premium\Admin::register();
		Commerce\Woo_Checkout::register();
		Awards\Display::register();
		Awards\Pages::register();
		Structured_Data::register();
		Site\Assets::register();
		Site\Blocks::register();
		Appearance\Color_Scheme::register();
		Site\Directory_Query::register();
		Site\Open_Hours::register();
		Site\Rest_Controller::register();
		Site\Pages::register();
		Site\Picker::register();
		Saved\View::register();
		Saved\Members::register();
		Saved\Rest_Controller::register();
		Translation\Integration::register();
		Site\Seo::register();
		Site\Sitemaps::register();
		Site\Cuisine_Villages::register();
		Site\Related_Links::register();
		Monitoring\Change_Tracker::register();
		Monitoring\Jobs::register();
		Monitoring\Job_Controls::register();
		Monitoring\Health_Endpoint::register();
		Legal\Pages::register();
		Tagging\Tag_Store::register();
		Tagging\Branch_Names::register();
		Admin\Ingestion_Runs_Screen::register();
		Admin\Scheduled_Jobs_Screen::register();
		Admin\Review_Queue_Screen::register();
		Admin\Website_Followup_Screen::register();
		Admin\Tag_Origins_Box::register();
		Photos\Website_Photo_Box::register();
		Photos\Agreement::register();
		Photos\Photo_Gallery::register();
		Photos\Photo_Rest_Controller::register();
		Photos\Moderation_Screen::register();
		Dishes\Display::register();
		Dishes\Dish_Index::register();
		Dishes\Dish_Pages::register();
		Admin\No_Cuisine_Filter::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Cli\Commands::register();
			Cli\Import_Commands::register();
			Cli\Ratings_Commands::register();
			Cli\Awards_Commands::register();
			Cli\Monitoring_Commands::register();
			Cli\Site_Commands::register();
			Cli\Photo_Commands::register();
			Cli\Premium_Commands::register();
			Cli\Operations_Commands::register();
			Cli\Translation_Commands::register();
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
