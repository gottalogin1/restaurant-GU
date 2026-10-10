<?php
/**
 * Closure signals, administrator approval, status checks and closure alerts.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Alerts;
use GuamRestaurants\Core\Monitoring\Closures;
use GuamRestaurants\Core\Monitoring\Google_Status_Source;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Monitoring\Status_Checker;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Restaurant_Meta;

/**
 * Proa in Tumon disappears from OSM; Google reports it closed.
 *
 * @covers \GuamRestaurants\Core\Monitoring\Closures
 * @covers \GuamRestaurants\Core\Monitoring\Status_Checker
 * @covers \GuamRestaurants\Core\Monitoring\Google_Status_Source
 * @covers \GuamRestaurants\Core\Monitoring\Alerts
 */
class Closures_Test extends Guam_Test_Case {

	/**
	 * Clears config set by a test.
	 */
	public function tear_down() {
		putenv( 'GUAM_RESTAURANTS_CLOSURE_ALERT_THRESHOLD' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		delete_option( Budget::MONTHLY_OPTION );
		parent::tear_down();
	}

	/**
	 * Pending closure items for a restaurant.
	 *
	 * @param int $post_id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	private function closure_items( int $post_id ): array {
		return $this->rows( Schema::REVIEW_QUEUE, "type = 'closure_signal' AND status = 'pending' AND restaurant_id = " . $post_id );
	}

	/**
	 * One missing run is not enough; two in a row queue it; nothing is closed without approval.
	 */
	public function test_two_consecutive_osm_checks_queue_a_closure() {
		$this->import();
		$proa = $this->post_for( 5002 );

		$first = $this->import( Guam_Fixtures::without( array( 5002 ) ) );
		$this->assertSame( array( $proa ), $first['closures']['closed'] );
		$this->assertSame( array(), $this->closure_items( $proa ), 'One check is not enough.' );

		// A re-run the same day does not count as a second check.
		$this->import( Guam_Fixtures::without( array( 5002 ) ) );
		$this->assertSame( 1, Closures::streaks( $proa )['osm']['count'] );

		$this->age_closure_checks( $proa );
		$second = $this->import( Guam_Fixtures::without( array( 5002 ) ) );

		$this->assertSame( array( $proa ), $second['closures']['queued'] );
		$items = $this->closure_items( $proa );
		$this->assertCount( 1, $items );
		$this->assertSame( 'osm', $items[0]['source'] );
		$this->assertSame( 'node/5002', json_decode( $items[0]['payload'], true )['osm_id'] );
		$this->assertNotSame( 'permanently_closed', get_post_meta( $proa, Restaurant_Meta::STATUS, true ), 'Not closed until approved.' );
		$this->assertStringContainsString( '1 queued for closure approval', $this->rows( Schema::INGESTION_RUNS, 'id = ' . (int) $second['run_id'] )[0]['notes'] );
	}

	/**
	 * A check that finds the restaurant again resets the count.
	 */
	public function test_reappearing_resets_the_count() {
		$this->import();
		$proa = $this->post_for( 5002 );

		$this->import( Guam_Fixtures::without( array( 5002 ) ) );
		$this->age_closure_checks( $proa );
		$this->import();
		$this->assertSame( array(), Closures::streaks( $proa ) );

		$this->import( Guam_Fixtures::without( array( 5002 ) ) );
		$this->assertSame( array(), $this->closure_items( $proa ) );
	}

	/**
	 * A response missing most known restaurants is treated as incomplete.
	 */
	public function test_truncated_response_skips_closure_checks() {
		$this->import();

		$result = $this->import( Guam_Fixtures::without( array( 5002, 5003, 5004 ) ) );

		$this->assertFalse( $result['closures']['checked'] );
		$this->assertStringContainsString( 'closure checks skipped', $result['closures']['note'] );
		$this->assertSame( array(), Closures::streaks( $this->post_for( 5002 ) ) );
	}

	/**
	 * Only an administrator's approval closes it; approval logs the status as a plain fact.
	 */
	public function test_administrator_approval_sets_status() {
		$this->import();
		$proa = $this->post_for( 5002 );
		Closures::record_check( $proa, 'osm', true );
		$this->age_closure_checks( $proa );
		Closures::record_check( $proa, 'osm', true );
		$item = (int) $this->closure_items( $proa )[0]['id'];

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( Review_Queue_Screen::decide( $item, 'approve_closure', $editor ) );
		$this->assertSame( 'unknown', get_post_meta( $proa, Restaurant_Meta::STATUS, true ) );

		$admin = $this->login_admin();
		$this->assertTrue( Review_Queue_Screen::decide( $item, 'approve_closure', $admin ) );

		$this->assertSame( 'permanently_closed', get_post_meta( $proa, Restaurant_Meta::STATUS, true ) );
		$this->assertSame( gmdate( 'Y-m' ), get_post_meta( $proa, Restaurant_Meta::CLOSED_ON, true ) );
		$this->assertSame( 'approved', $this->rows( Schema::REVIEW_QUEUE, 'id = ' . $item )[0]['status'] );
		$this->assertSame( (string) $admin, $this->rows( Schema::REVIEW_QUEUE, 'id = ' . $item )[0]['resolved_by'] );

		$log = $this->rows( Schema::CHANGE_LOG, "field = 'gr_status' AND restaurant_id = " . $proa );
		$this->assertCount( 1, $log );
		$this->assertSame( array( 'unknown', 'permanently_closed', 'editor' ), array( $log[0]['old_value'], $log[0]['new_value'], $log[0]['source'] ) );

		$closures = Queries::recent_closures( 7 );
		$this->assertSame( array( $proa ), array_column( $closures, 'restaurant_id' ) );
		$this->assertSame( 'Proa Restaurant', $closures[0]['name'] );
		$this->assertSame( array( 'tamuning' ), $closures[0]['village'] );
	}

	/**
	 * Rejecting keeps the restaurant open and starts the count again.
	 */
	public function test_rejection_resets() {
		$this->import();
		$proa = $this->post_for( 5002 );
		Closures::record_check( $proa, 'osm', true );
		$this->age_closure_checks( $proa );
		Closures::record_check( $proa, 'osm', true );

		$this->assertTrue( Review_Queue_Screen::decide( (int) $this->closure_items( $proa )[0]['id'], 'reject_closure', $this->login_admin() ) );

		$this->assertSame( array(), Closures::streaks( $proa ) );
		$this->assertSame( 'unknown', get_post_meta( $proa, Restaurant_Meta::STATUS, true ) );
		$this->assertSame( array(), Queries::recent_closures( 7 ) );
	}

	/**
	 * Monthly Google check: business status only, Pro field mask, place ID in the payload.
	 */
	public function test_google_status_check() {
		$this->import();
		$proa     = $this->post_for( 5002 );
		$shirleys = $this->post_for( 5001 );
		update_post_meta( $proa, Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJ-proa' );
		update_post_meta( $shirleys, Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJ-shirleys' );

		$api     = new Fake_Api(
			array(
				'#/places/ChIJ-proa$#'     => array( 'businessStatus' => 'CLOSED_PERMANENTLY' ),
				'#/places/ChIJ-shirleys$#' => array( 'businessStatus' => 'OPERATIONAL' ),
			)
		);
		$checker = new Status_Checker( new Google_Status_Source( 'test-key', $api->client() ), new Budget( 0 ), 0 );

		$first = $checker->run();
		$this->assertSame( array( $proa ), $first['closed'] );
		$this->assertSame( array(), $first['queued'] );
		$this->assertSame( 2, $first['requests'] );
		$this->assertSame( 0.0, $first['spent'] );
		$this->assertSame( 'businessStatus', $api->requests[0][1]['headers']['X-Goog-FieldMask'] );

		$this->age_closure_checks( $proa );
		$second = $checker->run();
		$this->assertSame( array( $proa ), $second['queued'] );
		$item = $this->closure_items( $proa )[0];
		$this->assertSame( 'google', $item['source'] );
		$this->assertSame( 'ChIJ-proa', json_decode( $item['payload'], true )['google_id'] );
		$this->assertStringContainsString( 'google status: 2 checked, 1 looked permanently closed, 1 queued', $this->rows( Schema::INGESTION_RUNS, 'id = ' . (int) $second['run_id'] )[0]['notes'] );
	}

	/**
	 * With the $0 cap the check stops at the end of the free allowance.
	 */
	public function test_status_check_stays_free() {
		$this->import();
		update_post_meta( $this->post_for( 5002 ), Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJ-proa' );
		update_option(
			Budget::MONTHLY_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => array( Pricing::GOOGLE_DETAILS_PRO => Pricing::free_per_month( Pricing::GOOGLE_DETAILS_PRO ) ),
				'spend' => array(),
			)
		);
		$api = new Fake_Api( array( '#/places/#' => array( 'businessStatus' => 'CLOSED_PERMANENTLY' ) ) );

		$result = ( new Status_Checker( new Google_Status_Source( 'test-key', $api->client() ), new Budget( 0 ), 0 ) )->run();

		$this->assertTrue( $result['stopped_at_cap'] );
		$this->assertSame( 0, $result['requests'] );
		$this->assertSame( array(), $api->requests );
	}

	/**
	 * An unknown place ID is an error for the run log, not evidence of closure.
	 */
	public function test_missing_place_is_not_a_closure() {
		$this->import();
		$proa = $this->post_for( 5002 );
		update_post_meta( $proa, Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJ-gone' );
		$api = new Fake_Api( array( '#/places/#' => 404 ) );

		$result = ( new Status_Checker( new Google_Status_Source( 'test-key', $api->client() ), new Budget( 0 ), 0 ) )->run();

		$this->assertSame( 1, $result['counts']['failed'] );
		$this->assertSame( array(), Closures::streaks( $proa ) );
		$this->assertStringContainsString( 'HTTP 404', $this->rows( Schema::INGESTION_RUNS, 'id = ' . (int) $result['run_id'] )[0]['errors'] );
	}

	/**
	 * More closures in one run than the threshold sends one email.
	 */
	public function test_alert_when_closures_exceed_threshold() {
		putenv( 'GUAM_RESTAURANTS_CLOSURE_ALERT_THRESHOLD=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		$this->import();
		$this->assertFalse( Alerts::closures_in_run( 'OpenStreetMap import', array( $this->post_for( 5002 ) ), array(), null ), 'At the threshold: no email.' );
		$this->assertSame( array(), $this->mails );

		$closed = array( $this->post_for( 5002 ), $this->post_for( 5004 ) );
		$this->assertTrue( Alerts::closures_in_run( 'OpenStreetMap import', $closed, array( $this->post_for( 5002 ) ), 9 ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( get_option( 'admin_email' ), $this->mails[0]['to'] );
		$this->assertStringContainsString( '2 restaurants look closed', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Proa Restaurant', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'awaiting approval', $this->mails[0]['message'] );
	}
}
