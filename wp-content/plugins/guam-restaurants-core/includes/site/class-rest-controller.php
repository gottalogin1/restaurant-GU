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
 * GET /wp-json/guam-restaurants/v1/pick: one matching restaurant at random
 * (Picker).
 *
 * All are public and read-only. Directory and map responses are cacheable
 * for a few minutes (one minute with "open now"); picks are never cached.
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
			'/pick',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'pick' ),
				'permission_callback' => '__return_true',
				'args'                => array_diff_key( $args, array_flip( array( 'q', 'context' ) ) ) + array(
					Picker::NOT => array( 'type' => 'string' ),
					'page_url'  => array( 'type' => 'string' ),
				),
			)
		);
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
	 * A random pick.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function pick( \WP_REST_Request $request ): \WP_REST_Response {
		$filters = Picker::filters( $request->get_params() );
		$pick    = Picker::pick( $filters, Picker::seen( $request->get_param( Picker::NOT ) ) );
		// "Pick again" links point back at the picker page, never at another site.
		$page = wp_validate_redirect( esc_url_raw( (string) $request->get_param( 'page_url' ) ), '' );
		$page = '' === $page ? Pages::url( 'picker' ) : strtok( $page, '?#' );

		$response = new \WP_REST_Response(
			array(
				'html'  => Picker::result( $pick, $filters, '' === $page ? home_url( '/' ) : $page ),
				'id'    => $pick['id'],
				'total' => $pick['total'],
				'left'  => $pick['left'],
				'not'   => implode( ',', $pick['seen'] ),
				'url'   => '' === $page ? '' : add_query_arg( array_map( array( Directory_View::class, 'encode' ), Filters::to_params( $filters ) ), $page ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
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
