<?php
/**
 * Premium profiles and selling them through WooCommerce.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Context;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Billing\Reminders;
use GuamRestaurants\Core\Commerce\Woo_Checkout;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Premium\Admin as Premium_Admin;
use GuamRestaurants\Core\Premium\Premium;

/**
 * Premium profiles.
 *
 * @covers \GuamRestaurants\Core\Premium\Premium
 * @covers \GuamRestaurants\Core\Premium\Admin
 * @covers \GuamRestaurants\Core\Commerce\Woo_Checkout
 */
class Premium_Test extends Test_Case {

	/**
	 * 2026-12-30 12:00 Guam (02:00 UTC).
	 */
	public const NOW = 1798596000;

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
	 * Fixed clock, two restaurants, an owner of the grill, mail capture.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'guam_restaurants_now', array( $this, 'now' ) );
		foreach ( array(
			'grill' => 'Test Grill',
			'rival' => 'Rival Bistro',
		) as $key => $title ) {
			$this->r[ $key ] = self::factory()->post->create(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_title'  => $title,
					'post_status' => 'publish',
				)
			);
		}
		foreach ( array(
			'owner'      => Roles::OWNER_ROLE,
			'rival'      => Roles::OWNER_ROLE,
			'subscriber' => 'subscriber',
			'admin'      => 'administrator',
		) as $key => $role ) {
			$this->u[ $key ] = self::factory()->user->create(
				array(
					'role'       => $role,
					'user_email' => $key . '@example.com',
				)
			);
		}
		Roles::set_owned_restaurants( $this->u['owner'], array( $this->r['grill'] ) );
		Roles::set_owned_restaurants( $this->u['rival'], array( $this->r['rival'] ) );
		reset_phpmailer_instance();
	}

	/**
	 * Logs out and clears request data.
	 */
	public function tear_down() {
		unset( $_REQUEST[ Woo_Checkout::CART_KEY ], $_REQUEST[ Woo_Checkout::NONCE_FIELD ], $_GET['restaurant'] );
		if ( Woo_Checkout::available() && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		wp_set_current_user( 0 );
		Server::reset();
		parent::tear_down();
	}

	/**
	 * Fixed clock.
	 *
	 * @return int
	 */
	public function now(): int {
		return self::NOW;
	}

	/**
	 * Skips when WooCommerce is not loaded (`make woocommerce`).
	 */
	private function needs_woocommerce(): void {
		if ( ! Woo_Checkout::available() ) {
			$this->markTestSkipped( 'WooCommerce is not installed; run `make woocommerce`.' );
		}
		wc_load_cart();
	}

	/**
	 * Days stack: new time starts after paid time, or today once it lapsed.
	 */
	public function test_extend_and_shorten() {
		$grill = $this->r['grill'];
		$this->assertSame( '2026-12-30', Server::today() );
		$this->assertFalse( Premium::is_active( $grill ) );

		$this->assertSame(
			array(
				'from'  => '2026-12-30',
				'until' => '2027-01-28',
			),
			Premium::extend( $grill, 30, 'Order #1' )
		);
		$this->assertTrue( Premium::is_active( $grill ) );
		$this->assertTrue( Premium::is_active( $grill, '2027-01-28' ), 'The last day is included' );
		$this->assertFalse( Premium::is_active( $grill, '2027-01-29' ) );

		$this->assertSame( '2027-01-29', Premium::extend( $grill, 365, 'Order #2' )['from'], 'Paid time is kept' );
		$this->assertSame( '2028-01-28', Premium::until( $grill ) );

		$this->assertSame( '2027-01-28', Premium::shorten( $grill, 365, 'Order #2 refunded' ) );
		$this->assertSame( '2026-12-29', Premium::shorten( $grill, 30, 'Order #1 refunded' ) );
		$this->assertFalse( Premium::is_active( $grill ), 'Refunding everything ends it' );

		$this->assertSame( '2026-12-30', Premium::extend( $grill, 30, 'Order #3' )['from'], 'A lapsed profile starts today' );
		$this->assertNull( Premium::extend( $grill, 0, 'Nothing' ) );
		$this->assertNull( Premium::extend( $this->u['owner'], 30, 'Not a restaurant' ) );

		$this->assertFalse( Premium::set_until( $grill, '2027-02-30', 'Bad date' ) );
		$this->assertTrue( Premium::set_until( $grill, '', 'Removed' ) );
		$this->assertSame( '', Premium::until( $grill ) );

		$log = Premium::log( $grill );
		$this->assertCount( 6, $log );
		$this->assertSame( 'Removed', $log[0]['note'], 'Newest first' );
		$this->assertSame( '+30', $log[5]['change'] );
	}

	/**
	 * A premium profile touches only its own meta: never ratings, awards,
	 * status or anything the directory sorts by.
	 */
	public function test_premium_changes_nothing_else() {
		$grill  = $this->r['grill'];
		$before = get_post_meta( $grill );
		$post   = get_post( $grill );
		Premium::extend( $grill, 365, 'Order #1' );
		$after = get_post_meta( $grill );
		unset( $after[ Premium::UNTIL ], $after[ Premium::LOG ] );
		$this->assertSame( $before, $after );
		$this->assertSame( $post->menu_order, get_post( $grill )->menu_order );
		$this->assertSame( $post->post_date, get_post( $grill )->post_date );
	}

	/**
	 * The call-to-action is premium only, uses fixed labels, and is hidden
	 * once the profile ends.
	 */
	public function test_call_to_action() {
		$grill = $this->r['grill'];
		$owner = $this->u['owner'];
		$cta   = array(
			'action' => 'order',
			'url'    => 'https://order.testgrill.com',
		);

		$result = Owner_Content::apply( $owner, $grill, array( 'cta' => $cta ) );
		$this->assertWPError( $result );
		$this->assertSame( 'gr_owner_cta_premium', $result->get_error_code() );

		Premium::extend( $grill, 30, 'Order #1' );
		$this->assertWPError(
			Owner_Content::apply(
				$owner,
				$grill,
				array(
					'cta' => array(
						'action' => 'featured',
						'url'    => 'https://x.example',
					),
				)
			),
			'Only fixed labels'
		);
		$this->assertWPError( Owner_Content::apply( $this->u['rival'], $grill, array( 'cta' => $cta ) ), 'Only the owner' );
		$this->assertSame( array( Premium::CTA ), Owner_Content::apply( $owner, $grill, array( 'cta' => $cta ) )['changed'] );
		$this->assertSame( $cta, Premium::cta( $grill ) );

		$this->go_to( get_permalink( $grill ) );
		$html = Owner_Content::render_block( array() );
		$this->assertStringContainsString( 'Order online', $html );
		$this->assertStringContainsString( 'https://order.testgrill.com', $html );
		$this->assertStringContainsString( 'pays for a premium profile', $html, 'The page says it is paid' );

		Premium::set_until( $grill, '2026-12-29', 'Ended' );
		$this->assertStringNotContainsString( 'Order online', Owner_Content::render_block( array() ) );

		Premium::extend( $grill, 30, 'Order #2' );
		$this->assertSame( array( Premium::CTA ), Owner_Content::apply( $owner, $grill, array( 'cta' => array() ) )['changed'], 'Empty removes it' );
		$this->assertNull( Premium::cta( $grill ) );
	}

	/**
	 * Eight owner photos normally, twenty with a premium profile.
	 */
	public function test_photo_limit() {
		$grill = $this->r['grill'];
		$ids   = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = self::factory()->attachment->create_object(
				array(
					'file'           => "photo-{$i}.jpg",
					'post_parent'    => $grill,
					'post_mime_type' => 'image/jpeg',
					'post_author'    => $this->u['owner'],
				)
			);
		}
		$this->assertCount( Owner_Content::MAX_PHOTOS, Owner_Content::photos_from_input( $ids, $grill, $this->u['owner'] ) );
		Premium::extend( $grill, 30, 'Order #1' );
		$this->assertSame( Premium::MAX_PHOTOS, Premium::max_photos( $grill ) );
		$this->assertCount( 12, Owner_Content::photos_from_input( $ids, $grill, $this->u['owner'] ) );
	}

	/**
	 * Other restaurants' ads stay off a premium restaurant's page, and only
	 * there; its own ads and ads for no restaurant still show.
	 */
	public function test_no_rival_ads_on_premium_page() {
		$grill   = $this->r['grill'];
		$rival   = array(
			'restaurant_id' => $this->r['rival'],
			'page_types'    => array(),
		);
		$own     = array( 'restaurant_id' => $grill );
		$general = array( 'restaurant_id' => 0 );

		$page = Context::for_restaurant( $grill );
		$this->assertSame( 0, $page['premium_restaurant'] );
		$this->assertTrue( Server::matches( $rival, $page ), 'Without a premium profile rivals may show' );

		Premium::extend( $grill, 30, 'Order #1' );
		$page = Context::for_restaurant( $grill );
		$this->assertSame( $grill, $page['premium_restaurant'] );
		$this->assertFalse( Server::matches( $rival, $page ) );
		$this->assertTrue( Server::matches( $own, $page ) );
		$this->assertTrue( Server::matches( $general, $page ) );
		$this->assertTrue( Server::matches( $rival, Context::for_restaurant( $this->r['rival'] ) ) );
		$this->assertTrue( Server::matches( $rival, Context::make( 'home' ) ) );
	}

	/**
	 * Owners get reminders 14 and 3 days before the end, once each, through
	 * the renewal job.
	 */
	public function test_premium_reminders() {
		Premium::set_until( $this->r['grill'], '2027-01-12', 'Paid' );
		Premium::set_until( $this->r['rival'], '2027-01-12', 'Paid' );
		Roles::set_owned_restaurants( $this->u['rival'], array() );

		$outcome = Jobs::run( Jobs::RENEWALS );
		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 1, $outcome['result']['premium']['sent'] );
		$this->assertSame( 1, $outcome['result']['premium']['skipped_no_email'], 'No owner, no email' );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'owner@example.com', $mail->to[0][0] );
		$this->assertStringContainsString( 'Test Grill', $mail->subject );
		$this->assertStringContainsString( 'page=' . Dashboard::PAGE, $mail->body );
		$this->assertStringContainsString( 'does not renew by itself', $mail->body );

		reset_phpmailer_instance();
		$this->assertSame( 0, Reminders::run_premium()['sent'], 'Not sent twice' );
		$this->assertSame( 1, Reminders::run_premium( '2027-01-10' )['sent'], 'The 3-day reminder' );
		$this->assertSame( 0, Reminders::run_premium( '2027-01-11' )['sent'] );

		Premium::extend( $this->r['grill'], 30, 'Renewed' );
		$this->assertSame( 0, Reminders::run_premium( '2027-01-11' )['sent'], 'Renewed: nothing due' );
		$this->assertSame( 1, Reminders::run_premium( '2027-01-29' )['sent'], 'The new end date starts over' );
	}

	/**
	 * Only ad managers set the date by hand.
	 */
	public function test_admin_box() {
		wp_set_current_user( $this->u['owner'] );
		$this->assertFalse( Premium_Admin::save_input( $this->r['grill'], array( 'until' => '2027-12-31' ) ) );
		$this->assertSame( '', Premium::until( $this->r['grill'] ) );

		wp_set_current_user( $this->u['admin'] );
		$this->assertTrue(
			Premium_Admin::save_input(
				$this->r['grill'],
				array(
					'until' => '2027-12-31',
					'note'  => 'GR-2026-0007 paid',
				)
			)
		);
		$this->assertSame( '2027-12-31', Premium::until( $this->r['grill'] ) );
		$this->assertSame( 'GR-2026-0007 paid', Premium::log( $this->r['grill'] )[0]['note'] );

		ob_start();
		Premium_Admin::box( get_post( $this->r['grill'] ) );
		$this->assertStringContainsString( 'Running until 2027-12-31', (string) ob_get_clean() );
	}

	/**
	 * The product command is idempotent and makes hidden, virtual,
	 * one-per-order products.
	 */
	public function test_products() {
		$this->needs_woocommerce();
		$first = Woo_Checkout::create_products();
		$this->assertSame( 2, $first['created'] );
		$again = Woo_Checkout::create_products();
		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 2, $again['unchanged'] );
		$this->assertSame( $first['ids'], $again['ids'] );

		$annual = wc_get_product( $first['ids']['annual'] );
		$this->assertSame( '390', $annual->get_regular_price() );
		$this->assertSame( 365, Woo_Checkout::product_days( $annual ) );
		$this->assertTrue( $annual->is_virtual() );
		$this->assertTrue( $annual->is_sold_individually() );
		$this->assertSame( 'hidden', $annual->get_catalog_visibility() );
		$this->assertSame( array( 'monthly', 'annual' ), array_keys( Woo_Checkout::products() ) );
	}

	/**
	 * Only the restaurant's verified owner can put its premium profile in
	 * the cart, with a valid link; the cart is checked again later.
	 */
	public function test_cart_is_for_the_owner_only() {
		$this->needs_woocommerce();
		$product  = wc_get_product( Woo_Checkout::create_products()['ids']['monthly'] );
		$grill    = $this->r['grill'];
		$ordinary = new \WC_Product_Simple();
		$ordinary->set_regular_price( '5' );
		$ordinary->save();

		$this->assertTrue( Woo_Checkout::validate_add( true, $ordinary->get_id() ), 'Other products are not touched' );

		$this->assertFalse( Woo_Checkout::validate_add( true, $product->get_id() ), 'Logged out' );

		wp_set_current_user( $this->u['owner'] );
		parse_str( (string) wp_parse_url( Woo_Checkout::buy_url( $product, $grill ), PHP_URL_QUERY ), $query );
		$this->assertSame( (string) $grill, $query[ Woo_Checkout::CART_KEY ] );

		$_REQUEST[ Woo_Checkout::CART_KEY ] = (string) $this->r['rival'];
		$this->assertFalse( Woo_Checkout::validate_add( true, $product->get_id() ), 'Not their restaurant' );

		$_REQUEST[ Woo_Checkout::CART_KEY ]    = (string) $grill;
		$_REQUEST[ Woo_Checkout::NONCE_FIELD ] = 'bad';
		$this->assertFalse( Woo_Checkout::validate_add( true, $product->get_id() ), 'Bad nonce' );

		$_REQUEST[ Woo_Checkout::NONCE_FIELD ] = $query[ Woo_Checkout::NONCE_FIELD ];
		$this->assertTrue( Woo_Checkout::validate_add( true, $product->get_id() ) );

		wp_set_current_user( $this->u['subscriber'] );
		$this->assertFalse( Woo_Checkout::validate_add( true, $product->get_id() ), 'Not an owner' );

		wp_set_current_user( $this->u['owner'] );
		$key = WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertNotEmpty( $key );
		$this->assertSame( $grill, WC()->cart->get_cart_item( $key )[ Woo_Checkout::CART_KEY ] );
		$this->assertSame( 'Test Grill', Woo_Checkout::show_item_data( array(), WC()->cart->get_cart_item( $key ) )[0]['value'] );

		Woo_Checkout::check_cart();
		$this->assertNotEmpty( WC()->cart->get_cart_item( $key ), 'Still the owner' );
		Roles::set_owned_restaurants( $this->u['owner'], array() );
		Woo_Checkout::check_cart();
		$this->assertEmpty( WC()->cart->get_cart_item( $key ), 'Removed once the link is gone' );
	}

	/**
	 * A paid order adds its days once; a refund takes them off once.
	 */
	public function test_paid_order_switches_premium_on() {
		$this->needs_woocommerce();
		$ids   = Woo_Checkout::create_products()['ids'];
		$grill = $this->r['grill'];
		Premium::set_until( $grill, '2027-01-05', 'Earlier time' );

		$order   = wc_create_order( array( 'customer_id' => $this->u['owner'] ) );
		$item_id = $order->add_product( wc_get_product( $ids['annual'] ), 1 );
		$item    = $order->get_item( $item_id );
		Woo_Checkout::order_line_item(
			$item,
			'key',
			array(
				'product_id'           => $ids['annual'],
				Woo_Checkout::CART_KEY => $grill,
			)
		);
		$item->save();
		$order->calculate_totals();
		$order->save();

		$order->update_status( 'pending' );
		$this->assertSame( '2027-01-05', Premium::until( $grill ), 'Unpaid orders change nothing' );

		$order->update_status( 'processing' );
		$this->assertSame( '2028-01-05', Premium::until( $grill ), '365 days after the time already paid for' );
		$order->update_status( 'completed' );
		$this->assertSame( '2028-01-05', Premium::until( $grill ), 'Added once' );
		$notes = implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$this->assertSame( 1, substr_count( $notes, '365 days added' ) );

		$order->update_status( 'refunded' );
		$this->assertSame( '2027-01-05', Premium::until( $grill ) );
		$order->update_status( 'cancelled' );
		$this->assertSame( '2027-01-05', Premium::until( $grill ), 'Taken off once' );
	}

	/**
	 * WooCommerce would send owners away from wp-admin; My restaurant lives there.
	 */
	public function test_owner_keeps_admin_access() {
		wp_set_current_user( $this->u['owner'] );
		$this->assertFalse( Woo_Checkout::keep_owner_admin( true ) );
		wp_set_current_user( $this->u['subscriber'] );
		$this->assertTrue( Woo_Checkout::keep_owner_admin( true ) );
		$this->assertFalse( Woo_Checkout::keep_owner_admin( false ) );
		if ( Woo_Checkout::available() ) {
			wp_set_current_user( $this->u['owner'] );
			$this->assertFalse( apply_filters( 'woocommerce_prevent_admin_access', true ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's filter.
		}
	}

	/**
	 * The dashboard offers the plans and, while premium runs, the button form.
	 */
	public function test_dashboard_section() {
		$this->needs_woocommerce();
		Woo_Checkout::create_products();
		wp_set_current_user( $this->u['owner'] );
		ob_start();
		Dashboard::screen();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Buy: Premium profile, 12 months', $html );
		$this->assertStringContainsString( 'add-to-cart=', $html );
		$this->assertStringNotContainsString( 'name="cta_action"', $html );

		Premium::extend( $this->r['grill'], 30, 'Order #1' );
		ob_start();
		Dashboard::screen();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Active until', $html );
		$this->assertStringContainsString( 'Add time: Premium profile, 1 month', $html );
		$this->assertStringContainsString( 'name="cta_action"', $html );
	}

	/**
	 * WooCommerce's scripts, styles and jQuery load only on shop pages.
	 */
	public function test_shop_assets_only_on_shop_pages() {
		$this->needs_woocommerce();
		// WooCommerce registers them on wp_enqueue_scripts, which tests do not run.
		foreach ( Woo_Checkout::SHOP_SCRIPTS as $handle ) {
			wp_register_script( $handle, 'https://example.org/' . $handle . '.js', array(), '1', true );
			wp_enqueue_script( $handle );
		}
		foreach ( Woo_Checkout::SHOP_STYLES as $handle ) {
			wp_register_style( $handle, 'https://example.org/' . $handle . '.css', array(), '1' );
			wp_enqueue_style( $handle );
		}
		$this->go_to( get_permalink( $this->r['grill'] ) );
		Woo_Checkout::drop_shop_assets();
		foreach ( Woo_Checkout::SHOP_SCRIPTS as $handle ) {
			$this->assertFalse( wp_script_is( $handle, 'enqueued' ), $handle );
		}
		foreach ( Woo_Checkout::SHOP_STYLES as $handle ) {
			$this->assertFalse( wp_style_is( $handle, 'enqueued' ), $handle );
		}

		$this->go_to( get_permalink( Woo_Checkout::create_products()['ids']['monthly'] ) );
		wp_enqueue_script( 'woocommerce' );
		wp_enqueue_style( 'woocommerce-general' );
		Woo_Checkout::drop_shop_assets();
		$this->assertTrue( wp_script_is( 'woocommerce', 'enqueued' ), 'Shop pages keep them.' );
		$this->assertTrue( wp_style_is( 'woocommerce-general', 'enqueued' ) );
	}
}
