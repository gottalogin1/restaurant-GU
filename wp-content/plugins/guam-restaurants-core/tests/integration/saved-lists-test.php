<?php
/**
 * Saved lists and visitor accounts: parsing, member lists, REST, sign-in
 * destinations, account deletion, privacy export and the views.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Database\Migrations;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Saved\Members;
use GuamRestaurants\Core\Saved\Saved_List;
use GuamRestaurants\Core\Saved\View;
use GuamRestaurants\Core\Site\Pages;
use GuamRestaurants\Core\Site\Restaurant_View;
use WP_REST_Request;

/**
 * Saved list tests.
 */
class Saved_Lists_Test extends Guam_Test_Case {

	/**
	 * Restaurants by key.
	 *
	 * @var array<string, int>
	 */
	private array $r = array();

	/**
	 * A few restaurants and the Saved page.
	 */
	public function set_up() {
		parent::set_up();
		$this->r['proa']  = $this->restaurant(
			'Proa',
			array(
				Restaurant_Meta::LATITUDE  => 13.5,
				Restaurant_Meta::LONGITUDE => 144.8,
			)
		);
		$this->r['sushi'] = $this->restaurant( 'Tumon Sushi Bar' );
		$this->r['bbq']   = $this->restaurant( 'Dededo BBQ' );
		$this->r['draft'] = $this->restaurant( 'Draft Noodles', array(), 'draft' );
		Pages::ensure();
	}

