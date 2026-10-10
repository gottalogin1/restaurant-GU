<?php
/**
 * OSM mapping tests: cuisines, hours and element fields.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Osm\Element_Mapper;
use GuamRestaurants\Core\Osm\Hours_Parser;
use GuamRestaurants\Core\Seed_Data;

/**
 * Mapping Overpass elements to the content model.
 *
 * @covers \GuamRestaurants\Core\Osm\Cuisine_Map
 * @covers \GuamRestaurants\Core\Osm\Hours_Parser
 * @covers \GuamRestaurants\Core\Osm\Element_Mapper
 */
class Osm_Mapping_Test extends Test_Case {

	/**
	 * Every slug in the bundled cuisine map is a seeded cuisine term.
	 */
	public function test_bundled_map_only_uses_seeded_slugs() {
		$slugs = Cuisine_Map::from_file()->slugs();

		$this->assertNotEmpty( $slugs );
		$this->assertSame( array(), array_diff( $slugs, array_keys( Seed_Data::cuisines() ) ) );
	}

	/**
	 * Cuisine lists are split on semicolons, lowercased, and unknown values reported.
	 */
	public function test_cuisine_values_are_split_mapped_and_unmapped_reported() {
		$map = Cuisine_Map::from_file();

		$this->assertSame(
			array(
				'slugs'    => array( 'coffee-and-cafe', 'bakery-and-dessert' ),
				'unmapped' => array( 'kombucha' ),
				'details'  => array(
					'coffee-and-cafe'    => 'osm:amenity=cafe',
					'bakery-and-dessert' => 'osm:cuisine=donut',
				),
			),
			$map->map(
				array(
					'amenity' => 'cafe',
					'cuisine' => 'Coffee_Shop; donut;kombucha;;',
				)
			)
		);
		$this->assertSame(
			array(
				'slugs'    => array( 'japanese', 'bbq-and-grill' ),
				'unmapped' => array(),
				'details'  => array(
					'japanese'      => 'osm:cuisine=yakiniku',
					'bbq-and-grill' => 'osm:cuisine=yakiniku',
				),
			),
			$map->map( array( 'amenity' => 'restaurant', 'cuisine' => 'yakiniku' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * A null mapping is a known value with no term: not reported as unmapped.
	 */
	public function test_null_mapping_is_known_but_has_no_term() {
		$map = new Cuisine_Map(
			array(
				'amenity' => array( 'restaurant' => null ),
				'cuisine' => array( 'regional' => null ),
			)
		);

		$this->assertSame(
			array(
				'slugs'    => array(),
				'unmapped' => array(),
				'details'  => array(),
			),
			$map->map( array( 'amenity' => 'restaurant', 'cuisine' => 'regional' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * Opening hours in common forms.
	 *
	 * @return array<string, array{string, array<string, mixed>|null}>
	 */
	public function hours_cases(): array {
		return array(
			'weekday and weekend'    => array(
				'Mo-Fr 10:00-21:00; Sa,Su 08:00-22:00',
				array(
					'weekly' => array(
						array( 'day' => 'mo', 'open' => '10:00', 'close' => '21:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'tu', 'open' => '10:00', 'close' => '21:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'we', 'open' => '10:00', 'close' => '21:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'th', 'open' => '10:00', 'close' => '21:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'fr', 'open' => '10:00', 'close' => '21:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'sa', 'open' => '08:00', 'close' => '22:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'su', 'open' => '08:00', 'close' => '22:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				),
			),
			'split day, wrap, off'   => array(
				'Fr-Mo 11:00-14:00,17:00-02:00; Su off',
				array(
					'weekly' => array(
						array( 'day' => 'mo', 'open' => '11:00', 'close' => '14:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'mo', 'open' => '17:00', 'close' => '02:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'fr', 'open' => '11:00', 'close' => '14:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'fr', 'open' => '17:00', 'close' => '02:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'sa', 'open' => '11:00', 'close' => '14:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'sa', 'open' => '17:00', 'close' => '02:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				),
			),
			'no day selector, 26:00' => array(
				'18:00-26:00',
				array(
					'weekly' => array_map(
						static function ( $day ) {
							return array( 'day' => $day, 'open' => '18:00', 'close' => '02:00' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						},
						array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' )
					),
				),
			),
			'comma between rules'    => array(
				'Sa 10:00-14:00, Su 09:00-12:00',
				array(
					'weekly' => array(
						array( 'day' => 'sa', 'open' => '10:00', 'close' => '14:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'day' => 'su', 'open' => '09:00', 'close' => '12:00' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				),
			),
			'always open'            => array(
				'24/7',
				array(
					'weekly'      => array(),
					'always_open' => true,
				),
			),
			'public holidays: raw'   => array( 'Mo-Fr 10:00-20:00; PH off', null ),
			'sunrise: raw'           => array( 'Mo-Su sunrise-sunset', null ),
			'open end: raw'          => array( 'Fr 18:00+', null ),
			'months: raw'            => array( 'Jan-Mar Mo-Fr 10:00-20:00', null ),
			'empty'                  => array( '', null ),
		);
	}

	/**
	 * Hours parse to gr_hours, or null when only the raw value can be kept.
	 *
	 * @dataProvider hours_cases
	 */
	public function test_hours( string $value, ?array $expected ) {
		$this->assertSame( $expected, Hours_Parser::parse( $value ) );
	}

	/**
	 * A node maps to every content-model field, sanitized as stored.
	 */
	public function test_maps_node_fields() {
		$record = $this->mapper()->map(
			array(
				'type' => 'node',
				'id'   => 42,
				'lat'  => 13.51801234567,
				'lon'  => 144.839,
				'tags' => array(
					'amenity'          => 'restaurant',
					'name'             => 'Kusina <b>Dededo</b>',
					'cuisine'          => 'filipino',
					'phone'            => 'not a number; (671) 555-0101',
					'website'          => 'kusina.example.com',
					'addr:housenumber' => '100',
					'addr:street'      => 'Marine Corps Drive',
					'opening_hours'    => 'Mo-Su 10:00-21:00; PH off',
				),
			)
		);

		$this->assertSame( 'node/42', $record['osm_id'] );
		$this->assertSame( 'Kusina Dededo', $record['title'] );
		$this->assertSame( 13.5180123, $record['latitude'] );
		$this->assertSame( 144.839, $record['longitude'] );
		$this->assertSame( '100 Marine Corps Drive', $record['meta']['gr_address'] );
		$this->assertSame( '+16715550101', $record['meta']['gr_phone'] );
		$this->assertSame( 'http://kusina.example.com', $record['meta']['gr_website'] );
		$this->assertSame(
			array(
				'osm_raw' => 'Mo-Su 10:00-21:00; PH off',
				'source'  => 'osm',
			),
			$record['meta']['gr_hours'],
			'Unparseable hours keep the raw value only.'
		);
		$this->assertSame( array( 'filipino' ), $record['cuisines'] );
	}

	/**
	 * Ways use the center Overpass computes; missing tags give empty values.
	 */
	public function test_way_uses_center_and_missing_tags_are_empty() {
		$record = $this->mapper()->map(
			array(
				'type'   => 'way',
				'id'     => 7,
				'center' => array(
					'lat' => 13.514,
					'lon' => 144.804,
				),
				'tags'   => array(
					'amenity' => 'fast_food',
					'name:en' => 'Burger Shack',
				),
			)
		);

		$this->assertSame( 'way/7', $record['osm_id'] );
		$this->assertSame( 'Burger Shack', $record['title'] );
		$this->assertSame( array( 13.514, 144.804 ), array( $record['latitude'], $record['longitude'] ) );
		$this->assertSame( '', $record['meta']['gr_address'] );
		$this->assertSame( '', $record['meta']['gr_phone'] );
		$this->assertSame( array(), $record['meta']['gr_hours'] );
		$this->assertSame( array( 'fast-food' ), $record['cuisines'] );
	}

	/**
	 * Elements without a name, coordinates or valid id are skipped.
	 */
	public function test_skip_reasons() {
		$this->assertSame( 'no name', Element_Mapper::skip_reason( array( 'type' => 'node', 'id' => 1, 'lat' => 13.5, 'lon' => 144.8, 'tags' => array( 'amenity' => 'cafe' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'no coordinates', Element_Mapper::skip_reason( array( 'type' => 'way', 'id' => 1, 'tags' => array( 'name' => 'X' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'malformed id', Element_Mapper::skip_reason( array( 'type' => 'area', 'id' => 1, 'tags' => array( 'name' => 'X' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( Element_Mapper::skip_reason( array( 'type' => 'node', 'id' => 1, 'lat' => 13.5, 'lon' => 144.8, 'tags' => array( 'name' => 'X' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Mapper with the bundled cuisine map.
	 */
	private function mapper(): Element_Mapper {
		return new Element_Mapper( Cuisine_Map::from_file() );
	}
}
