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
	 * User agent for outgoing API requests. Overpass asks every client to
	 * identify itself.
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		return self::get( self::USER_AGENT, sprintf( 'guam-restaurants-core/%s (+%s)', GUAM_RESTAURANTS_CORE_VERSION, home_url( '/' ) ) );
	}
}
