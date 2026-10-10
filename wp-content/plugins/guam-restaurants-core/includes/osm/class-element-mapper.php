<?php
/**
 * Maps one Overpass element to restaurant fields.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an Overpass JSON element into the content model's fields
 * (design/content-model.md §3). Values are sanitized the same way the
 * registered meta sanitizers store them, so comparing with stored values is
 * exact and a second import of the same data changes nothing.
 */
final class Element_Mapper {

	/**
	 * Amenity values imported as restaurants.
	 */
	public const AMENITIES = array( 'restaurant', 'cafe', 'fast_food', 'bar', 'pub', 'food_court' );

	/**
	 * Cuisine map.
	 *
	 * @var Cuisine_Map
	 */
	private Cuisine_Map $cuisines;

	/**
	 * Constructor.
	 *
	 * @param Cuisine_Map $cuisines Cuisine map.
	 */
	public function __construct( Cuisine_Map $cuisines ) {
		$this->cuisines = $cuisines;
	}

	/**
	 * Whether the element is an eating or drinking place we import.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return bool
	 */
	public static function is_restaurant( array $element ): bool {
		return in_array( $element['tags']['amenity'] ?? '', self::AMENITIES, true );
	}

	/**
	 * Returns "node/123", "way/45" or "relation/6", or "" when malformed.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return string
	 */
	public static function osm_id( array $element ): string {
		return Restaurant_Meta::sanitize_osm_id( ( $element['type'] ?? '' ) . '/' . ( $element['id'] ?? '' ) );
	}

	/**
	 * The element's point: a node's own coordinates, or the center Overpass
	 * computes for ways and relations (`out center`).
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array{0: float, 1: float}|null Latitude and longitude.
	 */
	public static function point( array $element ): ?array {
		$source = isset( $element['lat'], $element['lon'] ) ? $element : ( $element['center'] ?? null );
		if ( ! is_array( $source ) || ! isset( $source['lat'], $source['lon'] ) || ! is_numeric( $source['lat'] ) || ! is_numeric( $source['lon'] ) ) {
			return null;
		}
		$lat = Restaurant_Meta::sanitize_latitude( $source['lat'] );
		$lng = Restaurant_Meta::sanitize_longitude( $source['lon'] );
		return null === $lat || null === $lng ? null : array( $lat, $lng );
	}

	/**
	 * Why the element cannot be imported, or null when it can.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return string|null
	 */
	public static function skip_reason( array $element ): ?string {
		if ( '' === self::osm_id( $element ) ) {
			return 'malformed id';
		}
		if ( '' === self::name( $element['tags'] ?? array() ) ) {
			return 'no name';
		}
		if ( null === self::point( $element ) ) {
			return 'no coordinates';
		}
		return null;
	}

	/**
	 * Maps an element. Call skip_reason() first.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array{osm_id: string, title: string, latitude: float, longitude: float, meta: array<string, mixed>, cuisines: string[], cuisine_details: array<string, string>, unmapped: string[]}
	 */
	public function map( array $element ): array {
		$tags              = array_map( 'strval', $element['tags'] ?? array() );
		list( $lat, $lng ) = self::point( $element );
		$cuisine           = $this->cuisines->map( $tags );

		$meta = array(
			Restaurant_Meta::ADDRESS => Restaurant_Meta::sanitize_address( self::address( $tags ) ),
			Restaurant_Meta::PHONE   => self::phone( $tags ),
			Restaurant_Meta::WEBSITE => self::website( $tags ),
			Restaurant_Meta::HOURS   => self::hours( $tags ),
		);

		return array(
			'osm_id'          => self::osm_id( $element ),
			'title'           => self::name( $tags ),
			'latitude'        => $lat,
			'longitude'       => $lng,
			'meta'            => $meta,
			'cuisines'        => $cuisine['slugs'],
			'cuisine_details' => $cuisine['details'],
			'unmapped'        => $cuisine['unmapped'],
		);
	}

	/**
	 * Name, falling back to the English name.
	 *
	 * @param array<string, string> $tags Tags.
	 * @return string
	 */
	private static function name( array $tags ): string {
		return sanitize_text_field( (string) ( $tags['name'] ?? $tags['name:en'] ?? '' ) );
	}

	/**
	 * Street line: "123 Pale San Vitores Rd", or addr:full.
	 *
	 * @param array<string, string> $tags Tags.
	 * @return string
	 */
	private static function address( array $tags ): string {
		$street = trim( ( $tags['addr:housenumber'] ?? '' ) . ' ' . ( $tags['addr:street'] ?? '' ) );
		if ( '' !== $street ) {
			if ( '' !== ( $tags['addr:unit'] ?? '' ) ) {
				$street .= ', Unit ' . $tags['addr:unit'];
			}
			return $street;
		}
		return $tags['addr:full'] ?? '';
	}

	/**
	 * First phone number that is a valid Guam number.
	 *
	 * @param array<string, string> $tags Tags.
	 * @return string E.164, or "".
	 */
	private static function phone( array $tags ): string {
		foreach ( array( 'phone', 'contact:phone' ) as $key ) {
			foreach ( explode( ';', $tags[ $key ] ?? '' ) as $number ) {
				$clean = Restaurant_Meta::sanitize_phone( $number );
				if ( '' !== $clean ) {
					return $clean;
				}
			}
		}
		return '';
	}

	/**
	 * Website, with a scheme added when the tag has none.
	 *
	 * @param array<string, string> $tags Tags.
	 * @return string
	 */
	private static function website( array $tags ): string {
		$url = trim( explode( ';', $tags['website'] ?? $tags['contact:website'] ?? '' )[0] );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'http://' . $url;
		}
		return esc_url_raw( $url );
	}

	/**
	 * Hours: parsed when possible, always with the raw tag kept.
	 *
	 * @param array<string, string> $tags Tags.
	 * @return array<string, mixed> Empty when the tag is absent.
	 */
	private static function hours( array $tags ): array {
		$raw = trim( $tags['opening_hours'] ?? '' );
		if ( '' === $raw ) {
			return array();
		}
		$parsed = Hours_Parser::parse( $raw ) ?? array();
		return Restaurant_Meta::sanitize_hours(
			$parsed + array(
				'osm_raw' => $raw,
				'source'  => 'osm',
			)
		);
	}
}
