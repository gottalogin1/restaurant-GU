<?php
/**
 * Front-end assets for the site blocks.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * One small stylesheet for every site block, and scripts loaded only
 * where they are needed: directory.js (filter form), hours.js ("open now"
 * labels, computed in the browser so cached pages stay correct), map.js
 * (loads MapLibre only when a map is about to be seen) and picker.js (the
 * random place picker).
 */
final class Assets {

	public const STYLE = 'guam-restaurants-site';

	public const SCRIPTS = array(
		'directory' => 'guam-restaurants-directory',
		'hours'     => 'guam-restaurants-hours',
		'map'       => 'guam-restaurants-map',
		'share'     => 'guam-restaurants-share',
		'photos'    => 'guam-restaurants-photos',
		'picker'    => 'guam-restaurants-picker',
	);

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ) );
	}

	/**
	 * Registers the assets.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		$base = plugins_url( 'assets/site/', GUAM_RESTAURANTS_CORE_FILE );
		$ver  = GUAM_RESTAURANTS_CORE_VERSION;

		wp_register_style( self::STYLE, $base . 'site.css', array(), $ver );

		wp_register_script( self::SCRIPTS['hours'], $base . 'hours.js', array( 'wp-i18n' ), $ver, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPTS['hours'], 'guam-restaurants-core' );

		wp_register_script( self::SCRIPTS['directory'], $base . 'directory.js', array( 'wp-i18n', self::SCRIPTS['hours'] ), $ver, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPTS['directory'], 'guam-restaurants-core' );
		wp_add_inline_script(
			self::SCRIPTS['directory'],
			'window.guamRestaurantsDirectory = ' . wp_json_encode(
				array(
					'endpoint' => rest_url( Rest_Controller::NAMESPACE . '/directory' ),
					'map'      => rest_url( Rest_Controller::NAMESPACE . '/map' ),
				)
			) . ';',
			'before'
		);

		wp_register_script( self::SCRIPTS['share'], $base . 'share.js', array(), $ver, array( 'strategy' => 'defer' ) );

		wp_register_script( self::SCRIPTS['photos'], $base . 'photos.js', array( 'wp-i18n' ), $ver, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPTS['photos'], 'guam-restaurants-core' );

		wp_register_script( self::SCRIPTS['picker'], $base . 'picker.js', array( 'wp-i18n', self::SCRIPTS['hours'] ), $ver, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPTS['picker'], 'guam-restaurants-core' );
		wp_add_inline_script( self::SCRIPTS['picker'], 'window.guamRestaurantsPicker = ' . wp_json_encode( array( 'endpoint' => rest_url( Rest_Controller::NAMESPACE . '/pick' ) ) ) . ';', 'before' );

		wp_register_script( self::SCRIPTS['map'], $base . 'map.js', array( 'wp-i18n' ), $ver, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPTS['map'], 'guam-restaurants-core' );
		wp_add_inline_script( self::SCRIPTS['map'], 'window.guamRestaurantsMap = ' . wp_json_encode( Maps::script_settings() ) . ';', 'before' );
	}

	/**
	 * Enqueues the stylesheet and, optionally, a script.
	 *
	 * @param string $script directory, hours, map, or '' for the stylesheet only.
	 * @return void
	 */
	public static function enqueue( string $script = '' ): void {
		wp_enqueue_style( self::STYLE );
		if ( isset( self::SCRIPTS[ $script ] ) ) {
			wp_enqueue_script( self::SCRIPTS[ $script ] );
		}
	}
}
