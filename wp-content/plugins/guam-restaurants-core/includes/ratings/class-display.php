<?php
/**
 * Ratings block and shortcode.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `guam-restaurants/ratings` block and the
 * `[guam_restaurant_ratings]` shortcode. Both print a placeholder that
 * assets/ratings/ratings.js fills from the REST endpoint, showing each
 * source side by side with its own logo or attribution and link. There is
 * no combined score.
 */
final class Display {

	public const SHORTCODE    = 'guam_restaurant_ratings';
	public const SCRIPT       = 'guam-restaurants-ratings';
	public const STYLE        = 'guam-restaurants-ratings';
	public const BLOCK_FOLDER = 'blocks/ratings';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets_and_block' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/**
	 * Registers the front-end assets and the block.
	 *
	 * @return void
	 */
	public static function register_assets_and_block(): void {
		$base = plugins_url( 'assets/ratings/', GUAM_RESTAURANTS_CORE_FILE );

		wp_register_script( self::SCRIPT, $base . 'ratings.js', array( 'wp-i18n' ), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPT, 'guam-restaurants-core' );
		wp_register_style( self::STYLE, $base . 'ratings.css', array(), GUAM_RESTAURANTS_CORE_VERSION );

		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER,
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_block( $attributes ): string {
		return self::render(
			(int) ( $attributes['restaurantId'] ?? 0 ),
			(string) ( $attributes['sources'] ?? '' ),
			(bool) ( $attributes['showReviews'] ?? true )
		);
	}

	/**
	 * Shortcode: [guam_restaurant_ratings id="123" sources="google,foursquare" reviews="yes"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'sources' => '',
				'reviews' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		return self::render( (int) $atts['id'], (string) $atts['sources'], ! in_array( strtolower( (string) $atts['reviews'] ), array( 'no', '0', 'false' ), true ) );
	}

	/**
	 * Placeholder markup. The restaurant defaults to the current post.
	 *
	 * @param int    $restaurant_id Restaurant ID, or 0 for the current post.
	 * @param string $sources       Comma-separated sources; empty for the default (all but Yelp).
	 * @param bool   $reviews       Show review text.
	 * @return string
	 */
	public static function render( int $restaurant_id, string $sources, bool $reviews ): string {
		if ( $restaurant_id <= 0 ) {
			$restaurant_id = (int) get_the_ID();
		}
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) ) {
			return '';
		}

		$list = Ratings::sanitize_sources( $sources );
		if ( ! $list ) {
			return '';
		}

		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );

		$endpoint = add_query_arg(
			array(
				'sources' => implode( ',', $list ),
				'reviews' => $reviews ? '1' : '0',
			),
			Rest_Controller::url( $restaurant_id )
		);

		return sprintf(
			'<div class="gr-ratings" data-gr-ratings="%1$s" aria-live="polite"><p class="gr-ratings__status">%2$s</p></div>',
			esc_url( $endpoint ),
			esc_html__( 'Loading ratings…', 'guam-restaurants-core' )
		);
	}
}
