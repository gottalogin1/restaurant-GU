<?php
/**
 * Point-in-polygon lookup of a coordinate's village.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Geo;

defined( 'ABSPATH' ) || exit;

/**
 * Finds which of Guam's 19 villages contains a coordinate, using the Census
 * TIGER/Line boundaries in data/guam-villages.geojson.
 *
 * Only coordinates we are allowed to analyze may be passed in: never Google
 * coordinates (Maps Platform ToS §3.2.3(c)(iv)).
 */
final class Village_Locator {

	/**
	 * Shared instance for the bundled data file.
	 *
	 * @var Village_Locator|null
	 */
	private static ?Village_Locator $default = null;

	/**
	 * Parsed features: slug, bounding box and polygons (rings of [lng, lat]).
	 *
	 * @var array<int, array{slug: string, bbox: float[], polygons: array<int, array<int, array<int, float[]>>>}>|null
	 */
	private ?array $features = null;

	/**
	 * GeoJSON file path.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Constructor.
	 *
	 * @param string $file Path to a GeoJSON FeatureCollection whose features carry a `slug` property.
	 */
	public function __construct( string $file ) {
		$this->file = $file;
	}

	/**
	 * Returns the locator for the bundled village boundaries.
	 *
	 * @return Village_Locator
	 */
	public static function instance(): Village_Locator {
		if ( null === self::$default ) {
			self::$default = new self( GUAM_RESTAURANTS_CORE_DIR . 'data/guam-villages.geojson' );
		}
		return self::$default;
	}

	/**
	 * Returns the slug of the village containing the point, or null when the
	 * point is outside every village (offshore or not on Guam).
	 *
	 * A point exactly on a shared boundary resolves to the first village in
	 * file order (by Census GEOID), so the answer is deterministic.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string|null
	 */
	public function slug_for( float $lat, float $lng ): ?string {
		if ( ! is_finite( $lat ) || ! is_finite( $lng ) ) {
			return null;
		}

		foreach ( $this->features() as $feature ) {
			list( $min_lng, $min_lat, $max_lng, $max_lat ) = $feature['bbox'];
			if ( $lng < $min_lng || $lng > $max_lng || $lat < $min_lat || $lat > $max_lat ) {
				continue;
			}
			foreach ( $feature['polygons'] as $rings ) {
				if ( self::in_polygon( $lng, $lat, $rings ) ) {
					return $feature['slug'];
				}
			}
		}

		return null;
	}

	/**
	 * Village slugs in the data file, in file order.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		return array_column( $this->features(), 'slug' );
	}

	/**
	 * Inside the outer ring and not inside any hole.
	 *
	 * @param float                           $x     Longitude.
	 * @param float                           $y     Latitude.
	 * @param array<int, array<int, float[]>> $rings Outer ring first, then holes.
	 * @return bool
	 */
	public static function in_polygon( float $x, float $y, array $rings ): bool {
		if ( ! $rings || ! self::in_ring( $x, $y, $rings[0] ) ) {
			return false;
		}
		$count = count( $rings );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( self::in_ring( $x, $y, $rings[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Even-odd ray casting test for one ring.
	 *
	 * @param float               $x    Longitude.
	 * @param float               $y    Latitude.
	 * @param array<int, float[]> $ring Ring of [lng, lat] positions.
	 * @return bool
	 */
	public static function in_ring( float $x, float $y, array $ring ): bool {
		$inside = false;
		$count  = count( $ring );
		for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
			$xi = $ring[ $i ][0];
			$yi = $ring[ $i ][1];
			$xj = $ring[ $j ][0];
			$yj = $ring[ $j ][1];
			if ( ( $yi > $y ) !== ( $yj > $y ) && $x < ( $xj - $xi ) * ( $y - $yi ) / ( $yj - $yi ) + $xi ) {
				$inside = ! $inside;
			}
		}
		return $inside;
	}

	/**
	 * Loads and indexes the GeoJSON once.
	 *
	 * @return array<int, array{slug: string, bbox: float[], polygons: array<int, array<int, array<int, float[]>>>}>
	 * @throws \RuntimeException When the file is missing or malformed.
	 */
	private function features(): array {
		if ( null !== $this->features ) {
			return $this->features;
		}

		$json = is_readable( $this->file ) ? file_get_contents( $this->file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
			throw new \RuntimeException( 'Village boundary file is missing or not valid GeoJSON: ' . esc_html( $this->file ) );
		}

		$this->features = array();
		foreach ( $data['features'] as $feature ) {
			$slug     = $feature['properties']['slug'] ?? '';
			$geometry = $feature['geometry'] ?? array();
			$type     = $geometry['type'] ?? '';
			if ( '' === $slug || ! in_array( $type, array( 'Polygon', 'MultiPolygon' ), true ) ) {
				continue;
			}

			$polygons = 'Polygon' === $type ? array( $geometry['coordinates'] ) : $geometry['coordinates'];
			$bbox     = array( INF, INF, -INF, -INF );
			foreach ( $polygons as $rings ) {
				foreach ( $rings[0] as $position ) {
					$bbox[0] = min( $bbox[0], $position[0] );
					$bbox[1] = min( $bbox[1], $position[1] );
					$bbox[2] = max( $bbox[2], $position[0] );
					$bbox[3] = max( $bbox[3], $position[1] );
				}
			}

			$this->features[] = array(
				'slug'     => (string) $slug,
				'bbox'     => $bbox,
				'polygons' => $polygons,
			);
		}

		return $this->features;
	}
}
