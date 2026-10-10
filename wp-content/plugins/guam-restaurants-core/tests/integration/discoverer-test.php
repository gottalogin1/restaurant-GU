<?php
/**
 * Discovery tests with mocked Places responses.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Discovery\Discoverer;
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seeder;

/**
 * Finding missing restaurants and queuing them as candidates.
 *
 * @covers \GuamRestaurants\Core\Discovery\Discoverer
 * @covers \GuamRestaurants\Core\Discovery\Places_Client
 */
class Discoverer_Test extends Test_Case {

	/**
	 * Requests sent to the fake transport.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $sent = array();

	/**
	 * Seeds taxonomies.
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_all();
		$this->sent = array();
	}

	/**
	 * Discoverer whose Places responses come from $pages, keyed by text query;
	 * each value is a list of pages.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $pages Response bodies.
	 */
	private function discoverer( array $pages ): Discoverer {
		$transport = function ( $url, $args ) use ( &$pages ) {
			$this->sent[] = array( $url, $args );
			$body         = json_decode( $args['body'], true );
			$page         = isset( $body['pageToken'] ) ? (int) $body['pageToken'] : 0;
			$data         = $pages[ $body['textQuery'] ][ $page ] ?? array();
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $data ),
			);
		};
		$client    = new Places_Client( 'test-key', new Retrying_Client( $transport, static function () {} ) );
		return new Discoverer( $client, 0, 3, static function () {} );
	}

	/**
	 * A Places result.
	 *
	 * @return array<string, mixed>
	 */
	private static function place( string $id, string $name, string $status = 'OPERATIONAL' ): array {
		return array(
			'id'             => $id,
			'displayName'    => array(
				'text'         => $name,
				'languageCode' => 'en',
			),
			'businessStatus' => $status,
		);
	}

	/**
	 * A restaurant in a village.
	 */
	private function restaurant( string $title, string $village ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => $title,
			)
		);
		wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		return $id;
	}

	/**
	 * Review queue rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queue(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Schema::table( Schema::REVIEW_QUEUE ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Village area for Yigo.
	 *
	 * @return array<int, array{kind: string, slug: string, name: string, village: string}>
	 */
	private static function yigo(): array {
		return array(
			array(
				'kind'    => 'village',
				'slug'    => 'yigo',
				'name'    => 'Yigo',
				'village' => 'yigo',
			),
		);
	}

	/**
	 * Areas are the 19 villages plus every mall, with the expected queries.
	 */
	public function test_areas_cover_villages_and_malls() {
		$areas = Discoverer::areas();

		$this->assertCount( 19 + 6, $areas );
		$this->assertCount( 19, Discoverer::areas( 'villages' ) );
		$malls = Discoverer::areas( 'malls' );
		$this->assertCount( 6, $malls );
		$this->assertSame( 'dededo', array_column( $malls, 'village', 'slug' )['micronesia-mall'] );
	}

	/**
	 * Unknown places are queued with the place ID only; listed ones are skipped.
	 */
	public function test_queues_missing_places_only() {
		$this->restaurant( 'The Kracked Egg', 'yigo' );
		$this->restaurant( "Jeff's Pirates Cove", 'talofofo' );

		$result = $this->discoverer(
			array(
				'restaurants in Yigo, Guam' => array(
					array(
						'places'        => array(
							self::place( 'P-LISTED', 'Kracked Egg' ),
							self::place( 'P-NEW', 'Yigo Noodle House' ),
							self::place( 'P-CLOSED', 'Gone Grill', 'CLOSED_PERMANENTLY' ),
						),
						'nextPageToken' => '1',
					),
					array(
						'places' => array( self::place( 'P-BRANCH', 'Jeff’s Pirates Cove' ) ),
					),
				),
			)
		)->run( self::yigo() );

		$this->assertSame( 2, $result['counts']['created'] );
		$this->assertSame( 2, $result['counts']['unchanged'] );
		$this->assertSame( 2, $result['requests'] );

		$queue = $this->queue();
		$this->assertCount( 2, $queue );
		$this->assertSame( array( 'new_place', 'new_place' ), array_column( $queue, 'type' ) );
		$this->assertSame( array( 'google', 'google' ), array_column( $queue, 'source' ) );
		$this->assertSame( array( null, null ), array_column( $queue, 'restaurant_id' ) );
		$this->assertSame( array( '0.600', '0.300' ), array_column( $queue, 'confidence' ), 'A same-name restaurant elsewhere lowers confidence.' );

		$payload = json_decode( $queue[0]['payload'], true );
		$this->assertSame(
			array(
				'place_id'            => 'P-NEW',
				'found_by'            => array(
					'kind'  => 'village',
					'slug'  => 'yigo',
					'query' => 'restaurants in Yigo, Guam',
				),
				'same_name_elsewhere' => false,
				'google_maps_url'     => 'https://www.google.com/maps/place/?q=place_id:P-NEW',
			),
			$payload
		);
		$this->assertStringNotContainsString( 'Noodle', $queue[0]['payload'], 'Google names are never stored.' );
	}

	/**
	 * Candidates never become restaurant posts, and a rerun adds no duplicates.
	 */
	public function test_never_creates_posts_and_rerun_adds_nothing() {
		$pages = array(
			'restaurants in Yigo, Guam' => array( array( 'places' => array( self::place( 'P-NEW', 'Yigo Noodle House' ) ) ) ),
		);

		$this->discoverer( $pages )->run( self::yigo() );
		$second = $this->discoverer( $pages )->run( self::yigo() );

		$this->assertSame( 0, $second['counts']['created'] );
		$this->assertCount( 1, $this->queue() );
		$this->assertSame( array(), get_posts( array( 'post_type' => Content_Types::POST_TYPE, 'post_status' => 'any' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A place ID already stored on a restaurant is not a candidate.
	 */
	public function test_stored_place_id_is_known() {
		$id = $this->restaurant( 'Something Else', 'dededo' );
		update_post_meta( $id, Restaurant_Meta::GOOGLE_PLACE_ID, 'P-KNOWN' );

		$result = $this->discoverer(
			array( 'restaurants in Yigo, Guam' => array( array( 'places' => array( self::place( 'P-KNOWN', 'Different Name' ) ) ) ) )
		)->run( self::yigo() );

		$this->assertSame( 0, $result['counts']['created'] );
	}

	/**
	 * Dry runs call the API but write nothing.
	 */
	public function test_dry_run_writes_nothing() {
		$result = $this->discoverer(
			array( 'restaurants in Yigo, Guam' => array( array( 'places' => array( self::place( 'P-NEW', 'Yigo Noodle House' ) ) ) ) )
		)->run( self::yigo(), true );

		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertNull( $result['run_id'] );
		$this->assertSame( array(), $this->queue() );
	}

	/**
	 * Requests use the key header, a minimal field mask and Guam's box.
	 */
	public function test_request_shape() {
		$this->discoverer( array() )->run( self::yigo() );

		$this->assertCount( 1, $this->sent );
		list( $url, $args ) = $this->sent[0];
		$body               = json_decode( $args['body'], true );

		$this->assertSame( Places_Client::URL, $url );
		$this->assertSame( 'test-key', $args['headers']['X-Goog-Api-Key'] );
		$this->assertSame( 'places.id,places.displayName,places.businessStatus,nextPageToken', $args['headers']['X-Goog-FieldMask'] );
		$this->assertSame( 'restaurants in Yigo, Guam', $body['textQuery'] );
		$this->assertSame( 13.18, $body['locationRestriction']['rectangle']['low']['latitude'] );
		$this->assertSame( 145.01, $body['locationRestriction']['rectangle']['high']['longitude'] );
	}

	/**
	 * A failed query is counted and the run records it.
	 */
	public function test_failed_query_is_counted() {
		global $wpdb;

		$client     = new Places_Client(
			'bad-key',
			new Retrying_Client(
				static function () {
					return array(
						'response' => array( 'code' => 403 ),
						'body'     => '{"error":{"status":"PERMISSION_DENIED"}}',
					);
				},
				static function () {}
			)
		);
		$discoverer = new Discoverer( $client, 0, 3, static function () {} );

		$result = $discoverer->run( self::yigo() );

		$this->assertSame( 1, $result['counts']['failed'] );
		$this->assertStringContainsString( 'HTTP 403', $result['errors'][0] );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT failed FROM ' . Schema::table( Schema::INGESTION_RUNS ) . ' WHERE id = %d', $result['run_id'] ) ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Discovery counts its Text Search requests against the free allowance and stops at the end of it.
	 */
	public function test_stops_at_end_of_free_allowance() {
		update_option(
			\GuamRestaurants\Core\Ratings\Budget::MONTHLY_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => array( \GuamRestaurants\Core\Ratings\Pricing::GOOGLE_TEXT_SEARCH_PRO => \GuamRestaurants\Core\Ratings\Pricing::free_per_month( \GuamRestaurants\Core\Ratings\Pricing::GOOGLE_TEXT_SEARCH_PRO ) ),
				'spend' => array(),
			)
		);

		$result = $this->discoverer( array() )->run( self::yigo() );

		$this->assertTrue( $result['stopped_at_cap'] );
		$this->assertSame( array(), $this->sent );
	}
}
