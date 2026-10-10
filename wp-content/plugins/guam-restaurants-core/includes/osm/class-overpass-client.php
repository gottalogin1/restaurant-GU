<?php
/**
 * Overpass API client.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Http\Retrying_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches Guam's eating and drinking places and malls from Overpass in one
 * query per run, per the public instance's usage policy
 * (docs/data-sources.md §1): an identifying user agent, no parallel
 * requests, and a 30 second wait after HTTP 429 or 406.
 */
final class Overpass_Client {

	public const DEFAULT_URL = 'https://overpass-api.de/api/interpreter';

	/**
	 * Guam's bounding box (south, west, north, east), from the Census
	 * village boundaries with a small margin (docs/data-sources.md §6).
	 */
	public const GUAM_BBOX = array( 13.18, 144.56, 13.71, 145.01 );

	/**
	 * HTTP client.
	 *
	 * @var Retrying_Client
	 */
	private Retrying_Client $http;

	/**
	 * Constructor.
	 *
	 * @param Retrying_Client|null $http Client; defaults to one that waits 30 s on 429/406.
	 */
	public function __construct( ?Retrying_Client $http = null ) {
		$this->http = $http ?? new Retrying_Client( null, null, array( 429, 406 ), 30 );
	}

	/**
	 * The Overpass QL query: restaurants with centers for ways and
	 * relations, then malls with full geometry for outline matching.
	 *
	 * @return string
	 */
	public static function query(): string {
		$bbox      = implode( ',', self::GUAM_BBOX );
		$amenities = implode( '|', Element_Mapper::AMENITIES );
		return "[out:json][timeout:180];\n"
			. "nwr[\"amenity\"~\"^({$amenities})$\"]({$bbox});\n"
			. "out center;\n"
			. "nwr[\"shop\"=\"mall\"]({$bbox});\n"
			. "out geom;\n";
	}

	/**
	 * Runs the query.
	 *
	 * @return array{elements: array<int, array<string, mixed>>, raw: string}
	 * @throws \RuntimeException When the request fails or the response is not usable.
	 */
	public function fetch(): array {
		$response = $this->http->request(
			Config::get( Config::OVERPASS_URL, self::DEFAULT_URL ),
			array(
				'method'     => 'POST',
				'timeout'    => 200,
				'user-agent' => Config::user_agent(),
				'body'       => array( 'data' => self::query() ),
			)
		);
		return array(
			'elements' => self::decode( $response['body'] ),
			'raw'      => $response['body'],
		);
	}

	/**
	 * Decodes an Overpass JSON response (from the API or a saved file).
	 *
	 * @param string $json Response body.
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException On invalid JSON or an Overpass runtime error.
	 */
	public static function decode( string $json ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['elements'] ) || ! is_array( $data['elements'] ) ) {
			throw new \RuntimeException( 'Overpass response is not valid JSON with an elements list.' );
		}
		// Overpass reports timeouts and memory errors in "remark" with HTTP 200 and partial data.
		if ( isset( $data['remark'] ) && false !== stripos( (string) $data['remark'], 'error' ) ) {
			throw new \RuntimeException( 'Overpass returned an error: ' . esc_html( (string) $data['remark'] ) );
		}
		return $data['elements'];
	}

	/**
	 * Failed attempts during the last fetch.
	 *
	 * @return string[]
	 */
	public function attempt_log(): array {
		return $this->http->attempt_log();
	}
}
