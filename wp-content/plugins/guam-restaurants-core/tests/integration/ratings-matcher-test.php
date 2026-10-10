<?php
/**
 * Matching tests with mocked API responses.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Foursquare_Provider;
use GuamRestaurants\Core\Ratings\Google_Provider;
use GuamRestaurants\Core\Ratings\Matcher;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Ratings\Yelp_Provider;
use GuamRestaurants\Core\Restaurant_Meta;

/**
 * Matching restaurants to Google, Yelp and Foursquare records.
 *
 * @covers \GuamRestaurants\Core\Ratings\Matcher
 * @covers \GuamRestaurants\Core\Ratings\Google_Provider
 * @covers \GuamRestaurants\Core\Ratings\Yelp_Provider
 * @covers \GuamRestaurants\Core\Ratings\Foursquare_Provider
 */
class Ratings_Matcher_Test extends Test_Case {

	/**
	 * A restaurant at the fixture point (or the given one).
	 */
	private function restaurant( string $title, ?float $lat = Ratings_Fixtures::LAT, ?float $lng = Ratings_Fixtures::LNG ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => $title,
			)
		);
		if ( null !== $lat ) {
			update_post_meta( $id, Restaurant_Meta::LATITUDE, $lat );
			update_post_meta( $id, Restaurant_Meta::LONGITUDE, $lng );
		}
		return $id;
	}

	/**
	 * Google matcher answering every search with $places.
	 *
	 * @param array<int, array{0: string, 1: string, 2: float, 3: float}> $places Candidates.
	 * @return array{0: Matcher, 1: Fake_Api}
	 */
	private function google( array $places, float $cap = 100.0 ): array {
		$api = new Fake_Api( array( '#places:searchText#' => Ratings_Fixtures::google_search( $places ) ) );
		return array( new Matcher( new Google_Provider( 'test-key', $api->client() ), new Budget( $cap ), 0 ), $api );
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
	 * Name similarity ignores case, punctuation and "the", and rewards shared words.
	 */
	public function test_name_similarity() {
		$this->assertSame( 1.0, Matcher::name_similarity( 'The Kracked Egg', 'kracked egg' ) );
		$this->assertGreaterThan( 0.85, Matcher::name_similarity( 'Kracked Egg', 'The Kracked Egg Tumon' ) );
		$this->assertGreaterThan( 0.8, Matcher::name_similarity( "King's", 'Kings Restaurant' ) );
		$this->assertLessThan( 0.5, Matcher::name_similarity( 'Thai House', 'House of Brutus' ) );
		$this->assertSame( 0.0, Matcher::name_similarity( '', 'Anything' ) );
	}

	/**
	 * Distance is in metres; candidates past 150 m get no score.
	 */
	public function test_distance_and_radius() {
		$d = Matcher::distance_m( 13.5150, 144.8070, 13.5160, 144.8070 );
		$this->assertEqualsWithDelta( 111, $d, 2 );

		$far = array(
			'id'   => 'x',
			'name' => 'Test Grill',
			'lat'  => 13.5170,
			'lng'  => 144.8070,
		);
		$this->assertNull( Matcher::score( 'Test Grill', 13.5150, 144.8070, $far ) );

		$near = array( 'lat' => 13.5150 ) + $far;
		$this->assertSame( 1.0, Matcher::score( 'Test Grill', 13.5150, 144.8070, $near )['confidence'] );
	}

	/**
	 * A clear match stores the place ID and the confidence, and the search uses a field mask and a 150 m bias.
	 */
	public function test_clear_match_is_stored() {
		$id                    = $this->restaurant( 'Test Grill' );
		list( $matcher, $api ) = $this->google( array( array( 'ChIJgrill', 'Test Grill', 13.5151, 144.8070 ) ) );

		$result = $matcher->run( array( $id ) );

		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertSame( 'ChIJgrill', get_post_meta( $id, Restaurant_Meta::GOOGLE_PLACE_ID, true ) );
		$this->assertGreaterThan( 0.9, (float) get_post_meta( $id, Restaurant_Meta::GOOGLE_MATCH_CONFIDENCE, true ) );
		$this->assertSame( array(), $this->queue() );

		$args = $api->requests[0][1];
		$body = json_decode( $args['body'], true );
		$this->assertSame( Google_Provider::SEARCH_FIELD_MASK, $args['headers']['X-Goog-FieldMask'] );
		$this->assertSame( 'Test Grill', $body['textQuery'] );
		$this->assertSame( 150.0, (float) $body['locationBias']['circle']['radius'] );
		// Inside the free allowance: nothing billed, one call counted.
		$this->assertSame( 0.0, $result['spent'] );
		$this->assertSame( 1, Budget::usage()['calls'][ Pricing::GOOGLE_TEXT_SEARCH_PRO ] );
	}

	/**
	 * A weak match goes to the review queue with the place ID and our scores, never the Google name.
	 */
	public function test_low_confidence_goes_to_review_queue() {
		$id              = $this->restaurant( 'Test Grill' );
		list( $matcher ) = $this->google( array( array( 'ChIJother', 'Test Grill & Bar', 13.5160, 144.8070 ) ) );

		$result = $matcher->run( array( $id ) );
		$queue  = $this->queue();

		$this->assertSame( 1, $result['counts']['queued'] );
		$this->assertSame( '', get_post_meta( $id, Restaurant_Meta::GOOGLE_PLACE_ID, true ) );
		$this->assertCount( 1, $queue );
		$this->assertSame( 'match_conflict', $queue[0]['type'] );
		$this->assertSame( 'google', $queue[0]['source'] );
		$this->assertSame( (string) $id, $queue[0]['restaurant_id'] );
		$this->assertLessThan( Matcher::AUTO_ACCEPT, (float) $queue[0]['confidence'] );

		$payload = json_decode( $queue[0]['payload'], true );
		$this->assertSame( 'ChIJother', $payload['external_id'] );
		$this->assertStringNotContainsString( 'Bar', $queue[0]['payload'] );
	}

	/**
	 * Two close, similar candidates are ambiguous and go to review even when both score high.
	 */
	public function test_ambiguous_match_goes_to_review() {
		$id              = $this->restaurant( 'Test Grill' );
		list( $matcher ) = $this->google(
			array(
				array( 'ChIJa', 'Test Grill', 13.5150, 144.8070 ),
				array( 'ChIJb', 'Test Grill', 13.5151, 144.8070 ),
			)
		);

		$result  = $matcher->run( array( $id ) );
		$payload = json_decode( $this->queue()[0]['payload'], true );

		$this->assertSame( 1, $result['counts']['queued'] );
		$this->assertSame( 'ambiguous', $payload['reason'] );
		$this->assertSame( 'ChIJb', $payload['runner_up_id'] );
	}

	/**
	 * Candidates past 150 m and unrelated names are not matches.
	 */
	public function test_no_match() {
		$id              = $this->restaurant( 'Test Grill' );
		list( $matcher ) = $this->google(
			array(
				array( 'ChIJfar', 'Test Grill', 13.5200, 144.8070 ),
				array( 'ChIJnear', 'Zebra Sushi', 13.5150, 144.8070 ),
			)
		);

		$result = $matcher->run( array( $id ) );

		$this->assertSame( 1, $result['counts']['no_match'] );
		$this->assertSame( array(), $this->queue() );
	}

	/**
	 * With the default $0 cap, the run stops when the free allowance runs out.
	 */
	public function test_stops_at_end_of_free_allowance() {
		$this->set_usage( Pricing::GOOGLE_TEXT_SEARCH_PRO, Pricing::free_per_month( Pricing::GOOGLE_TEXT_SEARCH_PRO ) - 2 );
		$ids                   = array( $this->restaurant( 'A' ), $this->restaurant( 'B' ), $this->restaurant( 'C' ) );
		list( $matcher, $api ) = $this->google( array(), 0.0 );

		$result = $matcher->run( $ids );

		$this->assertTrue( $result['stopped_at_cap'] );
		$this->assertCount( 2, $api->requests );
		$this->assertSame( 0.0, $result['spent'] );
	}

	/**
	 * A paid cap allows paid calls up to the cap.
	 */
	public function test_paid_cap_allows_paid_calls_up_to_cap() {
		$this->set_usage( Pricing::GOOGLE_TEXT_SEARCH_PRO, Pricing::free_per_month( Pricing::GOOGLE_TEXT_SEARCH_PRO ) );
		$ids                   = array( $this->restaurant( 'A' ), $this->restaurant( 'B' ), $this->restaurant( 'C' ) );
		list( $matcher, $api ) = $this->google( array(), Pricing::price( Pricing::GOOGLE_TEXT_SEARCH_PRO ) * 2 );

		$result = $matcher->run( $ids );

		$this->assertTrue( $result['stopped_at_cap'] );
		$this->assertCount( 2, $api->requests );
	}

	/**
	 * The estimate splits free and billable requests.
	 */
	public function test_estimate() {
		$provider = new Google_Provider( 'k', ( new Fake_Api( array() ) )->client() );
		$this->assertSame(
			array(
				'calls' => 240,
				'free'  => 240,
				'cost'  => 0.0,
			),
			Matcher::estimate( $provider, 240 )
		);

		$this->set_usage( Pricing::GOOGLE_TEXT_SEARCH_PRO, Pricing::free_per_month( Pricing::GOOGLE_TEXT_SEARCH_PRO ) - 40 );
		$estimate = Matcher::estimate( $provider, 240 );
		$this->assertSame( 40, $estimate['free'] );
		$this->assertEqualsWithDelta( 200 * Pricing::price( Pricing::GOOGLE_TEXT_SEARCH_PRO ), $estimate['cost'], 1e-9 );
	}

	/**
	 * Sets this month's call count for a SKU.
	 */
	private function set_usage( string $sku, int $calls ): void {
		update_option(
			Budget::MONTHLY_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => array( $sku => $calls ),
				'spend' => array(),
			)
		);
	}

	/**
	 * Dry runs write nothing.
	 */
	public function test_dry_run_writes_nothing() {
		$id              = $this->restaurant( 'Test Grill' );
		list( $matcher ) = $this->google( array( array( 'ChIJgrill', 'Test Grill', 13.5150, 144.8070 ) ) );

		$result = $matcher->run( array( $id ), true );

		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertSame( '', get_post_meta( $id, Restaurant_Meta::GOOGLE_PLACE_ID, true ) );
		$this->assertNull( $result['run_id'] );
	}

	/**
	 * Pending skips restaurants with an ID, a pending review or no coordinates.
	 */
	public function test_pending_skips_done_queued_and_unlocated() {
		$todo    = $this->restaurant( 'Todo' );
		$matched = $this->restaurant( 'Matched' );
		update_post_meta( $matched, Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJdone' );
		$this->restaurant( 'Nowhere', null, null );
		$queued          = $this->restaurant( 'Queued' );
		list( $matcher ) = $this->google( array( array( 'ChIJq', 'Queued Place Elsewhere', 13.5155, 144.8070 ) ) );
		$matcher->run( array( $queued ) );

		$provider = new Google_Provider( 'k', ( new Fake_Api( array() ) )->client() );

		$this->assertSame( array( $todo ), Matcher::pending( $provider ) );
		$this->assertSame( array( $todo, $matched ), Matcher::pending( $provider, true ) );
	}

	/**
	 * Yelp and Foursquare searches parse their own response shapes and send their own auth headers.
	 */
	public function test_yelp_and_foursquare_matching() {
		$id = $this->restaurant( 'Test Grill' );

		$yelp_api = new Fake_Api( array( '#api\.yelp\.com/v3/businesses/search#' => Ratings_Fixtures::yelp_search( array( array( 'test-grill-tamuning', 'Test Grill', 13.5150, 144.8071 ) ) ) ) );
		( new Matcher( new Yelp_Provider( 'yelp-key', $yelp_api->client(), 8, '' ), new Budget( 1 ), 0 ) )->run( array( $id ) );

		$fsq_api = new Fake_Api( array( '#places-api\.foursquare\.com/places/search#' => Ratings_Fixtures::foursquare_search( array( array( '4b0588f1f964a520', 'The Test Grill', 13.5150, 144.8070 ) ) ) ) );
		( new Matcher( new Foursquare_Provider( 'fsq-key', $fsq_api->client() ), new Budget( 1 ), 0 ) )->run( array( $id ) );

		$this->assertSame( 'test-grill-tamuning', get_post_meta( $id, Restaurant_Meta::YELP_ID, true ) );
		$this->assertSame( '4b0588f1f964a520', get_post_meta( $id, Restaurant_Meta::FOURSQUARE_ID, true ) );

		$this->assertSame( 'Bearer yelp-key', $yelp_api->requests[0][1]['headers']['Authorization'] );
		$this->assertStringContainsString( 'radius=150', $yelp_api->requests[0][0] );
		$this->assertSame( 'Bearer fsq-key', $fsq_api->requests[0][1]['headers']['Authorization'] );
		$this->assertStringContainsString( 'fields=' . Foursquare_Provider::SEARCH_FIELDS, $fsq_api->requests[0][0] );
	}
}
