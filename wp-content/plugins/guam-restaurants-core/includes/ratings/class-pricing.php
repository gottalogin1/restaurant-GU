<?php
/**
 * API billing units (SKUs), free monthly allowances and prices.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

defined( 'ABSPATH' ) || exit;

/**
 * Billing units from docs/data-sources.md §2 to §4 (checked 2026-10-09).
 *
 * Free allowances are set 10% below each source's published free monthly
 * amount, as a margin for calls our counter cannot see (for example the
 * same key used elsewhere). With the default $0 caps, no call past these
 * allowances is ever made, so nothing is billed.
 */
final class Pricing {

	public const GOOGLE_TEXT_SEARCH_PRO    = 'google_text_search_pro';
	public const GOOGLE_DETAILS_PRO        = 'google_details_pro';
	public const GOOGLE_DETAILS_ENTERPRISE = 'google_details_enterprise';
	public const GOOGLE_DETAILS_ATMOSPHERE = 'google_details_atmosphere';
	public const YELP_CALL                 = 'yelp_call';
	public const FOURSQUARE_PRO            = 'foursquare_pro';
	public const FOURSQUARE_PREMIUM        = 'foursquare_premium';

	/**
	 * Price per call in US dollars once the free allowance is used.
	 */
	public const PRICES = array(
		self::GOOGLE_TEXT_SEARCH_PRO    => 0.032,   // $32 per 1,000.
		self::GOOGLE_DETAILS_PRO        => 0.017,   // $17 per 1,000 (business status).
		self::GOOGLE_DETAILS_ENTERPRISE => 0.020,   // $20 per 1,000 (rating, count).
		self::GOOGLE_DETAILS_ATMOSPHERE => 0.025,   // $25 per 1,000 (adds reviews).
		self::YELP_CALL                 => 0.00657, // Enhanced plan overage; plans start at $229/month.
		self::FOURSQUARE_PRO            => 0.015,   // $15 per 1,000.
		self::FOURSQUARE_PREMIUM        => 0.01875, // $18.75 per 1,000 (rating, stats, tips).
	);

	/**
	 * Calls per calendar month we treat as free: 90% of the published free amount.
	 * Yelp has no free tier (trial only); Foursquare Premium has none.
	 */
	public const FREE_PER_MONTH = array(
		self::GOOGLE_TEXT_SEARCH_PRO    => 4500, // Google: 5,000 free.
		self::GOOGLE_DETAILS_PRO        => 4500, // Google: 5,000 free.
		self::GOOGLE_DETAILS_ENTERPRISE => 900,  // Google: 1,000 free.
		self::GOOGLE_DETAILS_ATMOSPHERE => 900,  // Google: 1,000 free.
		self::YELP_CALL                 => 0,
		self::FOURSQUARE_PRO            => 450,  // Foursquare: 500 free.
		self::FOURSQUARE_PREMIUM        => 0,
	);

	/**
	 * Price of one call after the free allowance.
	 *
	 * @param string $sku SKU.
	 * @return float
	 */
	public static function price( string $sku ): float {
		return self::PRICES[ $sku ] ?? 0.0;
	}

	/**
	 * Free calls per month.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public static function free_per_month( string $sku ): int {
		return self::FREE_PER_MONTH[ $sku ] ?? 0;
	}

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
