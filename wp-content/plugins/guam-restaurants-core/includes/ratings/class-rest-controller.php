<?php
/**
 * REST endpoint the ratings block and shortcode call from the browser.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Content_Types;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /guam-restaurants/v1/restaurants/{id}/ratings
 *
 * Pages hold only a placeholder and load ratings through this endpoint, so
 * a full-page cache (W3 Total Cache) never stores or serves ratings. The
 * response is marked no-store: Google and Foursquare may not be cached.
 */
final class Rest_Controller {

	public const NAMESPACE = 'guam-restaurants/v1';

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/restaurants/(?P<id>\d+)/ratings',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_ratings' ),
				'permission_callback' => array( self::class, 'can_read' ),
				'args'                => array(
					'id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'sources' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Comma-separated sources: google, yelp, foursquare. Yelp is returned only on its own.', 'guam-restaurants-core' ),
					),
					'reviews' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	/**
	 * URL of the endpoint for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant ID.
	 * @return string
	 */
	public static function url( int $restaurant_id ): string {
		return rest_url( self::NAMESPACE . '/restaurants/' . $restaurant_id . '/ratings' );
	}

	/**
	 * Published restaurants are public; others need edit rights.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function can_read( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Content_Types::POST_TYPE !== get_post_type( $id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		return 'publish' === get_post_status( $id ) || current_user_can( 'edit_post', $id );
	}

	/**
	 * Returns the ratings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_ratings( WP_REST_Request $request ): WP_REST_Response {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page-cache plugins read this name.
		}

		$id      = (int) $request['id'];
		$ratings = Ratings::get(
			$id,
			array(
				'sources' => Ratings::sanitize_sources( (string) $request['sources'] ),
				'reviews' => (bool) $request['reviews'],
			)
		);

		$response = new WP_REST_Response(
			array(
				'restaurant_id' => $id,
				'ratings'       => $ratings,
			)
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}
