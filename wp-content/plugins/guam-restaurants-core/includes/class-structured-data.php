<?php
/**
 * One schema.org Restaurant node per restaurant page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Combines our facts (Site\Business_Data: address, hours, cuisine...), the
 * award citations (Awards\Display) and first-party user reviews
 * (Highlights\Display) into a single JSON-LD Restaurant node, so search
 * engines never see two descriptions of the same restaurant.
 *
 * Turn a part off with `guam_restaurants_business_structured_data`,
 * `guam_restaurants_awards_structured_data` or
 * `guam_restaurants_review_structured_data` (for example, if an SEO plugin
 * prints its own Restaurant node).
 */
final class Structured_Data {

	/**
	 * Hooks printing.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print' ) );
	}

	/**
	 * The node, or null when there is nothing to describe.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<string, mixed>|null
	 */
	public static function node( int $restaurant_id ): ?array {
		$parts = array();

		/**
		 * Filters whether address, hours and other facts are given in structured data.
		 *
		 * @param bool $print         Whether to include them.
		 * @param int  $restaurant_id Restaurant.
		 */
		if ( apply_filters( 'guam_restaurants_business_structured_data', true, $restaurant_id ) ) {
			$parts += Site\Business_Data::properties( $restaurant_id );
		}

		/**
		 * Filters whether award citations are given in structured data.
		 *
		 * @param bool $print Whether to include them.
		 */
		if ( apply_filters( 'guam_restaurants_awards_structured_data', true ) ) {
			$awards = Awards\Display::structured_data( $restaurant_id );
			if ( null !== $awards ) {
				$parts['award'] = $awards['award'];
			}
		}

		/**
		 * Filters whether user reviews are given in structured data.
		 *
		 * @param bool $print         Whether to include them.
		 * @param int  $restaurant_id Restaurant.
		 */
		if ( apply_filters( 'guam_restaurants_review_structured_data', true, $restaurant_id ) ) {
			$reviews = Highlights\Display::structured_data( $restaurant_id );
			if ( null !== $reviews ) {
				$parts += $reviews;
			}
		}

		if ( ! $parts ) {
			return null;
		}
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'Restaurant',
			'@id'      => get_permalink( $restaurant_id ) . '#restaurant',
			'name'     => get_the_title( $restaurant_id ),
			'url'      => get_permalink( $restaurant_id ),
		) + $parts;
	}

	/**
	 * Prints the node on single restaurant pages.
	 *
	 * @return void
	 */
	public static function print(): void {
		if ( ! is_singular( Content_Types::POST_TYPE ) ) {
			return;
		}
		$data = self::node( (int) get_queried_object_id() );
		if ( null !== $data ) {
			wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
		}
	}
}
