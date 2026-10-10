<?php
/**
 * Advertiser inquiry and lead management tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Advertising\Admin;
use GuamRestaurants\Core\Advertising\Display;
use GuamRestaurants\Core\Advertising\Leads;
use GuamRestaurants\Core\Advertising\Page_Content;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Site\Pages;
use WP_REST_Request;

/**
 * Validation, spam rules, storage, emails, permissions and export.
 *
 * @covers \GuamRestaurants\Core\Advertising\Leads
 * @covers \GuamRestaurants\Core\Advertising\Rest_Controller
 * @covers \GuamRestaurants\Core\Advertising\Admin
 * @covers \GuamRestaurants\Core\Advertising\Display
 * @covers \GuamRestaurants\Core\Advertising\Page_Content
 */
class Advertiser_Leads_Test extends Test_Case {

	/**
	 * Published restaurant.
	 *
	 * @var int
	 */
	private int $restaurant;

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Creates a restaurant and an administrator; tests start logged out.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->restaurant = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => 'Harbor Grill',
				'post_status' => 'publish',
			)
		);
		wp_set_current_user( 0 );
		reset_phpmailer_instance();
	}

	/**
	 * A valid submission.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private function input( array $overrides = array() ): array {
		return $overrides + array(
			'name'          => 'Pat Cruz',
			'business'      => 'Harbor Grill LLC',
			'restaurant_id' => $this->restaurant,
			'email'         => 'pat@example.com',
			'phone'         => '+1 (671) 555-0123',
			'package'       => 'premium_profile',
			'budget'        => '100_250',
			'message'       => 'We would like to know more about the premium profile.',
			'website'       => '',
			'elapsed_ms'    => 20000,
		);
	}

	/**
	 * Submits and returns the new lead ID, failing on error.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @param string               $client    Client key.
	 * @return int
	 */
	private function submit( array $overrides = array(), string $client = 'client-a' ): int {
		$id = Leads::submit( $this->input( $overrides ), hash( 'sha256', $client ) );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	/**
	 * A valid inquiry is stored as a private lead with status "new".
	 */
	public function test_submission_creates_private_lead() {
		$id   = $this->submit();
		$post = get_post( $id );
		$lead = Leads::get( $id );

		$this->assertSame( Leads::POST_TYPE, $post->post_type );
		$this->assertSame( 'private', $post->post_status );
		$this->assertSame( 'Harbor Grill LLC (Pat Cruz)', $post->post_title );
		$this->assertSame( 'new', $lead['status'] );
		$this->assertSame( $this->restaurant, $lead['restaurant_id'] );
		$this->assertSame( 'pat@example.com', $lead['email'] );
		$this->assertSame( '+1 (671) 555-0123', $lead['phone'] );
		$this->assertSame( 'premium_profile', $lead['package'] );
		$this->assertSame( '100_250', $lead['budget'] );
		$this->assertSame( 0, $lead['assigned_user'] );
	}

	/**
	 * Restaurant, phone and message are optional.
	 */
	public function test_optional_fields() {
		$id   = $this->submit(
			array(
				'restaurant_id' => 0,
				'phone'         => '',
				'message'       => '',
				'package'       => 'not_sure',
				'budget'        => 'not_sure',
			)
		);
		$lead = Leads::get( $id );
		$this->assertSame( 0, $lead['restaurant_id'] );
		$this->assertSame( '', $lead['phone'] );
	}

	/**
	 * Each invalid field is refused with its own error.
	 *
	 * @dataProvider invalid_fields
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @param string               $code      Expected error code.
	 */
	public function test_validation( array $overrides, string $code ) {
		$result = Leads::validate( $this->input( $overrides ) );
		$this->assertWPError( $result );
		$this->assertContains( $code, $result->get_error_codes() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Invalid inputs.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_fields(): array {
		return array(
			'name missing'                => array( array( 'name' => '   ' ), 'gr_lead_name' ),
			'name too long'               => array( array( 'name' => str_repeat( 'a', 101 ) ), 'gr_lead_name' ),
			'business missing'            => array( array( 'business' => '' ), 'gr_lead_business' ),
			'business too long'           => array( array( 'business' => str_repeat( 'b', 151 ) ), 'gr_lead_business' ),
			'email invalid'               => array( array( 'email' => 'not-an-email' ), 'gr_lead_email' ),
			'email missing'               => array( array( 'email' => '' ), 'gr_lead_email' ),
			'phone with letters'          => array( array( 'phone' => 'call me maybe' ), 'gr_lead_phone' ),
			'phone too short'             => array( array( 'phone' => '555-01' ), 'gr_lead_phone' ),
			'phone too many digits'       => array( array( 'phone' => '1234567890123456' ), 'gr_lead_phone' ),
			'package unknown'             => array( array( 'package' => 'homepage_leaderboard' ), 'gr_lead_package' ),
			'package missing'             => array( array( 'package' => '' ), 'gr_lead_package' ),
			'budget unknown'              => array( array( 'budget' => 'a_million' ), 'gr_lead_budget' ),
			'message too long'            => array( array( 'message' => str_repeat( 'm', 3001 ) ), 'gr_lead_message' ),
			'restaurant not a restaurant' => array( array( 'restaurant_id' => 999999 ), 'gr_lead_restaurant' ),
		);
	}

	/**
	 * Draft restaurants and other post types cannot be linked.
	 */
	public function test_restaurant_must_be_published() {
		$draft = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		foreach ( array( $draft, $page ) as $id ) {
			$result = Leads::validate( $this->input( array( 'restaurant_id' => $id ) ) );
			$this->assertWPError( $result );
			$this->assertSame( array( 'gr_lead_restaurant' ), $result->get_error_codes() );
		}
	}

	/**
	 * Markup is stripped, not stored.
	 */
	public function test_fields_are_sanitized() {
		$id   = $this->submit(
			array(
				'name'    => '<b>Pat</b> <script>alert(1)</script>',
				'message' => "Line one\n<img src=x onerror=alert(1)>Line two",
			)
		);
		$lead = Leads::get( $id );
		$this->assertSame( 'Pat', $lead['name'] );
		$this->assertStringNotContainsString( '<', $lead['message'] );
		$this->assertStringContainsString( "Line one\n", $lead['message'] );
	}

	/**
	 * Honeypot and too-fast submissions are refused without saving or mailing.
	 */
	public function test_spam_traps() {
		foreach ( array( array( 'website' => 'https://spam.example' ), array( 'elapsed_ms' => 500 ) ) as $overrides ) {
			$result = Leads::submit( $this->input( $overrides ), 'client-trap' );
			$this->assertWPError( $result );
			$this->assertSame( 'gr_lead_rejected', $result->get_error_code() );
		}
		$this->assertSame( 0, (int) wp_count_posts( Leads::POST_TYPE )->private );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * A visitor, or an email address, may send PER_HOUR inquiries an hour.
	 */
	public function test_rate_limit() {
		for ( $i = 0; $i < Leads::PER_HOUR; $i++ ) {
			$this->submit( array(), 'client-rate' );
		}
		$result = Leads::submit( $this->input( array( 'email' => 'other@example.com' ) ), hash( 'sha256', 'client-rate' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'gr_lead_rate', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		// Same email from a new visitor is limited too.
		$result = Leads::submit( $this->input(), hash( 'sha256', 'client-new' ) );
		$this->assertWPError( $result );

		// A different visitor and address can still send.
		$this->submit( array( 'email' => 'someone@example.com' ), 'client-other' );
	}

	/**
	 * The owner gets the lead; the sender gets a confirmation that quotes
	 * nothing they typed and promises no price or placement.
	 */
	public function test_emails() {
		$id   = $this->submit();
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 2, $sent );

		$owner = $sent[0];
		$this->assertSame( get_option( 'admin_email' ), $owner['to'][0][0] );
		$this->assertStringContainsString( 'Harbor Grill LLC', $owner['subject'] );
		$this->assertStringContainsString( 'We would like to know more', $owner['body'] );
		$this->assertStringContainsString( 'Harbor Grill', $owner['body'] );
		$this->assertStringContainsString( 'post=' . $id, $owner['body'] );
		$this->assertStringContainsString( 'Reply-To: pat@example.com', $owner['header'] );

		$confirmation = $sent[1];
		$this->assertSame( 'pat@example.com', $confirmation['to'][0][0] );
		$this->assertStringNotContainsString( '$', $confirmation['body'], 'No prices' );
		$this->assertStringNotContainsString( 'We would like to know more', $confirmation['body'], 'Does not echo the message' );
		$this->assertStringNotContainsString( 'Pat Cruz', $confirmation['body'], 'Does not echo the name' );
		$this->assertStringContainsString( 'not an offer or a quote', $confirmation['body'] );
		$this->assertStringContainsString( Policy_Page::url(), $confirmation['body'] );
	}

	/**
	 * The notification address can be changed with a filter.
	 */
	public function test_owner_email_filter() {
		$to = static function () {
			return 'sales@example.com';
		};
		add_filter( 'guam_restaurants_lead_notify_email', $to );
		$this->submit();
		remove_filter( 'guam_restaurants_lead_notify_email', $to );
		$this->assertSame( 'sales@example.com', tests_retrieve_phpmailer_instance()->mock_sent[0]['to'][0][0] );
	}

	/**
	 * The REST endpoint needs the form nonce; the form endpoint provides it.
	 */
	public function test_rest_requires_nonce() {
		$post = new WP_REST_Request( 'POST', '/guam-restaurants/v1/advertise/leads' );
		foreach ( $this->input() as $key => $value ) {
			$post->set_param( $key, $value );
		}

		$post->set_param( 'nonce', 'bad' );
		$response = rest_get_server()->dispatch( $post );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'gr_lead_nonce', $response->get_data()['code'] );
		$this->assertSame( 0, (int) wp_count_posts( Leads::POST_TYPE )->private );

		$form = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/advertise/form' ) );
		$this->assertSame( 'no-store', $form->get_headers()['Cache-Control'] );
		$post->set_param( 'nonce', $form->get_data()['nonce'] );
		$response = rest_get_server()->dispatch( $post );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 1, (int) wp_count_posts( Leads::POST_TYPE )->private );
	}

	/**
	 * Validation errors come back as 400 with every message.
	 */
	public function test_rest_validation_errors() {
		$post = new WP_REST_Request( 'POST', '/guam-restaurants/v1/advertise/leads' );
		foreach ( $this->input(
			array(
				'email'   => 'nope',
				'package' => 'nope',
			)
		) as $key => $value ) {
			$post->set_param( $key, $value );
		}
		$post->set_param( 'nonce', wp_create_nonce( Leads::NONCE_ACTION ) );
		$response = rest_get_server()->dispatch( $post );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'gr_lead_email', $data['code'] );
		$this->assertSame( 'gr_lead_package', $data['additional_errors'][0]['code'] );
	}

	/**
	 * The form endpoint pre-fills a published restaurant only.
	 */
	public function test_form_prefill() {
		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/advertise/form' );
		$request->set_param( 'restaurant', $this->restaurant );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( $this->restaurant, $data['restaurant']['id'] );
		$this->assertSame( 'Harbor Grill', $data['restaurant']['name'] );
		$this->assertContains( 'premium_profile', wp_list_pluck( $data['packages'], 'id' ) );
		$this->assertNotContains( 'Featured listing', wp_list_pluck( $data['packages'], 'label' ), 'Visitors never see "Featured"' );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$request->set_param( 'restaurant', $draft );
		$this->assertNull( rest_get_server()->dispatch( $request )->get_data()['restaurant'] );
	}

	/**
	 * The picker finds published restaurants by name, not drafts.
	 */
	public function test_restaurant_search() {
		self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => 'Harbor Secret Kitchen',
				'post_status' => 'draft',
			)
		);
		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/advertise/restaurants' );
		$request->set_param( 'search', 'harbor' );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( array( 'Harbor Grill' ), wp_list_pluck( $data, 'name' ) );

		$request->set_param( 'search', 'h' );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status(), 'At least two characters' );
	}

	/**
	 * Only administrators can see or change leads; nothing is public.
	 */
	public function test_permissions() {
		$id   = $this->submit();
		$type = get_post_type_object( Leads::POST_TYPE );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertFalse( $type->show_in_rest );
		$this->assertTrue( $type->exclude_from_search );
		$this->assertArrayNotHasKey( '/wp/v2/' . Leads::POST_TYPE, rest_get_server()->get_routes() );

		$owner = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		foreach ( array( 0, $owner, self::factory()->user->create( array( 'role' => 'subscriber' ) ), self::factory()->user->create( array( 'role' => 'author' ) ), self::factory()->user->create( array( 'role' => 'editor' ) ) ) as $user ) {
			wp_set_current_user( $user );
			$this->assertFalse( current_user_can( Leads::CAP ), "User $user has the lead capability" );
			$this->assertFalse( current_user_can( 'read_post', $id ), "User $user can read a lead" );
			$this->assertFalse( current_user_can( 'edit_post', $id ), "User $user can edit a lead" );
			$this->assertFalse( current_user_can( 'delete_post', $id ), "User $user can delete a lead" );
			$this->assertFalse( current_user_can( $type->cap->create_posts ), "User $user can add leads" );
		}

		wp_set_current_user( $this->admin );
		$this->assertTrue( current_user_can( Leads::CAP ) );
		$this->assertTrue( current_user_can( 'read_post', $id ) );
		$this->assertTrue( current_user_can( 'edit_post', $id ) );
		$this->assertTrue( current_user_can( 'delete_post', $id ) );
	}

	/**
	 * Leads stay private even when saved with the Publish button, and never
	 * show up in front-end queries.
	 */
	public function test_leads_stay_private() {
		wp_set_current_user( $this->admin );
		$id = wp_insert_post(
			array(
				'post_type'   => Leads::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Phone lead',
			)
		);
		$this->assertSame( 'private', get_post_status( $id ) );

		wp_set_current_user( 0 );
		$this->assertSame( array(), get_posts( array( 'post_type' => 'any' ) + array( 'p' => $id ) ) );
		$this->go_to(
			add_query_arg(
				array(
					'post_type' => Leads::POST_TYPE,
					'p'         => $id,
				),
				home_url( '/' )
			)
		);
		$this->assertNotContains( $id, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertTrue( is_404() );
	}

	/**
	 * Status, assignee and notes are validated.
	 */
	public function test_pipeline_updates() {
		$id         = $this->submit();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue(
			Leads::update_pipeline(
				$id,
				array(
					'status'        => 'proposal_sent',
					'assigned_user' => $this->admin,
					'notes'         => 'Called on Monday. <b>Wants</b> annual.',
				)
			)
		);
		$lead = Leads::get( $id );
		$this->assertSame( 'proposal_sent', $lead['status'] );
		$this->assertSame( $this->admin, $lead['assigned_user'] );
		$this->assertSame( 'Called on Monday. Wants annual.', $lead['notes'] );

		$this->assertSame( 'gr_lead_status', Leads::update_pipeline( $id, array( 'status' => 'published' ) )->get_error_code() );
		$this->assertSame( 'gr_lead_assignee', Leads::update_pipeline( $id, array( 'assigned_user' => $subscriber ) )->get_error_code() );
		$this->assertSame( 'gr_lead_missing', Leads::update_pipeline( $this->restaurant, array( 'status' => 'won' ) )->get_error_code() );
		$this->assertSame( 'proposal_sent', Leads::get( $id )['status'], 'Refused updates change nothing' );

		$this->assertTrue( Leads::update_pipeline( $id, array( 'assigned_user' => 0 ) ) );
		$this->assertSame( 0, Leads::get( $id )['assigned_user'] );
	}

	/**
	 * Editing a lead needs the capability; valid edits update the title.
	 */
	public function test_admin_save() {
		$id    = $this->submit();
		$input = $this->input(
			array(
				'business' => 'Harbor Group',
				'status'   => 'contacted',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'gr_lead_forbidden', Admin::save_lead( $id, $input )->get_error_code() );
		$this->assertSame( 'new', Leads::get( $id )['status'] );

		wp_set_current_user( $this->admin );
		$this->assertTrue( Admin::save_lead( $id, $input ) );
		$this->assertSame( 'contacted', Leads::get( $id )['status'] );
		$this->assertSame( 'Harbor Group (Pat Cruz)', get_the_title( $id ), 'No "Private:" prefix: every lead is private' );

		$result = Admin::save_lead(
			$id,
			array(
				'email'  => 'bad',
				'status' => 'won',
			) + $input
		);
		$this->assertWPError( $result );
		$this->assertSame( 'won', Leads::get( $id )['status'], 'Pipeline saves even when details are invalid' );
		$this->assertSame( 'pat@example.com', Leads::get( $id )['email'], 'Invalid details are not saved' );
	}

	/**
	 * List filters accept only known values and narrow the leads.
	 */
	public function test_filters() {
		$a = $this->submit( array( 'package' => 'premium_profile' ), 'f1' );
		$b = $this->submit(
			array(
				'package' => 'banner',
				'email'   => 'b@example.com',
			),
			'f2'
		);
		$c = $this->submit(
			array(
				'package' => 'banner',
				'email'   => 'c@example.com',
				'budget'  => 'over_1000',
			),
			'f3'
		);
		Leads::update_pipeline(
			$b,
			array(
				'status'        => 'won',
				'assigned_user' => $this->admin,
			)
		);

		$filters = Admin::filters_from(
			array(
				'gr_status'  => 'bogus',
				'gr_package' => 'banner',
			)
		);
		$this->assertSame( '', $filters['status'] );
		$this->assertSame( 'banner', $filters['package'] );

		$this->assertEqualSets( array( $b, $c ), Admin::lead_ids( $filters ) );
		$this->assertEqualSets( array( $a, $c ), Admin::lead_ids( Admin::filters_from( array( 'gr_status' => 'new' ) ) ) );
		$this->assertEqualSets( array( $b ), Admin::lead_ids( Admin::filters_from( array( 'gr_status' => 'won' ) ) ) );
		$this->assertEqualSets( array( $c ), Admin::lead_ids( Admin::filters_from( array( 'gr_budget' => 'over_1000' ) ) ) );
		$this->assertEqualSets( array( $b ), Admin::lead_ids( Admin::filters_from( array( 'gr_assigned' => (string) $this->admin ) ) ) );
		$this->assertEqualSets( array( $a, $c ), Admin::lead_ids( Admin::filters_from( array( 'gr_assigned' => 'none' ) ) ) );
	}

	/**
	 * Export needs the capability and the nonce, and neutralises formulas.
	 */
	public function test_csv_export() {
		$this->assertSame( 'gr_lead_forbidden', Admin::authorize_export( wp_create_nonce( Admin::EXPORT_ACTION ) )->get_error_code() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'gr_lead_forbidden', Admin::authorize_export( wp_create_nonce( Admin::EXPORT_ACTION ) )->get_error_code() );

		wp_set_current_user( $this->admin );
		$this->assertSame( 'gr_lead_nonce', Admin::authorize_export( 'bad' )->get_error_code() );
		$this->assertTrue( Admin::authorize_export( wp_create_nonce( Admin::EXPORT_ACTION ) ) );

		$id   = $this->submit( array( 'business' => '=HYPERLINK("http://evil.example")' ) );
		$rows = Admin::csv_rows( array( $id ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Business', $rows[0][3] );
		$this->assertSame( "'=HYPERLINK(\"http://evil.example\")", $rows[1][3] );
		$this->assertSame( 'New', $rows[1][1] );
		$this->assertSame( 'Harbor Grill', $rows[1][4] );
		$this->assertSame( "'+1 (671) 555-0123", $rows[1][7] );
	}

	/**
	 * The advertise and media kit pages are created published, with the
	 * form, the editorial policy and no audience estimates.
	 */
	public function test_pages() {
		$pages     = Pages::ensure();
		$advertise = get_post( $pages['advertise'] );
		$kit       = get_post( $pages['media_kit'] );

		$this->assertSame( 'publish', $advertise->post_status );
		$this->assertSame( 'publish', $kit->post_status );
		$this->assertTrue( has_block( 'guam-restaurants/advertiser-inquiry', $advertise ) );
		$this->assertStringContainsString( esc_url( Policy_Page::url() ), $advertise->post_content );
		$this->assertStringContainsString( esc_url( get_permalink( $kit ) ), $advertise->post_content );

		$this->assertStringContainsString( esc_url( Policy_Page::url() ), $kit->post_content );
		$this->assertStringContainsString( esc_html( Page_Content::editorial_statement( get_bloginfo( 'name' ) ) ), $kit->post_content );
		$this->assertStringContainsString( 'Not yet published', $kit->post_content );
		$this->assertStringContainsString( '$75 a month per page', $kit->post_content );
		$this->assertStringNotContainsString( 'Featured', $kit->post_content . $advertise->post_content );
		$this->assertStringNotContainsString( 'leaderboard', strtolower( $kit->post_content ) );

		$this->assertSame( (string) get_permalink( $advertise ), Pages::url( 'advertise' ) );
	}

	/**
	 * The block prints a placeholder pointing at the three endpoints.
	 */
	public function test_block_placeholder() {
		$html = Display::render();
		$this->assertStringContainsString( 'data-gr-lead-form', $html );
		$this->assertStringContainsString( 'advertise/form', $html );
		$this->assertStringContainsString( 'advertise/restaurants', $html );
		$this->assertStringContainsString( 'advertise/leads', $html );
		$this->assertTrue( wp_script_is( Display::SCRIPT, 'enqueued' ) );
	}
}
