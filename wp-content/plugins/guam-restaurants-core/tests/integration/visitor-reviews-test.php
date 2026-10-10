<?php
/**
 * Visitor review tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Reviews\Display;
use GuamRestaurants\Core\Highlights\Moderation_Screen;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;
use WP_REST_Request;

/**
 * Submitting, moderating and showing first-party visitor reviews.
 *
 * @covers \GuamRestaurants\Core\Reviews\Visitor_Reviews
 * @covers \GuamRestaurants\Core\Reviews\Rest_Controller
 * @covers \GuamRestaurants\Core\Reviews\Display
 * @covers \GuamRestaurants\Core\Highlights\Moderation_Screen
 */
class Visitor_Reviews_Test extends Test_Case {

	/**
	 * Published restaurant.
	 *
	 * @var int
	 */
	private int $restaurant;

	/**
	 * Administrator who moderates.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Creates a restaurant and logs in a visitor (email submissions are
	 * covered in Highlights_Test).
	 */
	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->restaurant = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => 'Test Grill',
				'post_status' => 'publish',
			)
		);
		reset_phpmailer_instance();
	}

	/**
	 * A valid submission.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function input( array $overrides = array() ): array {
		return $overrides + array(
			'rating'      => 4,
			'body'        => 'Great kelaguen and friendly staff, will come back.',
			'author_name' => 'Pat',
			'connected'   => false,
			'connection'  => '',
			'website'     => '',
			'elapsed_ms'  => 20000,
		);
	}

	/**
	 * Submits and returns the new ID, failing on error.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 */
	private function submit( array $overrides = array(), string $client = 'client-a' ): int {
		$result = Visitor_Reviews::submit( $this->restaurant, self::input( $overrides ), $client );
		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		return $result['id'];
	}

	/**
	 * Sets a review's created_at.
	 */
	private function set_created( int $id, string $datetime ): void {
		global $wpdb;
		$wpdb->update( Schema::table( Schema::HIGHLIGHTS ), array( 'created_at' => $datetime ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * A valid review is stored as pending, unchanged, and the admin is emailed.
	 */
	public function test_submission_is_pending() {
		$id     = $this->submit( array( 'body' => "Line one.\nLine two, plain text kept as written." ) );
		$review = Visitor_Reviews::get( $id );

		$this->assertSame( 'pending', $review['status'] );
		$this->assertSame( 'user_review', $review['type'] );
		$this->assertSame( '4', $review['rating'] );
		$this->assertSame( "Line one.\nLine two, plain text kept as written.", $review['body'] );
		$this->assertNull( $review['disclosure'] );
		$this->assertSame( 0, Visitor_Reviews::summary( $this->restaurant )['count'] );
		$this->assertSame( array(), Visitor_Reviews::approved( $this->restaurant ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'Test Grill', $mail->subject );
	}

	/**
	 * Invalid fields are refused with a message for each.
	 */
	public function test_validation() {
		$result = Visitor_Reviews::submit(
			$this->restaurant,
			self::input(
				array(
					'rating'      => 0,
					'body'        => 'Too short',
					'author_name' => '',
					'connected'   => true,
				)
			),
			'client-a'
		);

		$this->assertWPError( $result );
		$this->assertEqualsCanonicalizing( array( 'gr_review_rating', 'gr_review_body', 'gr_review_connection' ), $result->get_error_codes(), 'An empty name falls back to the display name' );

		$long = Visitor_Reviews::submit( $this->restaurant, self::input( array( 'author_name' => str_repeat( 'n', 61 ) ) ), 'client-a' );
		$this->assertSame( array( 'gr_review_name' ), $long->get_error_codes() );
	}

	/**
	 * Honeypot and instant submissions are refused.
	 */
	public function test_bot_traps() {
		$this->assertWPError( Visitor_Reviews::submit( $this->restaurant, self::input( array( 'website' => 'http://spam.example' ) ), 'c' ) );
		$this->assertWPError( Visitor_Reviews::submit( $this->restaurant, self::input( array( 'elapsed_ms' => 500 ) ), 'c' ) );
	}

	/**
	 * One review per restaurant per day, and three per hour, per visitor.
	 */
	public function test_rate_limits() {
		$this->submit();
		$duplicate = Visitor_Reviews::submit( $this->restaurant, self::input(), 'client-a' );
		$this->assertSame( 'gr_review_duplicate', $duplicate->get_error_code() );

		foreach ( array( 1, 2 ) as $n ) {
			$other = self::factory()->post->create(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_status' => 'publish',
				)
			);
			$this->assertIsArray( Visitor_Reviews::submit( $other, self::input(), 'client-a' ), "review $n" );
		}
		$fourth = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'gr_review_rate', Visitor_Reviews::submit( $fourth, self::input(), 'client-a' )->get_error_code() );

		// Another visitor is unaffected.
		$this->assertIsArray( Visitor_Reviews::submit( $this->restaurant, self::input(), 'client-b' ) );
	}

	/**
	 * Unpublished restaurants take no reviews.
	 */
	public function test_unpublished_restaurant() {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'gr_review_restaurant', Visitor_Reviews::submit( $draft, self::input(), 'c' )->get_error_code() );
	}

	/**
	 * A connected reviewer's disclosure is stored and shown with the review.
	 */
	public function test_connection_disclosure() {
		$id = $this->submit(
			array(
				'rating'     => 5,
				'connected'  => true,
				'connection' => 'I work in the kitchen',
			)
		);
		Visitor_Reviews::moderate( $id, 'approved', '', $this->admin );

		$this->assertSame( 'I work in the kitchen', Visitor_Reviews::approved( $this->restaurant )[0]['disclosure'] );
	}

	/**
	 * Rejection needs a published rule; approval publishes positive and negative reviews alike.
	 */
	public function test_moderation() {
		$negative = $this->submit(
			array(
				'rating' => 1,
				'body'   => 'Cold food and an hour wait. Would not return.',
			),
			'client-a'
		);
		$positive = $this->submit( array( 'rating' => 5 ), 'client-b' );
		$spam     = $this->submit( array(), 'client-c' );
		$this->set_created( $negative, '2026-09-02 10:00:00' );
		$this->set_created( $positive, '2026-09-01 10:00:00' );

		$this->assertSame( 'gr_review_reason', Visitor_Reviews::moderate( $spam, 'rejected', '', $this->admin )->get_error_code() );
		$this->assertSame( 'gr_review_reason', Visitor_Reviews::moderate( $spam, 'rejected', 'too_negative', $this->admin )->get_error_code() );
		$this->assertTrue( Visitor_Reviews::moderate( $spam, 'rejected', 'fake', $this->admin ) );
		$rejected = Visitor_Reviews::get( $spam );
		$this->assertSame( 'rejected', $rejected['status'] );
		$this->assertSame( 'fake', $rejected['moderation_reason'] );
		$this->assertSame( (string) $this->admin, $rejected['moderated_by'] );

		Visitor_Reviews::moderate( $negative, 'approved', '', $this->admin );
		Visitor_Reviews::moderate( $positive, 'approved', '', $this->admin );

		$shown = Visitor_Reviews::approved( $this->restaurant );
		$this->assertSame( array( $negative, $positive ), array_column( $shown, 'id' ), 'Newest first, negative included' );
		$this->assertSame( 'Cold food and an hour wait. Would not return.', $shown[0]['text'] );
		$this->assertSame(
			array(
				'count'   => 2,
				'average' => 3.0,
			),
			Visitor_Reviews::summary( $this->restaurant )
		);
	}

	/**
	 * The trend averages approved ratings by the month they were written, for the last 24 months.
	 */
	public function test_monthly_trend() {
		$rows = array(
			array( 4, '2026-08-03 00:00:00', 'a' ),
			array( 2, '2026-08-20 00:00:00', 'b' ),
			array( 5, '2026-10-01 00:00:00', 'c' ),
			array( 1, '2024-01-15 00:00:00', 'd' ), // Older than 24 months.
		);
		foreach ( $rows as list( $rating, $created, $client ) ) {
			$id = $this->submit( array( 'rating' => $rating ), $client );
			$this->set_created( $id, $created );
			Visitor_Reviews::moderate( $id, 'approved', '', $this->admin );
		}
		$pending = $this->submit( array( 'rating' => 1 ), 'e' );
		$this->set_created( $pending, '2026-08-05 00:00:00' );

		$trend = Visitor_Reviews::monthly_trend( $this->restaurant, strtotime( '2026-10-10 00:00:00 UTC' ) );

		$this->assertSame(
			array(
				array(
					'month'   => '2026-08',
					'average' => 3.0,
					'count'   => 2,
				),
				array(
					'month'   => '2026-10',
					'average' => 5.0,
					'count'   => 1,
				),
			),
			$trend
		);
	}

	/**
	 * REST: GET returns the public data; POST queues a review.
	 */
	public function test_rest_endpoints() {
		$id = $this->submit();
		Visitor_Reviews::moderate( $id, 'approved', '', $this->admin );

		$get = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/restaurants/' . $this->restaurant . '/visitor-reviews' ) );
		$this->assertSame( 200, $get->get_status() );
		$data = $get->get_data();
		$this->assertSame( 1, $data['summary']['count'] );
		$this->assertSame( 'Pat', $data['reviews'][0]['author'] );
		$this->assertArrayNotHasKey( 'author_user_id', $data['reviews'][0] );
		$this->assertCount( 9, $data['rules'] );

		$post = new WP_REST_Request( 'POST', '/guam-restaurants/v1/restaurants/' . $this->restaurant . '/visitor-reviews' );
		$post->set_header( 'Content-Type', 'application/json' );
		$post->set_body( (string) wp_json_encode( self::input( array( 'author_name' => 'Sam' ) ) ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$created                = rest_get_server()->dispatch( $post );
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 1, Visitor_Reviews::count( 'pending' ) );

		$bad = new WP_REST_Request( 'POST', '/guam-restaurants/v1/restaurants/' . $this->restaurant . '/visitor-reviews' );
		$bad->set_header( 'Content-Type', 'application/json' );
		$bad->set_body( (string) wp_json_encode( self::input( array( 'body' => 'short' ) ) ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$this->assertSame( 400, rest_get_server()->dispatch( $bad )->get_status() );

		$page = self::factory()->post->create();
		$this->assertSame( 404, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/restaurants/' . $page . '/visitor-reviews' ) )->get_status() );
	}

	/**
	 * The block and shortcode print only a placeholder; review text is not in the cached HTML.
	 */
	public function test_markup_is_a_placeholder() {
		$id = $this->submit();
		Visitor_Reviews::moderate( $id, 'approved', '', $this->admin );

		$html = do_shortcode( '[guam_visitor_reviews id="' . $this->restaurant . '" trend="no"]' );
		$this->assertStringContainsString( 'data-gr-visitor-reviews="', $html );
		$this->assertStringContainsString( 'data-trend="0"', $html );
		$this->assertStringNotContainsString( 'kelaguen', $html );
		$this->assertTrue( wp_script_is( Display::SCRIPT, 'enqueued' ) );

		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'guam-restaurants/visitor-reviews' ) );
		$this->assertStringContainsString( 'data-form="1"', do_blocks( '<!-- wp:guam-restaurants/visitor-reviews {"restaurantId":' . $this->restaurant . '} /-->' ) );
	}

	/**
	 * The moderation screen lists pending reviews with the rule picker, for administrators only.
	 */
	public function test_moderation_screen() {
		$this->submit(
			array(
				'connected'  => true,
				'connection' => 'Owner',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Moderation_Screen::render();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Moderation_Screen::render();
		$this->assertSame( '', ob_get_clean(), 'Editors write notes but do not moderate' );

		wp_set_current_user( $this->admin );
		ob_start();
		Moderation_Screen::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'kelaguen', $html );
		$this->assertStringContainsString( 'Disclosure: Owner', $html );
		$this->assertStringContainsString( 'value="undisclosed_connection"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}
}
