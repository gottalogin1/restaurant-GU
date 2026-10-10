<?php
/**
 * Ratings service, refresh, REST endpoint and display tests with mocked API responses.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Display;
use GuamRestaurants\Core\Ratings\Foursquare_Provider;
use GuamRestaurants\Core\Ratings\Google_Provider;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Ratings\Ratings;
use GuamRestaurants\Core\Ratings\Refresher;
use GuamRestaurants\Core\Ratings\Rest_Controller;
use GuamRestaurants\Core\Ratings\Yelp_Provider;
use GuamRestaurants\Core\Restaurant_Meta;
use WP_REST_Request;

use function GuamRestaurants\Core\get_ratings;

/**
 * Showing ratings within each source's storage and display rules.
 *
 * @covers \GuamRestaurants\Core\Ratings\Ratings
 * @covers \GuamRestaurants\Core\Ratings\Refresher
 * @covers \GuamRestaurants\Core\Ratings\Rest_Controller
 * @covers \GuamRestaurants\Core\Ratings\Display
 * @covers \GuamRestaurants\Core\Ratings\Budget
 * @covers \GuamRestaurants\Core\Ratings\Google_Provider
 * @covers \GuamRestaurants\Core\Ratings\Yelp_Provider
 * @covers \GuamRestaurants\Core\Ratings\Foursquare_Provider
 */
class Ratings_Display_Test extends Test_Case {

	public const YELP_ASSETS = 'https://cdn.example.com/yelp';

	/**
	 * Fake API shared by the providers.
	 *
	 * @var Fake_Api
	 */
	private Fake_Api $api;

	/**
	 * Restaurant with IDs for all three sources.
	 *
	 * @var int
	 */
	private int $restaurant;

	/**
	 * Sets up providers on a fake API and a matched restaurant.
	 */
	public function set_up() {
		parent::set_up();

		$this->api = new Fake_Api(
			array(
				'#places\.googleapis\.com/v1/places/#'  => Ratings_Fixtures::google_details(),
				'#api\.yelp\.com/v3/businesses/[^/?]+/reviews#' => Ratings_Fixtures::yelp_reviews(),
				'#api\.yelp\.com/v3/businesses/#'       => Ratings_Fixtures::yelp_business(),
				'#places-api\.foursquare\.com/places/[^/?]+/tips#' => Ratings_Fixtures::foursquare_tips(),
				'#places-api\.foursquare\.com/places/#' => Ratings_Fixtures::foursquare_details(),
			)
		);
		$this->use_providers( true );

		$this->restaurant = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => 'Test Grill',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->restaurant, Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJgrill' );
		update_post_meta( $this->restaurant, Restaurant_Meta::YELP_ID, 'test-grill-tamuning' );
		update_post_meta( $this->restaurant, Restaurant_Meta::FOURSQUARE_ID, '4b0588f1f964a520' );

		delete_option( Budget::MONTHLY_OPTION );
		Refresher::unschedule();
	}

