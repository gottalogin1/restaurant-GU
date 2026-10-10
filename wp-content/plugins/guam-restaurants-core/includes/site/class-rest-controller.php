<?php
/**
 * REST endpoints behind the filter form and the map.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/guam-restaurants/v1/directory: results markup for a set of
 * filters (the same markup the page renders), plus the shareable page URL.
 * GET /wp-json/guam-restaurants/v1/map: matching restaurants as GeoJSON.
 *
 * Both are public, read-only and cacheable for a few minutes (one minute
 * with "open now").
 */
final class Rest_Controller {

	public const NAMESPACE = 'guam-restaurants/v1';

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
		$args = array(
			'q'       => array( 'type' => 'string' ),
			'village' => array( 'type' => 'string' ),
			'mall'    => array( 'type' => 'string' ),
			'cuisine' => array( 'type' => 'string' ),
			'price'   => array( 'type' => 'string' ),
			'award'   => array( 'type' => 'string' ),
			'open'    => array( 'type' => 'string' ),
			'sort'    => array( 'type' => 'string' ),
			'page'    => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'context' => array( 'type' => 'string' ),
		);
		register_rest_route(
			self::NAMESPACE,
			'/directory',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'directory' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
		unset( $args['page'], $args['sort'] );
		register_rest_route(
			self::NAMESPACE,
			'/map',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'map' ),
				'permission_callback' => '__return_true',
				'args'                => $args,
			)
		);
	}

	/**
	 * Directory results.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function directory( \WP_REST_Request $request ): \WP_REST_Response {
		$locked  = Directory_View::parse_context( (string) $request->get_param( 'context' ) );
		$filters = Filters::from_params( $request->get_params(), $locked );
		$query   = Directory_Query::query( $filters );

		$response = new \WP_REST_Response(
			array(
				'html'  => Directory_View::results( $query, $filters, $locked ),
				'total' => (int) $query->found_posts,
				'page'  => $filters['page'],
				'pages' => (int) $query->max_num_pages,
				'url'   => Directory_View::page_url( $filters, $locked, $filters['page'] ),
			)
		);
		return self::cacheable( $response, $filters );
	}

	/**
	 * Map points.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function map( \WP_REST_Request $request ): \WP_REST_Response {
		$locked  = Directory_View::parse_context( (string) $request->get_param( 'context' ) );
		$filters = Filters::from_params( $request->get_params(), $locked );
		$data    = Map_Data::feature_collection( $filters );

		$response = new \WP_REST_Response( $data );
		$response->header( 'X-Gr-Total', (string) count( $data['features'] ) );
		return self::cacheable( $response, $filters );
	}

	/**
	 * Public cache headers.
	 *
	 * @param \WP_REST_Response    $response Response.
	 * @param array<string, mixed> $filters  Filters.
	 * @return \WP_REST_Response
	 */
	private static function cacheable( \WP_REST_Response $response, array $filters ): \WP_REST_Response {
		$response->header( 'Cache-Control', 'public, max-age=' . ( ! empty( $filters['open'] ) ? 60 : Cache::TTL ) );
		return $response;
	}
}
