<?php
/**
 * Guam places used by the tagging and monitoring tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

/**
 * Overpass-shaped elements for a few real Guam places. Coordinates are
 * approximate and phone numbers use the fictional 555 exchange.
 *
 * - Shirley's Coffee Shop inside Micronesia Mall (Dededo).
 * - Proa Restaurant on Pale San Vitores Road, Tumon.
 * - Meskla Chamoru Fusion Bistro in Hagåtña, which other sources spell
 *   "Meskla Chamorro Fusion Bistro".
 * - Tumon Bay Coffee and two Jamaican Grill branches (Tumon and Hagåtña),
 *   so a run with one place missing still holds most known places.
 */
final class Guam_Fixtures {

	public const MALL_OSM_ID = 4001;

	/**
	 * Micronesia Mall outline, roughly around the building.
	 *
	 * @return array<string, mixed>
	 */
	public static function micronesia_mall(): array {
		return array(
			'type'     => 'way',
			'id'       => self::MALL_OSM_ID,
			'tags'     => array(
				'shop' => 'mall',
				'name' => 'Micronesia Mall',
			),
			'geometry' => array(
				array(
					'lat' => 13.5163,
					'lon' => 144.8150,
				),
				array(
					'lat' => 13.5163,
					'lon' => 144.8182,
				),
				array(
					'lat' => 13.5187,
					'lon' => 144.8182,
				),
				array(
					'lat' => 13.5187,
					'lon' => 144.8150,
				),
				array(
					'lat' => 13.5163,
					'lon' => 144.8150,
				),
			),
		);
	}

	/**
	 * One restaurant node.
	 *
	 * @param int                   $id   OSM node id.
	 * @param float                 $lat  Latitude.
	 * @param float                 $lon  Longitude.
	 * @param array<string, string> $tags Tags.
	 * @return array<string, mixed>
	 */
	public static function node( int $id, float $lat, float $lon, array $tags ): array {
		return array(
			'type' => 'node',
			'id'   => $id,
			'lat'  => $lat,
			'lon'  => $lon,
			'tags' => $tags + array( 'amenity' => 'restaurant' ),
		);
	}

	/**
	 * Shirley's in Micronesia Mall.
	 *
	 * @return array<string, mixed>
	 */
	public static function shirleys(): array {
		return self::node(
			5001,
			13.5176,
			144.8166,
			array(
				'name'             => "Shirley's Coffee Shop",
				'cuisine'          => 'american;filipino',
				'phone'            => '+1 671 555 0101',
				'addr:housenumber' => '1088',
				'addr:street'      => 'West Marine Corps Drive',
			)
		);
	}

	/**
	 * Proa in Tumon.
	 *
	 * @return array<string, mixed>
	 */
	public static function proa(): array {
		return self::node(
			5002,
			13.5097,
			144.8044,
			array(
				'name'             => 'Proa Restaurant',
				'cuisine'          => 'chamorro',
				'phone'            => '+1 671 555 0142',
				'addr:housenumber' => '429',
				'addr:street'      => 'Pale San Vitores Road',
			)
		);
	}

	/**
	 * Meskla in Hagåtña, spelled the OSM way, with no cuisine tag.
	 *
	 * @return array<string, mixed>
	 */
	public static function meskla(): array {
		return self::node(
			5003,
			13.4769,
			144.7531,
			array(
				'name'  => 'Meskla Chamoru Fusion Bistro',
				'phone' => '+1 671 555 0177',
			)
		);
	}

	/**
	 * Every element: the mall and six places.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function elements(): array {
		return array(
			self::micronesia_mall(),
			self::shirleys(),
			self::proa(),
			self::meskla(),
			self::node(
				5004,
				13.5110,
				144.8060,
				array(
					'name'    => 'Tumon Bay Coffee',
					'amenity' => 'cafe',
					'phone'   => '+1 671 555 0150',
				)
			),
			self::node(
				5005,
				13.5120,
				144.8070,
				array(
					'name'    => 'Jamaican Grill',
					'cuisine' => 'caribbean',
					'phone'   => '+1 671 555 0160',
				)
			),
			self::node(
				5006,
				13.4760,
				144.7540,
				array(
					'name'    => 'Jamaican Grill',
					'cuisine' => 'caribbean',
					'phone'   => '+1 671 555 0161',
				)
			),
		);
	}

	/**
	 * Elements without the given OSM node ids.
	 *
	 * @param int[] $ids Node ids to drop.
	 * @return array<int, array<string, mixed>>
	 */
	public static function without( array $ids ): array {
		return array_values(
			array_filter(
				self::elements(),
				static function ( $element ) use ( $ids ) {
					return 'node' !== $element['type'] || ! in_array( $element['id'], $ids, true );
				}
			)
		);
	}
}
