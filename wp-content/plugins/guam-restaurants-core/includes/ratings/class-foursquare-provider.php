<?php
/**
 * Foursquare Places API rating source.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Foursquare ratings and tips. Place IDs may be kept; on pay-as-you-go,
 * nothing else may be cached on a server, so ratings are fetched for each
 * view (Foursquare Usage Guidelines, docs/data-sources.md §4a).
 */
final class Foursquare_Provider extends Base_Provider {

	public const API_URL     = 'https://places-api.foursquare.com/places/';
	public const API_VERSION = '2025-06-17';

	/**
	 * Pro fields used for matching.
	 */
	public const SEARCH_FIELDS = 'fsq_place_id,name,latitude,longitude';

	/**
	 * Premium fields for display.
	 */
	public const DETAILS_FIELDS = 'rating,stats';

	public const TIP_LIMIT = 3;

	/**
	 * Source key.
	 *
	 * @return string
	 */
	public function source(): string {
		return 'foursquare';
	}

	/**
	 * Post meta key holding this source's ID.
	 *
	 * @return string
	 */
	public function id_meta_key(): string {
		return Restaurant_Meta::FOURSQUARE_ID;
	}

	/**
	 * Longest server cache the source's terms allow, in seconds.
	 *
	 * @return int
	 */
	public function max_cache_seconds(): int {
		return 0;
	}

	/**
	 * Cost of one search in US dollars.
	 *
	 * @return float
	 */
	public function search_cost(): float {
		return Pricing::FOURSQUARE_PRO;
	}

	/**
	 * Cost of one fetch in US dollars.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return float
	 */
	public function fetch_cost( bool $with_reviews ): float {
		return Pricing::FOURSQUARE_PREMIUM * ( $with_reviews ? 2 : 1 );
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
				'query'  => rawurlencode( $name ),
				'll'     => $lat . ',' . $lng,
				'radius' => $radius,
				'limit'  => 10,
				'fields' => self::SEARCH_FIELDS,
			),
			self::API_URL . 'search'
		);
		$data = $this->json( 'GET', $url, $this->headers() );

		$places = array();
		foreach ( $data['results'] ?? array() as $place ) {
			if ( empty( $place['fsq_place_id'] ) || ! isset( $place['latitude'], $place['longitude'] ) ) {
				continue;
			}
			$places[] = array(
				'id'   => (string) $place['fsq_place_id'],
				'name' => (string) ( $place['name'] ?? '' ),
				'lat'  => (float) $place['latitude'],
				'lng'  => (float) $place['longitude'],
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
		$data = $this->json( 'GET', add_query_arg( 'fields', self::DETAILS_FIELDS, self::API_URL . rawurlencode( $id ) ), $this->headers() );

		// Foursquare rates on a 10-point scale; it is shown as is, not converted to stars.
		$rating                            = $this->rating_shell( 'Foursquare', 'https://foursquare.com/v/' . rawurlencode( $id ) );
		$rating['rating']                  = isset( $data['rating'] ) ? (float) $data['rating'] : null;
		$rating['rating_scale']            = 10;
		$rating['review_count']            = (int) ( $data['stats']['total_ratings'] ?? 0 );
		$rating['attribution']['text']     = 'Powered by Foursquare';
		$rating['attribution']['logo_url'] = Config::get( Config::FOURSQUARE_LOGO_URL );

		if ( $with_reviews ) {
			$tips = $this->json( 'GET', add_query_arg( 'limit', self::TIP_LIMIT, self::API_URL . rawurlencode( $id ) . '/tips' ), $this->headers() );
			foreach ( ( isset( $tips['results'] ) ? $tips['results'] : $tips ) as $tip ) {
				if ( ! is_array( $tip ) || ! isset( $tip['text'] ) ) {
					continue;
				}
				$rating['reviews'][] = array(
					'author'       => (string) ( $tip['user']['name'] ?? '' ),
					'author_url'   => '',
					'author_photo' => '',
					'rating'       => null,
					'text'         => (string) $tip['text'],
					'time'         => (string) ( $tip['created_at'] ?? '' ),
					'url'          => $rating['url'],
					'flag_url'     => '',
				);
			}
			$rating['reviews_notice'] = __( 'Tips from Foursquare users, as Foursquare returns them, without changes.', 'guam-restaurants-core' );
		}

		return $rating;
	}

	/**
	 * Request headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'Authorization'        => 'Bearer ' . $this->key,
			'X-Places-Api-Version' => self::API_VERSION,
		);
	}
}
