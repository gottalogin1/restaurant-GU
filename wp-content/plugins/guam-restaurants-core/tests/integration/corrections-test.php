<?php
/**
 * Correction form and owner listing request tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Corrections\Corrections;
use GuamRestaurants\Core\Corrections\Display;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Site\Pages;
use WP_REST_Request;

/**
 * Validation, spam rules, queue items, emails, REST, the page and the admin decision.
 *
 * @covers \GuamRestaurants\Core\Corrections\Corrections
 * @covers \GuamRestaurants\Core\Corrections\Rest_Controller
 * @covers \GuamRestaurants\Core\Corrections\Display
 * @covers \GuamRestaurants\Core\Admin\Review_Queue_Screen
 */
class Corrections_Test extends Guam_Test_Case {

	/**
	 * Published restaurant.
	 *
	 * @var int
	 */
	private int $restaurant;

	/**
	 * Creates a restaurant; tests start logged out.
	 */
	public function set_up() {
		parent::set_up();
		$this->restaurant = $this->restaurant( 'Harbor Grill', array( 'gr_phone' => '+16715550100' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * A valid correction.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function input( array $overrides = array() ): array {
		return $overrides + array(
			'restaurant_id' => $this->restaurant,
			'request'       => 'correction',
			'topic'         => 'hours',
			'details'       => 'They now open at 7 am on weekdays, not 9 am.',
			'evidence'      => 'Sign on the front door',
			'name'          => '',
			'email'         => 'diner@example.com',
			'relationship'  => 'customer',
			'website'       => '',
			'elapsed_ms'    => 20000,
		);
	}

	/**
	 * Every field is checked, and owner requests need an owner or manager with a name.
	 */
	public function test_validation() {
		$this->assertIsArray( Corrections::validate( $this->input() ) );

		$draft = $this->restaurant( 'Draft Place', array(), 'draft' );
		$cases = array(
			'gr_correction_restaurant'   => array( 'restaurant_id' => $draft ),
			'gr_correction_request'      => array( 'request' => 'delete_reviews' ),
			'gr_correction_details'      => array( 'details' => 'Wrong.' ),
			'gr_correction_email'        => array( 'email' => 'not-an-email' ),
			'gr_correction_evidence'     => array( 'evidence' => str_repeat( 'x', Corrections::MAX_EVIDENCE + 1 ) ),
			'gr_correction_relationship' => array( 'relationship' => 'competitor' ),
		);
		foreach ( $cases as $code => $override ) {
			$result = Corrections::validate( $this->input( $override ) );
			$this->assertWPError( $result, $code );
			$this->assertContains( $code, $result->get_error_codes(), $code );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}

		// A removal reason is not a correction topic, and the reverse.
		$this->assertContains( 'gr_correction_topic', Corrections::validate( $this->input( array( 'topic' => 'personal_info' ) ) )->get_error_codes() );
		$owner = array(
			'request'      => 'removal',
			'topic'        => 'hours',
			'relationship' => 'owner',
			'name'         => 'Pat Cruz',
		);
		$this->assertContains( 'gr_correction_topic', Corrections::validate( $this->input( $owner ) )->get_error_codes() );
		$this->assertIsArray( Corrections::validate( $this->input( array( 'topic' => 'not_public' ) + $owner ) ) );

		// Only owners and managers may ask to change or remove a listing, and they must give a name.
		$customer = Corrections::validate( $this->input( array( 'relationship' => 'customer' ) + $owner ) );
		$this->assertContains( 'gr_correction_relationship', $customer->get_error_codes() );
		$nameless = Corrections::validate( $this->input( array( 'name' => '' ) + $owner ) );
		$this->assertContains( 'gr_correction_name', $nameless->get_error_codes() );
		$this->assertIsArray(
			Corrections::validate(
				$this->input(
					array(
						'request'      => 'listing_change',
						'relationship' => 'manager',
						'name'         => 'Sam',
					)
				)
			)
		);

		// Text is cleaned.
		$clean = Corrections::validate( $this->input( array( 'details' => '<script>x</script>Opens at 7 am now, every day.' ) ) );
		$this->assertStringNotContainsString( '<script>', $clean['details'] );
	}

	/**
	 * A correction becomes a pending `correction` item due in 7 days, and both emails go out.
	 */
	public function test_submit_creates_queue_item_and_emails() {
		$id = Corrections::submit( $this->input(), 'client-a' );
		$this->assertIsInt( $id );

		$item = Review_Queue::get( $id );
		$this->assertSame( 'correction', $item['type'] );
		$this->assertSame( 'pending', $item['status'] );
		$this->assertSame( 'visitor', $item['source'] );
		$this->assertSame( $this->restaurant, (int) $item['restaurant_id'] );
		$this->assertSame( 'hours', $item['payload']['topic'] );
		$this->assertSame( 'diner@example.com', $item['payload']['email'] );
		$this->assertSame( gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ), $item['payload']['respond_by'] );

		$this->assertCount( 2, $this->mails );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertStringContainsString( 'They now open at 7 am', $this->mails[0]['message'] );
		$this->assertContains( 'Reply-To: diner@example.com', (array) $this->mails[0]['headers'] );
		$this->assertSame( 'diner@example.com', $this->mails[1]['to'] );
		$this->assertStringContainsString( 'within 7 days', $this->mails[1]['message'] );
		$this->assertStringNotContainsString( 'They now open at 7 am', $this->mails[1]['message'], 'The confirmation quotes nothing the sender typed.' );
	}

	/**
	 * Removal and listing-change requests are `listing_request` items due in 14 days.
	 */
	public function test_owner_requests_are_listing_requests() {
		$id   = Corrections::submit(
			$this->input(
				array(
					'request'      => 'removal',
					'topic'        => 'personal_info',
					'details'      => 'The address shown is my home, not a restaurant.',
					'relationship' => 'owner',
					'name'         => 'Pat Cruz',
				)
			),
			'client-b'
		);
		$item = Review_Queue::get( $id );
		$this->assertSame( 'listing_request', $item['type'] );
		$this->assertSame( 'removal', $item['payload']['request'] );
		$this->assertSame( gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ), $item['payload']['respond_by'] );
		$this->assertStringContainsString( 'within 14 days', $this->mails[1]['message'] );
	}

	/**
	 * A request never changes or marks the listing: it stays published, and
	 * nothing is logged in its change history until an editor acts.
	 */
	public function test_requests_publish_nothing() {
		global $wpdb;
		$count  = static function ( int $id ) use ( $wpdb ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( Schema::CHANGE_LOG ) . ' WHERE restaurant_id = %d', $id ) ); // phpcs:ignore WordPress.DB
		};
		$before = $count( $this->restaurant );
		$meta   = get_post_meta( $this->restaurant );

