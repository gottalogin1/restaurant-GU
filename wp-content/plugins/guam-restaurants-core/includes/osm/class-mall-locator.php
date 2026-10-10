<?php
/**
 * Mall lookup from OSM shop=mall features.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns a point to a mall term: inside the mall's OSM outline when OSM
 * has one, otherwise within a radius of the mall's OSM point.
 *
 * OSM malls are matched to gr_mall terms by the term's gr_osm_id, then by
 * name or alias. OSM malls with no term are reported, never created: new
 * malls need an editor (design/content-model.md §4.2).
 */
final class Mall_Locator {

	/**
	 * Mean Earth radius in metres.
	 */
	private const EARTH_RADIUS = 6371008.8;

	/**
	 * Matched malls.
	 *
	 * @var array<int, array{slug: string, osm_id: string, center: float[]|null, polygons: array<int, array<int, array<int, float[]>>>}>
	 */
	private array $malls = array();

	/**
	 * OSM malls with no matching term: osm_id => name.
	 *
	 * @var array<string, string>
	 */
	private array $unmatched = array();

	/**
	 * Radius in metres for malls without an outline.
	 *
	 * @var float
	 */
	private float $radius;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>>                                                 $elements Overpass elements tagged shop=mall (`out geom`).
	 * @param array<int, array{slug: string, name: string, aliases: string[], osm_id: string}> $terms    Mall terms.
	 * @param float                                                                            $radius   Metres.
	 */
	public function __construct( array $elements, array $terms, float $radius = 150.0 ) {
		$this->radius = $radius;

		$by_osm  = array();
		$by_name = array();
		foreach ( $terms as $term ) {
			if ( '' !== $term['osm_id'] ) {
				$by_osm[ $term['osm_id'] ] = $term['slug'];
			}
			foreach ( array_merge( array( $term['name'] ), $term['aliases'] ) as $name ) {
				$by_name[ Names::key( $name ) ] = $term['slug'];
			}
		}

		foreach ( $elements as $element ) {
			$osm_id = Element_Mapper::osm_id( $element );
			$name   = (string) ( $element['tags']['name'] ?? '' );
			$slug   = $by_osm[ $osm_id ] ?? $by_name[ Names::key( $name ) ] ?? null;
			if ( null === $slug || '' === $osm_id ) {
				$this->unmatched[ $osm_id ] = $name;
				continue;
			}
			$this->malls[] = array(
				'slug'     => $slug,
				'osm_id'   => $osm_id,
				'center'   => self::center( $element ),
				'polygons' => self::polygons( $element ),
			);
		}
	}

	/**
	 * Mall slug for a point, or null.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string|null
	 */
	public function slug_for( float $lat, float $lng ): ?string {
		foreach ( $this->malls as $mall ) {
			foreach ( $mall['polygons'] as $rings ) {
				if ( Village_Locator::in_polygon( $lng, $lat, $rings ) ) {
					return $mall['slug'];
				}
			}
		}

		$best      = null;
		$best_dist = INF;
		foreach ( $this->malls as $mall ) {
			if ( $mall['polygons'] || null === $mall['center'] ) {
				continue;
			}
			$dist = self::distance( $lat, $lng, $mall['center'][0], $mall['center'][1] );
			if ( $dist <= $this->radius && $dist < $best_dist ) {
				$best      = $mall['slug'];
				$best_dist = $dist;
			}
		}
		return $best;
	}

	/**
	 * Matched malls: slug => OSM id.
	 *
	 * @return array<string, string>
	 */
	public function matched(): array {
		return array_column( $this->malls, 'osm_id', 'slug' );
	}

	/**
	 * OSM malls with no term: OSM id => name.
	 *
	 * @return array<string, string>
	 */
	public function unmatched(): array {
		return $this->unmatched;
	}

