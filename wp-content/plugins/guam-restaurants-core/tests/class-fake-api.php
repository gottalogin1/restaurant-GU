<?php
/**
 * Fake HTTP transport for API tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Http\Retrying_Client;

/**
 * Answers requests from canned responses keyed by URL pattern and records
 * every request, so tests can check field masks and headers. Nothing
 * leaves the process.
 */
final class Fake_Api {

	/**
	 * Requests received: [url, args].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $requests = array();

	/**
	 * Routes: URL regex => response body (array, encoded as JSON) or HTTP status code (int).
	 *
	 * @var array<string, array<string, mixed>|int>
	 */
	private array $routes;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>|int> $routes Routes, first match wins.
	 */
	public function __construct( array $routes ) {
		$this->routes = $routes;
	}

	/**
	 * Client that sends through this fake and never waits.
	 *
	 * @return Retrying_Client
	 */
	public function client(): Retrying_Client {
		return new Retrying_Client( array( $this, 'handle' ), static function () {}, array( 429 ), 0, 0 );
	}

	/**
	 * Transport callback, same signature as wp_remote_request().
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function handle( string $url, array $args ): array {
		$this->requests[] = array( $url, $args );
		foreach ( $this->routes as $pattern => $response ) {
			if ( preg_match( $pattern, $url ) ) {
				$code = is_int( $response ) ? $response : 200;
				$body = is_int( $response ) ? array( 'error' => 'fake' ) : $response;
				return array(
					'response' => array( 'code' => $code ),
					'headers'  => array(),
					'body'     => (string) wp_json_encode( $body ),
				);
			}
		}
		return array(
			'response' => array( 'code' => 404 ),
			'headers'  => array(),
			'body'     => '{}',
		);
	}

	/**
	 * Requests whose URL matches a pattern.
	 *
	 * @param string $pattern Regex.
	 * @return array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public function requests_to( string $pattern ): array {
		return array_values(
			array_filter(
				$this->requests,
				static function ( $request ) use ( $pattern ) {
					return 1 === preg_match( $pattern, $request[0] );
				}
			)
		);
	}
}
