<?php
/**
 * Template functions.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Current ratings for a restaurant, one entry per source, never combined.
 *
 * Each entry holds the star rating (Foursquare: 10-point score), review
 * count, source name, required attribution and a link to the place on the
 * source's site. Google and Foursquare are fetched live; Yelp comes from a
 * cache of at most 24 hours. Call it from the REST endpoint or other
 * uncached code, not from markup a page cache stores.
 *
 * @param int                  $restaurant_id Restaurant post ID.
 * @param array<string, mixed> $args          See Ratings\Ratings::get().
 * @return array<int, array<string, mixed>>
 */
function get_ratings( int $restaurant_id, array $args = array() ): array {
	return Ratings\Ratings::get( $restaurant_id, $args );
}
