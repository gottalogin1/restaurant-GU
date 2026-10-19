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
 * Runs Text Search requests for discovery, and Place Details lookups for
 * the review queue.
 *
 * Asks only for the fields discovery needs: the place ID (the only Google
 * value we store) and the name and business status, which are used in
 * memory to skip places we already list and are never written anywhere
 * (Maps Platform ToS §3.2.3(a), docs/data-sources.md §2). Results are
 * restricted to Guam's bounding box. Details are shown to an administrator
 * on the review queue page and are not stored either.
 */
final class Places_Client {

	public const URL = 'https://places.googleapis.com/v1/places:searchText';

	public const FIELD_MASK = 'places.id,places.displayName,places.businessStatus,nextPageToken';

	public const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

	/**
	 * Place Details Pro fields (the address is a cheaper field, billed at Pro with the name).
	 */
	public const DETAILS_FIELD_MASK = 'displayName,formattedAddress,businessStatus';

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
	 * @param Retrying_Client|null $http HTTP client.
	 * @return Places_Client|null Null when no key is configured.
	 */
	public static function from_config( ?Retrying_Client $http = null ): ?Places_Client {
		$key = Config::get( Config::GOOGLE_PLACES_KEY );
		return '' === $key ? null : new self( $key, $http );
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

	/**
	 * A place's current name, address and business status, for display only.
	 *
	 * @param string $place_id Place ID.
	 * @return array{name: string, address: string, business_status: string}
	 * @throws \RuntimeException On request failure or an unexpected response.
	 */
	public function details( string $place_id ): array {
		$response = $this->http->request(
			self::DETAILS_URL . rawurlencode( $place_id ),
			array(
				'method'     => 'GET',
				'timeout'    => 10,
				'user-agent' => Config::user_agent(),
				'headers'    => array(
					'X-Goog-Api-Key'   => $this->key,
					'X-Goog-FieldMask' => self::DETAILS_FIELD_MASK,
					'Accept'           => 'application/json',
				),
			)
		);

		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Places response is not valid JSON.' );
		}

		return array(
			'name'            => (string) ( $data['displayName']['text'] ?? '' ),
			'address'         => (string) ( $data['formattedAddress'] ?? '' ),
			'business_status' => (string) ( $data['businessStatus'] ?? '' ),
		);
	}

	/**
	 * Failed attempts so far, across every search.
	 *
	 * @return string[]
	 */
	public function attempt_log(): array {
		return $this->http->attempt_log();
	}
}
