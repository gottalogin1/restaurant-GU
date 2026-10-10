<?php
/**
 * Listing claims and what a claimed owner may and may not change.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Claims\Admin as Claims_Admin;
use GuamRestaurants\Core\Claims\Claim_Form;
use GuamRestaurants\Core\Claims\Claims;
use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Highlights\Policy;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Restaurant_Meta;
use WP_REST_Request;

/**
 * Claim permissions.
 *
 * @covers \GuamRestaurants\Core\Claims\Claims
 * @covers \GuamRestaurants\Core\Owners\Owner_Content
 */
class Claims_Test extends Test_Case {

	public const CODE = '123456';

	/**
	 * Restaurants by key.
	 *
	 * @var array<string, int>
	 */
	private array $r = array();

	/**
	 * Users by key.
	 *
	 * @var array<string, int>
	 */
	private array $u = array();

	/**
	 * Restaurants, users and a fixed code.
	 */
	public function set_up() {
		parent::set_up();
		putenv( self::phone_env() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		foreach ( array(
			'grill'    => array( 'Test Grill', 'publish', 'https://www.testgrill.com/menu', '+16715551234' ),
			'facebook' => array( 'Page Only Cafe', 'publish', 'https://www.facebook.com/pageonlycafe', '+16715550000' ),
			'nosite'   => array( 'No Site Diner', 'publish', '', '' ),
			'draft'    => array( 'Draft Place', 'draft', 'https://draftplace.com', '' ),
			'other'    => array( 'Other Place', 'publish', 'https://other.example', '' ),
		) as $key => $row ) {
			$this->r[ $key ] = self::factory()->post->create(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_title'  => $row[0],
					'post_status' => $row[1],
				)
			);
			update_post_meta( $this->r[ $key ], Restaurant_Meta::WEBSITE, $row[2] );
			update_post_meta( $this->r[ $key ], Restaurant_Meta::PHONE, $row[3] );
		}
		foreach ( array(
			'claimant' => 'subscriber',
			'rival'    => 'subscriber',
			'admin'    => 'administrator',
			'admin2'   => 'administrator',
			'editor'   => 'editor',
			'owner'    => Roles::OWNER_ROLE,
		) as $key => $role ) {
			$this->u[ $key ] = self::factory()->user->create( array( 'role' => $role ) );
		}
		add_filter(
			'guam_restaurants_claim_code',
			static function () {
				return self::CODE;
			}
		);
		reset_phpmailer_instance();
	}

