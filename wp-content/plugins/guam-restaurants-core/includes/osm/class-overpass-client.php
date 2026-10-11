<?php
/**
 * Overpass API client.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Http\Retrying_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches Guam's eating and drinking places and malls from Overpass in one
 * query per run, per the public instance's usage policy
 * (docs/data-sources.md §1): an identifying user agent, no parallel
 * requests, and a 30 second wait after HTTP 429 or 406.
 *
 * When every attempt at the main instance fails (it is often overloaded and
 * answers 504), the same query goes once more, with the same retries, to a
 * second public instance. Never both at the same time.
 */
final class Overpass_Client {

	public const DEFAULT_URL = 'https://overpass-api.de/api/interpreter';

	/**
	 * Second public instance (wiki.openstreetmap.org/wiki/Overpass_API, public
	 * instances). Set GUAM_RESTAURANTS_OVERPASS_FALLBACK_URL to another URL, or
	 * to "none" to turn the fallback off.
	 */
	public const DEFAULT_FALLBACK_URL = 'https://overpass.kumi.systems/api/interpreter';

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
	 * Failed attempts during the last fetch, each with its host.
	 *
	 * @var string[]
	 */
	private array $attempt_log = array();

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
	 * Instances to try, in order: the main one, then the fallback.
	 *
	 * @return string[]
	 */
	public static function urls(): array {
		$urls     = array( Config::get( Config::OVERPASS_URL, self::DEFAULT_URL ) );
		$fallback = Config::get( Config::OVERPASS_FALLBACK, self::DEFAULT_FALLBACK_URL );
		if ( 'none' !== strtolower( $fallback ) ) {
			$urls[] = $fallback;
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Runs the query, moving to the fallback instance only after the main
	 * one has failed every retry or returned an unusable response.
	 *
	 * @return array{elements: array<int, array<string, mixed>>, raw: string}
	 * @throws Request_Failed When every instance fails; carries every attempt, by host.
	 */
	public function fetch(): array {
		$this->attempt_log = array();
		$error             = null;

		foreach ( self::urls() as $url ) {
			$elements = null;
			$logged   = count( $this->http->attempt_log() );
			$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
			try {
				$response = $this->http->request(
					$url,
					array(
						'method'     => 'POST',
						'timeout'    => 200,
						'user-agent' => Config::user_agent(),
						'body'       => array( 'data' => self::query() ),
					)
				);
				$elements = self::decode( $response['body'] );
			} catch ( \RuntimeException $e ) {
				$error = $e;
			}
			foreach ( array_slice( $this->http->attempt_log(), $logged ) as $line ) {
				$this->attempt_log[] = $host . ' ' . $line;
			}
			if ( null !== $elements ) {
				return array(
					'elements' => $elements,
					'raw'      => $response['body'],
				);
			}
		}

		throw new Request_Failed( esc_html( $error->getMessage() ), $this->attempt_log ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the attempt log is data for the run log, not output.
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
		return $this->attempt_log;
	}
}
