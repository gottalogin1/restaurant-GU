<?php
/**
 * Rating source interface.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

defined( 'ABSPATH' ) || exit;

/**
 * One third-party rating source (design/content-model.md §5.2).
 *
 * Providers return data in memory only. What may be kept, and for how long,
 * is max_cache_seconds(), taken from the source's terms
 * (docs/data-sources.md §7 and §9). Only the source's own ID is stored.
 */
interface Provider {

	/**
	 * Source key: google, yelp or foursquare.
	 *
	 * @return string
	 */
	public function source(): string;

	/**
	 * Post meta key holding this source's ID for a restaurant.
	 *
	 * @return string
	 */
	public function id_meta_key(): string;

	/**
	 * Longest time the rating, count and review text may be kept on our
	 * server, in seconds. 0 means it must be fetched live for each view.
	 *
	 * @return int
	 */
	public function max_cache_seconds(): int;

	/**
	 * Places near a point whose name may match, for matching only. Names and
	 * coordinates are compared in memory and never stored.
	 *
	 * @param string $name   Restaurant name.
	 * @param float  $lat    Latitude.
	 * @param float  $lng    Longitude.
	 * @param int    $radius Search radius in metres.
	 * @return array<int, array{id: string, name: string, lat: float, lng: float}>
	 * @throws \RuntimeException On request failure.
	 */
	public function candidates( string $name, float $lat, float $lng, int $radius ): array;

	/**
	 * Current rating for one place, normalized for display.
	 *
	 * @param string $id           Source ID.
	 * @param bool   $with_reviews Also fetch review text.
	 * @return array<string, mixed> See Ratings::get() for the shape.
	 * @throws \RuntimeException On request failure.
	 */
	public function fetch( string $id, bool $with_reviews ): array;

	/**
	 * Billing unit (Pricing SKU) of one candidates() call.
	 *
	 * @return string
	 */
	public function search_sku(): string;

	/**
	 * Billing unit (Pricing SKU) of the requests one fetch() makes.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return string
	 */
	public function fetch_sku( bool $with_reviews ): string;

	/**
	 * Number of requests one fetch() makes.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return int
	 */
	public function fetch_calls( bool $with_reviews ): int;
}
