<?php
/**
 * Maps Foursquare Open Source Places rows to restaurant records.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Fsq;

use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one row of the Guam extract (bin/fsq-extract-guam.py, schema at
 * docs.foursquare.com/data-products/docs/places-os-data-schema) into the
 * fields the importer stores.
 *
 * Categories are read like OSM tags so the existing cuisine map is reused:
 * "Dining and Drinking > Restaurant > Asian Restaurant > Japanese Restaurant"
 * becomes cuisine=japanese, "Café" becomes amenity=cafe, and so on.
 *
 * A website on Facebook or Instagram is stored as that network's link, not
 * as the website, so the follow-up list sees the business has no site.
 */
final class Place_Mapper {

	/**
	 * Top-level category every imported place must be under.
	 */
	public const ROOT_CATEGORY = 'Dining and Drinking';

	/**
	 * Guam's bounding box, with a small margin; the same box is used by
	 * bin/fsq-extract-guam.py.
	 */
	public const BOUNDS = array(
		'south' => 13.2,
		'north' => 13.7,
		'west'  => 144.6,
		'east'  => 145.0,
	);

	/**
	 * Category names (last segment, lowercased) that are not eating places.
	 */
	private const SKIP_CATEGORIES = array( 'night club', 'nightclub', 'strip club', 'hookah bar', 'karaoke bar', 'adult entertainment' );

	/**
	 * Foursquare flags that mean the place should not be listed.
	 */
	private const SKIP_FLAGS = array( 'closed', 'delete', 'doesnt_exist', 'duplicate', 'privatevenue', 'inappropriate' );

	/**
	 * Last category segments read as OSM amenity values.
	 */
	private const AMENITY = array(
		'café'                 => 'cafe',
		'cafe'                 => 'cafe',
		'coffee shop'          => 'cafe',
		'tea room'             => 'cafe',
		'fast food restaurant' => 'fast_food',
		'food court'           => 'food_court',
		'bar'                  => 'bar',
		'pub'                  => 'pub',
		'sports bar'           => 'bar',
		'beer bar'             => 'bar',
		'cocktail bar'         => 'bar',
		'wine bar'             => 'bar',
		'dive bar'             => 'bar',
		'lounge'               => 'bar',
		'brewery'              => 'bar',
		'ice cream parlor'     => 'ice_cream',
	);

	/**
	 * Words dropped from a category to get the cuisine value.
	 */
	private const SUFFIXES = '/\s+(restaurant|place|joint|shop|house|stand|parlor|bar)$/';

	/**
	 * Cuisine map.
	 *
	 * @var Cuisine_Map
	 */
	private Cuisine_Map $cuisines;

	/**
	 * Constructor.
	 *
	 * @param Cuisine_Map $cuisines Cuisine map (data/osm-cuisine-map.json).
	 */
	public function __construct( Cuisine_Map $cuisines ) {
		$this->cuisines = $cuisines;
	}

	/**
	 * Why a row is not imported, or null when it should be.
	 *
	 * @param array<string, mixed> $row       Row.
	 * @param string               $stale_before Rows last refreshed before this date (Y-m-d) are skipped; '' keeps all.
	 * @return string|null
	 */
	public static function skip_reason( array $row, string $stale_before = '' ): ?string {
		if ( '' === self::id( $row ) ) {
			return 'no fsq_place_id';
		}
		if ( '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
			return 'no name';
		}
		if ( ! is_numeric( $row['latitude'] ?? null ) || ! is_numeric( $row['longitude'] ?? null ) ) {
			return 'no coordinates';
		}
		if ( ! self::in_guam( (float) $row['latitude'], (float) $row['longitude'] ) ) {
			return 'not in Guam';
		}
		if ( '' !== (string) ( $row['date_closed'] ?? '' ) ) {
			return 'closed';
		}
		if ( array_intersect( self::flags( $row ), self::SKIP_FLAGS ) ) {
			return 'flagged by Foursquare';
		}
		$labels = self::labels( $row );
		$eating = array_filter(
			$labels,
			static function ( string $label ): bool {
				return 0 === strpos( $label, self::ROOT_CATEGORY ) && ! in_array( self::leaf( $label ), self::SKIP_CATEGORIES, true );
			}
		);
		if ( ! $eating ) {
			return 'not a place to eat or drink';
		}
		$refreshed = substr( (string) ( $row['date_refreshed'] ?? '' ), 0, 10 );
		if ( '' !== $stale_before && '' !== $refreshed && $refreshed < $stale_before ) {
			return 'not refreshed since ' . $stale_before;
		}
		return null;
	}

