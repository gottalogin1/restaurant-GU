<?php
/**
 * REST endpoints for visitor reviews.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Reviews;

use GuamRestaurants\Core\Content_Types;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /guam-restaurants/v1/restaurants/{id}/visitor-reviews: approved reviews, summary, monthly trend, rules.
 * POST /guam-restaurants/v1/restaurants/{id}/visitor-reviews: submits a review for moderation.
 *
 * The block loads everything through these, so a full-page cache never
 * shows a stale review list.
 */
final class Rest_Controller {

	public const NAMESPACE = 'guam-restaurants/v1';
	public const PER_PAGE  = 10;

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/restaurants/(?P<id>\d+)/visitor-reviews',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_reviews' ),
					'permission_callback' => array( self::class, 'is_published_restaurant' ),
					'args'                => array(
						'page' => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'create_review' ),
					// Anyone may submit; every review waits for a moderator.
					'permission_callback' => array( self::class, 'is_published_restaurant' ),
					'args'                => array(
						'rating'      => array(
							'type'     => 'integer',
							'required' => true,
						),
						'body'        => array(
							'type'     => 'string',
							'required' => true,
						),
						'author_name' => array(
							'type'     => 'string',
							'required' => true,
						),
						'connected'   => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'connection'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'website'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'elapsed_ms'  => array(
							'type'    => 'integer',
							'default' => 0,
						),
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
		return rest_url( self::NAMESPACE . '/restaurants/' . $restaurant_id . '/visitor-reviews' );
	}

	/**
	 * Only published restaurants have public reviews.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function is_published_restaurant( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Content_Types::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Approved reviews, summary, trend and rules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_reviews( WP_REST_Request $request ): WP_REST_Response {
		$id      = (int) $request['id'];
		$page    = max( 1, (int) $request['page'] );
		$summary = Visitor_Reviews::summary( $id );

		$rules = array();
		foreach ( Visitor_Reviews::rules() as $rule => $label ) {
			$rules[] = array(
				'id'    => $rule,
				'label' => $label,
			);
		}

		$response = new WP_REST_Response(
			array(
				'restaurant_id' => $id,
				'summary'       => $summary,
				'trend'         => Visitor_Reviews::monthly_trend( $id ),
				'reviews'       => Visitor_Reviews::approved( $id, self::PER_PAGE, $page ),
				'page'          => $page,
				'pages'         => (int) max( 1, ceil( $summary['count'] / self::PER_PAGE ) ),
				'rules'         => $rules,
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=60' );
		return $response;
	}

	/**
	 * Submits a review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_review( WP_REST_Request $request ) {
		$result = Visitor_Reviews::submit(
			(int) $request['id'],
			array(
				'rating'      => $request['rating'],
				'body'        => $request['body'],
				'author_name' => $request['author_name'],
				'connected'   => $request['connected'],
				'connection'  => $request['connection'],
				'website'     => $request['website'],
				'elapsed_ms'  => $request['elapsed_ms'],
			),
			Visitor_Reviews::client_key()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response(
			array(
				'status'  => 'pending',
				'message' => __( 'Thanks! Your review will appear after a moderator checks it against our review rules.', 'guam-restaurants-core' ),
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
