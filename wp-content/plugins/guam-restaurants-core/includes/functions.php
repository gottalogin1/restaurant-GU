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

/**
 * Restaurants first seen in the last `$days` days, newest first. The first
 * import of each source is the starting inventory and is not counted.
 *
 * @param int $days Days back from now.
 * @return array<int, array<string, mixed>> See Monitoring\Queries::recent_openings().
 */
function get_recent_openings( int $days ): array {
	return Monitoring\Queries::recent_openings( $days );
}

/**
 * Restaurants whose status became permanently closed in the last `$days`
 * days (each approved by an administrator), newest first.
 *
 * @param int $days Days back from now.
 * @return array<int, array<string, mixed>> See Monitoring\Queries::recent_closures().
 */
function get_recent_closures( int $days ): array {
	return Monitoring\Queries::recent_closures( $days );
}

/**
 * Every change recorded for a restaurant, newest first.
 *
 * @param int $restaurant_id Restaurant post ID.
 * @return array<int, array<string, mixed>> See Monitoring\Queries::changes_for().
 */
function get_changes_for( int $restaurant_id ): array {
	return Monitoring\Queries::changes_for( $restaurant_id );
}
