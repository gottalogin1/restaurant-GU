<?php
/**
 * Yelp Places API rating source.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Yelp ratings. Business IDs may be kept for back-end matching; rating,
 * count and excerpts may be kept for at most 24 hours (Yelp API Terms
 * §5(a), docs/data-sources.md §3).
 */
final class Yelp_Provider extends Base_Provider {

	public const API_URL = 'https://api.yelp.com/v3/businesses/';

	/**
	 * Excerpts requested per business (Enhanced plan returns up to 3).
	 */
	public const REVIEW_LIMIT = 3;

	/**
	 * Base URL of Yelp's official star images and logo.
	 *
	 * @var string
	 */
	private string $assets_url;

	/**
	 * Constructor.
	 *
	 * @param string               $key        API key.
	 * @param Retrying_Client|null $http       HTTP client.
	 * @param int                  $timeout    Request timeout in seconds.
	 * @param string|null          $assets_url Yelp asset base URL; defaults to the configured one.
	 */
	public function __construct( string $key, ?Retrying_Client $http = null, int $timeout = 8, ?string $assets_url = null ) {
		parent::__construct( $key, $http, $timeout );
		$this->assets_url = rtrim( $assets_url ?? Config::get( Config::YELP_ASSETS_URL ), '/' );
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
	 * Post meta key holding this source's ID.
	 *
	 * @return string
	 */
	public function id_meta_key(): string {
		return Restaurant_Meta::YELP_ID;
	}

	/**
	 * Longest server cache the source's terms allow, in seconds.
	 *
	 * @return int
	 */
	public function max_cache_seconds(): int {
		return DAY_IN_SECONDS;
	}

	/**
	 * Billing unit of one search.
	 *
	 * @return string
	 */
	public function search_sku(): string {
		return Pricing::YELP_CALL;
	}

	/**
	 * Billing unit of a fetch.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return string
	 */
	public function fetch_sku( bool $with_reviews ): string {
		return Pricing::YELP_CALL;
	}

	/**
	 * Requests one fetch makes.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return int
	 */
	public function fetch_calls( bool $with_reviews ): int {
		return $with_reviews ? 2 : 1;
	}

	/**
	 * Places near a point, compared in memory only.
	 *
	 * @param string $name   Restaurant name.
	 * @param float  $lat    Latitude.
	 * @param float  $lng    Longitude.
	 * @param int    $radius Radius in metres.
	 * @return array<int, array{id: string, name: string, lat: float, lng: float}>
	 * @throws \RuntimeException On request failure.
	 */
	public function candidates( string $name, float $lat, float $lng, int $radius ): array {
		$url  = add_query_arg(
			array(
				'term'      => rawurlencode( $name ),
				'latitude'  => $lat,
				'longitude' => $lng,
				'radius'    => $radius,
				'limit'     => 10,
				'sort_by'   => 'distance',
			),
			self::API_URL . 'search'
		);
		$data = $this->json( 'GET', $url, $this->headers() );

		$places = array();
		foreach ( $data['businesses'] ?? array() as $business ) {
			if ( empty( $business['id'] ) || ! isset( $business['coordinates']['latitude'], $business['coordinates']['longitude'] ) ) {
				continue;
			}
			$places[] = array(
				'id'   => (string) $business['id'],
				'name' => (string) ( $business['name'] ?? '' ),
				'lat'  => (float) $business['coordinates']['latitude'],
				'lng'  => (float) $business['coordinates']['longitude'],
			);
		}
		return $places;
	}

	/**
	 * Current rating, normalized for display.
	 *
	 * @param string $id           Source ID.
	 * @param bool   $with_reviews Also fetch review text.
	 * @return array<string, mixed>
	 * @throws \RuntimeException On request failure.
	 */
	public function fetch( string $id, bool $with_reviews ): array {
		$data = $this->json( 'GET', self::API_URL . rawurlencode( $id ), $this->headers() );

		// Yelp's URL is used exactly as returned, tracking parameters included (API Terms §5(e), (l)).
		$rating                            = $this->rating_shell( 'Yelp', (string) ( $data['url'] ?? '' ) );
		$rating['rating']                  = isset( $data['rating'] ) ? (float) $data['rating'] : null;
		$rating['review_count']            = (int) ( $data['review_count'] ?? 0 );
		$rating['attribution']['logo_url'] = '' === $this->assets_url ? '' : $this->assets_url . '/yelp_logo.png';
		$rating['stars_image']             = $this->stars_image( $rating['rating'] );

		if ( $with_reviews ) {
			$reviews = $this->json(
				'GET',
				add_query_arg(
					array(
						'limit'   => self::REVIEW_LIMIT,
						'sort_by' => 'yelp_sort',
					),
					self::API_URL . rawurlencode( $id ) . '/reviews'
				),
				$this->headers()
			);
			foreach ( $reviews['reviews'] ?? array() as $review ) {
				$rating['reviews'][] = array(
					'author'       => (string) ( $review['user']['name'] ?? '' ),
					'author_url'   => (string) ( $review['user']['profile_url'] ?? '' ),
					'author_photo' => (string) ( $review['user']['image_url'] ?? '' ),
					'rating'       => isset( $review['rating'] ) ? (float) $review['rating'] : null,
					'text'         => (string) ( $review['text'] ?? '' ),
					'time'         => (string) ( $review['time_created'] ?? '' ),
					'url'          => (string) ( $review['url'] ?? '' ),
					'flag_url'     => '',
					'stars_image'  => isset( $review['rating'] ) ? $this->stars_image( (float) $review['rating'] ) : '',
				);
			}
			$rating['reviews_notice'] = __( 'Review excerpts as Yelp selects and returns them, without changes.', 'guam-restaurants-core' );
		}

		return $rating;
	}

	/**
	 * URL of Yelp's official star image for a rating, in half-star steps.
	 * Yelp ratings are never drawn with our own stars or shown as a bare number.
	 *
	 * @param float|null $rating Rating, 0 to 5.
	 * @return string Empty when the assets are not configured.
	 */
	public function stars_image( ?float $rating ): string {
		if ( '' === $this->assets_url || null === $rating ) {
			return '';
		}
		$halves = (int) round( max( 0.0, min( 5.0, $rating ) ) * 2 );
		$name   = intdiv( $halves, 2 ) . ( $halves % 2 ? '_half' : '' );
		return $this->assets_url . '/regular_' . $name . '.png';
	}

	/**
	 * Request headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->key );
	}
}
