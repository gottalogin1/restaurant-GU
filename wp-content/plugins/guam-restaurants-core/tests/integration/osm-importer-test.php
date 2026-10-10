<?php
/**
 * OSM importer tests: upsert, village and mall assignment, idempotency.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Osm\Element_Mapper;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Osm\Mall_Locator;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seeder;

/**
 * Importing the hand-made Guam sample (tests/fixtures/overpass/guam-sample.json).
 *
 * @covers \GuamRestaurants\Core\Osm\Importer
 * @covers \GuamRestaurants\Core\Osm\Mall_Locator
 */
class Osm_Importer_Test extends Test_Case {

	/**
	 * Seeds the taxonomies.
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_all();
	}

	/**
	 * Fixture elements.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function elements(): array {
		return Overpass_Client::decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/overpass/guam-sample.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Importer with the bundled cuisine map and village boundaries.
	 */
	private function importer(): Importer {
		return new Importer( new Element_Mapper( Cuisine_Map::from_file() ) );
	}

	/**
	 * Post ID for an OSM id.
	 */
	private function post_for( string $osm_id ): int {
		return Importer::existing_ids()[ $osm_id ] ?? 0;
	}

	/**
	 * Term slugs on a post.
	 *
	 * @return string[]
	 */
	private function terms( int $post_id, string $taxonomy ): array {
		$slugs = wp_get_object_terms(
			$post_id,
			$taxonomy,
			array(
				'fields'                                  => 'slugs',
				Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
			)
		);
		sort( $slugs );
		return $slugs;
	}

