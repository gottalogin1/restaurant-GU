<?php
/**
 * Village point-in-polygon tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Seed_Data;

/**
 * Village lookup against the Census boundaries and synthetic shapes.
 *
 * @covers \GuamRestaurants\Core\Geo\Village_Locator
 */
class Village_Locator_Test extends Test_Case {

	/**
	 * Real Guam coordinates, each checked to sit well inside its village.
	 *
	 * @return array<string, array{float, float, string|null}>
	 */
	public function known_points(): array {
		return array(
			'Dededo, village core'          => array( 13.5180, 144.8390, 'dededo' ),
			'Dededo, Census interior point' => array( 13.5600219, 144.8476600, 'dededo' ),
			'Tumon hotel row (Tamuning)'    => array( 13.5140, 144.8040, 'tamuning' ),
			'Hagåtña, near Plaza de España' => array( 13.4757, 144.7497, 'hagatna' ),
			'Yigo, Census interior point'   => array( 13.5695215, 144.9008786, 'yigo' ),
			'Merizo, Census interior point' => array( 13.2649616, 144.6881383, 'merizo' ),
			'Philippine Sea west of Tumon'  => array( 13.52, 144.70, null ),
			'Gulf of Guinea, far from Guam' => array( 0.0, 0.0, null ),
		);
	}

	/**
	 * Known coordinates resolve to the right village, and water to null.
	 *
	 * @dataProvider known_points
	 */
	public function test_known_points( float $lat, float $lng, ?string $expected ) {
		$this->assertSame( $expected, Village_Locator::instance()->slug_for( $lat, $lng ) );
	}

	/**
	 * Non-finite input returns null instead of a wrong village.
	 */
	public function test_non_finite_input_returns_null() {
		$locator = Village_Locator::instance();

		$this->assertNull( $locator->slug_for( NAN, 144.8390 ) );
		$this->assertNull( $locator->slug_for( 13.5180, INF ) );
	}

	/**
	 * The bundled file has exactly the 19 seeded villages.
	 */
	public function test_bundled_file_has_the_19_villages() {
		$slugs = Village_Locator::instance()->slugs();

		$this->assertCount( 19, $slugs );
		$this->assertEqualsCanonicalizing( array_keys( Seed_Data::villages() ), $slugs );
	}

	/**
	 * Every Census interior point (INTPTLAT/INTPTLON) lands in its own village.
	 */
	public function test_every_village_contains_its_census_interior_point() {
		$points = array(
			'chalan-pago-ordot'   => array( 13.4418791, 144.7698792 ),
			'santa-rita'          => array( 13.4055514, 144.6788522 ),
			'sinajana'            => array( 13.4600688, 144.7614754 ),
			'yona'                => array( 13.3957437, 144.7449228 ),
			'hagatna'             => array( 13.4741028, 144.7501368 ),
			'mangilao'            => array( 13.4813583, 144.8485470 ),
			'mongmong-toto-maite' => array( 13.4709389, 144.7726744 ),
			'piti'                => array( 13.4537813, 144.6669289 ),
			'tamuning'            => array( 13.5052435, 144.7972794 ),
			'umatac'              => array( 13.3073236, 144.6691917 ),
			'asan-maina'          => array( 13.4567822, 144.7256636 ),
			'talofofo'            => array( 13.3362319, 144.7231555 ),
			'inarajan'            => array( 13.2976422, 144.7339348 ),
			'agana-heights'       => array( 13.4659009, 144.7446727 ),
			'barrigada'           => array( 13.4764296, 144.8101612 ),
			'agat'                => array( 13.3463953, 144.6812871 ),
		);

		foreach ( $points as $slug => list( $lat, $lng ) ) {
			$this->assertSame( $slug, Village_Locator::instance()->slug_for( $lat, $lng ), $slug );
		}
	}

	/**
	 * Holes are excluded, and every part of a MultiPolygon counts.
	 */
	public function test_holes_and_multipolygons() {
		$locator = new Village_Locator( dirname( __DIR__ ) . '/fixtures/shapes.geojson' );

		$this->assertSame( 'donut', $locator->slug_for( 2.0, 2.0 ) );
		$this->assertNull( $locator->slug_for( 5.0, 5.0 ), 'Point in the hole' );
		$this->assertSame( 'islands', $locator->slug_for( 1.0, 21.0 ) );
		$this->assertSame( 'islands', $locator->slug_for( 1.0, 31.0 ) );
		$this->assertNull( $locator->slug_for( 1.0, 25.0 ), 'Between the islands' );
	}

	/**
	 * A point on a shared edge resolves to one village, the same each time.
	 */
	public function test_shared_boundary_is_deterministic() {
		$locator = new Village_Locator( dirname( __DIR__ ) . '/fixtures/shapes.geojson' );

		$first = $locator->slug_for( 5.0, 10.0 );
		$this->assertContains( $first, array( 'donut', 'neighbor' ) );
		$this->assertSame( $first, $locator->slug_for( 5.0, 10.0 ) );
	}

	/**
	 * A missing file fails loudly rather than tagging nothing.
	 */
	public function test_missing_file_throws() {
		$this->expectException( \RuntimeException::class );

		( new Village_Locator( '/nonexistent.geojson' ) )->slug_for( 13.5, 144.8 );
	}
}
