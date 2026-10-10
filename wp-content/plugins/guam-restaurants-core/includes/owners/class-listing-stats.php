<?php
/**
 * Daily listing views and website and phone clicks.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Owners;

use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Ads\Tracking;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Counts, per restaurant and Guam date, how often its page was viewed and
 * its website and phone links were followed, for the owner dashboard.
 *
 * Same approach as ad counting (Ads\Tracking): assets/owners/listing.js on
 * the restaurant page sends POST /guam-restaurants/v1/listing/events with
 * `{"restaurant":12,"events":["view","website"]}` by navigator.sendBeacon,
 * so cached pages are counted. Bots (User-Agent, navigator.webdriver) are
 * dropped. Only totals are stored: no IP address, cookie, user ID or
 * User-Agent.
 */
final class Listing_Stats {

	public const ROUTE  = '/listing/events';
	public const SCRIPT = 'guam-restaurants-listing';

	public const VIEW    = 'view';
	public const WEBSITE = 'website';
	public const PHONE   = 'phone';

	/**
	 * Event => column.
	 */
	public const COLUMNS = array(
		self::VIEW    => 'views',
		self::WEBSITE => 'website_clicks',
		self::PHONE   => 'phone_clicks',
	);

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
			Tracking::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'record' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'restaurant' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'events'     => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => 3,
						'items'    => array(
							'type' => 'string',
							'enum' => array_keys( self::COLUMNS ),
						),
					),
				),
			)
		);
	}

	/**
	 * Registers the script.
	 *
	 * @return void
	 */
	public static function register_script(): void {
		wp_register_script( self::SCRIPT, plugins_url( 'assets/owners/listing.js', GUAM_RESTAURANTS_CORE_FILE ), array(), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_add_inline_script( self::SCRIPT, 'window.guamRestaurantsListing = ' . wp_json_encode( array( 'endpoint' => rest_url( Tracking::NAMESPACE . self::ROUTE ) ) ) . ';', 'before' );
	}

	/**
	 * Counts events. One request counts each event type once.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function record( WP_REST_Request $request ): WP_REST_Response {
		$response   = new WP_REST_Response( null, 204 );
		$restaurant = (int) $request['restaurant'];
		if ( Tracking::is_bot( (string) $request->get_header( 'user_agent' ) ) || Content_Types::POST_TYPE !== get_post_type( $restaurant ) || 'publish' !== get_post_status( $restaurant ) ) {
			return $response;
		}
		$counts = array_fill_keys( array_keys( self::COLUMNS ), 0 );
		foreach ( (array) $request['events'] as $event ) {
			if ( isset( $counts[ $event ] ) ) {
				$counts[ $event ] = 1;
			}
		}
		self::add( $restaurant, Server::today(), $counts );
		return $response;
	}

	/**
	 * Adds to a restaurant's counters for a day.
	 *
	 * @param int                $restaurant_id Restaurant.
	 * @param string             $date          Y-m-d (Guam).
	 * @param array<string, int> $counts     Event => amount.
	 * @return void
	 */
	public static function add( int $restaurant_id, string $date, array $counts ): void {
		global $wpdb;
		$views   = max( 0, (int) ( $counts[ self::VIEW ] ?? 0 ) );
		$website = max( 0, (int) ( $counts[ self::WEBSITE ] ?? 0 ) );
		$phone   = max( 0, (int) ( $counts[ self::PHONE ] ?? 0 ) );
		if ( $restaurant_id <= 0 || 0 === $views + $website + $phone ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (restaurant_id, stat_date, views, website_clicks, phone_clicks) VALUES (%d, %s, %d, %d, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views), website_clicks = website_clicks + VALUES(website_clicks), phone_clicks = phone_clicks + VALUES(phone_clicks)',
				Schema::table( Schema::LISTING_STATS ),
				$restaurant_id,
				$date,
				$views,
				$website,
				$phone
			)
		);
	}

	/**
	 * Totals for a restaurant between two dates (inclusive).
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $from          Y-m-d.
	 * @param string $to            Y-m-d.
	 * @return array{views: int, website_clicks: int, phone_clicks: int}
	 */
	public static function totals( int $restaurant_id, string $from, string $to ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dashboard on demand.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT SUM(views) AS views, SUM(website_clicks) AS website_clicks, SUM(phone_clicks) AS phone_clicks FROM %i WHERE restaurant_id = %d AND stat_date BETWEEN %s AND %s',
				Schema::table( Schema::LISTING_STATS ),
				$restaurant_id,
				$from,
				$to
			),
			ARRAY_A
		);
		return array(
			'views'          => (int) ( $row['views'] ?? 0 ),
			'website_clicks' => (int) ( $row['website_clicks'] ?? 0 ),
			'phone_clicks'   => (int) ( $row['phone_clicks'] ?? 0 ),
		);
	}
}
