<?php
/**
 * OSM tag to cuisine term mapping.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

defined( 'ABSPATH' ) || exit;

/**
 * Maps OpenStreetMap `amenity` and `cuisine` values to gr_cuisine slugs,
 * using data/osm-cuisine-map.json.
 */
final class Cuisine_Map {

	/**
	 * Amenity value => slugs (empty = known, no term).
	 *
	 * @var array<string, string[]>
	 */
	private array $amenity;

	/**
	 * Cuisine value => slugs (empty = known, no term).
	 *
	 * @var array<string, string[]>
	 */
	private array $cuisine;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $map Decoded map with `amenity` and `cuisine` objects.
	 */
	public function __construct( array $map ) {
		$this->amenity = self::normalize( $map['amenity'] ?? array() );
		$this->cuisine = self::normalize( $map['cuisine'] ?? array() );
	}

	/**
	 * Loads a map file; defaults to the bundled data/osm-cuisine-map.json.
	 *
	 * @param string|null $file Path.
	 * @return Cuisine_Map
	 * @throws \RuntimeException When the file is missing or not valid JSON.
	 */
	public static function from_file( ?string $file = null ): Cuisine_Map {
		$file = $file ?? GUAM_RESTAURANTS_CORE_DIR . 'data/osm-cuisine-map.json';
		$json = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$map  = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $map ) ) {
			throw new \RuntimeException( 'Cuisine map is missing or not valid JSON: ' . esc_html( $file ) );
		}
		return new self( $map );
	}

	/**
	 * Maps an element's tags.
	 *
	 * The `cuisine` tag is split on semicolons (OSM's list separator) and
	 * each value is lowercased and trimmed.
	 *
	 * @param array<string, string> $tags OSM tags.
	 * @return array{slugs: string[], unmapped: string[], details: array<string, string>}
	 */
	public function map( array $tags ): array {
		$slugs    = array();
		$unmapped = array();
		$details  = array();

		$amenity = strtolower( trim( (string) ( $tags['amenity'] ?? '' ) ) );
		if ( isset( $this->amenity[ $amenity ] ) ) {
			$slugs = array_merge( $slugs, $this->amenity[ $amenity ] );
			foreach ( $this->amenity[ $amenity ] as $slug ) {
				$details[ $slug ] = $details[ $slug ] ?? 'osm:amenity=' . $amenity;
			}
		}

		foreach ( explode( ';', (string) ( $tags['cuisine'] ?? '' ) ) as $value ) {
			$value = strtolower( trim( $value ) );
			if ( '' === $value ) {
				continue;
			}
			if ( array_key_exists( $value, $this->cuisine ) ) {
				$slugs = array_merge( $slugs, $this->cuisine[ $value ] );
				foreach ( $this->cuisine[ $value ] as $slug ) {
					$details[ $slug ] = $details[ $slug ] ?? 'osm:cuisine=' . $value;
				}
			} else {
				$unmapped[] = $value;
			}
		}

		return array(
			'slugs'    => array_values( array_unique( $slugs ) ),
			'unmapped' => array_values( array_unique( $unmapped ) ),
			'details'  => $details,
		);
	}

	/**
	 * Every slug the map can produce.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		$all = array_merge( array(), ...array_values( $this->amenity ), ...array_values( $this->cuisine ) );
		return array_values( array_unique( $all ) );
	}

	/**
	 * Normalizes values to lowercase keys and slug lists.
	 *
	 * @param mixed $section Map section.
	 * @return array<string, string[]>
	 */
	private static function normalize( $section ): array {
		$out = array();
		if ( ! is_array( $section ) ) {
			return $out;
		}
		foreach ( $section as $value => $slugs ) {
			$out[ strtolower( trim( (string) $value ) ) ] = null === $slugs ? array() : array_values( array_map( 'strval', (array) $slugs ) );
		}
		return $out;
	}
}
