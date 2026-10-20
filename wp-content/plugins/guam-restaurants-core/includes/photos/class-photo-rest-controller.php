<?php
/**
 * REST routes for photo uploads.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Reviews\Rest_Controller as Reviews_Rest;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /guam-restaurants/v1/restaurants/{id}/photos (multipart, field
 * `photo`) and GET /guam-restaurants/v1/photos/confirm?token=…
 */
final class Photo_Rest_Controller {

	/**
	 * Hooks.
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
			Reviews_Rest::NAMESPACE,
			'/restaurants/(?P<id>\d+)/photos',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create' ),
				// Anyone may upload; every photo waits for an administrator.
				'permission_callback' => array( Reviews_Rest::class, 'is_published_restaurant' ),
				'args'                => array(
					'dish'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'rating'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'author_name' => array(
						'type'    => 'string',
						'default' => '',
					),
					'email'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'connected'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'agree'       => array(
						'type'    => 'boolean',
						'default' => false,
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
			)
		);
		register_rest_route(
			Reviews_Rest::NAMESPACE,
			'/photos/confirm',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'confirm' ),
				// The token is the credential.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Upload URL for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function url( int $restaurant_id ): string {
		return rest_url( Reviews_Rest::NAMESPACE . '/restaurants/' . $restaurant_id . '/photos' );
	}

	/**
	 * Confirmation link for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function confirm_url( string $token ): string {
		return add_query_arg( 'token', rawurlencode( $token ), rest_url( Reviews_Rest::NAMESPACE . '/photos/confirm' ) );
	}

	/**
	 * Receives an upload.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$file  = isset( $files['photo'] ) && is_array( $files['photo'] ) ? $files['photo'] : array( 'error' => UPLOAD_ERR_NO_FILE );
		// Only files PHP received with this request; Photo_Submissions moves them with wp_handle_sideload().
		if ( UPLOAD_ERR_OK === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) && ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) ) {
			$file = array( 'error' => UPLOAD_ERR_NO_FILE );
		}
		$result = Photo_Submissions::submit(
			(int) $request['id'],
			array(
				'dish'        => $request['dish'],
				'rating'      => $request['rating'],
				'author_name' => $request['author_name'],
				'email'       => $request['email'],
				'connected'   => $request['connected'],
				'agree'       => $request['agree'],
				'website'     => $request['website'],
				'elapsed_ms'  => $request['elapsed_ms'],
			),
			$file,
			Photo_Submissions::client_key()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$waiting  = Photo_Submissions::UNCONFIRMED === $result['status'];
		$response = new WP_REST_Response(
			array(
				'status'  => $result['status'],
				'message' => $waiting
					? __( 'Almost done: we sent you an email. Open the link in it within 24 hours, and your photo will go to an administrator for approval.', 'guam-restaurants-core' )
					: __( 'Thanks! Your photo will appear after an administrator approves it.', 'guam-restaurants-core' ),
			),
			$waiting ? 202 : 201
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Opens a confirmation link and sends the visitor back to the restaurant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function confirm( WP_REST_Request $request ): WP_REST_Response {
		$result   = Photo_Submissions::confirm( (string) $request['token'] );
		$ok       = ! is_wp_error( $result );
		$target   = $ok ? (string) get_permalink( $result ) : home_url( '/' );
		$response = new WP_REST_Response( array( 'status' => $ok ? 'confirmed' : 'expired' ), 302 );
		$response->header( 'Location', add_query_arg( 'gr_photo', $ok ? 'confirmed' : 'expired', $target ) . '#gr-photos' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
