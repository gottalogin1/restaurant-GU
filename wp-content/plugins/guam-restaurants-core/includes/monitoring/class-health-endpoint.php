<?php
/**
 * Public health endpoint for uptime monitors.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Ads\Tracking;
use GuamRestaurants\Core\Database\Schema;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/guam-restaurants/v1/health (docs/monitoring.md §1).
 *
 * Answers 200 with `"status":"ok"` when the database answers, the custom
 * tables match the code and no scheduled job is overdue; 200 with
 * `"status":"degraded"` when only jobs are overdue (the daily health check
 * emails the details); 503 when the database or tables are wrong. It says
 * nothing else about the site: no versions, job names or errors.
 *
 * A monitor should check for the keyword `"status":"ok"` so it alerts on
 * both a 503 and a degraded answer.
 */
final class Health_Endpoint {

	public const ROUTE = '/health';

	/**
	 * Hooks registration.
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
			Tracking::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'respond' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The checks.
	 *
	 * @return array{status: string, checks: array{database: bool, schema: bool, jobs: bool}}
	 */
	public static function checks(): array {
		global $wpdb;
		$database = '1' === (string) $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- liveness probe, never cached.
		$schema   = $database && Schema::is_current();
		$jobs     = $database && ! Health_Check::overdue();
		$status   = 'ok';
		if ( ! $database || ! $schema ) {
			$status = 'error';
		} elseif ( ! $jobs ) {
			$status = 'degraded';
		}
		return array(
			'status' => $status,
			'checks' => array(
				'database' => $database,
				'schema'   => $schema,
				'jobs'     => $jobs,
			),
		);
	}

	/**
	 * REST callback.
	 *
	 * @return WP_REST_Response
	 */
	public static function respond(): WP_REST_Response {
		$checks   = self::checks();
		$response = new WP_REST_Response( $checks, 'error' === $checks['status'] ? 503 : 200 );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}
}
