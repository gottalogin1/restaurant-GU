<?php
/**
 * Change log tracking, query functions, scheduled jobs, health check and the Import runs screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Ingestion_Runs_Screen;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Health_Check;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Restaurant_Meta;
use function GuamRestaurants\Core\get_changes_for;
use function GuamRestaurants\Core\get_recent_closures;
use function GuamRestaurants\Core\get_recent_openings;

/**
 * Tracking, queries, jobs and the runs screen, with the Guam fixtures.
 *
 * @covers \GuamRestaurants\Core\Monitoring\Change_Tracker
 * @covers \GuamRestaurants\Core\Monitoring\Queries
 * @covers \GuamRestaurants\Core\Monitoring\Jobs
 * @covers \GuamRestaurants\Core\Monitoring\Health_Check
 * @covers \GuamRestaurants\Core\Admin\Ingestion_Runs_Screen
 */
class Monitoring_Test extends Guam_Test_Case {

	/**
	 * Clears job state and config.
	 */
	public function tear_down() {
		putenv( 'GUAM_RESTAURANTS_CRON_MODE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		putenv( 'GUAM_RESTAURANTS_GOOGLE_PLACES_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		wp_unschedule_hook( Jobs::HOOK );
		parent::tear_down();
	}

	/**
	 * An editor's changes to tracked facts are logged with source `editor`.
	 */
	public function test_editor_changes_are_logged() {
		$this->import();
		$proa = $this->post_for( 5002 );
		$this->login_admin();

		update_post_meta( $proa, Restaurant_Meta::PHONE, '+16715550143' );
		update_post_meta( $proa, Restaurant_Meta::WEBSITE, 'https://proa.example.com' );
		update_post_meta( $proa, Restaurant_Meta::STATUS, 'temporarily_closed' );
		wp_set_object_terms( $proa, 'p2', Content_Types::TAX_PRICE );
		wp_set_object_terms( $proa, 'tumon-sands-plaza', Content_Types::TAX_MALL );
		update_post_meta( $proa, Restaurant_Meta::LATITUDE, 13.51 ); // Not a tracked field.

		$changes = array_column( get_changes_for( $proa ), null, 'field' );

		$this->assertSame( array( '+16715550142', '+16715550143', 'editor' ), array( $changes['gr_phone']['old_value'], $changes['gr_phone']['new_value'], $changes['gr_phone']['source'] ) );
		$this->assertSame( 'https://proa.example.com', $changes['gr_website']['new_value'] );
		$this->assertSame( array( 'unknown', 'temporarily_closed' ), array( $changes['gr_status']['old_value'], $changes['gr_status']['new_value'] ) );
		$this->assertSame( array( '', 'p2' ), array( $changes['tax:gr_price_level']['old_value'], $changes['tax:gr_price_level']['new_value'] ) );
		$this->assertSame( 'tumon-sands-plaza', $changes['tax:gr_mall']['new_value'] );
		$this->assertArrayNotHasKey( 'gr_latitude', $changes );
		$this->assertSame( array( 'source' => 'osm', 'id' => 'node/5002', 'baseline' => true ), $changes['first_seen']['new_value'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Imports log address, hours and village changes with source and run.
	 */
	public function test_import_logs_fact_changes() {
		$this->import();
		$proa = $this->post_for( 5002 );

		$elements                                = Guam_Fixtures::elements();
		$elements[2]['tags']['opening_hours']    = 'Mo-Su 11:00-21:00';
		$elements[2]['tags']['addr:housenumber'] = '431';
		$elements[2]['tags']['website']          = 'https://proa.example.com';
		$result                                  = $this->import( $elements );

		$fields = array_column( $this->rows( Schema::CHANGE_LOG, 'run_id = ' . (int) $result['run_id'] ), 'field' );
		$this->assertEqualsCanonicalizing( array( 'gr_hours', 'gr_address', 'gr_website' ), $fields );
		$this->assertSame( '431 Pale San Vitores Road', get_post_meta( $proa, Restaurant_Meta::ADDRESS, true ) );
	}

	/**
	 * Openings leave out the first import (the starting inventory) and closed or trashed places.
	 */
	public function test_recent_openings() {
		$this->import( Guam_Fixtures::without( array( 5003 ) ) );
		$this->assertSame( array(), get_recent_openings( 30 ), 'The first run is the baseline.' );

		$this->import();
		$meskla   = $this->post_for( 5003 );
		$openings = get_recent_openings( 30 );
		$this->assertSame( array( $meskla ), array_column( $openings, 'restaurant_id' ) );
		$this->assertSame( 'Meskla Chamoru Fusion Bistro', $openings[0]['name'] );
		$this->assertSame( 'osm', $openings[0]['source'] );
		$this->assertSame( array( 'hagatna' ), $openings[0]['village'] );

		$hand = $this->restaurant( 'Kracked Egg Tumon' );
		$this->assertSame( array( $hand, $meskla ), array_column( get_recent_openings( 30 ), 'restaurant_id' ) );
		$this->assertSame( array( $hand ), array_column( \GuamRestaurants\Core\Monitoring\Queries::recent_openings( 30, 1 ), 'restaurant_id' ), 'The limit keeps the newest.' );

		global $wpdb;
		$wpdb->update( Schema::table( Schema::CHANGE_LOG ), array( 'detected_at' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'restaurant_id' => $meskla ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( $hand ), array_column( get_recent_openings( 30 ), 'restaurant_id' ), 'Outside the window.' );

		wp_trash_post( $hand );
		$this->assertSame( array(), get_recent_openings( 30 ) );
		$this->assertSame( array(), get_recent_closures( 30 ) );
	}

	/**
	 * WP-Cron mode schedules the enabled jobs; server mode keeps only the health-check backstop.
	 */
	public function test_scheduling_modes() {
		Jobs::schedule();
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::OSM ) ) );
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::HEALTH_CHECK ) ) );
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::GOOGLE_STATUS ) ), 'No key, no Google job.' );
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::YELP_STATUS ) ) );

		putenv( 'GUAM_RESTAURANTS_GOOGLE_PLACES_KEY=test-key' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		Jobs::schedule();
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::GOOGLE_STATUS ) ) );

		putenv( 'GUAM_RESTAURANTS_CRON_MODE=server' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		Jobs::schedule();
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::OSM ) ) );
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::GOOGLE_STATUS ) ) );
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::HEALTH_CHECK ) ) );
	}

	/**
	 * The health check emails once when the weekly import misses its window, and is quiet once it runs.
	 */
	public function test_health_check_alerts_on_missed_job() {
		update_option( Jobs::SINCE_OPTION, time() - 10 * DAY_IN_SECONDS );
		$this->assertSame( array( Jobs::OSM, Jobs::RENEWALS, Jobs::NEWSLETTER ), array_keys( Health_Check::overdue() ), 'Google is off without a key.' );
		$this->assertTrue( Jobs::run( Jobs::RENEWALS )['ok'] );

		$this->assertSame( array( Jobs::OSM, Jobs::NEWSLETTER ), array_keys( Health_Check::overdue() ), 'The daily reminder job is now on time.' );
		Health_Check::run();
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'missed their window', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'wp guam-restaurants run-job osm', $this->mails[0]['message'] );

		Health_Check::run();
		$this->assertCount( 1, $this->mails, 'The same missed window is reported once.' );

		$outcome = Jobs::run( Jobs::OSM, array( 'elements' => Guam_Fixtures::elements() ) );
		$this->assertTrue( $outcome['ok'] );
		$this->assertTrue( Jobs::run( Jobs::NEWSLETTER, array( 'notify' => false ) )['ok'] );
		$this->assertSame( array(), Health_Check::overdue() );
		$this->assertSame( 'All jobs ran in their windows.', Health_Check::run() );
		$this->assertCount( 1, $this->mails );

		$this->assertNotContains( Jobs::OSM, array_keys( Health_Check::overdue( time() + 7 * DAY_IN_SECONDS ) ), 'Within interval plus grace.' );
		$this->assertSame( array( Jobs::OSM, Jobs::RENEWALS, Jobs::NEWSLETTER ), array_keys( Health_Check::overdue( time() + 9 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The OSM job imports, runs duplicate detection and records it in the run notes.
	 */
	public function test_osm_job_runs_duplicates_and_notes() {
		$this->import();
		$this->restaurant(
			'Meskla Chamorro Fusion Bistro',
			array(
				Restaurant_Meta::PHONE        => '+16715550177',
				Restaurant_Meta::LATITUDE     => 13.4770,
				Restaurant_Meta::LONGITUDE    => 144.7531,
				Restaurant_Meta::COORD_SOURCE => 'manual',
			)
		);

		$outcome = Jobs::run( Jobs::OSM, array( 'elements' => Guam_Fixtures::elements() ) );

		$this->assertTrue( $outcome['ok'] );
		$this->assertStringContainsString( '1 merged automatically', $outcome['summary'] );
		$run = Ingestion_Runs::get( (int) $outcome['run_id'] );
		$this->assertStringContainsString( 'duplicates: 1 merged automatically, 0 queued for review', $run['notes'] );
		$this->assertTrue( Jobs::state( Jobs::OSM )['last_ok'] );
	}

	/**
	 * A failed run is logged with its error so it shows on the Import runs screen.
	 */
	public function test_failures_show_on_import_runs_screen() {
		Ingestion_Runs::log_failure( 'osm', 'Overpass: Gave up after 6 attempts (HTTP 504).' );
		$this->import();

		$this->login_admin();
		ob_start();
		Ingestion_Runs_Screen::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Overpass: Gave up after 6 attempts (HTTP 504).', $html );
		$this->assertStringContainsString( 'Scheduled jobs', $html );
		$this->assertStringContainsString( 'import-osm', $html );
		$this->assertSame( 2, Ingestion_Runs::count( 'osm' ) );
		$this->assertNotNull( Ingestion_Runs::last_success( 'osm' ) );
		$this->assertStringNotContainsString( 'failed before', (string) Ingestion_Runs::last_success( 'osm' )['notes'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Ingestion_Runs_Screen::render();
		$this->assertSame( '', ob_get_clean(), 'Administrators only.' );
	}
}