	/**
	 * Removes a paid cap set by a test.
	 */
	public function tear_down() {
		putenv( 'GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		parent::tear_down();
	}

	/**
	 * Allows paid page-view calls (Foursquare ratings have no free tier).
	 */
	private function allow_paid_live_calls(): void {
		putenv( 'GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD=5' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
	}

	/**
	 * Sets this month's call counts.
	 *
	 * @param array<string, int> $calls Calls by SKU.
	 */
	private function set_usage( array $calls ): void {
		update_option(
			Budget::MONTHLY_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => $calls,
				'spend' => array(),
			)
		);
	}

	/**
	 * Installs fake-backed providers through the providers filter.
	 *
	 * @param bool $with_yelp Include Yelp.
	 */
	private function use_providers( bool $with_yelp ): void {
		$client = $this->api->client();
		add_filter(
			Ratings::PROVIDERS_FILTER,
			static function () use ( $client, $with_yelp ) {
				$providers = array(
					'google'     => new Google_Provider( 'g-key', $client ),
					'foursquare' => new Foursquare_Provider( 'f-key', $client ),
				);
				if ( $with_yelp ) {
					$providers['yelp'] = new Yelp_Provider( 'y-key', $client, 8, self::YELP_ASSETS );
				}
				return $providers;
			}
		);
	}

	/**
	 * Ratings keyed by source.
	 *
	 * @param array<int, array<string, mixed>> $ratings Ratings.
	 * @return array<string, array<string, mixed>>
	 */
	private static function by_source( array $ratings ): array {
		return array_column( $ratings, null, 'source' );
	}

	/**
	 * Google: rating, count, link, attribution and reviews exactly as returned, fetched live, nothing stored.
	 */
	public function test_google_is_live_and_unaltered() {
		$google = get_ratings( $this->restaurant, array( 'sources' => array( 'google' ) ) )[0];

		$this->assertSame( 'google', $google['source'] );
		$this->assertSame( 'Google Maps', $google['label'] );
		$this->assertSame( 4.3, $google['rating'] );
		$this->assertSame( 812, $google['review_count'] );
		$this->assertSame( 'https://maps.google.com/?cid=1111', $google['url'] );
		$this->assertSame( 'Google Maps', $google['attribution']['text'] );
		$this->assertSame( 'Example Data Co', $google['attribution']['providers'][0]['name'] );
		$this->assertSame( 0, $google['cached_until'] );

		// The 1-star review comes back first and unchanged: no sentiment filtering or reordering.
		$this->assertCount( 2, $google['reviews'] );
		$this->assertSame( "Terrible service.\nCold food, would NOT return!!", $google['reviews'][0]['text'] );
		$this->assertSame( 'Pat Example', $google['reviews'][0]['author'] );
		$this->assertSame( 'https://www.google.com/maps/contrib/123', $google['reviews'][0]['author_url'] );
		$this->assertStringContainsString( 'report', $google['reviews'][0]['flag_url'] );
		$this->assertSame( 'Best kelaguen on the island 🌴', $google['reviews'][1]['text'] );
		$this->assertNotSame( '', $google['reviews_notice'] );

		// Field mask includes reviews only when asked, and every view is a new request.
		$this->assertSame( Google_Provider::DETAILS_FIELD_MASK . ',reviews', $this->api->requests[0][1]['headers']['X-Goog-FieldMask'] );
		get_ratings(
			$this->restaurant,
			array(
				'sources' => array( 'google' ),
				'reviews' => false,
			)
		);
		$this->assertCount( 2, $this->api->requests );
		$this->assertSame( Google_Provider::DETAILS_FIELD_MASK, $this->api->requests[1][1]['headers']['X-Goog-FieldMask'] );
		$this->assertFalse( get_transient( Ratings::transient_key( 'google', $this->restaurant ) ) );
	}

	/**
	 * Foursquare keeps its 10-point scale and "Powered by Foursquare".
	 */
	public function test_foursquare_scale_and_attribution() {
		$this->allow_paid_live_calls();
		$fsq = get_ratings( $this->restaurant, array( 'sources' => 'foursquare' ) )[0];

		$this->assertSame( 8.6, $fsq['rating'] );
		$this->assertSame( 10, $fsq['rating_scale'] );
		$this->assertSame( 140, $fsq['review_count'] );
		$this->assertSame( 'Powered by Foursquare', $fsq['attribution']['text'] );
		$this->assertSame( 'https://foursquare.com/v/4b0588f1f964a520', $fsq['url'] );
		$this->assertSame( 'Try the red rice.', $fsq['reviews'][0]['text'] );
		$this->assertSame( Foursquare_Provider::API_VERSION, $this->api->requests[0][1]['headers']['X-Places-Api-Version'] );
		$this->assertStringContainsString( 'fields=' . Foursquare_Provider::DETAILS_FIELDS, $this->api->requests[0][0] );
	}

	/**
	 * Default sources are side by side, Google then Foursquare, with no combined score.
	 */
	public function test_sources_side_by_side_without_combined_score() {
		$this->allow_paid_live_calls();
		$ratings = get_ratings( $this->restaurant, array( 'sources' => '' ) );

		$this->assertSame( array( 'google', 'foursquare' ), array_column( $ratings, 'source' ) );
		foreach ( $ratings as $rating ) {
			$this->assertArrayNotHasKey( 'average', $rating );
			$this->assertArrayNotHasKey( 'combined', $rating );
		}
	}

	/**
	 * Yelp is dropped from any list that mixes it with other sources.
	 */
	public function test_yelp_never_mixed() {
		$this->assertSame( array( 'google', 'foursquare' ), Ratings::sanitize_sources( 'google,yelp,foursquare' ) );
		$this->assertSame( array( 'yelp' ), Ratings::sanitize_sources( 'yelp' ) );
		$this->assertSame( array( 'google' ), Ratings::sanitize_sources( array( 'google', 'nonsense', 'google' ) ) );
	}

	/**
	 * Yelp on a page view reads only the cache; a miss schedules a refresh and calls nothing.
	 */
	public function test_yelp_page_view_never_calls_api() {
		$this->assertSame( array(), get_ratings( $this->restaurant, array( 'sources' => 'yelp' ) ) );
		$this->assertSame( array(), $this->api->requests );
		$this->assertNotFalse( wp_next_scheduled( Refresher::SINGLE_HOOK, array( $this->restaurant, 'yelp' ) ) );
	}

	/**
	 * The scheduled refresh caches Yelp for at most 24 hours, with Yelp's URL and star image as given.
	 */
	public function test_refresh_caches_yelp_for_24_hours() {
		$result = Refresher::run( Ratings::providers(), new Budget( 10 ) );

		$this->assertSame( 1, $result['refreshed'] );
		$this->assertEqualsWithDelta( 2 * Pricing::price( Pricing::YELP_CALL ), $result['spent'], 1e-9 );

		$timeout = (int) get_option( '_transient_timeout_' . Ratings::transient_key( 'yelp', $this->restaurant ) );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $timeout );
		$this->assertGreaterThan( time() + DAY_IN_SECONDS - 60, $timeout );

		$requests = count( $this->api->requests );
		$yelp     = get_ratings( $this->restaurant, array( 'sources' => 'yelp' ) )[0];
		$this->assertCount( $requests, $this->api->requests, 'A page view must not call Yelp.' );

		$this->assertSame( 3.5, $yelp['rating'] );
		$this->assertSame( 57, $yelp['review_count'] );
		$this->assertSame( Ratings_Fixtures::yelp_business()['url'], $yelp['url'] );
		$this->assertSame( self::YELP_ASSETS . '/regular_3_half.png', $yelp['stars_image'] );
		$this->assertSame( self::YELP_ASSETS . '/yelp_logo.png', $yelp['attribution']['logo_url'] );
		$this->assertSame( 'Waited an hour. Not worth it...', $yelp['reviews'][0]['text'] );
		$this->assertSame( 'Lee E.', $yelp['reviews'][0]['author'] );
	}

