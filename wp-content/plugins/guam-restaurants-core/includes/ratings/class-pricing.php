<?php
/**
 * Per-request prices used for cost estimates and caps.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

defined( 'ABSPATH' ) || exit;

/**
 * Prices per request in US dollars, from docs/data-sources.md §2 to §4
 * (checked 2026-10-09). Estimates assume the free monthly allowance is
 * already used up, so real bills are the same or lower.
 */
final class Pricing {

	/**
	 * Google Text Search Pro (id, displayName, location): $32 per 1,000.
	 */
	public const GOOGLE_SEARCH = 0.032;

	/**
	 * Google Place Details Enterprise (rating, userRatingCount): $20 per 1,000.
	 */
	public const GOOGLE_DETAILS = 0.020;

	/**
	 * Google Place Details Enterprise + Atmosphere (adds reviews): $25 per 1,000.
	 */
	public const GOOGLE_DETAILS_REVIEWS = 0.025;

	/**
	 * Yelp Places API, Enhanced plan overage: $6.57 per 1,000 calls.
	 */
	public const YELP_CALL = 0.00657;

	/**
	 * Foursquare Places API Pro (search with name and location): $15 per 1,000.
	 */
	public const FOURSQUARE_PRO = 0.015;

	/**
	 * Foursquare Places API Premium (rating, stats, tips): $18.75 per 1,000.
	 */
	public const FOURSQUARE_PREMIUM = 0.01875;

	/**
	 * Formats dollars for command output.
	 *
	 * @param float $usd Amount.
	 * @return string
	 */
	public static function format( float $usd ): string {
		return '$' . number_format( $usd, 2 );
	}
}
