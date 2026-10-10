<?php
/**
 * Manual invoicing, the billing lists, renewal reminders and owner stats.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Ads\Stats;
use GuamRestaurants\Core\Billing\Admin as Billing_Admin;
use GuamRestaurants\Core\Billing\Invoices;
use GuamRestaurants\Core\Billing\Reminders;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Owners\Listing_Stats;
use WP_REST_Request;

/**
 * Billing.
 *
 * @covers \GuamRestaurants\Core\Billing\Invoices
 * @covers \GuamRestaurants\Core\Billing\Reminders
 * @covers \GuamRestaurants\Core\Billing\Admin
 * @covers \GuamRestaurants\Core\Owners\Listing_Stats
 * @covers \GuamRestaurants\Core\Owners\Dashboard
 */
class Billing_Test extends Test_Case {

	/**
	 * 2026-12-30 12:00 Guam (02:00 UTC).
	 */
	public const NOW = 1798596000;

	/**
	 * Restaurant.
	 *
	 * @var int
	 */
	private int $restaurant;

	/**
	 * Fixed clock, a restaurant, mail capture.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'guam_restaurants_now', array( $this, 'now' ) );
		$this->restaurant = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => 'Test Grill',
				'post_status' => 'publish',
			)
		);
		delete_option( Invoices::SEQUENCE );
		reset_phpmailer_instance();
	}

	/**
	 * Logs out.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
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
	 * Creates an active ad.
	 *
	 * @param string               $title Title.
	 * @param array<string, mixed> $input Overrides.
	 * @return int
	 */
	private function ad( string $title, array $input = array() ): int {
		$id = Ads::create(
			$title,
			array_merge(
				array(
					'advertiser'    => 'Test Grill LLC',
					'restaurant_id' => $this->restaurant,
					'product'       => 'banner',
					'creative_type' => 'text',
					'headline'      => 'Lunch on the bay',
					'start'         => '2026-12-01',
					'end'           => '2027-01-12',
					'status'        => 'active',
				),
				$input
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Numbers are GR-YYYY-NNNN, in order, once per ad, restarting each year.
	 */
	public function test_invoice_numbers() {
		$this->assertSame( '2026-12-30', Server::today() );
		$a = $this->ad( 'A' );
		$b = $this->ad( 'B' );
		$this->assertSame( 'GR-2026-0001', Invoices::issue( $a ) );
		$this->assertSame( 'GR-2026-0001', Invoices::issue( $a ), 'An ad keeps its number' );
		$this->assertSame( 'GR-2026-0002', Invoices::issue( $b ) );
		$this->assertMatchesRegularExpression( Invoices::NUMBER_PATTERN, Invoices::get( $b )['number'] );

		$billing = Invoices::get( $a );
		$this->assertSame( '2026-12-30', $billing['issued'] );
		$this->assertSame( '2027-01-13', $billing['due'], 'Due 14 days after issue' );
		$this->assertSame( Invoices::UNPAID, $billing['status'] );

		$this->assertSame( 'GR-2027-0001', Invoices::next_number( '2027' ) );
		delete_option( Invoices::SEQUENCE );
		$this->assertSame( 'GR-2026-0003', Invoices::issue( $this->ad( 'C' ) ), 'A lost counter never reuses a number' );
		$this->assertWPError( Invoices::issue( $this->restaurant ) );
	}

	/**
	 * Only ad managers save billing; paid gets a date; the number is not editable.
	 */
	public function test_saving_billing() {
		$ad = $this->ad( 'A' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Billing_Admin::save_billing( $ad, array( 'status' => 'paid' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue(
			Billing_Admin::save_billing(
				$ad,
				array(
					'issue'  => '1',
					'amount' => '$1,250.5',
					'email'  => 'billing@testgrill.com',
					'number' => 'GR-1999-9999',
				)
			)
		);
		$billing = Invoices::get( $ad );
		$this->assertSame( 'GR-2026-0001', $billing['number'] );
		$this->assertSame( '1250.50', $billing['amount'] );
		$this->assertSame( Invoices::UNPAID, $billing['status'] );

		Billing_Admin::save_billing( $ad, array_merge( $billing, array( 'status' => 'paid' ) ) );
		$this->assertSame( '2026-12-30', Invoices::get( $ad )['paid_on'] );
		Billing_Admin::save_billing( $ad, array_merge( $billing, array( 'status' => 'unpaid' ) ) );
		$this->assertSame( '', Invoices::get( $ad )['paid_on'] );
	}

	/**
	 * The report lists unpaid invoices, ads ending soon and uninvoiced ads.
	 */
	public function test_report_lists() {
		$overdue = $this->ad( 'Overdue' );
		Invoices::issue( $overdue );
		update_post_meta( $overdue, Invoices::META_DUE, '2026-12-20' );
		$paid = $this->ad( 'Paid', array( 'end' => '2027-03-01' ) );
		Invoices::issue( $paid );
		update_post_meta( $paid, Invoices::META_STATUS, Invoices::PAID );
		$plain = $this->ad( 'No invoice', array( 'end' => '2026-12-31' ) );
		$this->ad( 'Paused', array( 'status' => 'paused' ) );
		$this->ad( 'Ended', array( 'end' => '2026-12-29' ) );

		$unpaid = Invoices::unpaid( Server::today() );
		$this->assertCount( 1, $unpaid );
		$this->assertSame( $overdue, $unpaid[0]['ad']['id'] );
		$this->assertSame( 10, $unpaid[0]['days_overdue'] );

		$expiring = wp_list_pluck( wp_list_pluck( Invoices::expiring( Server::today(), 30 ), 'ad' ), 'title' );
		$this->assertSame( array( 'No invoice', 'Overdue' ), $expiring, 'Soonest first; paused, ended and later ads left out' );

		$this->assertSame( array( $plain ), wp_list_pluck( Invoices::serving_without_invoice( Server::today() ), 'id' ) );
	}

	/**
	 * Reminders go 14 and 3 days before the end, once each per end date.
	 */
	public function test_reminder_schedule() {
		$this->assertSame( 14, Reminders::due( 14, array() ) );
		$this->assertSame( 14, Reminders::due( 10, array() ), 'A missed day is caught up' );
		$this->assertNull( Reminders::due( 10, array( 14 ) ) );
		$this->assertSame( 3, Reminders::due( 3, array( 14 ) ) );
		$this->assertSame( 3, Reminders::due( 2, array() ), 'Only the closer reminder when both are due' );
		$this->assertNull( Reminders::due( 0, array( 3 ) ) );
		$this->assertNull( Reminders::due( 15, array() ) );
		$this->assertNull( Reminders::due( -1, array() ) );
	}

	/**
	 * The daily job emails the billing contact, once per reminder.
	 */
	public function test_reminder_emails() {
		$ad = $this->ad( 'Bay banner', array( 'end' => '2027-01-13' ) ); // 14 days out.
		Invoices::save( $ad, Invoices::sanitize( array( 'email' => 'billing@testgrill.com' ) ) );
		$this->ad( 'No email', array( 'end' => '2027-01-13' ) );
		$this->ad(
			'Paused',
			array(
				'end'    => '2027-01-13',
				'status' => 'paused',
			)
		);

		$outcome = Jobs::run( Jobs::RENEWALS );
		$this->assertTrue( $outcome['ok'] );
		$this->assertSame( 1, $outcome['result']['sent'] );
		$this->assertSame( 1, $outcome['result']['skipped_no_email'] );
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;
		$this->assertCount( 1, $sent );
		$this->assertSame( 'billing@testgrill.com', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'ends on', $sent[0]['subject'] );
		$this->assertStringContainsString( 'in 14 days', $sent[0]['body'] );
		$this->assertStringNotContainsString( '$', $sent[0]['body'], 'No prices quoted' );

		$this->assertSame( 0, Reminders::run()['sent'], 'Not sent twice' );
		$this->assertSame( 1, Reminders::run( '2027-01-10' )['sent'], 'The 3-day reminder' );
		$this->assertSame( array( 14, 3 ), Reminders::sent( $ad, '2027-01-13' ) );

		update_post_meta( $ad, Ads::META_END, '2027-02-13' );
		$this->assertSame( array(), Reminders::sent( $ad, '2027-02-13' ), 'A new end date starts over' );
		$this->assertSame( 1, Reminders::run( '2027-01-30' )['sent'] );
	}

	/**
	 * Listing counters are totals per day; bots and drafts are not counted.
	 */
	public function test_listing_stats_endpoint() {
		$send = static function ( int $restaurant, array $events, string $agent = 'Mozilla/5.0 (iPhone)' ) {
			$request = new WP_REST_Request( 'POST', '/guam-restaurants/v1/listing/events' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'User-Agent', $agent );
			$request->set_body( wp_json_encode( compact( 'restaurant', 'events' ) ) );
			return rest_do_request( $request );
		};
		$this->assertSame( 204, $send( $this->restaurant, array( 'view' ) )->get_status() );
		$send( $this->restaurant, array( 'view', 'website', 'website' ) );
		$send( $this->restaurant, array( 'phone' ) );
		$send( $this->restaurant, array( 'view' ), 'Googlebot/2.1' );
		$this->assertSame( 400, $send( $this->restaurant, array( 'email' ) )->get_status() );
		$draft = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		$send( $draft, array( 'view' ) );

		$this->assertSame(
			array(
				'views'          => 2,
				'website_clicks' => 1,
				'phone_clicks'   => 1,
			),
			Listing_Stats::totals( $this->restaurant, '2026-12-30', '2026-12-30' )
		);
		$this->assertSame( 0, Listing_Stats::totals( $draft, '2026-12-01', '2026-12-31' )['views'] );
	}

	/**
	 * The dashboard shows the restaurant's listing totals and its ads only.
	 */
	public function test_dashboard_stats() {
		Listing_Stats::add(
			$this->restaurant,
			'2026-12-30',
			array(
				'view'    => 5,
				'website' => 2,
			)
		);
		Listing_Stats::add(
			$this->restaurant,
			'2026-11-20',
			array(
				'view'  => 3,
				'phone' => 1,
			)
		);
		Listing_Stats::add( $this->restaurant, '2026-09-01', array( 'view' => 99 ) );
		$mine  = $this->ad( 'Mine' );
		$other = $this->ad(
			'Theirs',
			array(
				'restaurant_id' => 0,
				'link'          => 'https://example.com',
			)
		);
		Stats::add( $mine, '2026-12-29', 'village', 100, 4 );
		Stats::add( $mine, '2026-12-29', 'home', 50, 1 );
		Stats::add( $other, '2026-12-29', 'home', 500, 50 );

		$stats = Dashboard::stats( $this->restaurant, Server::today() );
		$this->assertSame( '2026-12-01', $stats['from'] );
		$this->assertSame( 5, $stats['current']['views'] );
		$this->assertSame( 2, $stats['current']['website_clicks'] );
		$this->assertSame( 3, $stats['previous']['views'] );
		$this->assertSame( 1, $stats['previous']['phone_clicks'] );
		$this->assertCount( 1, $stats['ads'] );
		$this->assertSame( 150, $stats['ads'][0]['impressions'] );
		$this->assertSame( 5, $stats['ads'][0]['clicks'] );
	}

	/**
	 * Ads > Billing renders for administrators.
	 */
	public function test_billing_screen_renders() {
		$ad = $this->ad( 'Bay banner' );
		Invoices::issue( $ad );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Billing_Admin::screen();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'GR-2026-0001', $html );
		$this->assertStringContainsString( 'Ending in the next 30 days', $html );
	}
}