	/**
	 * A cached Yelp rating past its 24-hour limit is deleted, not shown.
	 */
	public function test_expired_yelp_cache_is_not_served() {
		$key                   = Ratings::transient_key( 'yelp', $this->restaurant );
		$stale                 = ( new Yelp_Provider( 'y', $this->api->client(), 8, self::YELP_ASSETS ) )->fetch( 'test-grill-tamuning', false );
		$stale['cached_until'] = time() - 1;
		set_transient( $key, $stale, HOUR_IN_SECONDS );

		$this->assertSame( array(), get_ratings( $this->restaurant, array( 'sources' => 'yelp' ) ) );
		$this->assertFalse( get_transient( $key ) );
	}

	/**
	 * Yelp is not shown without Yelp's own star images.
	 */
	public function test_yelp_hidden_without_official_stars() {
		$provider = new Yelp_Provider( 'y', $this->api->client(), 8, '' );
		$rating   = $provider->fetch( 'test-grill-tamuning', false );
		$this->assertSame( '', $rating['stars_image'] );

		$rating['cached_until'] = time() + HOUR_IN_SECONDS;
		set_transient( Ratings::transient_key( 'yelp', $this->restaurant ), $rating, HOUR_IN_SECONDS );
		$this->assertSame( array(), get_ratings( $this->restaurant, array( 'sources' => 'yelp' ) ) );
	}

