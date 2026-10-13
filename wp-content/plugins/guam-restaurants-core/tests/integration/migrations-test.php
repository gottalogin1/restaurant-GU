<?php
/**
 * One-time setting migration tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Database\Migrations;

/**
 * Migrations run once, in order, and leave later edits alone.
 *
 * @covers \GuamRestaurants\Core\Database\Migrations
 */
class Migrations_Test extends Test_Case {

	/**
	 * Clears the record between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Migrations::OPTION );
		delete_option( Migrations::LOCK_OPTION );
	}

	/**
	 * A fix is applied once; a value changed afterwards is kept on later runs.
	 */
	public function test_runs_once_and_keeps_later_edits() {
		update_option( 'gr_test_setting', 'old default' );
		$definitions = array(
			'2026-10-13-fix-test-setting' => static function (): void {
				if ( 'old default' === get_option( 'gr_test_setting' ) ) {
					update_option( 'gr_test_setting', 'fixed' );
				}
			},
		);

		$this->assertSame( array( '2026-10-13-fix-test-setting' ), Migrations::run( $definitions ) );
		$this->assertSame( 'fixed', get_option( 'gr_test_setting' ) );

		update_option( 'gr_test_setting', 'chosen by the owner' );
		$this->assertSame( array(), Migrations::run( $definitions ) );
		$this->assertSame( 'chosen by the owner', get_option( 'gr_test_setting' ) );
		$this->assertSame( array(), Migrations::pending( $definitions ) );
	}

	/**
	 * A setting someone already changed is kept even on the first run.
	 */
	public function test_conditional_migration_keeps_custom_value() {
		update_option( 'gr_test_setting', 'custom' );
		Migrations::run(
			array(
				'2026-10-13-fix-test-setting' => static function (): void {
					if ( 'old default' === get_option( 'gr_test_setting' ) ) {
						update_option( 'gr_test_setting', 'fixed' );
					}
				},
			)
		);
		$this->assertSame( 'custom', get_option( 'gr_test_setting' ) );
	}

	/**
	 * Migrations run in order; one that throws stops the run, is not
	 * recorded, and is retried next time while earlier ones are not repeated.
	 */
	public function test_failure_stops_and_retries_later() {
		$calls       = array();
		$fail        = true;
		$definitions = array(
			'a' => static function () use ( &$calls ): void {
				$calls[] = 'a';
			},
			'b' => static function () use ( &$calls, &$fail ): void {
				$calls[] = 'b';
				if ( $fail ) {
					throw new \RuntimeException( 'not yet' );
				}
			},
			'c' => static function () use ( &$calls ): void {
				$calls[] = 'c';
			},
		);

		try {
			Migrations::run( $definitions );
			$this->fail( 'Expected the exception to propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'not yet', $e->getMessage() );
		}
		$this->assertSame( array( 'a' ), array_keys( Migrations::applied() ) );
		$this->assertFalse( get_option( Migrations::LOCK_OPTION ) );

		$fail = false;
		$this->assertSame( array( 'b', 'c' ), Migrations::run( $definitions ) );
		$this->assertSame( array( 'a', 'b', 'b', 'c' ), $calls );
	}

	/**
	 * A fresh lock held by another run means this call does nothing.
	 */
	public function test_respects_lock() {
		add_option( Migrations::LOCK_OPTION, time() );
		$this->assertNull(
			Migrations::run(
				array(
					'a' => static function (): void {},
				)
			)
		);
		$this->assertSame( array(), Migrations::applied() );
	}
}
