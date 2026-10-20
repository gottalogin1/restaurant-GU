<?php
/**
 * Foursquare Open Source Places import tests: mapping, matching, idempotency.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Fsq\Importer;
use GuamRestaurants\Core\Fsq\Place_Mapper;
use GuamRestaurants\Core\Fsq\Reader;
use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seeder;

/**
 * Importing Foursquare places and matching them to existing listings.
 *
 * @covers \GuamRestaurants\Core\Fsq\Importer
 * @covers \GuamRestaurants\Core\Fsq\Place_Mapper
 * @covers \GuamRestaurants\Core\Fsq\Reader
 */
class Fsq_Import_Test extends Test_Case {

	/**
	 * Seeds the taxonomies.
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_all();
	}

	/**
	 * A Foursquare row in Tumon, with overrides.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'fsq_place_id'        => '4b0588a2f964a520a1d922e3',
				'name'                => 'Kracked Egg Tumon',
				'latitude'            => 13.5135,
				'longitude'           => 144.8055,
				'address'             => '1255 Pale San Vitores Rd',
				'country'             => 'GU',
				'tel'                 => '(671) 646-1234',
				'website'             => '',
				'email'               => 'hello@krackedegg.example',
				'facebook_id'         => '123456789',
				'instagram'           => 'krackedegg',
				'fsq_category_labels' => array( 'Dining and Drinking > Restaurant > American Restaurant' ),
				'date_refreshed'      => '2026-06-01',
				'date_closed'         => null,
				'unresolved_flags'    => array(),
			),
			$overrides
		);
	}

	/**
	 * Importer with the bundled maps.
	 */
	private function importer(): Importer {
		return new Importer( new Place_Mapper( Cuisine_Map::from_file() ), '2023-01-01' );
	}

