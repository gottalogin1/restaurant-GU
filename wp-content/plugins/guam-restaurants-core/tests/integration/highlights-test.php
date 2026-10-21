<?php
/**
 * Highlights tests: moderation permissions, submission validation, display and markup.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Display;
use GuamRestaurants\Core\Highlights\Email_Verification;
use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Highlights\Policy;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;
use GuamRestaurants\Core\Structured_Data;
use GuamRestaurants\Core\Vocabulary;
use WP_REST_Request;

/**
 * Owner quotes, editor's notes, user reviews and owner responses.
 *
 * @covers \GuamRestaurants\Core\Highlights\Highlights
 * @covers \GuamRestaurants\Core\Highlights\Roles
 * @covers \GuamRestaurants\Core\Highlights\Policy
 * @covers \GuamRestaurants\Core\Highlights\Email_Verification
 * @covers \GuamRestaurants\Core\Highlights\Display
 * @covers \GuamRestaurants\Core\Highlights\Policy_Page
 * @covers \GuamRestaurants\Core\Reviews\Visitor_Reviews
 * @covers \GuamRestaurants\Core\Structured_Data
 */
class Highlights_Test extends Test_Case {

	/**
	 * Published restaurant.
	 *
	 * @var int
	 */
	private int $restaurant;

	/**
	 * Another published restaurant.
	 *
	 * @var int
	 */
	private int $other;

	/**
	 * Users by role.
	 *
	 * @var array<string, int>
	 */
	private array $users = array();