		Corrections::submit(
			$this->input(
				array(
					'request'      => 'removal',
					'topic'        => 'not_public',
					'details'      => 'We only do private catering now.',
					'relationship' => 'owner',
					'name'         => 'Pat Cruz',
				)
			),
			'client-c'
		);
		Corrections::submit(
			$this->input(
				array(
					'topic'   => 'closed',
					'details' => 'This place has closed for good.',
				)
			),
			'client-c'
		);

		$this->assertSame( 'publish', get_post_status( $this->restaurant ) );
		$this->assertSame( $before, $count( $this->restaurant ) );
		$this->assertEquals( $meta, get_post_meta( $this->restaurant ) );
	}

	/**
	 * Honeypot, too-fast submissions and the hourly limit.
	 */
	public function test_spam_rules() {
		$this->assertWPError( Corrections::submit( $this->input( array( 'website' => 'http://spam.example' ) ), 'client-d' ) );
		$this->assertWPError( Corrections::submit( $this->input( array( 'elapsed_ms' => 500 ) ), 'client-d' ) );
		$this->assertSame( array(), Review_Queue::pending( 'correction' ) );

		for ( $i = 0; $i < Corrections::PER_HOUR; $i++ ) {
			$this->assertIsInt( Corrections::submit( $this->input( array( 'email' => "person{$i}@example.com" ) ), 'client-d' ) );
		}
		$limited = Corrections::submit( $this->input( array( 'email' => 'another@example.com' ) ), 'client-d' );
		$this->assertWPError( $limited );
		$this->assertSame( 429, $limited->get_error_data()['status'] );

		// The same email from another visitor is limited too.
		for ( $i = 0; $i < Corrections::PER_HOUR; $i++ ) {
			Corrections::submit( $this->input( array( 'email' => 'same@example.com' ) ), 'client-' . $i . '-x' );
		}
		$this->assertWPError( Corrections::submit( $this->input( array( 'email' => 'same@example.com' ) ), 'client-new' ) );
	}

	/**
	 * The REST form gives a nonce, the choices and the response times; the submit endpoint checks the nonce.
	 */
	public function test_rest_endpoints() {
		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/corrections/form' );
		$request->set_param( 'restaurant', $this->restaurant );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'Harbor Grill', $data['restaurant']['name'] );
		$this->assertSame( 7, $data['response_days']['correction'] );
		$this->assertSame( 14, $data['response_days']['removal'] );
		$this->assertSame( array( 'correction', 'listing_change', 'removal' ), wp_list_pluck( $data['requests'], 'id' ) );

		$post = new WP_REST_Request( 'POST', '/guam-restaurants/v1/corrections' );
		$post->set_body_params( $this->input( array( 'nonce' => 'bad' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $post )->get_status() );

		$post->set_body_params( $this->input( array( 'nonce' => $data['nonce'] ) ) );
		$created = rest_get_server()->dispatch( $post );
		$this->assertSame( 201, $created->get_status() );
		$this->assertStringContainsString( '7 days', $created->get_data()['message'] );
		$this->assertCount( 1, Review_Queue::pending( 'correction' ) );

		$post->set_body_params(
			$this->input(
				array(
					'nonce'   => $data['nonce'],
					'details' => 'x',
				)
			)
		);
		$this->assertSame( 400, rest_get_server()->dispatch( $post )->get_status() );
	}

	/**
	 * The correction page is published with the form and the response times;
	 * an old untouched draft is upgraded once, an edited page is left alone.
	 */
	public function test_correction_page() {
		$pages = Pages::ensure();
		$page  = get_post( $pages['correction'] );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertStringContainsString( '<!-- wp:guam-restaurants/correction-form /-->', $page->post_content );
		$this->assertStringContainsString( 'within 7 days', $page->post_content );
		$this->assertStringContainsString( 'within 14 days', $page->post_content );

		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:paragraph --><p>[Editor: add the correction form here, then publish this page.]</p><!-- /wp:paragraph -->',
			)
		);
		delete_option( Display::PAGE_OPTION );
		Display::upgrade_page();
		$page = get_post( $page->ID );
		$this->assertSame( 'publish', $page->post_status );
		$this->assertStringContainsString( 'correction-form', $page->post_content );

		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_status'  => 'draft',
				'post_content' => 'Our own text.',
			)
		);
		delete_option( Display::PAGE_OPTION );
		Display::upgrade_page();
		$this->assertSame( 'draft', get_post_status( $page->ID ), 'An edited page is left alone.' );

		$html = Display::render();
		$this->assertStringContainsString( 'data-gr-correction-form', $html );
		$this->assertStringContainsString( '/guam-restaurants/v1/corrections/form', $html );
	}

	/**
	 * Administrators close a request with a private note; others cannot.
	 */
	public function test_admin_closes_request() {
		$id     = Corrections::submit( $this->input(), 'client-e' );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( Review_Queue_Screen::decide( $id, 'request_done', $editor, 'x' ) );

		$admin = $this->login_admin();
		$this->assertTrue( Review_Queue_Screen::decide( $id, 'request_done', $admin, 'Checked their website; hours updated.' ) );
		$item = Review_Queue::get( $id );
		$this->assertSame( 'approved', $item['status'] );
		$this->assertSame( $admin, (int) $item['resolved_by'] );
		$this->assertSame( 'Checked their website; hours updated.', $item['payload']['decision_note'] );
		$this->assertSame( 'diner@example.com', $item['payload']['email'], 'The note is added; nothing else changes.' );

		$second = Corrections::submit( $this->input( array( 'email' => 'b@example.com' ) ), 'client-f' );
		$this->assertTrue( Review_Queue_Screen::decide( $second, 'request_decline', $admin, 'Could not confirm.' ) );
		$this->assertSame( 'rejected', Review_Queue::get( $second )['status'] );
		$this->assertFalse( Review_Queue_Screen::decide( $second, 'request_done', $admin ), 'Already decided.' );
	}
}
