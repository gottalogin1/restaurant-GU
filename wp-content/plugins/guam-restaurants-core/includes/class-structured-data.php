<?php
/**
 * Structured data (schema.org): Restaurant, ItemList and Organization.
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
 *
 * Listing pages (village, mall, cuisine, price and the restaurants
 * archive, unfiltered, and each dish page) get an ItemList of the restaurants on the page, in
 * the order shown, each pointing at the restaurant page that carries its
 * Restaurant node. The home page gets Organization and WebSite nodes for
 * the site's own brand when no SEO plugin prints them; Config::BRAND_PROFILES
 * adds the brand's Google Business Profile and social links as `sameAs`.
 */
final class Structured_Data {

	/**
	 * Hooks printing.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print' ) );
		add_action( 'wp_head', array( self::class, 'print_item_list' ) );
		add_action( 'wp_head', array( self::class, 'print_organization' ) );
	}

	/**
	 * ItemList for a list of restaurants.
	 *
	 * @param int[]  $ids    Restaurants, in display order.
	 * @param string $name   List name.
	 * @param string $url    Page URL.
	 * @param int    $offset Position of the first item minus one (for page 2 and later).
	 * @return array<string, mixed>|null
	 */
	public static function item_list( array $ids, string $name, string $url, int $offset = 0 ): ?array {
		$items    = array();
		$position = $offset;
		foreach ( $ids as $id ) {
			if ( Content_Types::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => ++$position,
				'url'      => get_permalink( $id ),
				'name'     => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		if ( ! $items ) {
			return null;
		}
		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => $name,
			'url'             => $url,
			'itemListOrder'   => 'https://schema.org/ItemListUnordered',
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);
	}

	/**
	 * Prints the ItemList on unfiltered listing pages and dish pages.
	 *
	 * @return void
	 */
	public static function print_item_list(): void {
		global $wp_query;
		$dish = is_404() ? null : Dishes\Dish_Pages::current_dish();
		if ( null !== $dish ) {
			$data = self::item_list( array_keys( Dishes\Dish_Pages::places( $dish ) ), Dishes\Dish_Pages::title( $dish ), Dishes\Dish_Pages::url( $dish['slug'] ) );
			if ( null !== $data ) {
				wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
			}
			return;
		}
		if ( ! $wp_query instanceof \WP_Query || ! Site\Directory_Query::is_directory( $wp_query ) || Site\Seo::is_filtered_listing() ) {
			return;
		}
		$term = get_queried_object();
		if ( $term instanceof \WP_Term ) {
			$name = Site\Seo::term_title( $term );
			$url  = get_term_link( $term );
		} else {
			$name = __( 'Restaurants on Guam', 'guam-restaurants-core' );
			$url  = get_post_type_archive_link( Content_Types::POST_TYPE );
		}
		$per_page = max( 1, (int) $wp_query->get( 'posts_per_page' ) );
		$paged    = max( 1, (int) $wp_query->get( 'paged' ) );
		$ids      = array_map( 'intval', wp_list_pluck( (array) $wp_query->posts, 'ID' ) );
		$data     = self::item_list( $ids, $name, is_string( $url ) ? $url : '', ( $paged - 1 ) * $per_page );
		if ( null !== $data ) {
			wp_print_inline_script_tag( (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
		}
	}

	/**
	 * Organization and WebSite nodes for the site's brand.
	 *
	 * @return array<string, mixed>
	 */
	public static function organization(): array {
		$home = home_url( '/' );
		$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$org  = array(
			'@type' => 'Organization',
			'@id'   => $home . '#organization',
			'name'  => $name,
			'url'   => $home,
		);
		$logo = get_site_icon_url( 512 );
		if ( '' !== $logo ) {
			$org['logo'] = $logo;
		}
		$profiles = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( ',', Config::get( Config::BRAND_PROFILES ) ) ) ) ) );
		if ( $profiles ) {
			$org['sameAs'] = $profiles;
		}
		return array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				$org,
				array(
					'@type'           => 'WebSite',
					'@id'             => $home . '#website',
					'name'            => $name,
					'url'             => $home,
					'publisher'       => array( '@id' => $home . '#organization' ),
					'potentialAction' => array(
						'@type'       => 'SearchAction',
						'target'      => add_query_arg( Site\Filters::SEARCH, '{search_term_string}', (string) get_post_type_archive_link( Content_Types::POST_TYPE ) ),
						'query-input' => 'required name=search_term_string',
					),
				),
			),
		);
	}

	/**
	 * Prints the brand nodes on the home page when no SEO plugin does.
	 *
	 * @return void
	 */
	public static function print_organization(): void {
		if ( ! is_front_page() || Site\Seo::seo_plugin_active() ) {
			return;
		}
		wp_print_inline_script_tag( (string) wp_json_encode( self::organization(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ), array( 'type' => 'application/ld+json' ) );
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