	/**
	 * Creates restaurants and users; links the owner to the first restaurant.
	 */
	public function set_up() {
		parent::set_up();
		foreach ( array(
			'restaurant' => 'Test Grill',
			'other'      => 'Other Place',
		) as $prop => $title ) {
			$this->$prop = self::factory()->post->create(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'publish',
				)
			);
		}
		foreach ( array( 'administrator', 'editor', 'subscriber', Roles::OWNER_ROLE ) as $role ) {
			$this->users[ $role ] = self::factory()->user->create(
				array(
					'role'         => $role,
					'display_name' => ( Roles::OWNER_ROLE === $role ? 'Owner' : ucfirst( $role ) ) . ' Person',
				)
			);
		}
		Roles::set_owned_restaurants( $this->users[ Roles::OWNER_ROLE ], array( $this->restaurant ) );
		wp_set_current_user( 0 );
		reset_phpmailer_instance();
	}

	/**
	 * Owner user ID.
	 */
	private function owner(): int {
		return $this->users[ Roles::OWNER_ROLE ];
	}

	/**
	 * Admin user ID.
	 */
	private function admin(): int {
		return $this->users['administrator'];
	}

	/**
	 * A valid review submission.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return array<string, mixed>
	 */
	private static function review( array $overrides = array() ): array {
		return $overrides + array(
			'rating'      => 2,
			'body'        => 'Slow service and the rice was cold, but the kelaguen was fine.',
			'author_name' => 'Pat',
			'email'       => 'pat@example.com',
			'website'     => '',
			'elapsed_ms'  => 20000,
		);
	}

	/**
	 * Logs in a subscriber, submits a review and approves it.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @param string               $client    Rate-limit key.
	 * @return int
	 */
	private function approved_review( array $overrides = array(), string $client = 'client-a' ): int {
		$current = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = Visitor_Reviews::submit( $this->restaurant, self::review( $overrides ), $client );
		wp_set_current_user( $current );
		$this->assertIsArray( $result );
		$this->assertTrue( Highlights::moderate( $result['id'], 'approved', '', $this->admin() ) );
		return $result['id'];
	}

	/**
	 * Extracts the token from the last confirmation email.
	 */
	private function token_from_mail(): string {
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertNotFalse( $mail );
		$this->assertSame( 1, preg_match( '/[?&]token=([A-Za-z0-9]{32})/', $mail->body, $match ) );
		return $match[1];
	}

	/*
	 * Roles and capabilities.
	 */

	/**
	 * Only administrators moderate; editors write notes; owners only write owner content.
	 */
	public function test_capabilities_by_role() {
		$expect = array(
			'administrator'   => array( true, true, true ),
			'editor'          => array( false, true, false ),
			'subscriber'      => array( false, false, false ),
			Roles::OWNER_ROLE => array( false, false, true ),
		);
		foreach ( $expect as $role => list( $moderate, $notes, $owner ) ) {
			$user = $this->users[ $role ];
			$this->assertSame( $moderate, user_can( $user, Roles::MODERATE ), "$role moderate" );
			$this->assertSame( $notes, user_can( $user, Roles::EDITOR ), "$role notes" );
			$this->assertSame( $owner, user_can( $user, Roles::OWNER ), "$role owner" );
		}
		$this->assertFalse( user_can( $this->owner(), 'edit_posts' ) );
		$this->assertFalse( user_can( $this->owner(), 'moderate_comments' ) );
	}

	/**
	 * Owner links use the restaurant's claimed-by meta, one owner per restaurant.
	 */
	public function test_owner_links() {
		$this->assertSame( array( $this->restaurant ), Roles::owned_restaurants( $this->owner() ) );
		$this->assertTrue( Roles::owns( $this->owner(), $this->restaurant ) );
		$this->assertFalse( Roles::owns( $this->owner(), $this->other ) );

		$second = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		Roles::set_owned_restaurants( $second, array( $this->restaurant ) );
		$this->assertFalse( Roles::owns( $this->owner(), $this->restaurant ), 'Replaced by the new owner' );

		Roles::set_owned_restaurants( $second, array() );
		$this->assertSame( array(), Roles::owned_restaurants( $second ) );
	}

	/*
	 * Moderation permissions.
	 */

	/**
	 * Owners, editors and subscribers cannot approve, reject (hide) or otherwise change a review.
	 */
	public function test_only_administrators_moderate() {
		$review = $this->approved_review();
		foreach ( array( Roles::OWNER_ROLE, 'editor', 'subscriber' ) as $role ) {
			foreach ( array( array( 'rejected', 'spam' ), array( 'approved', '' ) ) as list( $status, $reason ) ) {
				$result = Highlights::moderate( $review, $status, $reason, $this->users[ $role ] );
				$this->assertWPError( $result, $role );
				$this->assertSame( 'gr_highlight_forbidden', $result->get_error_code() );
			}
		}
		$this->assertSame( 'approved', Highlights::get( $review )['status'], 'Still published' );
	}

	/**
	 * Owners have no way to delete a review: no capability, no route.
	 */
	public function test_owners_cannot_delete_reviews() {
		$review = $this->approved_review();
		wp_set_current_user( $this->owner() );

		$this->assertFalse( current_user_can( 'delete_posts' ) );
		$this->assertFalse( current_user_can( Roles::MODERATE ) );
		foreach ( array( 'DELETE', 'PUT', 'PATCH' ) as $method ) {
			$request  = new WP_REST_Request( $method, '/guam-restaurants/v1/restaurants/' . $this->restaurant . '/visitor-reviews' );
			$response = rest_get_server()->dispatch( $request );
			$this->assertContains( $response->get_status(), array( 404, 405 ), $method );
		}
		$this->assertNotNull( Highlights::get( $review ) );
		$this->assertSame( 'approved', Highlights::get( $review )['status'] );
	}

	/**
	 * An administrator linked to a restaurant cannot moderate its reviews.
	 */
	public function test_moderator_conflict_of_interest() {
		$review = $this->approved_review();
		Roles::set_owned_restaurants( $this->admin(), array( $this->restaurant ) );

		$result = Highlights::moderate( $review, 'rejected', 'spam', $this->admin() );
		$this->assertSame( 'gr_highlight_conflict', $result->get_error_code() );

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( Highlights::moderate( $review, 'rejected', 'spam', $other_admin ) );
	}

	/**
	 * Rejections need a published content rule; a critical review is approved like any other.
	 */
	public function test_rejection_is_by_content_rule_only() {
		$this->assertSame( Vocabulary::MODERATION_REASONS, array_keys( Policy::rules() ) );
		foreach ( array( 'spam', 'profanity', 'personal_attack', 'undisclosed_connection' ) as $rule ) {
			$this->assertTrue( Policy::is_rule( $rule ), $rule );
		}
		foreach ( array( 'negative', 'low_rating', 'owner_request', '' ) as $reason ) {
			$this->assertFalse( Policy::is_rule( $reason ), $reason );
		}

		wp_set_current_user( self::factory()->user->create() );
		$result = Visitor_Reviews::submit( $this->restaurant, self::review( array( 'rating' => 1 ) ), 'c' );
		$this->assertSame( 'gr_review_reason', Highlights::moderate( $result['id'], 'rejected', 'negative', $this->admin() )->get_error_code() );
		$this->assertSame( 'pending', Highlights::get( $result['id'] )['status'] );
		$this->assertTrue( Highlights::moderate( $result['id'], 'approved', '', $this->admin() ) );
		$this->assertSame( 1, Visitor_Reviews::approved( $this->restaurant )[0]['rating'] );
	}

	/*
	 * Owner quotes.
	 */

	/**
	 * An owner's quote with dishes is saved as pending; nothing shows until approved.
	 */
	public function test_owner_quote() {
		$id = Highlights::submit_owner_quote(
			$this->restaurant,
			array(
				'body'   => 'Family kitchen since 1998, cooking village-style Chamorro food.',
				'dishes' => "Chicken kelaguen\nRed rice, Kadon pika\n",
			),
			$this->owner()
		);
		$this->assertIsInt( $id );
		$row = Highlights::get( $id );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( "Chicken kelaguen\nRed rice\nKadon pika", $row['dishes'] );
		$this->assertSame( 'Owner Person', $row['author_name'], 'Defaults to the display name' );
		$this->assertStringNotContainsString( 'Family kitchen', Display::render( $this->restaurant, false ) );

		Highlights::moderate( $id, 'approved', '', $this->admin() );
		$html = Display::render( $this->restaurant, false );
		$this->assertStringContainsString( 'From the owner', $html );
		$this->assertStringContainsString( 'Family kitchen', $html );
		$this->assertStringContainsString( '<li>Kadon pika</li>', $html );
	}

	/**
	 * Owner quotes need the owner capability and a link to that restaurant.
	 */
	public function test_owner_quote_permissions() {
		$input = array( 'body' => 'A short description of the restaurant.' );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_owner_quote( $this->other, $input, $this->owner() )->get_error_code(), 'Not their restaurant' );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_owner_quote( $this->restaurant, $input, $this->users['editor'] )->get_error_code() );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_owner_quote( $this->restaurant, $input, $this->users['subscriber'] )->get_error_code() );

		// A subscriber linked to a restaurant still needs the owner role.
		Roles::set_owned_restaurants( $this->users['subscriber'], array( $this->other ) );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_owner_quote( $this->other, $input, $this->users['subscriber'] )->get_error_code() );
	}

	/**
	 * Owner quote validation: length, dish count and dish length.
	 */
	public function test_owner_quote_validation() {
		$result = Highlights::submit_owner_quote(
			$this->restaurant,
			array(
				'body'   => 'Too short',
				'dishes' => array( 'a', 'b', 'c', 'd', 'e', 'f' ),
			),
			$this->owner()
		);
		$this->assertEqualsCanonicalizing( array( 'gr_review_body', 'gr_highlight_dishes' ), $result->get_error_codes() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$result = Highlights::submit_owner_quote(
			$this->restaurant,
			array(
				'body'   => str_repeat( 'x', Policy::OWNER_QUOTE_MAX + 1 ),
				'dishes' => array( str_repeat( 'y', Policy::MAX_DISH_LENGTH + 1 ) ),
			),
			$this->owner()
		);
		$this->assertEqualsCanonicalizing( array( 'gr_review_body', 'gr_highlight_dish_length' ), $result->get_error_codes() );

		$draft = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		Roles::set_owned_restaurants( $this->owner(), array( $draft ) );
		$this->assertSame( 'gr_review_restaurant', Highlights::submit_owner_quote( $draft, array( 'body' => str_repeat( 'z', 30 ) ), $this->owner() )->get_error_code() );
	}

	/*
	 * Editor's notes.
	 */

	/**
	 * Staff write editor's notes; others cannot; a connected staff member cannot.
	 */
	public function test_editor_note_permissions_and_validation() {
		$note = array(
			'body'       => 'The fried lumpia here are crisp and generous; go early on weekends.',
			'disclosure' => 'The restaurant paid for this meal.',
		);
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_editor_note( $this->restaurant, $note, $this->owner() )->get_error_code() );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_editor_note( $this->restaurant, $note, $this->users['subscriber'] )->get_error_code() );

		$this->assertSame( 'gr_review_body', Highlights::submit_editor_note( $this->restaurant, array( 'body' => 'Short.' ), $this->users['editor'] )->get_error_code() );
		$long = Highlights::submit_editor_note(
			$this->restaurant,
			array(
				'body'       => $note['body'],
				'disclosure' => str_repeat( 'd', 201 ),
			),
			$this->users['editor']
		);
		$this->assertSame( 'gr_highlight_disclosure', $long->get_error_code() );

		$id = Highlights::submit_editor_note( $this->restaurant, $note, $this->users['editor'] );
		$this->assertIsInt( $id );
		$this->assertSame( 'pending', Highlights::get( $id )['status'] );

		Roles::set_owned_restaurants( $this->users['editor'], array( $this->other ) );
		$this->assertSame( 'gr_highlight_conflict', Highlights::submit_editor_note( $this->other, $note, $this->users['editor'] )->get_error_code() );
	}

	/*
	 * Owner responses.
	 */

	/**
	 * An owner may post one public response to a published review of their restaurant.
	 */
	public function test_owner_response() {
		$review = $this->approved_review();

		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_owner_response( $review, array( 'body' => 'Thanks!' ), $this->users['subscriber'] )->get_error_code() );
		$stranger = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		Roles::set_owned_restaurants( $stranger, array( $this->other ) );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::submit_owner_response( $review, array( 'body' => 'Thanks!' ), $stranger )->get_error_code(), 'Owner of another restaurant' );
		$this->assertSame( 'gr_review_body', Highlights::submit_owner_response( $review, array( 'body' => '' ), $this->owner() )->get_error_code() );

		$response = Highlights::submit_owner_response( $review, array( 'body' => 'Sorry about the wait; we have added a second cook.' ), $this->owner() );
		$this->assertIsInt( $response );
		$this->assertSame( 'gr_highlight_response_exists', Highlights::submit_owner_response( $review, array( 'body' => 'Again' ), $this->owner() )->get_error_code() );
		$this->assertNull( Visitor_Reviews::approved( $this->restaurant )[0]['response'], 'Pending responses are not shown' );

		Highlights::moderate( $response, 'approved', '', $this->admin() );
		$shown = Visitor_Reviews::approved( $this->restaurant )[0];
		$this->assertSame( 'Sorry about the wait; we have added a second cook.', $shown['response']['text'] );
		$this->assertSame( 'Slow service and the rice was cold, but the kelaguen was fine.', $shown['text'], 'Review unchanged' );
	}

	/**
	 * Responses only to published reviews; a rejected response may be rewritten.
	 */
	public function test_owner_response_targets() {
		wp_set_current_user( self::factory()->user->create() );
		$pending = Visitor_Reviews::submit( $this->restaurant, self::review(), 'p' )['id'];
		$this->assertSame( 'gr_highlight_review', Highlights::submit_owner_response( $pending, array( 'body' => 'Hi' ), $this->owner() )->get_error_code() );
		$this->assertSame( 'gr_highlight_review', Highlights::submit_owner_response( 999999, array( 'body' => 'Hi' ), $this->owner() )->get_error_code() );

		$review = $this->approved_review( array(), 'q' );
		$first  = Highlights::submit_owner_response( $review, array( 'body' => 'You are an idiot.' ), $this->owner() );
		Highlights::moderate( $first, 'rejected', 'personal_attack', $this->admin() );
		$this->assertIsInt( Highlights::submit_owner_response( $review, array( 'body' => 'Thank you for the feedback.' ), $this->owner() ) );
	}

	/**
	 * Owners cannot review their own restaurant.
	 */
	public function test_owner_cannot_review_own_restaurant() {
		wp_set_current_user( $this->owner() );
		$this->assertSame( 'gr_review_owner', Visitor_Reviews::submit( $this->restaurant, self::review(), 'o' )->get_error_code() );
		$this->assertIsArray( Visitor_Reviews::submit( $this->other, self::review(), 'o' ) );
	}

	/*
	 * User review submission: login or email.
	 */

	/**
	 * Without a login the email is required and validated; nothing is stored until confirmed.
	 */
	public function test_email_submission_and_confirmation() {
		$missing = Visitor_Reviews::submit( $this->restaurant, self::review( array( 'email' => 'not-an-email' ) ), 'e' );
		$this->assertContains( 'gr_review_email', $missing->get_error_codes() );

		$result = Visitor_Reviews::submit( $this->restaurant, self::review(), 'e' );
		$this->assertSame(
			array(
				'status' => 'awaiting_email',
				'id'     => 0,
			),
			$result
		);
		$this->assertSame( 0, Highlights::count( 'pending' ), 'Nothing stored before confirmation' );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'pat@example.com', $mail->to[0][0] );

		$token     = $this->token_from_mail();
		$confirmed = Visitor_Reviews::confirm( $token );
		$this->assertIsArray( $confirmed );
		$row = Highlights::get( $confirmed['id'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '1', $row['email_verified'] );
		$this->assertNull( $row['author_user_id'] );
		$this->assertStringNotContainsString( 'pat@example.com', (string) wp_json_encode( $row ), 'Email is not stored' );

		$this->assertSame( 'gr_review_token', Visitor_Reviews::confirm( $token )->get_error_code(), 'Single use' );
		$this->assertSame( 'gr_review_token', Visitor_Reviews::confirm( str_repeat( 'a', 32 ) )->get_error_code() );
		$this->assertSame( 'gr_review_token', Visitor_Reviews::confirm( '../x' )->get_error_code() );
	}

	/**
	 * Logged-in visitors skip the email step; the badge is opt-in and email-only.
	 */
	public function test_logged_in_submission_and_badge() {
		$user = self::factory()->user->create( array( 'user_email' => 'sam@example.com' ) );
		wp_set_current_user( $user );

		$plain = Visitor_Reviews::submit( $this->restaurant, self::review( array( 'email' => '' ) ), 'l1' );
		$this->assertSame( 'pending', $plain['status'] );
		$this->assertSame( '0', Highlights::get( $plain['id'] )['email_verified'] );
		$this->assertSame( (string) $user, Highlights::get( $plain['id'] )['author_user_id'] );

		$badge  = Visitor_Reviews::submit( $this->other, self::review( array( 'verify_email' => true ) ), 'l2' );
		$mailer = tests_retrieve_phpmailer_instance();
		$last   = end( $mailer->mock_sent );
		$this->assertSame( 'sam@example.com', $last['to'][0][0], 'Link goes to the account email' );
		Visitor_Reviews::confirm( $this->token_from_last_mail() );
		$this->assertSame( '1', Highlights::get( $badge['id'] )['email_verified'] );

		Highlights::moderate( $badge['id'], 'approved', '', $this->admin() );
		$this->assertTrue( Visitor_Reviews::approved( $this->other )[0]['email_verified'] );
		$this->assertSame( 'Verified email', Policy::badge_label() );
		$this->assertStringNotContainsStringIgnoringCase( 'diner', Policy::badge_label() );
		$this->assertStringContainsString( 'not checked that they ate here', Policy::badge_explanation() );
	}

	/**
	 * Token from the newest email sent.
	 */
	private function token_from_last_mail(): string {
		$mailer = tests_retrieve_phpmailer_instance();
		$last   = end( $mailer->mock_sent );
		$this->assertSame( 1, preg_match( '/[?&]token=([A-Za-z0-9]{32})/', $last['body'], $match ) );
		return $match[1];
	}

	/**
	 * Honeypot and fill time apply to email submissions; limits apply per email address too.
	 */
	public function test_spam_guards_for_email_submissions() {
		$this->assertSame( 'gr_review_rejected', Visitor_Reviews::submit( $this->restaurant, self::review( array( 'website' => 'x' ) ), 's' )->get_error_code() );
		$this->assertSame( 'gr_review_rejected', Visitor_Reviews::submit( $this->restaurant, self::review( array( 'elapsed_ms' => 100 ) ), 's' )->get_error_code() );
		$this->assertSame( 0, count( tests_retrieve_phpmailer_instance()->mock_sent ), 'No email sent to bots' );

		// Same email from three different visitors, then a fourth restaurant: over the hourly limit.
		$ids   = array( $this->restaurant, $this->other );
		$ids[] = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		foreach ( $ids as $n => $id ) {
			$this->assertIsArray( Visitor_Reviews::submit( $id, self::review(), "visitor-$n" ) );
		}
		$fourth = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'gr_review_rate', Visitor_Reviews::submit( $fourth, self::review(), 'visitor-new' )->get_error_code() );
		$this->assertSame( 'gr_review_rate', Visitor_Reviews::submit( $fourth, self::review( array( 'email' => 'PAT@Example.com' ) ), 'visitor-x' )->get_error_code(), 'Same address in other case' );
		$this->assertIsArray( Visitor_Reviews::submit( $fourth, self::review( array( 'email' => 'lee@example.com' ) ), 'visitor-y' ) );
	}

	/**
	 * REST: an email submission answers 202; the confirmation link redirects back to the restaurant.
	 */
	public function test_rest_email_flow() {
		$post = new WP_REST_Request( 'POST', '/guam-restaurants/v1/restaurants/' . $this->restaurant . '/visitor-reviews' );
		$post->set_header( 'Content-Type', 'application/json' );
		$post->set_body( (string) wp_json_encode( self::review() ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		$response               = rest_get_server()->dispatch( $post );
		$this->assertSame( 202, $response->get_status() );
		$this->assertSame( 'awaiting_email', $response->get_data()['status'] );

		$confirm = new WP_REST_Request( 'GET', '/guam-restaurants/v1/visitor-reviews/confirm' );
		$confirm->set_query_params( array( 'token' => $this->token_from_mail() ) );
		$redirect = rest_get_server()->dispatch( $confirm );
		$this->assertSame( 302, $redirect->get_status() );
		$this->assertStringContainsString( 'gr_review=confirmed', $redirect->get_headers()['Location'] );
		$this->assertStringStartsWith( (string) get_permalink( $this->restaurant ), $redirect->get_headers()['Location'] );
		$this->assertSame( 1, Highlights::count( 'pending', Policy::USER_REVIEW ) );

		$again = rest_get_server()->dispatch( $confirm );
		$this->assertStringContainsString( 'gr_review=expired', $again->get_headers()['Location'] );
	}

	/**
	 * Expired tokens are gone; tokens are stored hashed.
	 */
	public function test_token_storage() {
		$token = Email_Verification::create( array( 'kind' => 'x' ) );
		$this->assertFalse( get_transient( Email_Verification::PREFIX . $token ) );
		$this->assertSame( array( 'kind' => 'x' ), Email_Verification::take( $token ) );
		$this->assertNull( Email_Verification::take( $token ) );
	}

	/*
	 * Display.
	 */

	/**
	 * Owner and editor content sit in their own labeled boxes, apart from user reviews.
	 */
	public function test_sections_are_separate_and_labeled() {
		$quote = Highlights::submit_owner_quote( $this->restaurant, array( 'body' => 'We grill everything over tangantangan wood.' ), $this->owner() );
		$note  = Highlights::submit_editor_note( $this->restaurant, array( 'body' => 'Our pick for a quick lunch near the beach.' ), $this->users['editor'] );
		Highlights::moderate( $quote, 'approved', '', $this->admin() );
		Highlights::moderate( $note, 'approved', '', $this->admin() );

		$html = Display::render( $this->restaurant );
		$this->assertStringContainsString( '>Highlights</h2>', $html );
		$this->assertStringContainsString( 'gr-highlight--owner', $html );
		$this->assertStringContainsString( 'Editor&#039;s note', $html );
		$this->assertStringContainsString( '<span class="notranslate" translate="no">Editor Person</span>, staff writer', $html );
		$this->assertStringContainsString( '>User reviews</h3>', $html );
		$this->assertStringContainsString( esc_url( Policy_Page::url() ), $html );

		// Owner and editor boxes come before, and outside, the user reviews section.
		$first_party_end = strpos( $html, '</div>' );
		$this->assertLessThan( strpos( $html, 'gr-visitor-reviews' ), $first_party_end );
		$this->assertLessThan( $first_party_end, strpos( $html, 'tangantangan' ) );
		$this->assertLessThan( $first_party_end, strpos( $html, 'quick lunch' ) );
	}

	/**
	 * The section is added to restaurant pages once, and never shows reviews twice.
	 */
	public function test_auto_section_has_no_double_reviews() {
		$this->go_to( get_permalink( $this->restaurant ) );
		$this->assertTrue( is_singular( Content_Types::POST_TYPE ) );
		while ( have_posts() ) {
			the_post();
			$plain = apply_filters( 'the_content', 'About us.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$with  = apply_filters( 'the_content', '<!-- wp:guam-restaurants/visitor-reviews /-->' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$block = apply_filters( 'the_content', '<!-- wp:guam-restaurants/highlights /-->' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}
		$this->assertSame( 1, substr_count( $plain, 'class="gr-highlights"' ) );
		$this->assertSame( 1, substr_count( $plain, 'data-gr-visitor-reviews=' ) );
		$this->assertSame( 1, substr_count( $with, 'data-gr-visitor-reviews=' ), 'Reviews block already on the page' );
		$this->assertSame( 1, substr_count( $block, 'class="gr-highlights"' ), 'Block placed by hand' );
	}

	/*
	 * Structured data.
	 */

	/**
	 * Review markup only for user reviews: none for owner quotes, responses or editor's notes.
	 */
	public function test_structured_data_is_not_self_serving() {
		$quote = Highlights::submit_owner_quote( $this->restaurant, array( 'body' => 'Best barbecue on the island, says us.' ), $this->owner() );
		Highlights::moderate( $quote, 'approved', '', $this->admin() );
		$this->assertNull( Display::structured_data( $this->restaurant ), 'Owner quote alone gives nothing' );

		$review   = $this->approved_review( array( 'rating' => 4 ) );
		$response = Highlights::submit_owner_response( $review, array( 'body' => 'Thank you for visiting!' ), $this->owner() );
		Highlights::moderate( $response, 'approved', '', $this->admin() );

		$node = Structured_Data::node( $this->restaurant );
		$json = (string) wp_json_encode( $node );
		$this->assertSame( 'Restaurant', $node['@type'] );
		$this->assertCount( 1, $node['review'] );
		$this->assertSame( 'Pat', $node['review'][0]['author']['name'] );
		$this->assertSame( 4, $node['review'][0]['reviewRating']['ratingValue'] );
		$this->assertSame( 1, $node['aggregateRating']['reviewCount'] );
		$this->assertStringNotContainsString( 'barbecue', $json );
		$this->assertStringNotContainsString( 'Thank you for visiting', $json );
	}

	/**
	 * Reviews with a disclosed connection are left out, and so is an aggregate that would no longer match.
	 */
	public function test_structured_data_leaves_out_connected_reviews() {
		$this->approved_review( array( 'rating' => 5 ), 'a' );
		$this->approved_review(
			array(
				'rating'     => 5,
				'connected'  => true,
				'connection' => 'My cousin cooks here',
			),
			'b'
		);
		$data = Display::structured_data( $this->restaurant );
		$this->assertCount( 1, $data['review'] );
		$this->assertArrayNotHasKey( 'aggregateRating', $data );

		add_filter( 'guam_restaurants_review_structured_data', '__return_false' );
		add_filter( 'guam_restaurants_business_structured_data', '__return_false' );
		$this->assertNull( Structured_Data::node( $this->restaurant ) );
	}

	/*
	 * Editorial policy page.
	 */

	/**
	 * The policy page lists every rule a moderator can pick, and the badge's meaning.
	 */
	public function test_policy_page() {
		$html = do_shortcode( '[guam_editorial_policy]' );
		foreach ( Policy::rules() as $text ) {
			$this->assertStringContainsString( esc_html( $text ), $html );
		}
		$this->assertStringContainsString( 'cannot be bought', $html );
		$this->assertStringContainsString( 'Owners cannot edit, hide or delete reviews', $html );
		$this->assertStringContainsString( esc_html( Policy::badge_explanation() ), $html );

		$id = Policy_Page::ensure();
		$this->assertSame( 'editorial-policy', get_post_field( 'post_name', $id ) );
		$this->assertTrue( has_shortcode( (string) get_post_field( 'post_content', $id ), Policy_Page::SHORTCODE ) );
		$this->assertSame( $id, Policy_Page::ensure(), 'Idempotent' );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $id ), '[guam_editorial_policy]' ) );
	}
}
