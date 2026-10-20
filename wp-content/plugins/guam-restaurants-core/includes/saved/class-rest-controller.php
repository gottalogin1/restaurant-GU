<?php
/**
 * REST routes for saved lists.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Saved;

use GuamRestaurants\Core\Site\Cache;
use GuamRestaurants\Core\Site\Rest_Controller as Site_Rest;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/guam-restaurants/v1/saved/cards?ids=1,2: the Saved page's
 * numbered list for these restaurants. Public: it only turns IDs into the
 * cards anyone can see. Used for lists kept in the browser.
 *
 * GET and POST /wp-json/guam-restaurants/v1/saved: the signed-in member's
 * list. POST takes `add` and `remove` (comma-separated or arrays); a
 * browser's list is merged in by POSTing it as `add`. Needs the REST nonce
 * (cookie authentication), so another site cannot change a list.
 */
final class Rest_Controller {

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function routes(): void {
		register_rest_route(
			Site_Rest::NAMESPACE,
			'/saved/cards',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'cards' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Site_Rest::NAMESPACE,
			'/saved',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_list' ),
					'permission_callback' => array( self::class, 'signed_in' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'change_list' ),
					'permission_callback' => array( self::class, 'signed_in' ),
					'args'                => array(
						'add'    => array( 'type' => array( 'string', 'array' ) ),
						'remove' => array( 'type' => array( 'string', 'array' ) ),
					),
				),
			)
		);
	}

	/**
	 * Whether someone is signed in.
	 *
	 * @return bool|\WP_Error
	 */
	public static function signed_in() {
		return is_user_logged_in() ? true : new \WP_Error( 'rest_forbidden', __( 'Sign in to keep a list on your account.', 'guam-restaurants-core' ), array( 'status' => 401 ) );
	}

	/**
	 * Cards for a list of restaurant IDs.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function cards( \WP_REST_Request $request ): \WP_REST_Response {
		$ids      = Saved_List::published( Saved_List::parse_ids( $request->get_param( 'ids' ) ) );
		$response = new \WP_REST_Response(
			array(
				'ids'  => $ids,
				'html' => View::results( $ids, View::MINE ),
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=' . Cache::TTL );
		return $response;
	}

	/**
	 * The member's list.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_list(): \WP_REST_Response {
		return self::list_response( Saved_List::published( Saved_List::get( get_current_user_id() ) ) );
	}

	/**
	 * Adds to and removes from the member's list.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function change_list( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = Saved_List::change(
			get_current_user_id(),
			Saved_List::parse_ids( $request->get_param( 'add' ) ),
			Saved_List::parse_ids( $request->get_param( 'remove' ) )
		);
		return self::list_response( Saved_List::published( $ids ) );
	}

	/**
	 * A member's list, never cached.
	 *
	 * @param int[] $ids Restaurant IDs, oldest first.
	 * @return \WP_REST_Response
	 */
	private static function list_response( array $ids ): \WP_REST_Response {
		$response = new \WP_REST_Response( array( 'ids' => $ids ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}