	/**
	 * Rows in a custom table.
	 */
	private function count_rows( string $table, string $where = '1=1' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Restaurant posts in any status.
	 */
	private function count_restaurants(): int {
		return count(
			get_posts(
				array(
					'post_type'      => Content_Types::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			)
		);
	}

	/**
	 * First import creates every named eating place with its fields and terms.
	 */
	public function test_first_import_creates_restaurants_with_fields_and_terms() {
		$result = $this->importer()->run( $this->elements() );

		$this->assertSame(
			array(
				'created'   => 6,
				'updated'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			),
			$result['counts']
		);
		$this->assertSame( array( 'no name' => 1 ), $result['skipped'] );
		$this->assertSame( 6, $this->count_restaurants(), 'Parking and nameless elements are not imported.' );

		$kusina = $this->post_for( 'node/1001' );
		$this->assertSame( 'Kusina Dededo', get_the_title( $kusina ) );
		$this->assertSame( 'publish', get_post_status( $kusina ) );
		$this->assertSame( '100 Marine Corps Drive', get_post_meta( $kusina, Restaurant_Meta::ADDRESS, true ) );
		$this->assertSame( '+16715550101', get_post_meta( $kusina, Restaurant_Meta::PHONE, true ) );
		$this->assertSame( 'http://kusina.example.com', get_post_meta( $kusina, Restaurant_Meta::WEBSITE, true ) );
		$this->assertSame( 'osm', get_post_meta( $kusina, Restaurant_Meta::COORD_SOURCE, true ) );
		$this->assertEqualsWithDelta( 13.518, (float) get_post_meta( $kusina, Restaurant_Meta::LATITUDE, true ), 1e-9 );
		$this->assertCount( 7, get_post_meta( $kusina, Restaurant_Meta::HOURS, true )['weekly'] );
		$this->assertSame( array( 'filipino', 'seafood' ), $this->terms( $kusina, Content_Types::TAX_CUISINE ) );

		$burger = $this->post_for( 'way/2001' );
		// Without a user who may post unfiltered HTML, WordPress stores "&" as "&amp;"; the second-run test checks this does not count as a change.
		$this->assertSame( 'Burger Shack & Co', html_entity_decode( get_post_field( 'post_title', $burger ) ) );
		$this->assertSame( array( 'american', 'fast-food' ), $this->terms( $burger, Content_Types::TAX_CUISINE ) );
		$this->assertTrue( get_post_meta( $burger, Restaurant_Meta::HOURS, true )['always_open'] );

		$this->assertSame( array( 'kombucha' => 1, 'ethiopian' => 1 ), $result['unmapped'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Villages come from the coordinates; offshore points go to the review queue.
	 */
	public function test_assigns_villages_by_point_in_polygon() {
		$result = $this->importer()->run( $this->elements() );

		$this->assertSame( array( 'dededo' ), $this->terms( $this->post_for( 'node/1001' ), Content_Types::TAX_VILLAGE ) );
		$this->assertSame( array( 'tamuning' ), $this->terms( $this->post_for( 'way/2001' ), Content_Types::TAX_VILLAGE ) );
		$this->assertSame( array( 'hagatna' ), $this->terms( $this->post_for( 'node/1002' ), Content_Types::TAX_VILLAGE ) );

		$offshore = $this->post_for( 'node/1003' );
		$this->assertSame( array(), $this->terms( $offshore, Content_Types::TAX_VILLAGE ) );
		$this->assertSame( array( 'node/1003' ), $result['village_unresolved'] );
		$this->assertSame( 1, $this->count_rows( Schema::REVIEW_QUEUE, "type = 'village_unresolved' AND restaurant_id = " . $offshore ) );

		$this->importer()->run( $this->elements() );
		$this->assertSame( 1, $this->count_rows( Schema::REVIEW_QUEUE, "type = 'village_unresolved' AND restaurant_id = " . $offshore ), 'Queued once while pending.' );
	}

	/**
	 * Malls by OSM outline, or by radius for malls mapped as a point.
	 */
	public function test_assigns_malls_by_outline_or_radius() {
		$result = $this->importer()->run( $this->elements() );

		$this->assertSame( array( 'micronesia-mall' ), $this->terms( $this->post_for( 'node/1001' ), Content_Types::TAX_MALL ), 'Inside the outline.' );
		$this->assertSame( array( 'agana-shopping-center' ), $this->terms( $this->post_for( 'node/1002' ), Content_Types::TAX_MALL ), 'Within 150 m of the mall point.' );
		$this->assertSame( array(), $this->terms( $this->post_for( 'way/2001' ), Content_Types::TAX_MALL ) );
		$this->assertSame( array( 'relation/3003' => 'Unknown Plaza' ), $result['unmatched_malls'] );
	}

	/**
	 * Relation outlines made of several open ways are joined into a ring.
	 */
	public function test_relation_outline_is_joined_from_member_ways() {
		$relation = array_values(
			array_filter(
				$this->elements(),
				static function ( $e ) {
					return 'relation' === $e['type'];
				}
			)
		)[0];

		$locator = new Mall_Locator(
			array( $relation ),
			array(
				array(
					'slug'    => 'unknown-plaza',
					'name'    => 'Unknown Plaza',
					'aliases' => array(),
					'osm_id'  => '',
				),
			)
		);

		$this->assertCount( 1, Mall_Locator::polygons( $relation ) );
		$this->assertSame( 'unknown-plaza', $locator->slug_for( 13.405, 144.705 ) );
		$this->assertNull( $locator->slug_for( 13.415, 144.705 ) );
	}

	/**
	 * Running the same data again changes nothing and logs nothing.
	 */
	public function test_second_run_is_idempotent() {
		$this->importer()->run( $this->elements() );
		$posts = $this->count_restaurants();

		$result = $this->importer()->run( $this->elements() );

		$this->assertSame(
			array(
				'created'   => 0,
				'updated'   => 0,
				'unchanged' => 6,
				'failed'    => 0,
			),
			$result['counts']
		);
		$this->assertSame( $posts, $this->count_restaurants() );
		$this->assertSame( 0, $this->count_rows( Schema::CHANGE_LOG ) );
	}

	/**
	 * Changed OSM values update the restaurant and write the change log.
	 */
	public function test_changed_fields_update_and_write_change_log() {
		global $wpdb;

		$this->importer()->run( $this->elements() );
		$kusina = $this->post_for( 'node/1001' );

		$elements                             = $this->elements();
		$elements[0]['tags']['phone']         = '+1 671 555 0199';
		$elements[0]['tags']['name']          = 'Kusina Dededo Grill';
		$elements[0]['tags']['opening_hours'] = 'Mo-Su 09:00-21:00';
		$elements[0]['lat']                   = 13.5140;
		$elements[0]['lon']                   = 144.8040;

		$result = $this->importer()->run( $elements );

		$this->assertSame( 1, $result['counts']['updated'] );
		$this->assertSame( 5, $result['counts']['unchanged'] );
		$this->assertSame( 'Kusina Dededo Grill', get_the_title( $kusina ) );
		$this->assertSame( '+16715550199', get_post_meta( $kusina, Restaurant_Meta::PHONE, true ) );
		$this->assertSame( array( 'tamuning' ), $this->terms( $kusina, Content_Types::TAX_VILLAGE ) );

		$log      = $wpdb->get_results( $wpdb->prepare( 'SELECT field, old_value, new_value, source, run_id FROM ' . Schema::table( Schema::CHANGE_LOG ) . ' WHERE restaurant_id = %d ORDER BY id', $kusina ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$by_field = array_column( $log, null, 'field' );

		$this->assertEqualsCanonicalizing( array( 'title', 'gr_latitude', 'gr_longitude', 'gr_phone', 'gr_hours', 'tax:gr_village' ), array_keys( $by_field ) );
		$this->assertSame( '+16715550101', $by_field['gr_phone']['old_value'] );
		$this->assertSame( '+16715550199', $by_field['gr_phone']['new_value'] );
		$this->assertSame( 'dededo', $by_field['tax:gr_village']['old_value'] );
		$this->assertSame( 'census', $by_field['tax:gr_village']['source'] );
		$this->assertSame( 'osm', $by_field['title']['source'] );
		$this->assertSame( (string) $result['run_id'], $by_field['title']['run_id'] );
	}

	/**
	 * Editors' cuisines, owner coordinates and owner hours survive an import;
	 * an empty OSM value never blanks a stored one.
	 */
	public function test_editor_and_owner_values_are_kept() {
		$this->importer()->run( $this->elements() );
		$kusina = $this->post_for( 'node/1001' );

		wp_set_object_terms( $kusina, 'local-plate-lunch', Content_Types::TAX_CUISINE, true );
		update_post_meta( $kusina, Restaurant_Meta::LATITUDE, 13.5181 );
		update_post_meta( $kusina, Restaurant_Meta::COORD_SOURCE, 'owner' );
		update_post_meta(
			$kusina,
			Restaurant_Meta::HOURS,
			array(
				'weekly' => array(),
				'source' => 'owner',
			)
		);

		$elements = $this->elements();
		unset( $elements[0]['tags']['website'] );
		$elements[0]['tags']['opening_hours'] = 'Mo-Su 09:00-21:00';
		$elements[0]['lat']                   = 13.5185;

		$result = $this->importer()->run( $elements );

		$this->assertSame( 6, $result['counts']['unchanged'] );
		$this->assertSame( array( 'filipino', 'local-plate-lunch', 'seafood' ), $this->terms( $kusina, Content_Types::TAX_CUISINE ) );
		$this->assertEqualsWithDelta( 13.5181, (float) get_post_meta( $kusina, Restaurant_Meta::LATITUDE, true ), 1e-9 );
		$this->assertSame( 'owner', get_post_meta( $kusina, Restaurant_Meta::HOURS, true )['source'] );
		$this->assertSame( 'http://kusina.example.com', get_post_meta( $kusina, Restaurant_Meta::WEBSITE, true ) );
	}

	/**
	 * A trashed restaurant is matched by OSM id, not re-created, and stays trashed.
	 */
	public function test_trashed_restaurant_is_not_duplicated() {
		$this->importer()->run( $this->elements() );
		$kusina = $this->post_for( 'node/1001' );
		wp_trash_post( $kusina );

		$result = $this->importer()->run( $this->elements() );

		$this->assertSame( 0, $result['counts']['created'] );
		$this->assertSame( 'trash', get_post_status( $kusina ) );
	}

	/**
	 * Each run is recorded with its counts; dry runs write nothing at all.
	 */
	public function test_ingestion_run_recorded_and_dry_run_writes_nothing() {
		global $wpdb;

		$dry = $this->importer()->run( $this->elements(), true );

		$this->assertSame( 6, $dry['counts']['created'] );
		$this->assertNull( $dry['run_id'] );
		$this->assertSame( 0, $this->count_restaurants() );
		$this->assertSame( 0, $this->count_rows( Schema::INGESTION_RUNS ) );
		$this->assertSame( 0, $this->count_rows( Schema::REVIEW_QUEUE ) );

		$result = $this->importer()->run( $this->elements() );
		$run    = $wpdb->get_row( $wpdb->prepare( 'SELECT source, created, updated, unchanged, failed, finished_at, notes FROM ' . Schema::table( Schema::INGESTION_RUNS ) . ' WHERE id = %d', $result['run_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$this->assertSame( 'osm', $run['source'] );
		$this->assertSame( array( '6', '0', '0', '0' ), array( $run['created'], $run['updated'], $run['unchanged'], $run['failed'] ) );
		$this->assertNotNull( $run['finished_at'] );
		$this->assertStringContainsString( 'unmapped cuisines: kombucha (1), ethiopian (1)', $run['notes'] );
	}
}
