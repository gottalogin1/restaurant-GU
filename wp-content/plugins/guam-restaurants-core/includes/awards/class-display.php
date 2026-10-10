<?php
/**
 * Awards section on restaurant pages, and its structured data.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an "Awards" section to each published restaurant page that has
 * results: one plain-text line per result (our citation) with a link to the
 * page that gave it, and the "not affiliated" notice. No logos, badges,
 * images or text from the awards program are shown.
 *
 * The section is appended to the restaurant's content; place it elsewhere
 * with the `[guam_restaurant_awards]` shortcode (which turns off the
 * automatic section on that page) or turn the automatic section off with
 * the `guam_restaurants_awards_auto_section` filter.
 */
final class Display {

	public const SHORTCODE = 'guam_restaurant_awards';
	public const STYLE     = 'guam-restaurants-awards';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'append_section' ), 20 );
	}

	/**
	 * Registers the stylesheet.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		wp_register_style( self::STYLE, plugins_url( 'assets/awards/awards.css', GUAM_RESTAURANTS_CORE_FILE ), array(), GUAM_RESTAURANTS_CORE_VERSION );
	}

	/**
	 * Shortcode: [guam_restaurant_awards id="123"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), self::SHORTCODE );
		$id   = (int) $atts['id'] > 0 ? (int) $atts['id'] : (int) get_the_ID();
		return self::render( $id );
	}

	/**
	 * Appends the section to the main restaurant's content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function append_section( $content ): string {
		$content = (string) $content;
		if ( ! is_singular( Content_Types::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		// The shortcode has already run by now (priority 11), so look for its output too.
		if ( has_shortcode( $content, self::SHORTCODE ) || str_contains( $content, '<section class="gr-awards"' ) ) {
			return $content;
		}
		/**
		 * Filters whether the awards section is appended to restaurant pages.
		 *
		 * @param bool $append        Whether to append.
		 * @param int  $restaurant_id Restaurant.
		 */
		if ( ! apply_filters( 'guam_restaurants_awards_auto_section', true, (int) get_the_ID() ) ) {
			return $content;
		}
		return $content . self::render( (int) get_the_ID() );
	}

	/**
	 * The section's markup, or '' when the restaurant has no results or is not published.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function render( int $restaurant_id ): string {
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return '';
		}
		$awards = Repository::for_restaurant( $restaurant_id );
		if ( empty( $awards ) ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );

		$items = '';
		foreach ( $awards as $award ) {
			$items .= '<li class="gr-awards__item">' . self::line( $award ) . '</li>';
		}

		return sprintf(
			'<section class="gr-awards" aria-labelledby="gr-awards-title-%1$d"><h2 class="gr-awards__title" id="gr-awards-title-%1$d">%2$s</h2><ul class="gr-awards__list">%3$s</ul><p class="gr-awards__more"><a href="%4$s">%5$s</a></p>%6$s</section>',
			$restaurant_id,
			esc_html__( 'Awards', 'guam-restaurants-core' ),
			$items,
			esc_url( Pages::url( 'archive' ) ),
			esc_html__( 'All award results', 'guam-restaurants-core' ),
			self::notice()
		);
	}

	/**
	 * One result: citation plus source link, plain text.
	 *
	 * @param array<string, mixed> $award Award row.
	 * @return string
	 */
	public static function line( array $award ): string {
		return sprintf(
			'%1$s <span class="gr-awards__source">(%2$s <a href="%3$s" rel="nofollow noopener">%4$s</a>)</span>',
			esc_html( Policy::citation( $award ) ),
			esc_html__( 'source:', 'guam-restaurants-core' ),
			esc_url( (string) $award['source_url'] ),
			esc_html( Policy::source_label( $award ) )
		);
	}

	/**
	 * Source link only, for tables.
	 *
	 * @param array<string, mixed> $award Award row.
	 * @return string
	 */
	public static function source_link( array $award ): string {
		return sprintf( '<a href="%1$s" rel="nofollow noopener">%2$s</a>', esc_url( (string) $award['source_url'] ), esc_html( Policy::source_label( $award ) ) );
	}

	/**
	 * The "not affiliated" notice.
	 *
	 * @return string
	 */
	public static function notice(): string {
		return '<p class="gr-awards__notice">' . esc_html( Policy::notice() ) . '</p>';
	}

	/**
	 * Restaurant name, linked when the restaurant is published. Drafts created
	 * for past winners show the name only.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $name          Title.
	 * @param string $status        Post status.
	 * @return string
	 */
	public static function restaurant_link( int $restaurant_id, string $name, string $status ): string {
		if ( 'publish' === $status ) {
			return sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_permalink( $restaurant_id ) ), esc_html( $name ) );
		}
		return esc_html( $name );
	}

	/**
	 * Structured data for the restaurant page.
	 *
	 * The schema.org `award` property (Text) applies to Organization, and
	 * Restaurant is an Organization through LocalBusiness, so each result is
	 * given as our own citation text. Nothing else about the program is
	 * described. Returns null when there is nothing to print. Printed by
	 * Structured_Data, together with any user reviews.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<string, mixed>|null
	 */
	public static function structured_data( int $restaurant_id ): ?array {
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return null;
		}
		$awards = Repository::for_restaurant( $restaurant_id );
		if ( empty( $awards ) ) {
			return null;
		}
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'Restaurant',
			'@id'      => get_permalink( $restaurant_id ) . '#restaurant',
			'name'     => get_the_title( $restaurant_id ),
			'url'      => get_permalink( $restaurant_id ),
			'award'    => array_values( array_unique( array_map( array( Policy::class, 'citation' ), $awards ) ) ),
		);
	}
}