	/**
	 * Maps a row.
	 *
	 * @param array<string, mixed> $row Row that passed skip_reason().
	 * @return array{fsq_id: string, title: string, latitude: float, longitude: float, meta: array<string, string>, cuisine_details: array<string, string>, unmapped: string[]}
	 */
	public function map( array $row ): array {
		$website = self::url( (string) ( $row['website'] ?? '' ) );
		$social  = array(
			'facebook'  => self::facebook( $row['facebook_id'] ?? '' ),
			'instagram' => self::instagram( (string) ( $row['instagram'] ?? '' ) ),
		);
		$network = Restaurant_Meta::social_network( $website );
		if ( null !== $network ) {
			$social[ $network ] = '' !== $social[ $network ] ? $social[ $network ] : Restaurant_Meta::sanitize_social_url( (string) preg_replace( '#^http://#i', 'https://', $website ) );
			$website            = '';
		}

		$tags = $this->tags( self::labels( $row ) );
		$map  = $this->cuisines->map( $tags );

		$details = array();
		foreach ( $map['details'] as $slug => $detail ) {
			$details[ $slug ] = 'fsq_os:' . substr( $detail, strlen( 'osm:' ) );
		}

		return array(
			'fsq_id'          => self::id( $row ),
			'title'           => sanitize_text_field( (string) $row['name'] ),
			'latitude'        => round( (float) $row['latitude'], 7 ),
			'longitude'       => round( (float) $row['longitude'], 7 ),
			'meta'            => array(
				Restaurant_Meta::ADDRESS   => Restaurant_Meta::sanitize_address( (string) ( $row['address'] ?? '' ) ),
				Restaurant_Meta::PHONE     => Restaurant_Meta::sanitize_phone( (string) ( $row['tel'] ?? '' ) ),
				Restaurant_Meta::WEBSITE   => $website,
				Restaurant_Meta::EMAIL     => sanitize_email( (string) ( $row['email'] ?? '' ) ),
				Restaurant_Meta::FACEBOOK  => $social['facebook'],
				Restaurant_Meta::INSTAGRAM => $social['instagram'],
			),
			'cuisine_details' => $details,
			'unmapped'        => $map['unmapped'],
		);
	}

	/**
	 * OSM-style tags from category labels.
	 *
	 * @param string[] $labels Category labels.
	 * @return array<string, string>
	 */
	private function tags( array $labels ): array {
		$amenity  = '';
		$cuisines = array();
		foreach ( $labels as $label ) {
			if ( 0 !== strpos( $label, self::ROOT_CATEGORY ) ) {
				continue;
			}
			$leaf = self::leaf( $label );
			if ( isset( self::AMENITY[ $leaf ] ) ) {
				$amenity = '' === $amenity ? self::AMENITY[ $leaf ] : $amenity;
				continue;
			}
			if ( 'restaurant' === $leaf ) {
				continue; // Says nothing about the cuisine.
			}
			$value = trim( (string) preg_replace( self::SUFFIXES, '', $leaf ) );
			if ( '' !== $value ) {
				$cuisines[] = str_replace( ' ', '_', $value );
			}
		}
		return array_filter(
			array(
				'amenity' => '' !== $amenity ? $amenity : 'restaurant',
				'cuisine' => implode( ';', array_unique( $cuisines ) ),
			)
		);
	}

	/**
	 * Whether a point is inside Guam's bounding box (some Guam places carry
	 * country US, so the country column is not used).
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool
	 */
	public static function in_guam( float $lat, float $lng ): bool {
		return $lat >= self::BOUNDS['south'] && $lat <= self::BOUNDS['north'] && $lng >= self::BOUNDS['west'] && $lng <= self::BOUNDS['east'];
	}

	/**
	 * The place's Foursquare ID.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string
	 */
	public static function id( array $row ): string {
		return Restaurant_Meta::sanitize_identifier( (string) ( $row['fsq_place_id'] ?? '' ) );
	}

	/**
	 * Category labels; the extract writes a list, CSV exports a JSON or `|`-separated string.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string[]
	 */
	public static function labels( array $row ): array {
		return self::strings( $row['fsq_category_labels'] ?? array() );
	}

	/**
	 * Foursquare's unresolved flags.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string[]
	 */
	private static function flags( array $row ): array {
		return array_map( 'strtolower', self::strings( $row['unresolved_flags'] ?? array() ) );
	}

	/**
	 * A list value as strings.
	 *
	 * @param mixed $value List, JSON array string, or `|`-separated string.
	 * @return string[]
	 */
	private static function strings( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : explode( '|', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ) ) );
	}

	/**
	 * Last segment of a category label, lowercased.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	private static function leaf( string $label ): string {
		$parts = explode( '>', $label );
		return strtolower( trim( (string) end( $parts ) ) );
	}

	/**
	 * A website URL with a scheme.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'http://' . $url;
		}
		return esc_url_raw( $url );
	}

	/**
	 * Facebook page URL from Foursquare's facebook_id (a numeric page ID).
	 *
	 * @param mixed $id Page ID.
	 * @return string
	 */
	private static function facebook( $id ): string {
		$id = (string) preg_replace( '/\D/', '', (string) $id );
		return '' === $id ? '' : 'https://www.facebook.com/' . $id;
	}

	/**
	 * Instagram profile URL from a handle.
	 *
	 * @param string $handle Handle, with or without @.
	 * @return string
	 */
	private static function instagram( string $handle ): string {
		$handle = ltrim( trim( $handle ), '@' );
		return 1 === preg_match( '/^[A-Za-z0-9._]{1,30}$/', $handle ) ? 'https://www.instagram.com/' . $handle : '';
	}
}
