<?php
/**
 * Daily page-view counts per restaurant.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Analytics;

use GuamRestaurants\Core\Ads\Tracking;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Site\Clock;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Counts views of restaurant pages, so the newsletter can list the most
 * viewed restaurants without asking the analytics tool.
 *
 * The script assets/analytics/views.js sends POST /guam-restaurants/v1/views with
 * `{"restaurant":123}` once per restaurant per browser tab session, so
 * pages served from a full page cache are still counted. Each request adds
 * 1 to the restaurant's counter for today (Guam date). Nothing about the
 * visitor is stored: no IP address, cookie, user ID or User-Agent. Bots are
 * dropped by User-Agent (the ad counter's list) and navigator.webdriver,
 * and one visitor can add at most PER_HOUR views an hour (keyed by a
 * hashed IP kept only in a short-lived transient), so counts are hard to
 * inflate. Rows older than KEEP_DAYS are deleted by prune().
 */
final class Listing_Views {

	public const NAMESPACE = 'guam-restaurants/v1';
	public const ROUTE     = '/views';
	public const SCRIPT    = 'guam-restaurants-views';
	public const PER_HOUR  = 60;
	public const KEEP_DAYS = 400;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'init', array( self::class, 'register_script' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
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
				// No nonce: it changes nothing but an anonymous counter, and cached pages could not carry a fresh one.
				'permission_callback' => '__return_true',
				'args'                => array(
					'restaurant' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
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
		wp_register_script( self::SCRIPT, plugins_url( 'assets/analytics/views.js', GUAM_RESTAURANTS_CORE_FILE ), array(), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_add_inline_script( self::SCRIPT, 'window.guamRestaurantsViews = ' . wp_json_encode( array( 'endpoint' => rest_url( self::NAMESPACE . self::ROUTE ) ) ) . ';', 'before' );
	}

	/**
	 * Loads the script on restaurant pages.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( is_singular( Content_Types::POST_TYPE ) ) {
			wp_add_inline_script( self::SCRIPT, 'window.guamRestaurantsViews.restaurant = ' . (int) get_queried_object_id() . ';', 'before' );
			wp_enqueue_script( self::SCRIPT );
		}
	}

	/**
	 * Counts a view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function record( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );

		$id = (int) $request['restaurant'];
		if ( Tracking::is_bot( (string) $request->get_header( 'user_agent' ) ) || Content_Types::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return $response;
		}

		$key   = 'gr_views_rl_' . substr( \GuamRestaurants\Core\Reviews\Visitor_Reviews::client_key(), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= self::PER_HOUR ) {
			return $response;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		self::add( $id, self::today() );
		return $response;
	}

	/**
	 * Today's date in Guam.
	 *
	 * @return string Y-m-d.
	 */
	public static function today(): string {
		return ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( Clock::zone() )->format( 'Y-m-d' );
	}

	/**
	 * Adds views to a day's counter.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $date          Y-m-d.
	 * @param int    $views         Views to add.
	 * @return void
	 */
	public static function add( int $restaurant_id, string $date, int $views = 1 ): void {
		global $wpdb;
		$table = Schema::table( Schema::LISTING_VIEWS );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (restaurant_id, stat_date, views) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE views = views + VALUES(views)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$restaurant_id,
				$date,
				max( 0, $views )
			)
		);
	}

	/**
	 * Most viewed published restaurants over the last N days (today included).
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return array<int, array{restaurant_id: int, views: int}>
	 */
	public static function top( int $days, int $limit ): array {
		global $wpdb;
		$table = Schema::table( Schema::LISTING_VIEWS );
		$since = ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( Clock::zone() )->modify( '-' . max( 0, $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT v.restaurant_id, SUM(v.views) AS views FROM {$table} v INNER JOIN {$wpdb->posts} p ON p.ID = v.restaurant_id AND p.post_type = %s AND p.post_status = 'publish' WHERE v.stat_date >= %s GROUP BY v.restaurant_id ORDER BY views DESC, v.restaurant_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Content_Types::POST_TYPE,
				$since,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				return array(
					'restaurant_id' => (int) $row['restaurant_id'],
					'views'         => (int) $row['views'],
				);
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Deletes counts older than KEEP_DAYS.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune(): int {
		global $wpdb;
		$table  = Schema::table( Schema::LISTING_VIEWS );
		$before = ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( Clock::zone() )->modify( '-' . self::KEEP_DAYS . ' days' )->format( 'Y-m-d' );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE stat_date < %s", $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
