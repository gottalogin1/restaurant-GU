<?php
/**
 * Visitor reviews block and shortcode.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Reviews;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `guam-restaurants/visitor-reviews` block and the
 * `[guam_visitor_reviews]` shortcode. Both print a placeholder that
 * assets/reviews/reviews.js fills from the REST endpoint: the visitor
 * average (with how it is calculated), the monthly trend chart, approved
 * reviews and the review form.
 *
 * Yelp ratings may not appear alongside user content (Yelp API Terms
 * §5(c)), so keep this block out of any page section that shows Yelp.
 */
final class Display {

	public const SHORTCODE    = 'guam_visitor_reviews';
	public const SCRIPT       = 'guam-restaurants-visitor-reviews';
	public const STYLE        = 'guam-restaurants-visitor-reviews';
	public const BLOCK_FOLDER = 'blocks/visitor-reviews';

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
	 * Registers the assets and the block.
	 *
	 * @return void
	 */
	public static function register_assets_and_block(): void {
		$base = plugins_url( 'assets/reviews/', GUAM_RESTAURANTS_CORE_FILE );

		wp_register_script( self::SCRIPT, $base . 'reviews.js', array( 'wp-i18n' ), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPT, 'guam-restaurants-core' );
		wp_register_style( self::STYLE, $base . 'reviews.css', array(), GUAM_RESTAURANTS_CORE_VERSION );

		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER,
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function render_block( $attributes ): string {
		return self::render(
			(int) ( $attributes['restaurantId'] ?? 0 ),
			(bool) ( $attributes['showForm'] ?? true ),
			(bool) ( $attributes['showTrend'] ?? true )
		);
	}

	/**
	 * Shortcode: [guam_visitor_reviews id="123" form="yes" trend="yes"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'form'  => 'yes',
				'trend' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$no   = array( 'no', '0', 'false' );
		return self::render( (int) $atts['id'], ! in_array( strtolower( (string) $atts['form'] ), $no, true ), ! in_array( strtolower( (string) $atts['trend'] ), $no, true ) );
	}

	/**
	 * Placeholder markup; the restaurant defaults to the current post.
	 *
	 * @param int  $restaurant_id Restaurant, or 0 for the current post.
	 * @param bool $form          Show the review form.
	 * @param bool $trend         Show the trend chart.
	 * @return string
	 */
	public static function render( int $restaurant_id, bool $form, bool $trend ): string {
		if ( $restaurant_id <= 0 ) {
			$restaurant_id = (int) get_the_ID();
		}
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return '';
		}

		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );

		return sprintf(
			'<section class="gr-visitor-reviews" data-gr-visitor-reviews="%1$s" data-form="%2$s" data-trend="%3$s" aria-live="polite"><h2 class="gr-visitor-reviews__title">%4$s</h2><p class="gr-visitor-reviews__status">%5$s</p></section>',
			esc_url( Rest_Controller::url( $restaurant_id ) ),
			$form ? '1' : '0',
			$trend ? '1' : '0',
			esc_html__( 'Visitor reviews', 'guam-restaurants-core' ),
			esc_html__( 'Loading reviews…', 'guam-restaurants-core' )
		);
	}
}