	/**
	 * A published listing as the OSM import would leave it.
	 *
	 * @param string               $name Name.
	 * @param array<string, mixed> $meta Meta.
	 */
	private function listing( string $name, array $meta ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Pending review items of a type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queue( string $type ): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND status = 'pending'", $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows that are closed, flagged, not food, stale or outside Guam are skipped.
	 */
	public function test_skip_reasons() {
		$this->assertNull( Place_Mapper::skip_reason( $this->row(), '2023-01-01' ) );
		$this->assertSame( 'closed', Place_Mapper::skip_reason( $this->row( array( 'date_closed' => '2025-02-01' ) ) ) );
		$this->assertSame( 'flagged by Foursquare', Place_Mapper::skip_reason( $this->row( array( 'unresolved_flags' => array( 'doesnt_exist' ) ) ) ) );
		$this->assertSame( 'not a place to eat or drink', Place_Mapper::skip_reason( $this->row( array( 'fsq_category_labels' => array( 'Retail > Convenience Store' ) ) ) ) );
		$this->assertSame( 'not a place to eat or drink', Place_Mapper::skip_reason( $this->row( array( 'fsq_category_labels' => array( 'Dining and Drinking > Bar > Night Club' ) ) ) ) );
		$this->assertSame( 'not refreshed since 2023-01-01', Place_Mapper::skip_reason( $this->row( array( 'date_refreshed' => '2019-05-05' ) ), '2023-01-01' ) );
		$this->assertSame( 'not in Guam', Place_Mapper::skip_reason( $this->row( array( 'latitude' => 15.18 ) ) ) );
		$this->assertSame( 'no name', Place_Mapper::skip_reason( $this->row( array( 'name' => ' ' ) ) ) );
	}

	/**
	 * Phone in E.164, social links built from IDs, cuisines through the OSM map.
	 */
	public function test_mapping() {
		$mapper = new Place_Mapper( Cuisine_Map::from_file() );
		$record = $mapper->map(
			$this->row(
				array(
					'fsq_category_labels' => array( 'Dining and Drinking > Restaurant > Asian Restaurant > Japanese Restaurant > Sushi Restaurant', 'Dining and Drinking > Cafe, Coffee, and Tea House > Café' ),
				)
			)
		);

		$this->assertSame( '4b0588a2f964a520a1d922e3', $record['fsq_id'] );
		$this->assertSame( '+16716461234', $record['meta'][ Restaurant_Meta::PHONE ] );
		$this->assertSame( 'https://www.facebook.com/123456789', $record['meta'][ Restaurant_Meta::FACEBOOK ] );
		$this->assertSame( 'https://www.instagram.com/krackedegg', $record['meta'][ Restaurant_Meta::INSTAGRAM ] );
		$this->assertSame( 'hello@krackedegg.example', $record['meta'][ Restaurant_Meta::EMAIL ] );
		$this->assertArrayHasKey( 'japanese', $record['cuisine_details'] );
		$this->assertArrayHasKey( 'coffee-and-cafe', $record['cuisine_details'] );
		$this->assertStringStartsWith( 'fsq_os:', $record['cuisine_details']['japanese'] );
	}

	/**
	 * A Facebook page given as the website is a social link, not a website.
	 */
	public function test_facebook_website_is_not_a_website() {
		$mapper = new Place_Mapper( Cuisine_Map::from_file() );
		$record = $mapper->map(
			$this->row(
				array(
					'website'     => 'www.facebook.com/KrackedEggGuam',
					'facebook_id' => '',
				)
			)
		);
		$this->assertSame( '', $record['meta'][ Restaurant_Meta::WEBSITE ] );
		$this->assertSame( 'https://www.facebook.com/KrackedEggGuam', $record['meta'][ Restaurant_Meta::FACEBOOK ] );
		$this->assertSame( '', Restaurant_Meta::sanitize_social_url( 'https://example.com/facebook' ) );
		$this->assertSame( 'https://example.com/', $mapper->map( $this->row( array( 'website' => 'https://example.com/' ) ) )['meta'][ Restaurant_Meta::WEBSITE ] );
	}

	/**
	 * JSON Lines, JSON arrays and CSV all read the same.
	 */
	public function test_reader_formats() {
		$jsonl = Reader::parse( '{"fsq_place_id":"a","name":"A"}' . "\n\n" . '{"fsq_place_id":"b","name":"B"}' . "\n", 'jsonl' );
		$this->assertSame( array( 'a', 'b' ), array_column( $jsonl, 'fsq_place_id' ) );

		// Names with the byte 0x85 inside a UTF-8 character (Å, 々), and CRLF line ends.
		$utf8 = Reader::parse( '{"fsq_place_id":"a","name":"Å Café"}' . "\r\n" . '{"fsq_place_id":"b","name":"人々の店"}' . "\r\n", 'jsonl' );
		$this->assertSame( array( 'Å Café', '人々の店' ), array_column( $utf8, 'name' ) );

		$array = Reader::parse( '[{"fsq_place_id":"a"}]', 'json' );
		$this->assertSame( 'a', $array[0]['fsq_place_id'] );

		$csv = Reader::parse( "fsq_place_id,name,fsq_category_labels\nc,\"Café, Bar\",\"[\"\"Dining and Drinking > Bar\"\"]\"\n", 'csv' );
		$this->assertSame( 'Café, Bar', $csv[0]['name'] );
		$this->assertSame( array( 'Dining and Drinking > Bar' ), Place_Mapper::labels( $csv[0] ) );
	}

	/**
	 * A place that clearly matches a listing is linked and only fills its gaps.
	 */
	public function test_links_existing_listing_and_fills_gaps_only() {
		$id = $this->listing(
			'The Kracked Egg',
			array(
				Restaurant_Meta::LATITUDE     => 13.51355,
				Restaurant_Meta::LONGITUDE    => 144.80548,
				Restaurant_Meta::COORD_SOURCE => 'osm',
				Restaurant_Meta::ADDRESS      => 'Pale San Vitores Road, Tumon',
				Restaurant_Meta::PHONE        => '+16716461234',
			)
		);

		$result = $this->importer()->run( array( $this->row( array( 'website' => 'https://krackedegg.example/' ) ) ) );

		$this->assertSame( 1, $result['linked'] );
		$this->assertSame( 1, $result['counts']['updated'] );
		$this->assertSame( 0, $result['counts']['created'] );
		$this->assertSame( '4b0588a2f964a520a1d922e3', get_post_meta( $id, Restaurant_Meta::FOURSQUARE_ID, true ) );
		$this->assertSame( 'Pale San Vitores Road, Tumon', get_post_meta( $id, Restaurant_Meta::ADDRESS, true ), 'OSM address is kept.' );
		$this->assertSame( 'https://krackedegg.example/', get_post_meta( $id, Restaurant_Meta::WEBSITE, true ) );
		$this->assertSame( 'hello@krackedegg.example', get_post_meta( $id, Restaurant_Meta::EMAIL, true ) );
		$this->assertSame( 'osm', get_post_meta( $id, Restaurant_Meta::COORD_SOURCE, true ) );

		// Running again changes nothing and creates nothing.
		$again = $this->importer()->run( array( $this->row( array( 'website' => 'https://krackedegg.example/' ) ) ) );
		$this->assertSame( 1, $again['counts']['unchanged'] );
		$this->assertSame( 0, $again['counts']['created'] );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * A new place becomes a tagged draft, once.
	 */
	public function test_new_place_becomes_draft_once() {
		$result = $this->importer()->run( array( $this->row() ) );
		$this->assertSame( 1, $result['counts']['created'] );

		$id = Importer::existing_ids()['4b0588a2f964a520a1d922e3'];
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'Kracked Egg Tumon', get_the_title( $id ) );
		$this->assertSame( 'fsq_os', get_post_meta( $id, Restaurant_Meta::COORD_SOURCE, true ) );
		$this->assertSame( '+16716461234', get_post_meta( $id, Restaurant_Meta::PHONE, true ) );
		$this->assertSame( '', get_post_meta( $id, Restaurant_Meta::WEBSITE, true ) );
		$this->assertSame( 'https://www.facebook.com/123456789', get_post_meta( $id, Restaurant_Meta::FACEBOOK, true ) );
		$this->assertSame( array( 'american' ), wp_get_object_terms( $id, Content_Types::TAX_CUISINE, array( 'fields' => 'slugs' ) ) );
		$village = Village_Locator::instance()->slug_for( 13.5135, 144.8055 );
		$this->assertNotNull( $village );
		$this->assertSame( array( $village ), wp_get_object_terms( $id, Content_Types::TAX_VILLAGE, array( 'fields' => 'slugs' ) ) );

		$again = $this->importer()->run( array( $this->row() ) );
		$this->assertSame( 1, $again['counts']['unchanged'] );
		$this->assertSame( 0, $again['counts']['created'] );

		// A listing an administrator trashed is not brought back.
		wp_trash_post( $id );
		$after_trash = $this->importer()->run( array( $this->row() ) );
		$this->assertSame( 0, $after_trash['counts']['created'] );
	}

	/**
	 * A place that only looks like a listing becomes a draft queued as a possible duplicate.
	 */
	public function test_unclear_match_is_queued_for_review() {
		$id = $this->listing(
			'Kracked Egg',
			array(
				Restaurant_Meta::LATITUDE  => 13.5140,
				Restaurant_Meta::LONGITUDE => 144.8060,
				Restaurant_Meta::PHONE     => '+16716469999',
			)
		);

		$result = $this->importer()->run( array( $this->row() ) );

		$this->assertSame( 0, $result['linked'] );
		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertSame( 1, $result['queued'] );
		$this->assertSame( '', get_post_meta( $id, Restaurant_Meta::FOURSQUARE_ID, true ) );
		$items = $this->queue( 'duplicate' );
		$this->assertCount( 1, $items );
		$this->assertSame( 'fsq_os', $items[0]['source'] );
		$this->assertSame( $id, json_decode( $items[0]['payload'], true )['duplicate_of'] );
	}

	/**
	 * A dry run reports but writes nothing.
	 */
	public function test_dry_run_writes_nothing() {
		$result = $this->importer()->run(
			array(
				$this->row(),
				$this->row(
					array(
						'fsq_place_id' => 'zz',
						'date_closed'  => '2024-01-01',
					)
				),
			),
			true
		);
		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertSame( array( 'closed' => 1 ), $result['skipped'] );
		$this->assertNull( $result['run_id'] );
		$this->assertSame( array(), Importer::existing_ids() );
	}
}