	/**
	 * Great-circle distance in metres.
	 *
	 * @param float $lat1 Latitude 1.
	 * @param float $lng1 Longitude 1.
	 * @param float $lat2 Latitude 2.
	 * @param float $lng2 Longitude 2.
	 * @return float
	 */
	public static function distance( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );
		$a     = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;
		return 2 * self::EARTH_RADIUS * asin( min( 1.0, sqrt( $a ) ) );
	}

	/**
	 * Node position, Overpass center, or the middle of the bounds.
	 *
	 * @param array<string, mixed> $element Element.
	 * @return float[]|null [lat, lng].
	 */
	private static function center( array $element ): ?array {
		if ( isset( $element['lat'], $element['lon'] ) ) {
			return array( (float) $element['lat'], (float) $element['lon'] );
		}
		if ( isset( $element['center']['lat'], $element['center']['lon'] ) ) {
			return array( (float) $element['center']['lat'], (float) $element['center']['lon'] );
		}
		if ( isset( $element['bounds']['minlat'], $element['bounds']['maxlat'], $element['bounds']['minlon'], $element['bounds']['maxlon'] ) ) {
			$b = $element['bounds'];
			return array( ( $b['minlat'] + $b['maxlat'] ) / 2, ( $b['minlon'] + $b['maxlon'] ) / 2 );
		}
		return null;
	}

	/**
	 * Outline polygons from `out geom` output: a closed way, or a relation's
	 * outer ways joined into rings. Holes are ignored (a restaurant in a
	 * mall's courtyard still belongs to the mall).
	 *
	 * @param array<string, mixed> $element Element.
	 * @return array<int, array<int, array<int, float[]>>> Polygons of rings of [lng, lat].
	 */
	public static function polygons( array $element ): array {
		$type = $element['type'] ?? '';

		if ( 'way' === $type ) {
			$ring = self::line( $element['geometry'] ?? array() );
			return self::is_closed( $ring ) ? array( array( $ring ) ) : array();
		}

		if ( 'relation' !== $type ) {
			return array();
		}

		$lines = array();
		foreach ( $element['members'] ?? array() as $member ) {
			if ( 'way' === ( $member['type'] ?? '' ) && in_array( $member['role'] ?? '', array( 'outer', '' ), true ) ) {
				$line = self::line( $member['geometry'] ?? array() );
				if ( count( $line ) >= 2 ) {
					$lines[] = $line;
				}
			}
		}

		$polygons = array();
		foreach ( self::join_rings( $lines ) as $ring ) {
			$polygons[] = array( $ring );
		}
		return $polygons;
	}

	/**
	 * Joins open ways end to end into closed rings; leftovers are dropped.
	 *
	 * @param array<int, array<int, float[]>> $lines Lines of [lng, lat].
	 * @return array<int, array<int, float[]>>
	 */
	private static function join_rings( array $lines ): array {
		$rings = array();
		while ( $lines ) {
			$ring = array_shift( $lines );
			while ( ! self::is_closed( $ring ) ) {
				$joined = false;
				foreach ( $lines as $i => $line ) {
					$end = end( $ring );
					if ( $line[0] === $end ) {
						$ring = array_merge( $ring, array_slice( $line, 1 ) );
					} elseif ( end( $line ) === $end ) {
						$ring = array_merge( $ring, array_slice( array_reverse( $line ), 1 ) );
					} else {
						continue;
					}
					unset( $lines[ $i ] );
					$joined = true;
					break;
				}
				if ( ! $joined ) {
					break;
				}
			}
			if ( self::is_closed( $ring ) ) {
				$rings[] = $ring;
			}
		}
		return $rings;
	}

	/**
	 * Converts Overpass geometry ({lat, lon} objects) to [lng, lat] pairs.
	 *
	 * @param mixed $geometry Geometry.
	 * @return array<int, float[]>
	 */
	private static function line( $geometry ): array {
		$line = array();
		foreach ( is_array( $geometry ) ? $geometry : array() as $point ) {
			if ( isset( $point['lat'], $point['lon'] ) ) {
				$line[] = array( (float) $point['lon'], (float) $point['lat'] );
			}
		}
		return $line;
	}

	/**
	 * Whether a line is a closed ring.
	 *
	 * @param array<int, float[]> $ring Ring.
	 * @return bool
	 */
	private static function is_closed( array $ring ): bool {
		return count( $ring ) >= 4 && $ring[0] === $ring[ count( $ring ) - 1 ];
	}
}
