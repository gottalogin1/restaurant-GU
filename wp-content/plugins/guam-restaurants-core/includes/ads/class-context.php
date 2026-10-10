<?php
/**
 * Describes the page a slot is on, for targeting.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * The page type and the village, mall and cuisine term IDs of the current
 * page: the term itself on a term page, or the restaurant's terms on a
 * restaurant page.
 */
final class Context {

	/**
	 * Taxonomy => page type.
	 */
	public const TERM_PAGES = array(
		Content_Types::TAX_VILLAGE => 'village',
		Content_Types::TAX_MALL    => 'mall',
		Content_Types::TAX_CUISINE => 'cuisine',
	);

	/**
	 * Context for the current request.
	 *
	 * @return array{page_type: string, villages: int[], malls: int[], cuisines: int[]}
	 */
	public static function current(): array {
		if ( is_front_page() ) {
			return self::make( 'home' );
		}
		$object = get_queried_object();
		if ( $object instanceof \WP_Term && isset( self::TERM_PAGES[ $object->taxonomy ] ) ) {
			return self::for_term( $object );
		}
		if ( is_singular( Content_Types::POST_TYPE ) ) {
			return self::for_restaurant( (int) get_queried_object_id() );
		}
		if ( is_post_type_archive( Content_Types::POST_TYPE ) ) {
			return self::make( 'directory' );
		}
		return self::make( 'other' );
	}

	/**
	 * Context of a village, mall or cuisine page.
	 *
	 * @param \WP_Term $term Term.
	 * @return array{page_type: string, villages: int[], malls: int[], cuisines: int[]}
	 */
	public static function for_term( \WP_Term $term ): array {
		$context = self::make( self::TERM_PAGES[ $term->taxonomy ] ?? 'other' );
		$key     = array_search( $term->taxonomy, Ads::TARGET_TAXONOMIES, true );
		if ( false !== $key ) {
			$context[ $key ] = array( (int) $term->term_id );
		}
		return $context;
	}

	/**
	 * Context of a restaurant page.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{page_type: string, villages: int[], malls: int[], cuisines: int[]}
	 */
	public static function for_restaurant( int $restaurant_id ): array {
		$context = self::make( 'restaurant' );
		foreach ( Ads::TARGET_TAXONOMIES as $key => $taxonomy ) {
			$ids             = wp_get_object_terms( $restaurant_id, $taxonomy, array( 'fields' => 'ids' ) );
			$context[ $key ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		}
		return $context;
	}

	/**
	 * Empty context of a page type.
	 *
	 * @param string $page_type Page type.
	 * @return array{page_type: string, villages: int[], malls: int[], cuisines: int[]}
	 */
	public static function make( string $page_type ): array {
		return array(
			'page_type' => $page_type,
			'villages'  => array(),
			'malls'     => array(),
			'cuisines'  => array(),
		);
	}
}