	/**
	 * Star images step by half stars.
	 */
	public function test_yelp_star_image_names() {
		$provider = new Yelp_Provider( 'y', null, 8, 'https://x.test/y/' );
		$this->assertSame( 'https://x.test/y/regular_0.png', $provider->stars_image( 0.0 ) );
		$this->assertSame( 'https://x.test/y/regular_4_half.png', $provider->stars_image( 4.5 ) );
		$this->assertSame( 'https://x.test/y/regular_5.png', $provider->stars_image( 5.0 ) );
	}

	/**
	 * When Yelp is no longer configured, the refresh deletes its cache (Yelp API Terms §4).
	 */
	public function test_refresh_purges_yelp_when_not_configured() {
		Refresher::run( Ratings::providers(), new Budget( 10 ) );
		$this->assertNotFalse( get_transient( Ratings::transient_key( 'yelp', $this->restaurant ) ) );

		remove_all_filters( Ratings::PROVIDERS_FILTER );
		$this->use_providers( false );
		Refresher::run( Ratings::providers(), new Budget( 10 ) );

		$this->assertFalse( get_transient( Ratings::transient_key( 'yelp', $this->restaurant ) ) );
	}

	/**
	 * The refresh stops at the run cap; Google and Foursquare are never refreshed or cached.
	 */
	public function test_refresh_cap_and_live_sources_untouched() {
		$result = Refresher::run( Ratings::providers(), new Budget( 0 ) );

		$this->assertTrue( $result['stopped_at_cap'] );
		$this->assertSame( array(), $this->api->requests );
		$this->assertSame( array( 'yelp' => 1 ), Refresher::planned( Ratings::providers() ) );
	}

	/**
	 * By default only free calls are made: Google within its free allowance,
	 * Foursquare (no free tier for ratings) not at all.
	 */
	public function test_default_is_free_calls_only() {
		$ratings = get_ratings( $this->restaurant );

		$this->assertSame( array( 'google' ), array_column( $ratings, 'source' ) );
		$this->assertSame( array(), $this->api->requests_to( '#foursquare#' ) );
		$usage = Budget::usage();
		$this->assertSame( 1, $usage['calls'][ Pricing::GOOGLE_DETAILS_ATMOSPHERE ] );
		$this->assertSame( 0.0, array_sum( $usage['spend'] ) );
	}

	/**
	 * When the free review allowance is used, Google is shown without reviews (cheaper SKU, its own allowance).
	 */
	public function test_google_falls_back_to_rating_without_reviews() {
		$this->set_usage( array( Pricing::GOOGLE_DETAILS_ATMOSPHERE => Pricing::free_per_month( Pricing::GOOGLE_DETAILS_ATMOSPHERE ) ) );

		$google = get_ratings( $this->restaurant, array( 'sources' => 'google' ) )[0];

		$this->assertSame( 4.3, $google['rating'] );
		$this->assertSame( array(), $google['reviews'] );
		$this->assertSame( Google_Provider::DETAILS_FIELD_MASK, $this->api->requests[0][1]['headers']['X-Goog-FieldMask'] );
	}

