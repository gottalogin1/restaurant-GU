<?php
/**
 * Yelp business status.
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
 * Yelp Business Details `is_closed` (true means permanently closed). Yelp
 * has no free tier, so with the default $0 caps no Yelp check is ever made;
 * it runs only when GUAM_RESTAURANTS_YELP_STATUS_CHECKS is on and the run
 * cap allows the cost.
 */
final class Yelp_Status_Source implements Status_Source {

	public const API_URL = 'https://api.yelp.com/v3/businesses/';

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
		return 'yelp';
	}

	/**
	 * Post meta key holding the Yelp ID.
	 *
	 * @return string
	 */
	public function id_meta_key(): string {
		return Restaurant_Meta::YELP_ID;
	}

	/**
	 * Billing unit.
	 *
	 * @return string
	 */
	public function sku(): string {
		return Pricing::YELP_CALL;
	}

	/**
	 * Business status.
	 *
	 * @param string $id Yelp business ID.
	 * @return string
	 * @throws \RuntimeException On request failure.
	 */
	public function check( string $id ): string {
		$response = $this->http->request(
			self::API_URL . rawurlencode( $id ),
			array(
				'method'     => 'GET',
				'timeout'    => 15,
				'user-agent' => Config::user_agent(),
				'headers'    => array(
					'Authorization' => 'Bearer ' . $this->key,
					'Accept'        => 'application/json',
				),
			)
		);
		$data     = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || ! array_key_exists( 'is_closed', $data ) ) {
			return self::UNKNOWN;
		}
		return true === $data['is_closed'] ? self::CLOSED : self::OPEN;
	}
}
