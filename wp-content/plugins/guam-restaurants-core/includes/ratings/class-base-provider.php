<?php
/**
 * Shared HTTP handling for rating sources.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Http\Retrying_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Sends JSON requests through a Retrying_Client, so tests swap the
 * transport and never reach a live API.
 */
abstract class Base_Provider implements Provider {

	/**
	 * API key.
	 *
	 * @var string
	 */
	protected string $key;

	/**
	 * HTTP client.
	 *
	 * @var Retrying_Client
	 */
	protected Retrying_Client $http;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected int $timeout;

	/**
	 * Constructor.
	 *
	 * @param string               $key     API key.
	 * @param Retrying_Client|null $http    HTTP client; defaults to one that does not retry, for page-view requests.
	 * @param int                  $timeout Request timeout in seconds.
	 */
	public function __construct( string $key, ?Retrying_Client $http = null, int $timeout = 8 ) {
		$this->key     = $key;
		$this->http    = $http ?? new Retrying_Client( null, null, array( 429 ), 30, 0 );
		$this->timeout = $timeout;
	}

	/**
	 * Sends a request and decodes the JSON body.
	 *
	 * @param string                $method  GET or POST.
	 * @param string                $url     URL.
	 * @param array<string, string> $headers Headers.
	 * @param array<string, mixed>  $body    JSON body for POST.
	 * @return array<string, mixed>
	 * @throws \RuntimeException On failure or invalid JSON.
	 */
	protected function json( string $method, string $url, array $headers, array $body = array() ): array {
		$args = array(
			'method'     => $method,
			'timeout'    => $this->timeout,
			'user-agent' => Config::user_agent(),
			'headers'    => $headers + array( 'Accept' => 'application/json' ),
		);
		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = $this->http->request( $url, $args );
		$data     = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( esc_html( $this->source() . ' response is not valid JSON.' ) );
		}
		return $data;
	}

	/**
	 * Empty normalized rating for this source.
	 *
	 * @param string $label Source name shown to visitors.
	 * @param string $url   Link to the place on the source's site.
	 * @return array<string, mixed>
	 */
	protected function rating_shell( string $label, string $url ): array {
		return array(
			'source'         => $this->source(),
			'label'          => $label,
			'rating'         => null,
			'rating_scale'   => 5,
			'review_count'   => 0,
			'url'            => $url,
			'attribution'    => array(
				'text'      => $label,
				'logo_url'  => '',
				'providers' => array(),
			),
			'stars_image'    => '',
			'details'        => array(
				'phone'   => '',
				'website' => '',
				'hours'   => array(),
			),
			'reviews'        => array(),
			'reviews_notice' => '',
			'fetched_at'     => time(),
		);
	}
}
