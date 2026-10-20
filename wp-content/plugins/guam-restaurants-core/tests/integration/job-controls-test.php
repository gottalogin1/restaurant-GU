<?php
/**
 * Scheduled jobs screen: turning jobs off and on, Run now, and the WP-Cron loopback.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Scheduled_Jobs_Screen;
use GuamRestaurants\Core\Monitoring\Health_Check;
use GuamRestaurants\Core\Monitoring\Job_Controls;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Ratings\Refresher;

/**
 * Job controls with the Guam fixtures.
 *
 * @covers \GuamRestaurants\Core\Monitoring\Job_Controls
 * @covers \GuamRestaurants\Core\Admin\Scheduled_Jobs_Screen
 */
class Job_Controls_Test extends Guam_Test_Case {

	/**
	 * Clears schedules, job state and config.
	 */
	public function tear_down() {
		putenv( 'GUAM_RESTAURANTS_CRON_LOOPBACK_URL' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		wp_unschedule_hook( Jobs::HOOK );
		wp_unschedule_hook( Job_Controls::RUN_NOW_HOOK );
		delete_option( Job_Controls::PAUSED_OPTION );
		delete_option( Jobs::STATE_OPTION );
		Refresher::schedule();
		parent::tear_down();
	}

	/**
	 * Turning the OSM import off removes its event, skips it on WP-Cron and in the health check; turning it on restores it.
	 */
	public function test_turning_a_job_off_and_on() {
		Jobs::schedule();
		update_option( Jobs::SINCE_OPTION, time() - 10 * DAY_IN_SECONDS );
		$this->assertArrayHasKey( Jobs::OSM, Health_Check::overdue() );

		$this->assertTrue( Job_Controls::set_paused( Jobs::OSM, true, 7 ) );
		$this->assertTrue( Job_Controls::is_paused( Jobs::OSM ) );
		$this->assertSame( 7, Job_Controls::paused()[ Jobs::OSM ]['by'] );
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::OSM ) ) );
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::NEWSLETTER ) ), 'Other jobs keep their schedule.' );
		$this->assertArrayNotHasKey( Jobs::OSM, Health_Check::overdue(), 'A job that is off is not reported as missed.' );

		Jobs::schedule();
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::OSM ) ), 'Stays off on the next page load.' );

		Jobs::run_from_cron( Jobs::OSM );
		$this->assertSame( array(), Jobs::state( Jobs::OSM ), 'A leftover event does not run it.' );

		$this->assertTrue( Job_Controls::set_paused( Jobs::OSM, false ) );
		$this->assertFalse( Job_Controls::is_paused( Jobs::OSM ) );
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::OSM ) ) );
		$this->assertArrayNotHasKey( Jobs::OSM, Health_Check::overdue(), 'The window restarts when it is turned back on.' );
		$this->assertArrayHasKey( Jobs::OSM, Health_Check::overdue( time() + 9 * DAY_IN_SECONDS ) );

		$this->assertFalse( Job_Controls::set_paused( 'nope', true ) );
	}

	/**
	 * Run now queues one single event, once, also for a job that is off; jobs that need a key cannot be queued.
	 */
	public function test_run_now_queues_one_extra_run() {
		Jobs::schedule();
		$regular = wp_next_scheduled( Jobs::HOOK, array( Jobs::RENEWALS ) );
		Job_Controls::set_paused( Jobs::RENEWALS, true );

		$this->assertSame( 'queued', Job_Controls::queue_run( Jobs::RENEWALS ) );
		$this->assertGreaterThan( 0, Job_Controls::queued_at( Jobs::RENEWALS ) );
		$this->assertSame( 'already_queued', Job_Controls::queue_run( Jobs::RENEWALS ) );
		$this->assertSame( 'off', Job_Controls::queue_run( Jobs::GOOGLE_STATUS ), 'No Google key.' );
		$this->assertSame( 'unknown', Job_Controls::queue_run( 'nope' ) );

		Job_Controls::run_queued( Jobs::RENEWALS );
		$this->assertTrue( Jobs::state( Jobs::RENEWALS )['last_ok'] );
		$this->assertGreaterThan( 0, Job_Controls::last_success( Jobs::RENEWALS ) );

		Job_Controls::set_paused( Jobs::RENEWALS, false );
		$this->assertGreaterThanOrEqual( $regular, wp_next_scheduled( Jobs::HOOK, array( Jobs::RENEWALS ) ), 'The regular schedule is still there.' );

		Jobs::update_state( Jobs::NEWSLETTER, array( 'last_started' => time() - 60 ) );
		$this->assertSame( 'running', Job_Controls::queue_run( Jobs::NEWSLETTER ) );
		Jobs::update_state( Jobs::NEWSLETTER, array( 'last_started' => time() - 2 * HOUR_IN_SECONDS ) );
		$this->assertSame( 0, Job_Controls::running_since( Jobs::NEWSLETTER ), 'A run that never finished is taken as stopped after an hour.' );
	}

	/**
	 * The ratings refresh can be turned off and run on its own.
	 */
	public function test_ratings_refresh_controls() {
		Refresher::schedule();
		$this->assertNotFalse( wp_next_scheduled( Refresher::HOOK ) );

		Job_Controls::set_paused( Job_Controls::RATINGS_REFRESH, true );
		$this->assertFalse( wp_next_scheduled( Refresher::HOOK ) );
		Refresher::run_scheduled();
		$this->assertSame( array(), Jobs::state( Job_Controls::RATINGS_REFRESH ) );

		Job_Controls::run_queued( Job_Controls::RATINGS_REFRESH );
		$this->assertTrue( Jobs::state( Job_Controls::RATINGS_REFRESH )['last_ok'] );
		$this->assertGreaterThan( 0, Job_Controls::last_success( Job_Controls::RATINGS_REFRESH ) );

		Job_Controls::set_paused( Job_Controls::RATINGS_REFRESH, false );
		$this->assertNotFalse( wp_next_scheduled( Refresher::HOOK ) );
	}

	/**
	 * With GUAM_RESTAURANTS_CRON_LOOPBACK_URL set, WP-Cron calls that address instead of the site address.
	 */
	public function test_cron_loopback_url() {
		$request = array(
			'url'  => 'http://localhost:8080/wp-cron.php?doing_wp_cron=123.45',
			'key'  => '123.45',
			'args' => array(),
		);
		$this->assertSame( $request, Job_Controls::loopback_request( $request ), 'Unset: unchanged.' );

		putenv( 'GUAM_RESTAURANTS_CRON_LOOPBACK_URL=http://localhost/' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		$this->assertSame( 'http://localhost/wp-cron.php?doing_wp_cron=123.45', Job_Controls::loopback_request( $request )['url'] );
		$this->assertSame( 'http://localhost/wp-cron.php?doing_wp_cron=1', apply_filters( 'cron_request', array( 'url' => 'https://localhost:8443/wp-cron.php?doing_wp_cron=1' ) )['url'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
	}

	/**
	 * The screen lists every task with its buttons; its actions apply; administrators only.
	 */
	public function test_screen() {
		$admin = $this->login_admin();
		$this->assertSame( 'paused', Scheduled_Jobs_Screen::apply( Jobs::OSM, 'pause', $admin ) );
		$this->assertSame( 'queued', Scheduled_Jobs_Screen::apply( Jobs::NEWSLETTER, 'run', $admin ) );
		$this->assertSame( 'unknown', Scheduled_Jobs_Screen::apply( Jobs::OSM, 'delete', $admin ) );

		ob_start();
		Scheduled_Jobs_Screen::render();
		$html = ob_get_clean();

		foreach ( array_keys( Job_Controls::tasks() ) as $task ) {
			$this->assertStringContainsString( 'id="gr-job-' . $task . '"', $html );
		}
		$this->assertCount( 10, Job_Controls::tasks() );
		$this->assertStringContainsString( 'Turn on', $html );
		$this->assertStringContainsString( 'Turn off', $html );
		$this->assertStringContainsString( 'Run now', $html );
		$this->assertStringContainsString( 'Extra run queued', $html );
		$this->assertStringContainsString( 'Off (needs an API key or setting)', $html );

		$this->assertSame( 'resumed', Scheduled_Jobs_Screen::apply( Jobs::OSM, 'resume', $admin ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		Scheduled_Jobs_Screen::render();
		$this->assertSame( '', ob_get_clean(), 'Administrators only.' );
	}
}
