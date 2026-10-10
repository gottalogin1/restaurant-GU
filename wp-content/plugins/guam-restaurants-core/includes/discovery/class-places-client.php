<?php
/**
 * Google Places API (New) Text Search client.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Discovery;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Osm\Overpass_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Runs Text Search requests for discovery.
 *
 * Asks only for the fields discovery needs: the place ID (the only Google
 * value we store) and the name and business status, which are used in
 * memory to skip places we already list and are never written anywhere
 * (Maps Platform ToS §3.2.3(a), docs/data-sources.md §2). Results are
 * restricted to Guam's bounding box.
 */
final class Places_Client {

	public const URL = 'https://places.googleapis.com/v1/places:searchText';

	public const FIELD_MASK = 'places.id,places.displayName,places.businessStatus,nextPageToken';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * HTTP client.
	 *
	 * @var Retrying_Client
	 */
	private Retrying_Client $http;

	/**
	 * Constructor.
	 *
	 * @param string               $key  API key.
	 * @param Retrying_Client|null $http HTTP client.
	 */
	public function __construct( string $key, ?Retrying_Client $http = null ) {
		$this->key  = $key;
		$this->http = $http ?? new Retrying_Client();
	}

	/**
	 * Builds a client from the GUAM_RESTAURANTS_GOOGLE_PLACES_KEY constant
	 * or environment variable.
	 *
	 * @return Places_Client|null Null when no key is configured.
	 */
	public static function from_config(): ?Places_Client {
		$key = Config::get( Config::GOOGLE_PLACES_KEY );
		return '' === $key ? null : new self( $key );
	}

	/**
	 * One page of results.
	 *
	 * @param string $text_query Query, for example "restaurants in Yigo, Guam".
	 * @param string $page_token Token from the previous page, or "".
	 * @return array{places: array<int, array{id: string, name: string, business_status: string}>, next_page_token: string}
	 * @throws \RuntimeException On request failure or an unexpected response.
	 */
	public function search( string $text_query, string $page_token = '' ): array {
		list( $south, $west, $north, $east ) = Overpass_Client::GUAM_BBOX;

		$body = array(
			'textQuery'           => $text_query,
			'pageSize'            => 20,
			'locationRestriction' => array(
				'rectangle' => array(
					'low'  => array(
						'latitude'  => $south,
						'longitude' => $west,
					),
					'high' => array(
						'latitude'  => $north,
						'longitude' => $east,
					),
				),
			),
		);
		if ( '' !== $page_token ) {
			$body['pageToken'] = $page_token;
		}

		$response = $this->http->request(
			self::URL,
			array(
				'method'     => 'POST',
				'timeout'    => 30,
				'user-agent' => Config::user_agent(),
				'headers'    => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $this->key,
					'X-Goog-FieldMask' => self::FIELD_MASK,
				),
				'body'       => (string) wp_json_encode( $body ),
			)
		);

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Places response is not valid JSON.' );
		}

		$places = array();
		foreach ( $data['places'] ?? array() as $place ) {
			if ( empty( $place['id'] ) ) {
				continue;
			}
			$places[] = array(
				'id'              => (string) $place['id'],
				'name'            => (string) ( $place['displayName']['text'] ?? '' ),
				'business_status' => (string) ( $place['businessStatus'] ?? '' ),
			);
		}

		return array(
			'places'          => $places,
			'next_page_token' => (string) ( $data['nextPageToken'] ?? '' ),
		);
	}
}
