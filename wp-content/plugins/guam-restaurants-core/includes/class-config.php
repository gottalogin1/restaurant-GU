<?php
/**
 * Settings read from wp-config.php constants or environment variables.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads settings and secrets (CLAUDE.md rule 8): a wp-config.php constant
 * wins, then an environment variable of the same name.
 */
final class Config {

	public const GOOGLE_PLACES_KEY = 'GUAM_RESTAURANTS_GOOGLE_PLACES_KEY';
	public const OVERPASS_URL      = 'GUAM_RESTAURANTS_OVERPASS_URL';
	public const USER_AGENT        = 'GUAM_RESTAURANTS_USER_AGENT';
	public const YELP_KEY          = 'GUAM_RESTAURANTS_YELP_API_KEY';
	public const FOURSQUARE_KEY    = 'GUAM_RESTAURANTS_FOURSQUARE_API_KEY';

	/**
	 * Hard cap in US dollars for one matching or refresh run. The run stops
	 * before the request that would pass it. The default, 0, allows only
	 * calls inside each source's free monthly allowance.
	 */
	public const RUN_CAP_USD = 'GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD';

	/**
	 * Hard cap in US dollars per calendar month (UTC) for ratings fetched
	 * live while visitors view pages (Google, Foursquare). Past it, those
	 * sources are hidden until the next month. The default, 0, allows only
	 * free calls.
	 */
	public const MONTHLY_CAP_USD = 'GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD';

	/**
	 * Base URL of Yelp's official star images and logo, downloaded by hand
	 * from Yelp's brand kit. Yelp ratings are not shown without them.
	 */
	public const YELP_ASSETS_URL = 'GUAM_RESTAURANTS_YELP_ASSETS_URL';

	/**
	 * Optional URLs of the official Google Maps and Foursquare logos. Without
	 * them the text attribution is shown, which both sources allow.
	 */
	public const GOOGLE_MAPS_LOGO_URL = 'GUAM_RESTAURANTS_GOOGLE_MAPS_LOGO_URL';
	public const FOURSQUARE_LOGO_URL  = 'GUAM_RESTAURANTS_FOURSQUARE_LOGO_URL';

	public const DEFAULT_RUN_CAP_USD     = 0.0;
	public const DEFAULT_MONTHLY_CAP_USD = 0.0;

	/**
	 * Returns a setting, or the default when it is unset or empty.
	 *
	 * @param string $name    Constant / environment variable name.
	 * @param string $fallback Default.
	 * @return string
	 */
	public static function get( string $name, string $fallback = '' ): string {
		if ( defined( $name ) && '' !== (string) constant( $name ) ) {
			return (string) constant( $name );
		}
		$env = getenv( $name );
		return false === $env || '' === $env ? $fallback : (string) $env;
	}

	/**
	 * A dollar cap; falls back to the default when unset or not a number.
	 *
	 * @param string $name     Constant / environment variable name.
	 * @param float  $fallback Default.
	 * @return float
	 */
	public static function money( string $name, float $fallback ): float {
		$value = self::get( $name );
		return is_numeric( $value ) && (float) $value >= 0 ? (float) $value : $fallback;
	}

	/**
	 * User agent for outgoing API requests. Overpass asks every client to
	 * identify itself.
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		return self::get( self::USER_AGENT, sprintf( 'guam-restaurants-core/%s (+%s)', GUAM_RESTAURANTS_CORE_VERSION, home_url( '/' ) ) );
	}
}
