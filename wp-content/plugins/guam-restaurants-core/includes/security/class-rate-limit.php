<?php
/**
 * Per-visitor request limits for public endpoints.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Security;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per visitor in fixed windows (docs/security.md §5).
 *
 * The visitor is a keyed hash of their IP address, never the address
 * itself. Behind a CDN the address comes from GUAM_RESTAURANTS_CLIENT_IP_HEADER;
 * without it every visitor shares the CDN's addresses and one limit.
 *
 * Counters are transients: the object cache when there is one, otherwise
 * the options table, where expired ones are cleaned up by WordPress.
 */
final class Rate_Limit {

	public const PREFIX = 'gr_rl_';

	/**
	 * Hashed visitor address.
	 *
	 * @return string 64 hex characters.
	 */
	public static function client_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Behind a CDN every visitor shares the CDN's addresses, so one
		// visitor's limit would block everyone (docs/security.md §5).
		$header = strtoupper( Config::get( Config::CLIENT_IP_HEADER ) );
		if ( '' !== $header && preg_match( '/^HTTP_[A-Z0-9_]+$/', $header ) && ! empty( $_SERVER[ $header ] ) ) {
			$forwarded = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
			if ( false !== filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
				$ip = $forwarded;
			}
		}

		/**
		 * Filters the visitor IP used for rate limiting, for hosts behind a
		 * proxy that puts the real address in a header.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'guam_restaurants_review_client_ip', $ip );

		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Counts one request and says whether it is within the limit. A refused
	 * request is not counted, so the window does not grow while someone
	 * keeps trying.
	 *
	 * @param string $bucket Short name of what is limited, such as `ads`.
	 * @param int    $limit  Requests allowed per window.
	 * @param int    $window Window in seconds.
	 * @param string $client Visitor key; the current visitor's when empty.
	 * @return bool True when the request may go ahead.
	 */
	public static function hit( string $bucket, int $limit, int $window, string $client = '' ): bool {
		$key   = self::key( $bucket, '' !== $client ? $client : self::client_key() );
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || (int) $state['reset'] <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + max( 1, $window ),
			);
		}
		if ( (int) $state['count'] >= $limit ) {
			return false;
		}
		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, max( 1, (int) $state['reset'] - $now ) );
		return true;
	}

	/**
	 * Transient name for a bucket and visitor.
	 *
	 * @param string $bucket Bucket.
	 * @param string $client Visitor key.
	 * @return string
	 */
	public static function key( string $bucket, string $client ): string {
		return self::PREFIX . sanitize_key( $bucket ) . '_' . substr( $client, 0, 32 );
	}
}
