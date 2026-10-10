<?php
/**
 * REST endpoints for the advertiser inquiry form.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Advertising;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Site\Restaurant_View;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /guam-restaurants/v1/advertise/form?restaurant=: a fresh nonce, the package and budget choices, and the pre-filled restaurant.
 * GET  /guam-restaurants/v1/advertise/restaurants?search=: published restaurants for the searchable picker.
 * POST /guam-restaurants/v1/advertise/leads: sends an inquiry.
 *
 * The form loads its nonce from the first endpoint after the page loads,
 * so a full-page cache never serves an expired one. The script never sends
 * an X-WP-Nonce header, so both requests run as a logged-out visitor and
 * the nonce always matches.
 */
final class Rest_Controller {

	public const NAMESPACE    = 'guam-restaurants/v1';
	public const SEARCH_LIMIT = 10;

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
			'/advertise/form',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'form' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'restaurant' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/advertise/restaurants',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'search_restaurants' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'search' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 2,
						'maxLength' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/advertise/leads',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_lead' ),
				// Anyone may send an inquiry; the nonce proves it came from our form.
				'permission_callback' => array( self::class, 'verify_nonce' ),
				'args'                => array(
					'nonce'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'name'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'business'      => array(
						'type'     => 'string',
						'required' => true,
					),
					'restaurant_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'email'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'phone'         => array(
						'type'    => 'string',
						'default' => '',
					),
					'package'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'budget'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'message'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'website'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'elapsed_ms'    => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
	}

	/**
	 * URL of an endpoint.
	 *
	 * @param string $path form, restaurants or leads.
	 * @return string
	 */
	public static function url( string $path ): string {
		return rest_url( self::NAMESPACE . '/advertise/' . $path );
	}

	/**
	 * Rejects a submission without a valid form nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify_nonce( WP_REST_Request $request ) {
		if ( ! wp_verify_nonce( (string) $request['nonce'], Leads::NONCE_ACTION ) ) {
			return new WP_Error( 'gr_lead_nonce', __( 'This form has expired. Reload the page and try again.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Form settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function form( WP_REST_Request $request ): WP_REST_Response {
		$restaurant = (int) $request['restaurant'];
		$response   = new WP_REST_Response(
			array(
				'nonce'      => wp_create_nonce( Leads::NONCE_ACTION ),
				'packages'   => self::choices( Leads::packages() ),
				'budgets'    => self::choices( Leads::budgets() ),
				'restaurant' => Leads::is_published_restaurant( $restaurant ) ? self::restaurant( $restaurant ) : null,
				'policy_url' => Policy_Page::url(),
				'privacy'    => (string) get_privacy_policy_url(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Restaurants whose name matches the search, for the picker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function search_restaurants( WP_REST_Request $request ): WP_REST_Response {
		$ids      = get_posts(
			array(
				'post_type'        => Content_Types::POST_TYPE,
				'post_status'      => 'publish',
				's'                => sanitize_text_field( (string) $request['search'] ),
				'search_columns'   => array( 'post_title' ),
				'posts_per_page'   => self::SEARCH_LIMIT,
				'fields'           => 'ids',
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		$response = new WP_REST_Response( array_map( array( self::class, 'restaurant' ), array_map( 'intval', $ids ) ) );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/**
	 * Sends an inquiry.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_lead( WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'name', 'business', 'restaurant_id', 'email', 'phone', 'package', 'budget', 'message', 'website', 'elapsed_ms' ) as $field ) {
			$input[ $field ] = $request[ $field ];
		}
		$result = Leads::submit( $input, Leads::client_key() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new WP_REST_Response(
			array(
				'status'  => 'received',
				'message' => __( 'Thank you. We have received your inquiry and sent a confirmation to your email address. We will reply by email.', 'guam-restaurants-core' ),
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Picker entry for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{id: int, name: string, place: string}
	 */
	public static function restaurant( int $restaurant_id ): array {
		return array(
			'id'    => $restaurant_id,
			'name'  => html_entity_decode( get_the_title( $restaurant_id ), ENT_QUOTES, 'UTF-8' ),
			'place' => Restaurant_View::term_names( $restaurant_id, Content_Types::TAX_VILLAGE ),
		);
	}

	/**
	 * Choice list in the shape the script reads.
	 *
	 * @param array<string, string> $choices Key => label.
	 * @return array<int, array{id: string, label: string}>
	 */
	private static function choices( array $choices ): array {
		$list = array();
		foreach ( $choices as $id => $label ) {
			$list[] = array(
				'id'    => $id,
				'label' => $label,
			);
		}
		return $list;
	}
}
