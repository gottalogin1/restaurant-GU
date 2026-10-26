<?php
/**
 * Security headers.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Security;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Sends browser security headers from PHP (docs/security.md §3).
 *
 * This is the fallback: pages served by a page cache or CDN without PHP
 * only carry the headers the server or CDN adds, so the same headers belong
 * in the server config too. Turn this class off with
 * GUAM_RESTAURANTS_SECURITY_HEADERS=0 once they are.
 *
 * The Content-Security-Policy is report-only by default. WordPress core,
 * WooCommerce and PayPal print inline scripts, so script-src keeps
 * 'unsafe-inline'; the policy still blocks plugins, base-tag injection,
 * framing by other sites and form posts to unknown hosts.
 */
final class Headers {

	public const CSP_OFF         = 'off';
	public const CSP_REPORT_ONLY = 'report-only';
	public const CSP_ENFORCE     = 'enforce';

	public const HSTS_VALUE = 'max-age=31536000; includeSubDomains';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'wp_headers', array( self::class, 'public_headers' ) );
		add_action( 'send_headers', array( self::class, 'remove_php_version' ) );
		add_action( 'admin_init', array( self::class, 'send_admin_headers' ) );
		add_action( 'login_init', array( self::class, 'send_admin_headers' ) );
	}

	/**
	 * Whether the plugin sends headers (on unless the setting is 0/off).
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return ! in_array( strtolower( Config::get( Config::SECURITY_HEADERS ) ), array( '0', 'false', 'no', 'off' ), true );
	}

	/**
	 * Headers for every response: public pages, wp-admin and the login page.
	 *
	 * @param bool $https Whether the request came over HTTPS.
	 * @return array<string, string>
	 */
	public static function base( bool $https ): array {
		$headers = array(
			'X-Content-Type-Options'            => 'nosniff',
			'Referrer-Policy'                   => 'strict-origin-when-cross-origin',
			'X-Frame-Options'                   => 'SAMEORIGIN',
			// The map asks for nothing; PayPal's buttons need payment on our own pages.
			'Permissions-Policy'                => 'camera=(), microphone=(), geolocation=(), usb=(), payment=(self "https://www.paypal.com")',
			// Other sites' windows get no handle on ours; PayPal and Google sign-in popups still work.
			'Cross-Origin-Opener-Policy'        => 'same-origin-allow-popups',
			'X-Permitted-Cross-Domain-Policies' => 'none',
		);
		if ( $https && Config::flag( Config::HSTS ) ) {
			$headers['Strict-Transport-Security'] = self::HSTS_VALUE;
		}
		return $headers;
	}

	/**
	 * CSP mode from GUAM_RESTAURANTS_CSP.
	 *
	 * @return string
	 */
	public static function csp_mode(): string {
		$mode = strtolower( Config::get( Config::CSP, self::CSP_REPORT_ONLY ) );
		return in_array( $mode, array( self::CSP_OFF, self::CSP_ENFORCE ), true ) ? $mode : self::CSP_REPORT_ONLY;
	}

	/**
	 * Content-Security-Policy directives for public pages.
	 *
	 * Hosts: OpenFreeMap or the configured map style (fetched by MapLibre,
	 * so connect-src https: covers any provider), Google Maps Embed
	 * (frame), PayPal (checkout scripts and frames), and the analytics
	 * script when it is on another host (Umami Cloud).
	 *
	 * @return array<string, string[]>
	 */
	public static function csp_directives(): array {
		$paypal  = array( 'https://www.paypal.com', 'https://*.paypal.com', 'https://*.paypalobjects.com' );
		$scripts = array_merge( array( "'self'", "'unsafe-inline'" ), $paypal );
		$origin  = self::origin_of( Config::get( Config::ANALYTICS_SCRIPT_URL ) );
		if ( '' !== $origin ) {
			$scripts[] = $origin;
		}
		$directives = array(
			'default-src'     => array( "'self'" ),
			'script-src'      => array_values( array_unique( $scripts ) ),
			'style-src'       => array( "'self'", "'unsafe-inline'" ),
			'img-src'         => array( "'self'", 'data:', 'blob:', 'https:' ),
			'font-src'        => array( "'self'", 'data:' ),
			'connect-src'     => array( "'self'", 'https:' ),
			'frame-src'       => array_merge( array( "'self'", 'https://www.google.com' ), $paypal ),
			'worker-src'      => array( "'self'", 'blob:' ),
			'child-src'       => array( "'self'", 'blob:' ),
			'object-src'      => array( "'none'" ),
			'base-uri'        => array( "'self'" ),
			'form-action'     => array_merge( array( "'self'" ), $paypal ),
			'frame-ancestors' => array( "'self'" ),
		);

		/**
		 * Filters the Content-Security-Policy directives for public pages.
		 *
		 * @param array<string, string[]> $directives Directive => sources.
		 */
		return (array) apply_filters( 'guam_restaurants_csp_directives', $directives );
	}

	/**
	 * The policy as a header value.
	 *
	 * @return string
	 */
	public static function csp(): string {
		$parts = array();
		foreach ( self::csp_directives() as $directive => $sources ) {
			$parts[] = trim( $directive . ' ' . implode( ' ', (array) $sources ) );
		}
		return implode( '; ', $parts );
	}

	/**
	 * Adds headers to public responses (`wp_headers`).
	 *
	 * @param array<string, string> $headers Headers WordPress will send.
	 * @return array<string, string>
	 */
	public static function public_headers( $headers ): array {
		$headers = array_merge( (array) $headers, self::base( is_ssl() ) );
		unset( $headers['X-Pingback'] );
		$mode = self::csp_mode();
		if ( self::CSP_ENFORCE === $mode ) {
			$headers['Content-Security-Policy'] = self::csp();
		} elseif ( self::CSP_REPORT_ONLY === $mode ) {
			$headers['Content-Security-Policy-Report-Only'] = self::csp();
		}
		return $headers;
	}

	/**
	 * Sends the base headers on wp-admin and the login page (no CSP: admin
	 * screens of other plugins load scripts from anywhere).
	 *
	 * @return void
	 */
	public static function send_admin_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		self::remove_php_version();
		foreach ( self::base( is_ssl() ) as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Drops PHP's `X-Powered-By: PHP/8.x` header, which tells scanners which
	 * PHP flaws to try (`expose_php = Off` in php.ini does the same).
	 *
	 * @return void
	 */
	public static function remove_php_version(): void {
		if ( ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}

	/**
	 * Origin (scheme://host[:port]) of a URL, or an empty string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return '';
		}
		return 'https://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