	/**
	 * Clears the phone switch.
	 */
	public function tear_down() {
		putenv( self::phone_env() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * The phone switch variable, unset.
	 *
	 * @return string
	 */
	private static function phone_env(): string {
		return 'GUAM_RESTAURANTS_CLAIM_PHONE_CODES';
	}

	/**
	 * Requests and verifies a claim on the grill for a user.
	 *
	 * @param string $user User key.
	 * @return int Claim ID.
	 */
	private function verified_claim( string $user = 'claimant' ): int {
		$claim = Claims::request( $this->u[ $user ], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' );
		$this->assertIsInt( $claim );
		$this->assertTrue( Claims::verify( $claim, $this->u[ $user ], self::CODE ) );
		return $claim;
	}

	/**
	 * Email verification needs an address at the restaurant's own domain.
	 */
	public function test_email_domain_rules() {
		$this->assertSame( 'testgrill.com', Claims::claim_domain( $this->r['grill'] ) );
		$this->assertSame( '', Claims::claim_domain( $this->r['facebook'] ), 'Shared hosts prove nothing' );
		$this->assertSame( '', Claims::claim_domain( $this->r['nosite'] ) );

		$this->assertTrue( Claims::email_matches_domain( 'Owner@TestGrill.com', 'testgrill.com' ) );
		$this->assertTrue( Claims::email_matches_domain( 'chef@mail.testgrill.com', 'testgrill.com' ) );
		$this->assertTrue( Claims::email_matches_domain( 'owner@testgrill.com', 'order.testgrill.com' ) );
		$this->assertFalse( Claims::email_matches_domain( 'owner@gmail.com', 'testgrill.com' ) );
		$this->assertFalse( Claims::email_matches_domain( 'owner@nottestgrill.com', 'testgrill.com' ) );
		$this->assertFalse( Claims::email_matches_domain( 'owner@testgrill.com.evil.io', 'testgrill.com' ) );
		$this->assertFalse( Claims::email_matches_domain( 'owner@com', 'testgrill.com' ) );

		$this->assertSame( array( Claims::METHOD_EMAIL ), Claims::methods( $this->r['grill'] ) );
		$this->assertSame( array(), Claims::methods( $this->r['facebook'] ) );

		$result = Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@gmail.com' );
		$this->assertWPError( $result );
		$this->assertSame( 'gr_claim_email', $result->get_error_code() );
		$result = Claims::request( $this->u['claimant'], $this->r['facebook'], Claims::METHOD_EMAIL, 'owner@facebook.com' );
		$this->assertSame( 'gr_claim_method', $result->get_error_code() );
	}

	/**
	 * Logged-out visitors, unpublished and already-claimed listings are refused.
	 */
	public function test_request_preconditions() {
		$this->assertSame( 'gr_claim_login', Claims::request( 0, $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' )->get_error_code() );
		$this->assertSame( 'gr_claim_restaurant', Claims::request( $this->u['claimant'], $this->r['draft'], Claims::METHOD_EMAIL, 'a@draftplace.com' )->get_error_code() );

		update_post_meta( $this->r['grill'], Restaurant_Meta::CLAIMED_BY_USER_ID, $this->u['rival'] );
		$this->assertSame( 'gr_claim_taken', Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' )->get_error_code() );
		$this->assertSame( 'gr_claim_yours', Claims::request( $this->u['rival'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' )->get_error_code() );
	}

	/**
	 * A code is emailed, stored only as a hash, and expires.
	 */
	public function test_code_is_emailed_and_hashed() {
		$claim = Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'Owner@TestGrill.com' );
		$this->assertIsInt( $claim );
		$row = Claims::get( $claim );
		$this->assertSame( Claims::PENDING, $row['status'] );
		$this->assertSame( 'owner@testgrill.com', $row['contact'] );
		$this->assertNotSame( self::CODE, $row['code_hash'] );
		$this->assertStringNotContainsString( self::CODE, $row['code_hash'] );

		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'owner@testgrill.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( self::CODE, $sent[0]['body'] );

		global $wpdb;
		$wpdb->update( Schema::table( Schema::CLAIMS ), array( 'code_expires' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $claim ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'gr_claim_expired', Claims::verify( $claim, $this->u['claimant'], self::CODE )->get_error_code() );
		$this->assertSame( Claims::CANCELLED, Claims::get( $claim )['status'] );
	}

	/**
	 * Wrong codes count; five end the claim. Only the claimant can verify.
	 */
	public function test_wrong_codes_and_other_users() {
		$claim = Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' );
		$this->assertSame( 'gr_claim_missing', Claims::verify( $claim, $this->u['rival'], self::CODE )->get_error_code(), 'Someone else cannot use the code' );
		for ( $i = 1; $i < Claims::MAX_ATTEMPTS; $i++ ) {
			$this->assertSame( 'gr_claim_code', Claims::verify( $claim, $this->u['claimant'], '000000' )->get_error_code() );
		}
		$this->assertSame( 'gr_claim_locked', Claims::verify( $claim, $this->u['claimant'], '000000' )->get_error_code() );
		$this->assertSame( 'gr_claim_state', Claims::verify( $claim, $this->u['claimant'], self::CODE )->get_error_code(), 'The right code is too late' );
	}

	/**
	 * A verified claim links nothing until an administrator approves it.
	 */
	public function test_verification_alone_links_nothing() {
		$claim = $this->verified_claim();
		$this->assertSame( Claims::VERIFIED, Claims::get( $claim )['status'] );
		$this->assertSame( 0, (int) get_post_meta( $this->r['grill'], Restaurant_Meta::CLAIMED_BY_USER_ID, true ) );
		$this->assertFalse( user_can( $this->u['claimant'], Roles::OWNER ) );
		$this->assertFalse( Roles::can_speak_for( $this->u['claimant'], $this->r['grill'] ) );
		$this->assertWPError( Owner_Content::apply( $this->u['claimant'], $this->r['grill'], array( 'menu_links' => array() ) ) );

		$mail = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertStringContainsString( 'Listing claim to review', end( $mail )['subject'], 'Administrators are told' );
	}

	/**
	 * Only administrators approve, only verified claims, never their own.
	 */
	public function test_who_can_approve() {
		$claim = Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' );
		$this->assertSame( 'gr_claim_state', Claims::approve( $claim, $this->u['admin'] )->get_error_code(), 'Unverified claims cannot be approved' );
		Claims::verify( $claim, $this->u['claimant'], self::CODE );

		foreach ( array( 'claimant', 'rival', 'editor', 'owner' ) as $user ) {
			$this->assertSame( 'gr_claim_forbidden', Claims::approve( $claim, $this->u[ $user ] )->get_error_code(), $user . ' cannot approve' );
			$this->assertSame( 'gr_claim_forbidden', Claims::reject( $claim, $this->u[ $user ], 'no' )->get_error_code(), $user . ' cannot reject' );
		}
		$this->assertSame( 0, (int) get_post_meta( $this->r['grill'], Restaurant_Meta::CLAIMED_BY_USER_ID, true ) );

		$own = Claims::request( $this->u['admin'], $this->r['other'], Claims::METHOD_EMAIL, 'me@other.example' );
		Claims::verify( $own, $this->u['admin'], self::CODE );
		$this->assertSame( 'gr_claim_self', Claims::approve( $own, $this->u['admin'] )->get_error_code() );
		$this->assertTrue( Claims::approve( $own, $this->u['admin2'] ) );
	}

	/**
	 * Approval links the listing, adds the owner role and closes rival claims.
	 */
	public function test_approval_links_owner() {
		$rival = $this->verified_claim( 'rival' );
		$claim = $this->verified_claim();
		$this->assertTrue( Claims::approve( $claim, $this->u['admin'] ) );

		$this->assertSame( $this->u['claimant'], (int) get_post_meta( $this->r['grill'], Restaurant_Meta::CLAIMED_BY_USER_ID, true ) );
		$user = get_userdata( $this->u['claimant'] );
		$this->assertContains( Roles::OWNER_ROLE, $user->roles );
		$this->assertContains( 'subscriber', $user->roles, 'Existing roles are kept' );
		$this->assertTrue( Roles::can_speak_for( $this->u['claimant'], $this->r['grill'] ) );
		$this->assertSame( Claims::APPROVED, Claims::get( $claim )['status'] );
		$this->assertSame( Claims::CANCELLED, Claims::get( $rival )['status'] );
		$this->assertSame( 'gr_claim_state', Claims::approve( $rival, $this->u['admin'] )->get_error_code() );
		$this->assertSame( 'gr_claim_taken', Claims::request( $this->u['rival'], $this->r['grill'], Claims::METHOD_EMAIL, 'x@testgrill.com' )->get_error_code() );
	}

	/**
	 * A rejected claim links nothing.
	 */
	public function test_rejection() {
		$claim = $this->verified_claim();
		$this->assertTrue( Claims::reject( $claim, $this->u['admin'], 'Address is not the restaurant\'s' ) );
		$this->assertSame( Claims::REJECTED, Claims::get( $claim )['status'] );
		$this->assertSame( 0, (int) get_post_meta( $this->r['grill'], Restaurant_Meta::CLAIMED_BY_USER_ID, true ) );
		$this->assertFalse( user_can( $this->u['claimant'], Roles::OWNER ) );
		$mail = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertStringContainsString( 'Address is not the restaurant', end( $mail )['body'] );
	}

	/**
	 * Phone codes are off by default and need a sender when on.
	 */
	public function test_phone_codes_are_optional() {
		$this->assertFalse( Claims::phone_enabled() );
		$this->assertSame( 'gr_claim_method', Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_PHONE )->get_error_code() );

		putenv( self::phone_env() . '=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		$this->assertSame( array( Claims::METHOD_EMAIL, Claims::METHOD_PHONE ), Claims::methods( $this->r['grill'] ) );
		$this->assertSame( array( Claims::METHOD_PHONE ), Claims::methods( $this->r['facebook'] ) );
		$this->assertSame( 'gr_claim_send', Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_PHONE )->get_error_code(), 'No SMS sender, no claim' );

		$texts = array();
		add_filter(
			'guam_restaurants_send_claim_sms',
			static function ( $sent, $phone, $message ) use ( &$texts ) {
				$texts[] = array( $phone, $message );
				return true;
			},
			10,
			3
		);
		$claim = Claims::request( $this->u['claimant'], $this->r['facebook'], Claims::METHOD_PHONE );
		$this->assertIsInt( $claim );
		$this->assertSame( '+16715550000', $texts[0][0], 'The code goes to the listed phone, not one the claimant types' );
		$this->assertStringContainsString( self::CODE, $texts[0][1] );
		$this->assertTrue( Claims::verify( $claim, $this->u['claimant'], self::CODE ) );
	}

	/**
	 * Code requests are limited per user.
	 */
	public function test_rate_limit() {
		for ( $i = 0; $i < Claims::SENDS_PER_HOUR; $i++ ) {
			$this->assertIsInt( Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' ) );
		}
		$this->assertSame( 'gr_claim_rate', Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' )->get_error_code() );
		$this->assertCount( 1, Claims::for_user( $this->u['claimant'], Claims::OPEN_STATUSES ), 'A new request replaces the old one' );
	}

	/**
	 * A claimed owner can edit hours, menu links, specials and photos.
	 */
	public function test_owner_can_edit_allowed_fields() {
		$this->assertTrue( Claims::approve( $this->verified_claim(), $this->u['admin'] ) );
		$owner = $this->u['claimant'];
		$photo = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $this->r['grill'] );
		wp_update_post(
			array(
				'ID'          => $photo,
				'post_author' => $owner,
			)
		);
		$stranger = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $this->r['grill'] );

		$result = Owner_Content::apply(
			$owner,
			$this->r['grill'],
			array(
				'hours'      => array(
					'mo' => '11:00-14:00, 17:00-21:00',
					'sa' => '9:00 - 24:00',
				),
				'menu_links' => array(
					array(
						'label' => 'Lunch',
						'url'   => 'https://testgrill.com/lunch.pdf',
					),
					array(
						'label' => '',
						'url'   => '',
					),
				),
				'specials'   => array(
					array(
						'title'   => 'Weekday lunch set',
						'details' => '$12 with drink',
						'ends'    => '2099-12-31',
					),
				),
				'photos'     => array( $photo, $stranger ),
			)
		);
		$this->assertIsArray( $result );
		$this->assertEqualSets( array( Restaurant_Meta::HOURS, Owner_Content::MENU_LINKS, Owner_Content::SPECIALS, Owner_Content::PHOTOS ), $result['changed'] );

		$hours = get_post_meta( $this->r['grill'], Restaurant_Meta::HOURS, true );
		$this->assertSame( 'owner', $hours['source'], 'Imports do not overwrite owner hours' );
		$this->assertSame(
			array(
				array(
					'day'   => 'mo',
					'open'  => '11:00',
					'close' => '14:00',
				),
				array(
					'day'   => 'mo',
					'open'  => '17:00',
					'close' => '21:00',
				),
				array(
					'day'   => 'sa',
					'open'  => '09:00',
					'close' => '24:00',
				),
			),
			$hours['weekly']
		);
		$fields = Owner_Content::get( $this->r['grill'] );
		$this->assertCount( 1, $fields['menu_links'] );
		$this->assertSame( 'Weekday lunch set', $fields['specials'][0]['title'] );
		$this->assertSame( array( $photo ), $fields['photos'], 'Only photos the owner uploaded to this listing' );

		global $wpdb;
		$sources = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source FROM %i WHERE restaurant_id = %d', Schema::table( Schema::CHANGE_LOG ), $this->r['grill'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertContains( 'owner', $sources, 'Owner edits are logged as the owner\'s' );

		$this->assertWPError( Owner_Content::apply( $owner, $this->r['grill'], array( 'hours' => array( 'mo' => '25:00-26:00' ) ) ) );
		$this->assertWPError( Owner_Content::apply( $owner, $this->r['grill'], array( 'specials' => array( array( 'title' => 'Award-winning ribs' ) ) ) ), 'No award claims in specials' );
		$this->assertWPError(
			Owner_Content::apply(
				$owner,
				$this->r['grill'],
				array(
					'menu_links' => array(
						array(
							'label' => 'Top pick menu',
							'url'   => 'https://testgrill.com',
						),
					),
				)
			)
		);
	}

	/**
	 * A claimed owner cannot change ratings, awards, status history or
	 * reviews, nor touch another restaurant.
	 */
	public function test_owner_cannot_reach_protected_data() {
		$this->assertTrue( Claims::approve( $this->verified_claim(), $this->u['admin'] ) );
		$owner = $this->u['claimant'];
		update_post_meta( $this->r['grill'], Restaurant_Meta::STATUS, 'open' );
		update_post_meta( $this->r['grill'], Restaurant_Meta::CLOSED_ON, '' );

		$result = Owner_Content::apply(
			$owner,
			$this->r['grill'],
			array(
				Restaurant_Meta::STATUS             => 'permanently_closed',
				Restaurant_Meta::CLOSED_ON          => '2020',
				'status'                            => 'temporarily_closed',
				'awards'                            => array( 'Best of Guam' ),
				'ratings'                           => array( 'google' => 5 ),
				'gr_google_place_id'                => 'fake',
				Restaurant_Meta::CLAIMED_BY_USER_ID => $this->u['rival'],
			)
		);
		$this->assertSame( array(), $result['changed'] );
		$this->assertCount( 7, $result['ignored'] );
		$this->assertSame( 'open', get_post_meta( $this->r['grill'], Restaurant_Meta::STATUS, true ) );
		$this->assertSame( '', get_post_meta( $this->r['grill'], Restaurant_Meta::CLOSED_ON, true ) );
		$this->assertSame( '', get_post_meta( $this->r['grill'], Restaurant_Meta::GOOGLE_PLACE_ID, true ) );
		$this->assertSame( $owner, (int) get_post_meta( $this->r['grill'], Restaurant_Meta::CLAIMED_BY_USER_ID, true ) );

		// No WordPress editing rights: the post editor, REST API and meta API stay closed.
		foreach ( array( 'edit_post', 'delete_post' ) as $cap ) {
			$this->assertFalse( user_can( $owner, $cap, $this->r['grill'] ), $cap );
		}
		foreach ( array( 'edit_posts', 'edit_others_posts', 'upload_files', 'manage_options', Roles::MODERATE, Roles::EDITOR, Claims::CAP ) as $cap ) {
			$this->assertFalse( user_can( $owner, $cap ), $cap );
		}
		wp_set_current_user( $owner );
		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Content_Types::POST_TYPE . '/' . $this->r['grill'] );
		$request->set_body_params( array( 'meta' => array( Restaurant_Meta::STATUS => 'permanently_closed' ) ) );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 401, 403, 404 ) );
		$this->assertSame( 'open', get_post_meta( $this->r['grill'], Restaurant_Meta::STATUS, true ) );
		wp_set_current_user( 0 );

		// Reviews: owners reply, but never approve, hide or delete.
		$review = Highlights::insert(
			array(
				'restaurant_id' => $this->r['grill'],
				'type'          => Policy::USER_REVIEW,
				'body'          => 'Great ribs and friendly staff.',
				'author_name'   => 'Visitor',
			)
		);
		$this->assertIsInt( $review );
		$this->assertTrue( Highlights::moderate( $review, 'approved', '', $this->u['admin'] ) );
		$this->assertSame( 'gr_highlight_forbidden', Highlights::moderate( $review, 'rejected', 'off_topic', $owner )->get_error_code() );
		$this->assertSame( 'approved', Highlights::get( $review )['status'] );
		$this->assertIsInt( Highlights::submit_owner_response( $review, array( 'body' => 'Thank you for visiting!' ), $owner ) );

		// Another restaurant is out of reach.
		$this->assertSame( 'gr_owner_forbidden', Owner_Content::apply( $owner, $this->r['other'], array( 'menu_links' => array() ) )->get_error_code() );
		$this->assertFalse( Roles::can_speak_for( $owner, $this->r['other'] ) );
	}

	/**
	 * The claim form, the owner block, the dashboard and the review screen render.
	 */
	public function test_screens_render() {
		$_GET['restaurant'] = (string) $this->r['grill'];
		$html               = Claim_Form::render();
		$this->assertStringContainsString( 'to claim this listing', $html, 'Logged out: log in first' );

		wp_set_current_user( $this->u['claimant'] );
		$html = Claim_Form::render();
		$this->assertStringContainsString( 'name="method" value="email"', $html );
		$this->assertStringContainsString( 'testgrill.com', $html );

		$claim = Claims::request( $this->u['claimant'], $this->r['grill'], Claims::METHOD_EMAIL, 'owner@testgrill.com' );
		$this->assertStringContainsString( 'name="code"', Claim_Form::render() );
		Claims::verify( $claim, $this->u['claimant'], self::CODE );
		$this->assertStringContainsString( 'waiting for review', Claim_Form::render() );

		wp_set_current_user( $this->u['admin'] );
		ob_start();
		Claims_Admin::screen();
		$this->assertStringContainsString( 'owner@testgrill.com', (string) ob_get_clean() );
		Claims::approve( $claim, $this->u['admin'] );

		Owner_Content::apply( $this->u['claimant'], $this->r['grill'], array( 'specials' => array( array( 'title' => 'Fiesta plate' ) ) ) );
		$this->go_to( get_permalink( $this->r['grill'] ) );
		$html = Owner_Content::render_block( array() );
		$this->assertStringContainsString( 'From the owner', $html );
		$this->assertStringContainsString( 'Fiesta plate', $html );

		wp_set_current_user( $this->u['claimant'] );
		ob_start();
		Dashboard::screen();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Listing views', $html );
		$this->assertStringContainsString( 'name="hours[mo]"', $html );
		unset( $_GET['restaurant'] );
	}
}
