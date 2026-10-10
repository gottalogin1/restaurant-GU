<?php
/**
 * Impression and click counting.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Counts POST /guam-restaurants/v1/ads/events requests with
 * the JSON body `{"page":"village","events":[{"ad":12,"type":"impression"}]}`.
 *
 * The script assets/ads/beacon.js sends it with navigator.sendBeacon: an impression
 * once an ad is at least half on screen, and a click when its link is
 * followed. Counting happens in the browser, so pages served from a full
 * page cache are still counted, and an ad that is never seen is not.
 *
 * Requests from bots (by User-Agent, and by navigator.webdriver in the
 * script) are dropped. Each event adds 1 to the ad's daily counter; no
 * IP address, cookie, user ID or User-Agent is stored. Only ads that are
 * servable today are counted, and one request counts each ad and event
 * type once.
 *
 * The endpoint needs no nonce: it changes nothing but anonymous counters,
 * and cached pages could not carry a fresh nonce.
 */
final class Tracking {

	public const NAMESPACE  = 'guam-restaurants/v1';
	public const ROUTE      = '/ads/events';
	public const SCRIPT     = 'guam-restaurants-ads';
	public const MAX_EVENTS = 12;

	public const IMPRESSION = 'impression';
	public const CLICK      = 'click';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'register_script' ) );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'record' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'   => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( Ads::page_types() ),
					),
					'events' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => self::MAX_EVENTS,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'ad'   => array(
									'type'    => 'integer',
									'minimum' => 1,
								),
								'type' => array(
									'type' => 'string',
									'enum' => array( self::IMPRESSION, self::CLICK ),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Registers the beacon script.
	 *
	 * @return void
	 */
	public static function register_script(): void {
		wp_register_script(
			self::SCRIPT,
			plugins_url( 'assets/ads/beacon.js', GUAM_RESTAURANTS_CORE_FILE ),
			array(),
			GUAM_RESTAURANTS_CORE_VERSION,
			array( 'strategy' => 'defer' )
		);
		wp_add_inline_script( self::SCRIPT, 'window.guamRestaurantsAds = ' . wp_json_encode( array( 'endpoint' => rest_url( self::NAMESPACE . self::ROUTE ) ) ) . ';', 'before' );
	}

	/**
	 * Counts events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function record( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( null, 204 );
		if ( self::is_bot( (string) $request->get_header( 'user_agent' ) ) ) {
			return $response;
		}

		$page   = (string) $request['page'];
		$today  = Server::today();
		$counts = array();
		foreach ( (array) $request['events'] as $event ) {
			$ad_id = (int) ( $event['ad'] ?? 0 );
			$type  = (string) ( $event['type'] ?? '' );
			if ( $ad_id <= 0 || ! in_array( $type, array( self::IMPRESSION, self::CLICK ), true ) ) {
				continue;
			}
			$counts[ $ad_id ][ $type ] = 1;
		}

		foreach ( $counts as $ad_id => $types ) {
			$ad = Ads::get( $ad_id );
			if ( null === $ad || ! Server::is_servable( $ad, $today ) ) {
				continue;
			}
			Stats::add( $ad_id, $today, $page, (int) ( $types[ self::IMPRESSION ] ?? 0 ), (int) ( $types[ self::CLICK ] ?? 0 ) );
		}
		return $response;
	}

	/**
	 * Whether a User-Agent looks automated. An empty one counts as a bot.
	 *
	 * @param string $user_agent User-Agent header.
	 * @return bool
	 */
	public static function is_bot( string $user_agent ): bool {
		$user_agent = trim( $user_agent );
		if ( '' === $user_agent ) {
			return true;
		}
		$pattern = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|preview|headless|phantomjs|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl\/|wget\/|python-requests|python-urllib|go-http-client|java\/|okhttp|axios\/|node-fetch|httpclient|scrapy|WordPress\//i';
		/**
		 * Filters whether a request is from a bot and is not counted.
		 *
		 * @param bool   $is_bot     Whether the User-Agent matched the bot list.
		 * @param string $user_agent User-Agent header.
		 */
		return (bool) apply_filters( 'guam_restaurants_ad_is_bot', 1 === preg_match( $pattern, $user_agent ), $user_agent );
	}
}
