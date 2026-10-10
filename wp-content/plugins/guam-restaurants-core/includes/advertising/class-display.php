<?php
/**
 * Advertiser inquiry form block and shortcode.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Advertising;

use GuamRestaurants\Core\Site\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `guam-restaurants/advertiser-inquiry` block and the
 * `[guam_advertiser_inquiry]` shortcode. Both print a placeholder that
 * assets/advertising/inquiry.js turns into the form, after loading a fresh
 * nonce and the restaurant from `?restaurant=` over REST, so the page can be
 * cached.
 */
final class Display {

	public const SHORTCODE    = 'guam_advertiser_inquiry';
	public const SCRIPT       = 'guam-restaurants-advertiser-inquiry';
	public const STYLE        = 'guam-restaurants-advertiser-inquiry';
	public const BLOCK_FOLDER = 'blocks/advertiser-inquiry';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets_and_block' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
	}

	/**
	 * Registers the assets and the block.
	 *
	 * @return void
	 */
	public static function register_assets_and_block(): void {
		$base = plugins_url( 'assets/advertising/', GUAM_RESTAURANTS_CORE_FILE );

		wp_register_script( self::SCRIPT, $base . 'inquiry.js', array( 'wp-i18n' ), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPT, 'guam-restaurants-core' );
		wp_register_style( self::STYLE, $base . 'inquiry.css', array(), GUAM_RESTAURANTS_CORE_VERSION );

		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER,
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Placeholder markup.
	 *
	 * @return string
	 */
	public static function render(): string {
		Assets::enqueue();
		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );

		return sprintf(
			'<div class="gr-lead-form" id="advertise-form" data-gr-lead-form data-form="%1$s" data-search="%2$s" data-submit="%3$s" aria-live="polite"><p class="gr-lead-form__status">%4$s</p><noscript><p>%5$s</p></noscript></div>',
			esc_url( Rest_Controller::url( 'form' ) ),
			esc_url( Rest_Controller::url( 'restaurants' ) ),
			esc_url( Rest_Controller::url( 'leads' ) ),
			esc_html__( 'Loading the form…', 'guam-restaurants-core' ),
			esc_html__( 'The inquiry form needs JavaScript.', 'guam-restaurants-core' )
		);
	}
}
