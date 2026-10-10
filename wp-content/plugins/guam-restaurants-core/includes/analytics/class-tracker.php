<?php
/**
 * Cookieless analytics tracker.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Analytics;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the tracker for the analytics tool set in Config::ANALYTICS:
 *
 * - `matomo`: the Matomo JavaScript tracker with cookies disabled and the
 *   browser's Do Not Track setting respected. Works with Matomo for
 *   WordPress (the recommended setup, docs/seo-and-analytics.md), Matomo
 *   On-Premise or Matomo Cloud.
 * - `umami`: Umami's script, which sets no cookies, with Do Not Track respected.
 *
 * Nothing is printed when the setting or its URLs are missing, in
 * wp-admin, or for logged-in users who can edit posts, so staff visits do
 * not count.
 */
final class Tracker {

	public const MATOMO = 'matomo';
	public const UMAMI  = 'umami';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_head', array( self::class, 'print' ), 20 );
	}

	/**
	 * The configured tool, or ''.
	 *
	 * @return string
	 */
	public static function provider(): string {
		$provider = strtolower( Config::get( Config::ANALYTICS ) );
		return in_array( $provider, array( self::MATOMO, self::UMAMI ), true ) ? $provider : '';
	}

	/**
	 * Tracker markup, or '' when analytics are off.
	 *
	 * @return string
	 */
	public static function markup(): string {
		$script  = esc_url_raw( Config::get( Config::ANALYTICS_SCRIPT_URL ) );
		$site_id = Config::get( Config::ANALYTICS_SITE_ID );
		if ( '' === $script || '' === $site_id ) {
			return '';
		}

		switch ( self::provider() ) {
			case self::MATOMO:
				$endpoint = esc_url_raw( Config::get( Config::ANALYTICS_ENDPOINT ) );
				if ( '' === $endpoint ) {
					return '';
				}
				$commands = array(
					array( 'disableCookies' ),
					array( 'setDoNotTrack', true ),
					array( 'setTrackerUrl', $endpoint ),
					array( 'setSiteId', $site_id ),
					array( 'trackPageView' ),
					array( 'enableLinkTracking' ),
				);
				$js       = 'var _paq=window._paq=window._paq||[];';
				foreach ( $commands as $command ) {
					$js .= '_paq.push(' . wp_json_encode( $command ) . ');';
				}
				return wp_get_inline_script_tag( $js ) . "\n" . wp_get_script_tag(
					array(
						'src'   => $script,
						'async' => true,
					)
				);
			case self::UMAMI:
				return wp_get_script_tag(
					array(
						'src'               => $script,
						'defer'             => true,
						'data-website-id'   => $site_id,
						'data-do-not-track' => 'true',
						// Only the live site's host counts, so staging copies do not.
						'data-domains'      => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
					)
				);
		}
		return '';
	}

	/**
	 * Prints the tracker on public pages for visitors.
	 *
	 * @return void
	 */
	public static function print(): void {
		if ( is_admin() || is_preview() || current_user_can( 'edit_posts' ) ) {
			return;
		}
		echo self::markup() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with wp_get_script_tag() and wp_json_encode().
	}
}
