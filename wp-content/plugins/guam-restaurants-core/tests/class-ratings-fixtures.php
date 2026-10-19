<?php
/**
 * Mocked rating API responses.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

/**
 * Response bodies shaped like Google Places (New), Yelp Places and
 * Foursquare Places API responses. All values are invented.
 */
final class Ratings_Fixtures {

	public const LAT = 13.5150;
	public const LNG = 144.8070;

	/**
	 * Google Text Search response.
	 *
	 * @param array<int, array{0: string, 1: string, 2: float, 3: float}> $places [id, name, lat, lng].
	 * @return array<string, mixed>
	 */
	public static function google_search( array $places ): array {
		return array(
			'places' => array_map(
				static function ( $place ) {
					return array(
						'id'          => $place[0],
						'displayName' => array(
							'text'         => $place[1],
							'languageCode' => 'en',
						),
						'location'    => array(
							'latitude'  => $place[2],
							'longitude' => $place[3],
						),
					);
				},
				$places
			),
		);
	}

	/**
	 * Google Place Details response.
	 *
	 * @param bool $reviews Include reviews.
	 * @return array<string, mixed>
	 */
	public static function google_details( bool $reviews = true ): array {
		$data = array(
			'rating'              => 4.3,
			'userRatingCount'     => 812,
			'googleMapsUri'       => 'https://maps.google.com/?cid=1111',
			'nationalPhoneNumber' => '(671) 646-1234',
			'websiteUri'          => 'https://example-grill.com/',
			'regularOpeningHours' => array(
				'weekdayDescriptions' => array( 'Monday: 11:00 AM – 9:00 PM', 'Tuesday: Closed' ),
			),
			'attributions'        => array(
				array(
					'provider'    => 'Example Data Co',
					'providerUri' => 'https://data.example.com/',
				),
			),
		);
		if ( $reviews ) {
			$data['reviews'] = array(
				array(
					'name'                           => 'places/ChIJtest/reviews/1',
					'relativePublishTimeDescription' => 'a month ago',
					'rating'                         => 1,
					'text'                           => array(
						'text'         => "Terrible service.\nCold food, would NOT return!!",
						'languageCode' => 'en',
					),
					'authorAttribution'              => array(
						'displayName' => 'Pat Example',
						'uri'         => 'https://www.google.com/maps/contrib/123',
						'photoUri'    => 'https://lh3.googleusercontent.com/a/photo',
					),
					'flagContentUri'                 => 'https://www.google.com/local/review/rap/report?postId=1',
					'googleMapsUri'                  => 'https://www.google.com/maps/reviews/data=1',
				),
				array(
					'relativePublishTimeDescription' => '2 weeks ago',
					'rating'                         => 5,
					'text'                           => array(
						'text'         => 'Best kelaguen on the island 🌴',
						'languageCode' => 'en',
					),
					'authorAttribution'              => array(
						'displayName' => 'Sam Sample',
						'uri'         => 'https://www.google.com/maps/contrib/456',
						'photoUri'    => 'https://lh3.googleusercontent.com/a/photo2',
					),
					'flagContentUri'                 => 'https://www.google.com/local/review/rap/report?postId=2',
					'googleMapsUri'                  => 'https://www.google.com/maps/reviews/data=2',
				),
			);
		}
		return $data;
	}

	/**
	 * Yelp business search response.
	 *
	 * @param array<int, array{0: string, 1: string, 2: float, 3: float}> $places [id, name, lat, lng].
	 * @return array<string, mixed>
	 */
	public static function yelp_search( array $places ): array {
		return array(
			'businesses' => array_map(
				static function ( $place ) {
					return array(
						'id'          => $place[0],
						'name'        => $place[1],
						'coordinates' => array(
							'latitude'  => $place[2],
							'longitude' => $place[3],
						),
					);
				},
				$places
			),
			'total'      => count( $places ),
		);
	}

	/**
	 * Yelp business details response.
	 *
	 * @return array<string, mixed>
	 */
	public static function yelp_business(): array {
		return array(
			'id'           => 'yelp-biz-1',
			'name'         => 'Test Grill',
			'rating'       => 3.5,
			'review_count' => 57,
			'url'          => 'https://www.yelp.com/biz/test-grill-tamuning?adjust_creative=abc&utm_campaign=yelp_api_v3&utm_medium=api_v3_business_lookup&utm_source=abc',
		);
	}

	/**
	 * Yelp reviews response.
	 *
	 * @return array<string, mixed>
	 */
	public static function yelp_reviews(): array {
		return array(
			'reviews' => array(
				array(
					'id'           => 'r1',
					'url'          => 'https://www.yelp.com/biz/test-grill-tamuning?adjust_creative=abc&hrid=r1',
					'text'         => 'Waited an hour. Not worth it...',
					'rating'       => 2,
					'time_created' => '2026-09-01 12:00:00',
					'user'         => array(
						'id'          => 'u1',
						'profile_url' => 'https://www.yelp.com/user_details?userid=u1',
						'image_url'   => 'https://s3-media.fl.yelpcdn.com/photo/u1.jpg',
						'name'        => 'Lee E.',
					),
				),
			),
			'total'   => 57,
		);
	}

	/**
	 * Foursquare place search response.
	 *
	 * @param array<int, array{0: string, 1: string, 2: float, 3: float}> $places [id, name, lat, lng].
	 * @return array<string, mixed>
	 */
	public static function foursquare_search( array $places ): array {
		return array(
			'results' => array_map(
				static function ( $place ) {
					return array(
						'fsq_place_id' => $place[0],
						'name'         => $place[1],
						'latitude'     => $place[2],
						'longitude'    => $place[3],
					);
				},
				$places
			),
		);
	}

	/**
	 * Foursquare place details response.
	 *
	 * @return array<string, mixed>
	 */
	public static function foursquare_details(): array {
		return array(
			'rating' => 8.6,
			'stats'  => array( 'total_ratings' => 140 ),
		);
	}

	/**
	 * Foursquare tips response.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function foursquare_tips(): array {
		return array(
			array(
				'id'         => 't1',
				'created_at' => '2026-08-02T01:02:03.000Z',
				'text'       => 'Try the red rice.',
			),
		);
	}
}
