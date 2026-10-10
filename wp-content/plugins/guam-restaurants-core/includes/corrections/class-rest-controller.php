<?php
/**
 * REST endpoints for the correction form.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Corrections;

use GuamRestaurants\Core\Advertising\Leads;
use GuamRestaurants\Core\Advertising\Rest_Controller as Advertising_Rest;
use GuamRestaurants\Core\Highlights\Policy_Page;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /guam-restaurants/v1/corrections/form?restaurant=: a fresh nonce, the choices, response times and the pre-filled restaurant.
 * POST /guam-restaurants/v1/corrections: sends a correction or listing request.
 *
 * Like the advertiser form, the script loads its nonce after the page
 * loads (so a cached page never carries an expired one) and never sends an
 * X-WP-Nonce header, so both requests run as a logged-out visitor.
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
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/corrections/form',
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

		$string = array(
			'type'    => 'string',
			'default' => '',
		);
		register_rest_route(
			self::NAMESPACE,
			'/corrections',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create' ),
				// Anyone may send a correction; the nonce proves it came from our form.
				'permission_callback' => array( self::class, 'verify_nonce' ),
				'args'                => array(
					'nonce'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'restaurant_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'request'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'topic'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'details'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'relationship'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'evidence'      => $string,
					'name'          => $string,
					'website'       => $string,
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
	 * @param string $path `form`, or '' for the submit endpoint.
	 * @return string
	 */
	public static function url( string $path = '' ): string {
		return rest_url( self::NAMESPACE . '/corrections' . ( '' === $path ? '' : '/' . $path ) );
	}

	/**
	 * Rejects a submission without a valid form nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify_nonce( WP_REST_Request $request ) {
		if ( ! wp_verify_nonce( (string) $request['nonce'], Corrections::NONCE_ACTION ) ) {
			return new WP_Error( 'gr_correction_nonce', __( 'This form has expired. Reload the page and try again.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
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
				'nonce'         => wp_create_nonce( Corrections::NONCE_ACTION ),
				'requests'      => self::choices( Corrections::requests() ),
				'topics'        => self::choices( Corrections::topics() ),
				'reasons'       => self::choices( Corrections::removal_reasons() ),
				'relationships' => self::choices( Corrections::relationships() ),
				'response_days' => array(
					Corrections::CORRECTION     => Corrections::RESPONSE_DAYS_CORRECTION,
					Corrections::LISTING_CHANGE => Corrections::RESPONSE_DAYS_LISTING,
					Corrections::REMOVAL        => Corrections::RESPONSE_DAYS_LISTING,
				),
				'restaurant'    => Leads::is_published_restaurant( $restaurant ) ? Advertising_Rest::restaurant( $restaurant ) : null,
				'policy_url'    => Policy_Page::url(),
				'privacy'       => (string) get_privacy_policy_url(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Sends a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create( WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'restaurant_id', 'request', 'topic', 'details', 'evidence', 'name', 'email', 'relationship', 'website', 'elapsed_ms' ) as $field ) {
			$input[ $field ] = $request[ $field ];
		}
		$result = Corrections::submit( $input, Corrections::client_key() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$days     = Corrections::response_days( sanitize_key( (string) $request['request'] ) );
		$response = new WP_REST_Response(
			array(
				'status'  => 'received',
				'message' => sprintf(
					/* translators: %d: number of days. */
					_n( 'Thank you. We have received your request and sent a confirmation to your email address. We will reply within %d day.', 'Thank you. We have received your request and sent a confirmation to your email address. We will reply within %d days.', $days, 'guam-restaurants-core' ),
					$days
				),
			),
			201
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
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