	/**
	 * A visitor account.
	 *
	 * @return int
	 */
	private function member(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * IDs are positive integers, in order; a repeat moves to the end.
	 */
	public function test_parse_ids_keeps_positive_integers_in_order_and_moves_repeats_to_the_end(): void {
		$this->assertSame( array( 3, 2, 1 ), Saved_List::parse_ids( '1, 3, x, -4, 0, 2, 1e3, 1' ) );
		$this->assertSame( array( 5, 7 ), Saved_List::parse_ids( array( 5, '7', 'seven', array( 8 ), 7.5 ) ) );
		$this->assertSame( array( 3, 4 ), Saved_List::parse_ids( '1,2,3,4', 2 ) );
		$this->assertSame( array(), Saved_List::parse_ids( null ) );
	}

	/**
	 * Only published restaurants are kept.
	 */
	public function test_published_drops_drafts_and_other_posts(): void {
		$page = self::factory()->post->create();
		$this->assertSame(
			array( $this->r['bbq'], $this->r['proa'] ),
			Saved_List::published( array( $this->r['bbq'], $this->r['draft'], $page, 999999, $this->r['proa'] ) )
		);
	}

	/**
	 * Adding skips unpublished and repeated restaurants; removing works.
	 */
	public function test_change_adds_published_restaurants_once_and_removes(): void {
		$user = $this->member();
		$this->assertSame( array( $this->r['proa'], $this->r['sushi'] ), Saved_List::change( $user, array( $this->r['proa'], $this->r['draft'], $this->r['sushi'] ) ) );
		// Merging a browser's list keeps the account's order and skips repeats.
		$this->assertSame( array( $this->r['proa'], $this->r['sushi'], $this->r['bbq'] ), Saved_List::change( $user, array( $this->r['bbq'], $this->r['proa'] ) ) );
		$this->assertSame( array( $this->r['proa'], $this->r['bbq'] ), Saved_List::change( $user, array(), array( $this->r['sushi'] ) ) );
		$this->assertSame( array( $this->r['proa'], $this->r['bbq'] ), Saved_List::get( $user ) );
		$this->assertSame( array(), Saved_List::change( 0, array( $this->r['proa'] ) ) );
	}

	/**
	 * A list keeps the newest MAX restaurants.
	 */
	public function test_list_is_capped_at_max_dropping_the_oldest(): void {
		$user = $this->member();
		$ids  = range( 1, Saved_List::MAX + 5 );
		update_user_meta( $user, Saved_List::META, $ids );
		$this->assertCount( Saved_List::MAX, Saved_List::get( $user ) );
		$this->assertSame( 6, Saved_List::get( $user )[0] );
	}

	/**
	 * A deleted restaurant leaves every list.
	 */
	public function test_deleting_a_restaurant_removes_it_from_lists(): void {
		$user = $this->member();
		Saved_List::change( $user, array( $this->r['proa'], $this->r['bbq'] ) );
		wp_delete_post( $this->r['proa'], true );
		$this->assertSame( array( $this->r['bbq'] ), Saved_List::get( $user ) );
	}

	/**
	 * A share link holds only the newest restaurant IDs.
	 */
	public function test_share_url_carries_only_the_newest_restaurant_ids(): void {
		$url = Saved_List::share_url( range( 1, Saved_List::SHARE_MAX + 3 ), 'https://example.com/saved/' );
		$this->assertStringStartsWith( 'https://example.com/saved/?list=4,5,', $url );
		$this->assertStringEndsWith( ',' . ( Saved_List::SHARE_MAX + 3 ), $url );
		$this->assertSame( '', Saved_List::share_url( array(), 'https://example.com/saved/' ) );
	}

	/**
	 * The cards route is public, cacheable and newest first.
	 */
	public function test_cards_route_is_public_and_shows_published_restaurants_newest_first(): void {
		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/saved/cards' );
		$request->set_query_params( array( 'ids' => $this->r['proa'] . ',' . $this->r['draft'] . ',' . $this->r['sushi'] ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->r['proa'], $this->r['sushi'] ), $data['ids'] );
		$this->assertStringContainsString( '2 saved restaurants', $data['html'] );
		$this->assertLessThan( strpos( $data['html'], 'Proa' ), strpos( $data['html'], 'Tumon Sushi Bar' ) );
		$this->assertStringContainsString( 'data-gr-pin="1"', $data['html'] );
		$this->assertStringNotContainsString( 'Draft Noodles', $data['html'] );
		$this->assertStringStartsWith( 'public', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * Reading or changing an account list needs sign-in.
	 */
	public function test_member_routes_need_sign_in(): void {
		wp_set_current_user( 0 );
		foreach ( array( 'GET', 'POST' ) as $method ) {
			$response = rest_get_server()->dispatch( new WP_REST_Request( $method, '/guam-restaurants/v1/saved' ) );
			$this->assertSame( 401, $response->get_status() );
		}
	}

	/**
	 * Members add, remove and read their list over REST.
	 */
	public function test_member_routes_read_and_change_the_account_list(): void {
		$user = $this->member();
		wp_set_current_user( $user );

		$request = new WP_REST_Request( 'POST', '/guam-restaurants/v1/saved' );
		$request->set_body_params( array( 'add' => $this->r['proa'] . ',' . $this->r['bbq'] ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->r['proa'], $this->r['bbq'] ), $response->get_data()['ids'] );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		$request = new WP_REST_Request( 'POST', '/guam-restaurants/v1/saved' );
		$request->set_body_params( array( 'remove' => array( $this->r['proa'] ) ) );
		$this->assertSame( array( $this->r['bbq'] ), rest_get_server()->dispatch( $request )->get_data()['ids'] );

		// A restaurant unpublished later stays stored but is not returned.
		Saved_List::change( $user, array( $this->r['sushi'] ) );
		wp_update_post(
			array(
				'ID'          => $this->r['sushi'],
				'post_status' => 'draft',
			)
		);
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/saved' ) );
		$this->assertSame( array( $this->r['bbq'] ), $response->get_data()['ids'] );
	}

	/**
	 * Staff and owners are not members.
	 */
	public function test_members_are_visitor_accounts_only(): void {
		$this->assertTrue( Members::is_member( $this->member() ) );
		$this->assertFalse( Members::is_member( 0 ) );
		$this->assertFalse( Members::is_member( self::factory()->user->create( array( 'role' => 'administrator' ) ) ) );
		$this->assertFalse( Members::is_member( self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		$this->assertFalse( Members::is_member( self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) ) ) );
	}

	/**
	 * Members never see the toolbar.
	 */
	public function test_toolbar_is_hidden_from_members_only(): void {
		wp_set_current_user( $this->member() );
		$this->assertFalse( Members::show_admin_bar( true ) );
		$this->login_admin();
		$this->assertTrue( Members::show_admin_bar( true ) );
	}

	/**
	 * After sign-in members go back, or to the Saved page instead of wp-admin.
	 */
	public function test_members_return_to_their_page_or_the_saved_page_after_sign_in(): void {
		$member = get_userdata( $this->member() );
		$admin  = get_userdata( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved  = Pages::url( 'saved' );
		$page   = home_url( '/restaurants/?village=dededo' );

		$this->assertNotSame( '', $saved );
		$this->assertSame( $page, Members::login_redirect( $page, $page, $member ) );
		$this->assertSame( $saved, Members::login_redirect( admin_url(), '', $member ) );
		$this->assertSame( $saved, Members::login_redirect( admin_url( 'profile.php' ), '', $member ) );
		$this->assertSame( $saved, Members::login_redirect( '', '', $member ) );
		$this->assertSame( admin_url(), Members::login_redirect( admin_url(), '', $admin ) );
		$this->assertSame( admin_url(), Members::login_redirect( admin_url(), '', new \WP_Error( 'x' ) ) );
	}

	/**
	 * Members can delete their account; owners cannot here.
	 */
	public function test_member_can_delete_own_account_and_list_but_owners_cannot(): void {
		$member = $this->member();
		Saved_List::change( $member, array( $this->r['proa'] ) );
		$this->assertTrue( Members::delete( $member ) );
		$this->assertFalse( get_userdata( $member ) );
		$this->assertSame( array(), Saved_List::get( $member ) );

		$owner = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		$this->assertFalse( Members::delete( $owner ) );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $owner ) );
	}

	/**
	 * The personal data exporter and eraser cover the saved list.
	 */
	public function test_privacy_export_and_erase_cover_the_saved_list(): void {
		$user = $this->member();
		Saved_List::change( $user, array( $this->r['proa'], $this->r['bbq'] ) );
		$email = get_userdata( $user )->user_email;

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hooks.
		$this->assertArrayHasKey( 'guam-restaurants-saved', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'guam-restaurants-saved', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
		// phpcs:enable

		$export = Members::export( $email );
		$this->assertTrue( $export['done'] );
		$this->assertStringContainsString( 'Proa', $export['data'][0]['data'][0]['value'] );
		$this->assertStringContainsString( 'Dededo BBQ', $export['data'][0]['data'][0]['value'] );

		$erase = Members::erase( $email );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( array(), Saved_List::get( $user ) );
		$this->assertFalse( Members::erase( $email )['items_removed'] );
		$this->assertSame( array(), Members::export( 'nobody@example.com' )['data'] );
	}

	/**
	 * Cards and restaurant pages get save buttons, hidden until saved.js runs.
	 */
	public function test_cards_and_restaurant_page_have_hidden_save_buttons(): void {
		$card = Restaurant_View::card( $this->r['proa'] );
		$this->assertStringContainsString( 'data-gr-save="' . $this->r['proa'] . '"', $card );
		$this->assertStringContainsString( 'aria-label="Save Proa"', $card );
		$this->assertMatchesRegularExpression( '/<button[^>]*class="gr-save"[^>]*hidden>/', $card );
		$this->assertStringContainsString( 'gr-save--action', View::action_button( $this->r['proa'] ) );
		$this->assertTrue( wp_script_is( 'guam-restaurants-saved', 'enqueued' ) );
	}

	/**
	 * Not signed in, the Saved page invites sign-in and leaves the list to the browser.
	 */
	public function test_saved_page_for_a_visitor_invites_sign_in_and_leaves_the_list_to_the_browser(): void {
		wp_set_current_user( 0 );
		update_option( 'users_can_register', 1 );
		$this->go_to( Pages::url( 'saved' ) );
		$html = View::render_page();

		$this->assertStringContainsString( 'Keep your list on every device', $html );
		$this->assertStringContainsString( 'Sign up with email', $html );
		$this->assertStringContainsString( 'action=register', $html );
		$this->assertStringContainsString( 'data-gr-saved-local', $html );
		$this->assertStringNotContainsString( 'gr-card', $html );
		// No social buttons until LoginPress Pro's Social Login is set up.
		$this->assertStringNotContainsString( 'gr-join__social', $html );
	}

	/**
	 * Social buttons come from LoginPress, returning to the same page.
	 */
	public function test_social_buttons_come_from_the_loginpress_shortcode(): void {
		$seen = null;
		add_shortcode(
			'loginpress_social_login',
			static function ( $atts ) use ( &$seen ) {
				$seen = $atts;
				return '<div class="social-networks"><a href="https://example.com/wp-login.php?lpsl_login_id=gplus_login">Continue with Google</a></div>';
			}
		);
		wp_set_current_user( 0 );
		$html = View::join_panel( 'https://example.com/restaurants/?a=1&b=2', 'dialog' );
		remove_shortcode( 'loginpress_social_login' );

		$this->assertSame( 'https://example.com/restaurants/?a=1&b=2', $seen['redirect_to'] );
		$this->assertStringContainsString( 'Continue with Google', $html );
		$this->assertStringContainsString( 'gr-join__or', $html );
	}

	/**
	 * Signed in, the Saved page shows the list and the account panel.
	 */
	public function test_saved_page_for_a_member_shows_the_list_and_account(): void {
		$user = $this->member();
		Saved_List::change( $user, array( $this->r['proa'], $this->r['sushi'] ) );
		wp_set_current_user( $user );
		$this->go_to( Pages::url( 'saved' ) );
		$html = View::render_page();

		$this->assertStringContainsString( '2 saved restaurants', $html );
		$this->assertStringContainsString( 'Tumon Sushi Bar', $html );
		$this->assertStringContainsString( 'Your account', $html );
		$this->assertStringContainsString( 'Delete my account', $html );
		$this->assertStringContainsString( Members::DELETE_ACTION, $html );
		$this->assertStringNotContainsString( 'Keep your list on every device', $html );
	}

	/**
	 * A shared link shows its published restaurants and Save all.
	 */
	public function test_shared_link_shows_its_restaurants_and_a_save_all_button(): void {
		wp_set_current_user( 0 );
		$this->go_to( add_query_arg( View::LIST_PARAM, $this->r['bbq'] . ',' . $this->r['draft'], Pages::url( 'saved' ) ) );
		$_GET[ View::LIST_PARAM ] = $this->r['bbq'] . ',' . $this->r['draft'];
		$html                     = View::render_page();
		unset( $_GET[ View::LIST_PARAM ] );

		$this->assertStringContainsString( 'Someone shared this list', $html );
		$this->assertStringContainsString( '1 restaurant in this list', $html );
		$this->assertStringContainsString( 'Dededo BBQ', $html );
		$this->assertStringNotContainsString( 'Draft Noodles', $html );
		$this->assertStringContainsString( 'data-gr-save-all="' . $this->r['bbq'] . '"', $html );
	}

	/**
	 * The Saved page is kept out of search results.
	 */
	public function test_saved_page_is_not_indexed(): void {
		$this->go_to( Pages::url( 'saved' ) );
		$this->assertTrue( View::robots( array() )['noindex'] );
	}

	/**
	 * The one-time migration turns sign-up on.
	 */
	public function test_signup_migration_turns_registration_on_once(): void {
		update_option( 'users_can_register', 0 );
		$definitions = Migrations::definitions();
		call_user_func( $definitions['2026-10-20-visitor-signup'] );
		$this->assertSame( '1', (string) get_option( 'users_can_register' ) );
		$this->assertSame( 'subscriber', get_option( 'default_role' ) );
	}

	/**
	 * WooCommerce's account and cart icons stay out of the header.
	 */
	public function test_woocommerce_header_icons_are_not_hooked(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$types = apply_filters( 'hooked_block_types', array( 'woocommerce/customer-account', 'woocommerce/mini-cart', 'core/paragraph' ), 'after', 'core/navigation', null );
		$this->assertSame( array( 'core/paragraph' ), $types );
	}
}
