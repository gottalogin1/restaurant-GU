<?php
/**
 * Search log tests: discovery runs new searches first, re-checks weekly and
 * keeps going until the cap.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Discovery\Discoverer;
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Discovery\Search_Log;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seed_Data;
use GuamRestaurants\Core\Seeder;

/**
 * The persisted list of discovery searches.
 *
 * @covers \GuamRestaurants\Core\Discovery\Search_Log
 * @covers \GuamRestaurants\Core\Discovery\Discoverer
 * @covers \GuamRestaurants\Core\Admin\Review_Queue_Screen::add_restaurant
 */
class Search_Log_Test extends Test_Case {

	/**
	 * Text queries sent, in order (one entry per request).
	 *
	 * @var string[]
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
	 * Places client whose pages come from $pages (text query => list of page bodies).
	 *
	 * @param array<string, array<int, array<string, mixed>>> $pages Response bodies.
	 */
	private function client( array $pages = array() ): Places_Client {
		$transport = function ( $url, $args ) use ( $pages ) {
			$body         = json_decode( $args['body'], true );
			$this->sent[] = $body['textQuery'];
			$page         = isset( $body['pageToken'] ) ? (int) $body['pageToken'] : 0;
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( $pages[ $body['textQuery'] ][ $page ] ?? array() ),
			);
		};
		return new Places_Client( 'test-key', new Retrying_Client( $transport, static function () {} ) );
	}

	/**
	 * Discoverer with no waits.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $pages Response bodies.
	 */
	private function discoverer( array $pages = array() ): Discoverer {
		return new Discoverer( $this->client( $pages ), 0, 3, static function () {} );
	}

	/**
	 * A Places result.
	 *
	 * @return array<string, mixed>
	 */
	private static function place( string $id, string $name ): array {
		return array(
			'id'             => $id,
			'displayName'    => array( 'text' => $name ),
			'businessStatus' => 'OPERATIONAL',
		);
	}

	/**
	 * Log row by query text.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function row( string $query ): ?array {
		foreach ( Search_Log::all() as $row ) {
			if ( $row['query'] === $query ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Moves every search's re-check date into the past.
	 */
	private static function make_all_due(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Schema::table( Schema::SEARCH_LOG ) . " SET next_due_at = '2000-01-01 00:00:00' WHERE last_run_at IS NOT NULL" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Planned searches: areas, place types per village and island-wide cuisines; syncing again adds nothing.
	 */
	public function test_sync_plans_searches_once() {
		$areas    = count( Discoverer::areas() );
		$types    = count( Seed_Data::villages() ) * count( Search_Log::TYPE_TEMPLATES );
		$cuisines = count( get_terms( array( 'taxonomy' => Content_Types::TAX_CUISINE, 'hide_empty' => false ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( $areas + $types + $cuisines, Search_Log::sync() );
		$this->assertSame( 0, Search_Log::sync() );
		$this->assertNotNull( self::row( 'restaurants in Yigo, Guam' ) );
		$this->assertNotNull( self::row( 'cafes in Tamuning, Guam' ) );
		$this->assertNotNull( self::row( 'Korean restaurants in Guam' ) );
		$this->assertSame( 0, Search_Log::add( '  RESTAURANTS in yigo,   Guam ', Search_Log::ORIGIN_CUSTOM, 'island' ), 'Case and spacing do not make a new search.' );
	}

	/**
	 * A run does every search once and records it; the next run repeats none until the weekly re-check is due.
	 */
	public function test_runs_each_search_once_then_rechecks_when_due() {
		$pages = array(
			'restaurants in Yigo, Guam' => array( array( 'places' => array( self::place( 'P-NEW', 'Yigo Noodle House' ) ) ) ),
		);

		$first = $this->discoverer( $pages )->run_from_log();
		$total = count( Search_Log::all() );

		$this->assertSame( $total, $first['queries'] );
		$this->assertSame( $total, count( array_unique( $this->sent ) ), 'No search is sent twice in a run.' );
		$this->assertSame( 0, $first['left'] );
		$this->assertSame( 1, $first['counts']['created'] );

		$row = self::row( 'restaurants in Yigo, Guam' );
		$this->assertSame( 1, $row['runs'] );
		$this->assertSame( 1, $row['last_results'] );
		$this->assertSame( 1, $row['last_new'] );
		$this->assertSame( $first['run_id'], $row['last_run_id'] );
		$this->assertEqualsWithDelta( time() + 7 * DAY_IN_SECONDS, strtotime( $row['next_due_at'] . ' UTC' ), 60 );

		$this->sent = array();
		$second     = $this->discoverer( $pages )->run_from_log();
		$this->assertSame( 0, $second['requests'], 'Nothing is due a day later.' );
		$this->assertSame( array(), $this->sent );

		self::make_all_due();
		$third = $this->discoverer( $pages )->run_from_log();
		$this->assertSame( $total, $third['queries'] );
		$this->assertSame( 0, $third['counts']['created'], 'A re-check does not queue the same place again.' );
		$this->assertSame( 2, self::row( 'restaurants in Yigo, Guam' )['runs'] );
		$this->assertSame( 0, self::row( 'restaurants in Yigo, Guam' )['last_new'] );
		$this->assertSame( 1, self::row( 'restaurants in Yigo, Guam' )['total_new'] );
	}

	/**
	 * New searches run before re-checks, and a stopped run picks up where it left off.
	 */
	public function test_new_searches_first_and_resume_after_limit() {
		$this->discoverer()->run_from_log();
		self::make_all_due();
		Search_Log::add( 'ramen in Tumon, Guam', Search_Log::ORIGIN_CUSTOM, 'village', 'tamuning', 'tamuning' );

		$this->sent = array();
		$partial    = $this->discoverer()->run_from_log( 'all', false, 3 );
		$this->assertSame( 3, $partial['requests'] );
		$this->assertSame( 'ramen in Tumon, Guam', $this->sent[0], 'The never-run search goes first.' );
		$this->assertSame( count( Search_Log::all() ) - 3, $partial['left'] );

		$this->sent = array();
		$rest       = $this->discoverer()->run_from_log();
		$this->assertNotContains( 'ramen in Tumon, Guam', $this->sent );
		$this->assertSame( count( Search_Log::all() ) - 3, $rest['queries'] );
		$this->assertSame( 0, $rest['left'] );
	}

	/**
	 * At the end of the free allowance the run stops and the unrun searches stay due.
	 */
	public function test_stops_at_cap_and_keeps_searches_due() {
		Search_Log::sync();
		update_option(
			Budget::MONTHLY_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => array( Pricing::GOOGLE_TEXT_SEARCH_PRO => Pricing::free_per_month( Pricing::GOOGLE_TEXT_SEARCH_PRO ) - 2 ),
				'spend' => array(),
			)
		);

		$result = $this->discoverer()->run_from_log();

		$this->assertTrue( $result['stopped_at_cap'] );
		$this->assertSame( 2, $result['requests'] );
		$this->assertSame( count( Search_Log::all() ) - 2, $result['left'] );
		$this->assertSame( 0, Budget::free_left( Pricing::GOOGLE_TEXT_SEARCH_PRO ) );
	}

	/**
	 * A village whose search fills every page gets narrower cuisine searches.
	 */
	public function test_full_village_search_is_narrowed() {
		$full  = array();
		$count = 0;
		for ( $page = 0; $page < 3; $page++ ) {
			$places = array();
			for ( $i = 0; $i < 20; $i++ ) {
				++$count;
				$places[] = self::place( 'P-' . $count, 'Place ' . $count );
			}
			$full[] = array(
				'places'        => $places,
				'nextPageToken' => (string) ( $page + 1 ),
			);
		}

		$result = $this->discoverer( array( 'restaurants in Tamuning, Guam' => $full ) )->run_from_log();

		$this->assertTrue( self::row( 'restaurants in Tamuning, Guam' )['saturated'] );
		$this->assertFalse( self::row( 'restaurants in Yigo, Guam' )['saturated'] );
		$this->assertGreaterThan( 0, $result['added_queries'] );
		$narrow = self::row( 'Korean restaurants in Tamuning, Guam' );
		$this->assertNotNull( $narrow );
		$this->assertNull( $narrow['last_run_at'], 'Narrowed searches wait for the next run.' );
		$this->assertNull( self::row( 'Korean restaurants in Yigo, Guam' ) );
	}

	/**
	 * Island-wide searches skip names we already list anywhere (they cannot tell branches apart).
	 */
	public function test_island_search_skips_listed_names() {
		$id = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => 'Kracked Egg',
			)
		);
		wp_set_object_terms( $id, 'yigo', Content_Types::TAX_VILLAGE );

		$result = $this->discoverer(
			array(
				'Korean restaurants in Guam' => array(
					array( 'places' => array( self::place( 'P-K1', 'The Kracked Egg' ), self::place( 'P-K2', 'Seoul Kitchen' ) ) ),
				),
			)
		)->run_from_log( 'island' );

		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertSame( 1, self::row( 'Korean restaurants in Guam' )['last_new'] );
	}

	/**
	 * Dry runs leave the log untouched.
	 */
	public function test_dry_run_leaves_log_alone() {
		Search_Log::sync();
		$this->discoverer()->run_from_log( 'all', true, 5 );

		$this->assertSame( 0, array_sum( array_column( Search_Log::all(), 'runs' ) ) );
		$this->assertSame( count( Search_Log::all() ), Search_Log::summary()['never_run'] );
	}

	/**
	 * A failed search stays due with its error, and repeated failures end the run.
	 */
	public function test_failed_search_stays_due() {
		$client = new Places_Client(
			'bad-key',
			new Retrying_Client(
				static function () {
					return array(
						'response' => array( 'code' => 403 ),
						'body'     => '{}',
					);
				},
				static function () {}
			)
		);

		$result = ( new Discoverer( $client, 0, 3, static function () {} ) )->run_from_log();

		$this->assertSame( Discoverer::MAX_FAILURES_IN_A_ROW, $result['requests'], 'A run gives up after a few failures in a row.' );
		$this->assertStringContainsString( 'in a row', end( $result['errors'] ) );
		$failed = array_values( array_filter( Search_Log::all(), static fn( $row ) => null !== $row['last_error'] ) );
		$this->assertCount( Discoverer::MAX_FAILURES_IN_A_ROW, $failed );
		$this->assertNull( $failed[0]['last_run_at'] );
		$this->assertSame( count( Search_Log::all() ), $result['left'], 'Failed searches stay due.' );
	}

	/**
	 * The daily job runs discovery from the log, and is enabled only with a key.
	 */
	public function test_discover_job() {
		$this->assertFalse( Jobs::enabled( Jobs::DISCOVER ) );

		$outcome = Jobs::run(
			Jobs::DISCOVER,
			array(
				'client'       => $this->client(),
				'delay'        => 0,
				'max_requests' => 4,
			)
		);

		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 4, $outcome['result']['requests'] );
		$this->assertStringContainsString( '4 requests', $outcome['summary'] );
		$this->assertSame( 4, count( Search_Log::all() ) - Search_Log::summary()['never_run'] );

		$this->assertSame( 'Skipped: no Google Places key.', Jobs::run( Jobs::DISCOVER )['summary'] );
	}

	/**
	 * "Add as restaurant" turns a candidate into a draft with the place ID and village.
	 */
	public function test_add_restaurant_from_candidate() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$item  = Review_Queue::enqueue(
			'new_place',
			array(
				'place_id' => 'P-NEW',
				'found_by' => array(
					'kind'  => 'village',
					'slug'  => 'yigo',
					'query' => 'restaurants in Yigo, Guam',
				),
			),
			'google'
		);

		$this->assertSame( 0, Review_Queue_Screen::add_restaurant( $item, '  ', $admin ), 'A name is required.' );
		$post_id = Review_Queue_Screen::add_restaurant( $item, 'Yigo Noodle House', $admin );

		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'Yigo Noodle House', get_the_title( $post_id ) );
		$this->assertSame( 'P-NEW', get_post_meta( $post_id, Restaurant_Meta::GOOGLE_PLACE_ID, true ) );
		$this->assertSame( array( 'yigo' ), wp_get_object_terms( $post_id, Content_Types::TAX_VILLAGE, array( 'fields' => 'slugs' ) ) );
		$this->assertSame( 'approved', Review_Queue::get( $item )['status'] );
		$this->assertSame( 0, Review_Queue_Screen::add_restaurant( $item, 'Again', $admin ), 'An item is added once.' );
	}
}