	/**
	 * When every free allowance is used, live sources are hidden and no request is made.
	 */
	public function test_hidden_when_free_allowance_used() {
		$this->set_usage(
			array(
				Pricing::GOOGLE_DETAILS_ATMOSPHERE => Pricing::free_per_month( Pricing::GOOGLE_DETAILS_ATMOSPHERE ),
				Pricing::GOOGLE_DETAILS_ENTERPRISE => Pricing::free_per_month( Pricing::GOOGLE_DETAILS_ENTERPRISE ),
			)
		);

		$this->assertSame( array(), get_ratings( $this->restaurant ) );
		$this->assertSame( array(), $this->api->requests );
	}

	/**
	 * A paid monthly cap records spend per source and stops at the cap.
	 */
	public function test_paid_monthly_cap() {
		$this->allow_paid_live_calls();
		get_ratings( $this->restaurant );

		$usage = Budget::usage();
		$this->assertSame( 0.0, (float) $usage['spend']['google'] );
		$this->assertEqualsWithDelta( 2 * Pricing::price( Pricing::FOURSQUARE_PREMIUM ), $usage['spend']['foursquare'], 1e-9 );

		$usage['spend']['foursquare'] = 5.0;
		update_option( Budget::MONTHLY_OPTION, $usage );
		$this->assertSame( array( 'google' ), array_column( get_ratings( $this->restaurant ), 'source' ) );
	}

	/**
	 * A failing source is left out; the others still show.
	 */
	public function test_failed_source_is_skipped() {
		$this->allow_paid_live_calls();
		$api    = new Fake_Api( array( '#places-api\.foursquare\.com#' => Ratings_Fixtures::foursquare_details() ) );
		$client = $api->client();
		remove_all_filters( Ratings::PROVIDERS_FILTER );
		add_filter(
			Ratings::PROVIDERS_FILTER,
			static function () use ( $client ) {
				return array(
					'google'     => new Google_Provider( 'g', $client ),
					'foursquare' => new Foursquare_Provider( 'f', $client ),
				);
			}
		);

		$this->assertSame( array( 'foursquare' ), array_column( get_ratings( $this->restaurant, array( 'reviews' => false ) ), 'source' ) );
	}

	/**
	 * The REST endpoint returns ratings with no-store, and hides drafts and non-restaurants.
	 */
	public function test_rest_endpoint() {

		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/restaurants/' . $this->restaurant . '/ratings' );
		$request->set_param( 'sources', 'google' );
		$request->set_param( 'reviews', false );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'google' ), array_column( $response->get_data()['ratings'], 'source' ) );
		$this->assertSame( array(), $response->get_data()['ratings'][0]['reviews'] );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/restaurants/' . $draft . '/ratings' ) )->get_status() );

		$page = self::factory()->post->create();
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/restaurants/' . $page . '/ratings' ) )->get_status() );
	}

	/**
	 * The shortcode and block print only a placeholder pointing at the endpoint: no ratings in cached HTML.
	 */
	public function test_markup_is_a_placeholder() {
		$html = do_shortcode( '[guam_restaurant_ratings id="' . $this->restaurant . '" sources="google,yelp" reviews="no"]' );

		$this->assertStringContainsString( 'data-gr-ratings="', $html );
		$decoded = rawurldecode( html_entity_decode( $html ) );
		$this->assertStringContainsString( 'guam-restaurants/v1/restaurants/' . $this->restaurant . '/ratings', $decoded );
		$this->assertStringContainsString( 'sources=google&reviews=0', $decoded );
		$this->assertStringNotContainsString( '4.3', $html );
		$this->assertSame( array(), $this->api->requests );
		$this->assertTrue( wp_script_is( Display::SCRIPT, 'enqueued' ) );

		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'guam-restaurants/ratings' ) );
		$block = do_blocks( '<!-- wp:guam-restaurants/ratings {"restaurantId":' . $this->restaurant . ',"sources":"yelp"} /-->' );
		$this->assertStringContainsString( 'sources=yelp', $block );

		$this->assertSame( '', Display::render( self::factory()->post->create(), '', true ) );
	}
}
