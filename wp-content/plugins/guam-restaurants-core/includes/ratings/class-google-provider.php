<?php
/**
 * Google Places API (New) rating source.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Google ratings. Only the place ID may be stored; rating, count and
 * reviews may not be cached at all, so they are fetched for each view
 * (Maps Platform ToS §3.2.3(a), docs/data-sources.md §2).
 */
final class Google_Provider extends Base_Provider {

	public const SEARCH_URL  = 'https://places.googleapis.com/v1/places:searchText';
	public const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

	/**
	 * Text Search Pro fields: enough to compare name and distance in memory.
	 */
	public const SEARCH_FIELD_MASK = 'places.id,places.displayName,places.location';

	/**
	 * Place Details Enterprise fields. The phone, website and opening hours
	 * are in the same billing unit as the rating, so they cost nothing extra.
	 */
	public const DETAILS_FIELD_MASK = 'rating,userRatingCount,googleMapsUri,attributions,nationalPhoneNumber,websiteUri,regularOpeningHours.weekdayDescriptions';

	/**
	 * Added for reviews; raises the request to Enterprise + Atmosphere.
	 */
	public const REVIEWS_FIELD = 'reviews';

	/**
	 * Language Google writes its own fields in (hours, review dates, and
	 * reviews it translates itself), e.g. ja; '' for Google's default.
	 *
	 * @var string
	 */
	private string $language = '';

	/**
	 * A copy that asks Google for its content in a language (the page's
	 * language on machine-translated pages). The text is still Google's
	 * own, shown as returned.
	 *
	 * @param string $language Language code such as ja or ko.
	 * @return self
	 */
	public function with_language( string $language ): self {
		$copy           = clone $this;
		$copy->language = preg_match( '/^[a-z]{2,3}$/', $language ) ? $language : '';
		return $copy;
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
	 * Post meta key holding this source's ID.
	 *
	 * @return string
	 */
	public function id_meta_key(): string {
		return Restaurant_Meta::GOOGLE_PLACE_ID;
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
	 * Billing unit of one search.
	 *
	 * @return string
	 */
	public function search_sku(): string {
		return Pricing::GOOGLE_TEXT_SEARCH_PRO;
	}

	/**
	 * Billing unit of a fetch.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return string
	 */
	public function fetch_sku( bool $with_reviews ): string {
		return $with_reviews ? Pricing::GOOGLE_DETAILS_ATMOSPHERE : Pricing::GOOGLE_DETAILS_ENTERPRISE;
	}

	/**
	 * Requests one fetch makes.
	 *
	 * @param bool $with_reviews Whether reviews are fetched too.
	 * @return int
	 */
	public function fetch_calls( bool $with_reviews ): int {
		return 1;
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
		$data = $this->json(
			'POST',
			self::SEARCH_URL,
			$this->headers( self::SEARCH_FIELD_MASK ),
			array(
				'textQuery'    => $name,
				'pageSize'     => 10,
				'locationBias' => array(
					'circle' => array(
						'center' => array(
							'latitude'  => $lat,
							'longitude' => $lng,
						),
						'radius' => (float) $radius,
					),
				),
			)
		);

		$places = array();
		foreach ( $data['places'] ?? array() as $place ) {
			if ( empty( $place['id'] ) || ! isset( $place['location']['latitude'], $place['location']['longitude'] ) ) {
				continue;
			}
			$places[] = array(
				'id'   => (string) $place['id'],
				'name' => (string) ( $place['displayName']['text'] ?? '' ),
				'lat'  => (float) $place['location']['latitude'],
				'lng'  => (float) $place['location']['longitude'],
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
		$mask = self::DETAILS_FIELD_MASK . ( $with_reviews ? ',' . self::REVIEWS_FIELD : '' );
		$url  = self::DETAILS_URL . rawurlencode( $id ) . ( '' !== $this->language ? '?languageCode=' . rawurlencode( $this->language ) : '' );
		$data = $this->json( 'GET', $url, $this->headers( $mask ) );

		$rating                             = $this->rating_shell( 'Google Maps', (string) ( $data['googleMapsUri'] ?? '' ) );
		$rating['rating']                   = isset( $data['rating'] ) ? (float) $data['rating'] : null;
		$rating['review_count']             = (int) ( $data['userRatingCount'] ?? 0 );
		$rating['attribution']['text']      = 'Google Maps';
		$rating['attribution']['logo_url']  = Config::get( Config::GOOGLE_MAPS_LOGO_URL );
		$rating['attribution']['providers'] = array();
		foreach ( $data['attributions'] ?? array() as $attribution ) {
			$rating['attribution']['providers'][] = array(
				'name' => (string) ( $attribution['provider'] ?? '' ),
				'url'  => (string) ( $attribution['providerUri'] ?? '' ),
			);
		}

		$rating['details'] = array(
			'phone'   => sanitize_text_field( (string) ( $data['nationalPhoneNumber'] ?? '' ) ),
			'website' => esc_url_raw( (string) ( $data['websiteUri'] ?? '' ), array( 'http', 'https' ) ),
			'hours'   => array_values( array_map( 'sanitize_text_field', array_filter( (array) ( $data['regularOpeningHours']['weekdayDescriptions'] ?? array() ), 'is_string' ) ) ),
		);

		if ( $with_reviews ) {
			foreach ( $data['reviews'] ?? array() as $review ) {
				$rating['reviews'][] = array(
					'author'       => (string) ( $review['authorAttribution']['displayName'] ?? '' ),
					'author_url'   => (string) ( $review['authorAttribution']['uri'] ?? '' ),
					'author_photo' => (string) ( $review['authorAttribution']['photoUri'] ?? '' ),
					'rating'       => isset( $review['rating'] ) ? (float) $review['rating'] : null,
					'text'         => (string) ( $review['text']['text'] ?? '' ),
					'time'         => (string) ( $review['relativePublishTimeDescription'] ?? '' ),
					'url'          => (string) ( $review['googleMapsUri'] ?? '' ),
					'flag_url'     => (string) ( $review['flagContentUri'] ?? '' ),
				);
			}
			$rating['reviews_notice'] = __( 'Reviews from Google Maps, shown in the order Google returns them (most relevant first), without changes.', 'guam-restaurants-core' );
		}

		return $rating;
	}

	/**
	 * Request headers.
	 *
	 * @param string $field_mask Field mask.
	 * @return array<string, string>
	 */
	private function headers( string $field_mask ): array {
		return array(
			'X-Goog-Api-Key'   => $this->key,
			'X-Goog-FieldMask' => $field_mask,
		);
	}
}
