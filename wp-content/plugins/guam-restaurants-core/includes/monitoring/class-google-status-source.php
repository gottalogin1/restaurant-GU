<?php
/**
 * Google Places business status.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Place Details (New) with only the `businessStatus` field, billed as
 * Place Details Pro (5,000 free a month; we stop at 4,500). The status is
 * compared in memory and never stored (Maps ToS §3.2.3, docs/data-sources.md §2).
 */
final class Google_Status_Source implements Status_Source {

	public const DETAILS_URL = 'https://places.googleapis.com/v1/places/';
	public const FIELD_MASK  = 'businessStatus';

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
		$this->http = $http ?? new Retrying_Client( null, null, array( 429 ), 30, 3 );
	}

	/**
	 * Source key.
	 *
	 * @return string
	 */
	public function source(): string {
		return 'google';
	}

	/**
	 * Post meta key holding the place ID.
	 *
	 * @return string
	 */
	public function id_meta_key(): string {
		return Restaurant_Meta::GOOGLE_PLACE_ID;
	}

	/**
	 * Billing unit.
	 *
	 * @return string
	 */
	public function sku(): string {
		return Pricing::GOOGLE_DETAILS_PRO;
	}

	/**
	 * Business status.
	 *
	 * @param string $id Place ID.
	 * @return string
	 * @throws \RuntimeException On request failure.
	 */
	public function check( string $id ): string {
		$response = $this->http->request(
			self::DETAILS_URL . rawurlencode( $id ),
			array(
				'method'     => 'GET',
				'timeout'    => 15,
				'user-agent' => Config::user_agent(),
				'headers'    => array(
					'X-Goog-Api-Key'   => $this->key,
					'X-Goog-FieldMask' => self::FIELD_MASK,
					'Accept'           => 'application/json',
				),
			)
		);
		$data     = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Google response is not valid JSON.' );
		}

		switch ( $data['businessStatus'] ?? '' ) {
			case 'CLOSED_PERMANENTLY':
				return self::CLOSED;
			case 'OPERATIONAL':
			case 'CLOSED_TEMPORARILY':
				return self::OPEN;
			default:
				return self::UNKNOWN;
		}
	}
}
