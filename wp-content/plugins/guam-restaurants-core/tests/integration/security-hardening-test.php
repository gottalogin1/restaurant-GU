<?php
/**
 * Rate limits on the public beacons, the private uploads folder, and the
 * sign-in, oEmbed and application password hardening.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Database\Migrations;
use GuamRestaurants\Core\Owners\Listing_Stats;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Security\Hardening;
use GuamRestaurants\Core\Security\Headers;
use GuamRestaurants\Core\Security\Preflight;
use GuamRestaurants\Core\Security\Private_Uploads;
use GuamRestaurants\Core\Security\Rate_Limit;
use WP_Error;
use WP_REST_Request;

/**
 * Security hardening, 2026-10-26 review.
 *
 * @covers \GuamRestaurants\Core\Security\Rate_Limit
 * @covers \GuamRestaurants\Core\Security\Private_Uploads
 * @covers \GuamRestaurants\Core\Security\Hardening
 * @covers \GuamRestaurants\Core\Security\Headers
 * @covers \GuamRestaurants\Core\Security\Preflight
 * @covers \GuamRestaurants\Core\Owners\Listing_Stats
 */
class Security_Hardening_Test extends Guam_Test_Case {

	private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

	/**
	 * Body served by the fake web server in probe tests, or null for a 403.
	 *
	 * @var string|null
	 */
	private ?string $served = null;

	/**
	 * Clears the visitor address.
	 */
	public function tear_down() {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	/**
	 * A window allows `limit` requests, refuses the next without counting
	 * it, and starts again once it has passed. Buckets and visitors are separate.
	 */
	public function test_rate_limit_window() {
		$this->assertTrue( Rate_Limit::hit( 'test', 2, 60, 'visitor-a' ) );
		$this->assertTrue( Rate_Limit::hit( 'test', 2, 60, 'visitor-a' ) );
		$this->assertFalse( Rate_Limit::hit( 'test', 2, 60, 'visitor-a' ) );
		$this->assertSame( 2, get_transient( Rate_Limit::key( 'test', 'visitor-a' ) )['count'], 'Refused requests are not counted.' );

		$this->assertTrue( Rate_Limit::hit( 'test', 2, 60, 'visitor-b' ), 'Another visitor has their own count.' );
		$this->assertTrue( Rate_Limit::hit( 'other', 2, 60, 'visitor-a' ), 'Another bucket has its own count.' );

		set_transient(
			Rate_Limit::key( 'test', 'visitor-a' ),
			array(
				'count' => 2,
				'reset' => time() - 1,
			),
			60
		);
		$this->assertTrue( Rate_Limit::hit( 'test', 2, 60, 'visitor-a' ), 'A new window starts after the old one ends.' );
	}

	/**
	 * The visitor key is a keyed hash, never the address.
	 */
	public function test_client_key_is_hashed() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';
		$key                    = Rate_Limit::client_key();
		$this->assertSame( 64, strlen( $key ) );
		$this->assertStringNotContainsString( '203.0.113.30', Rate_Limit::key( 'ads', $key ) );
	}

	/**
	 * The listing beacon stops counting a visitor after PER_HOUR requests.
	 */
	public function test_listing_events_rate_limited() {
		$id                     = $this->restaurant( 'Proa' );
		$send                   = static function () use ( $id ): void {
			$request = new WP_REST_Request( 'POST', '/guam-restaurants/v1/listing/events' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'User-Agent', self::BROWSER );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'restaurant' => $id,
						'events'     => array( 'view' ),
					)
				)
			);
			rest_get_server()->dispatch( $request );
		};
		$_SERVER['REMOTE_ADDR'] = '203.0.113.40';
		set_transient(
			Rate_Limit::key( 'listing', Rate_Limit::client_key() ),
			array(
				'count' => Listing_Stats::PER_HOUR - 1,
				'reset' => time() + HOUR_IN_SECONDS,
			),
			HOUR_IN_SECONDS
		);
		$send();
		$send();
		$today = gmdate( 'Y-m-d', time() + 10 * HOUR_IN_SECONDS );
		$this->assertSame( 1, Listing_Stats::totals( $id, gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ), $today )['views'] );
	}

	/**
	 * The private folder is created with deny rules and an empty index.
	 */
	public function test_private_folder_protection_files() {
		$dir  = Private_Uploads::dir( 'photo-originals' );
		$root = dirname( $dir );
		$this->assertSame( Private_Uploads::FOLDER, basename( $root ) );
		$this->assertStringEqualsFile( $root . '/.htaccess', Private_Uploads::HTACCESS );
		$this->assertStringContainsString( 'Require all denied', Private_Uploads::HTACCESS );
		$this->assertFileExists( $root . '/index.php' );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertTrue( Private_Uploads::contains( $dir . '/a.jpg' ) );
		$this->assertFalse( Private_Uploads::contains( dirname( $root ) . '/2026/10/a.jpg' ) );
	}

	/**
	 * Fake web server: serves files from disk unless $served is null.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return array<string, mixed>
	 */
	public function fake_server( $pre, $args, $url ) {
		unset( $pre, $args );
		if ( null === $this->served ) {
			return array(
				'headers'  => array(),
				'body'     => 'Forbidden',
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
				'cookies'  => array(),
			);
		}
		$file = str_replace( Private_Uploads::url(), Private_Uploads::dir(), (string) $url );
		$body = '' === $this->served && is_file( $file ) ? (string) file_get_contents( $file ) : $this->served; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test file.
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Preflight reports whether the server really refuses the private folder and lists uploads.
	 */
	public function test_preflight_probes() {
		$results = array_column( Preflight::run(), null, 'check' );
		$this->assertSame( Preflight::WARN, $results['Private uploads closed']['status'], 'HTTP is blocked in tests: unknown.' );
		$this->assertSame( Preflight::OK, $results['Application passwords off']['status'] );

		remove_filter( 'pre_http_request', array( $this, 'block_http' ), 1 );
		add_filter( 'pre_http_request', array( $this, 'fake_server' ), 1, 3 );

		$this->served = null;
		$this->assertSame( 'closed', Private_Uploads::probe() );
		$this->assertSame( 'off', Private_Uploads::listing() );

		$this->served = '';
		$this->assertSame( 'open', Private_Uploads::probe(), 'nginx ignoring .htaccess serves the probe.' );
		$this->assertFileDoesNotExist( Private_Uploads::dir() . '/' . Private_Uploads::PROBE, 'The probe is removed.' );

		$this->served = '<html><head><title>Index of /wp-content/uploads</title></head></html>';
		$this->assertSame( 'on', Private_Uploads::listing() );
		$results = array_column( Preflight::run(), null, 'check' );
		$this->assertSame( Preflight::WARN, $results['Uploads folder listing off']['status'] );
	}

	/**
	 * Originals saved in the public folders before this change are moved by the migration.
	 */
	public function test_migration_moves_old_originals() {
		$this->assertArrayHasKey( '2026-10-26-private-photo-originals', Migrations::definitions() );
		$uploads = wp_upload_dir();
		$old     = $uploads['path'] . '/gr-original-oldoriginal1234567.jpg';
		file_put_contents( $old, 'jpeg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test file.
		$photo = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $photo, Photo_Submissions::ORIGINAL, $old );

		$this->assertSame( 1, Photo_Submissions::make_originals_private() );
		$moved = (string) get_post_meta( $photo, Photo_Submissions::ORIGINAL, true );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $moved );
		$this->assertTrue( Private_Uploads::contains( $moved ) );
		$this->assertSame( 0, Photo_Submissions::make_originals_private(), 'Safe to run again.' );
		wp_delete_file( $moved );
	}

	/**
	 * Unknown user and wrong password read the same; other errors are kept.
	 */
	public function test_login_errors_do_not_reveal_accounts() {
		foreach ( array( 'invalid_username', 'invalid_email', 'incorrect_password' ) as $code ) {
			$errors = Hardening::same_login_error( new WP_Error( $code, 'Detailed message naming the account.' ) );
			$this->assertSame( array( 'gr_login_failed' ), $errors->get_error_codes(), $code );
			$this->assertStringNotContainsString( 'naming the account', $errors->get_error_message() );
		}
		$errors = Hardening::same_login_error( new WP_Error( 'empty_password', 'The password field is empty.' ) );
		$this->assertSame( array( 'empty_password' ), $errors->get_error_codes() );
	}

	/**
	 * No author in oEmbed answers, and application passwords are off.
	 */
	public function test_oembed_author_and_app_passwords() {
		$data = Hardening::drop_oembed_author(
			array(
				'title'       => 'Proa',
				'author_name' => 'site-admin',
				'author_url'  => 'https://example.com/author/site-admin/',
			)
		);
		$this->assertSame( array( 'title' => 'Proa' ), $data );
		$this->assertNotFalse( has_filter( 'oembed_response_data', array( Hardening::class, 'drop_oembed_author' ) ) );
		$this->assertNotFalse( has_filter( 'wp_is_application_passwords_available', '__return_false' ) );
		$this->assertFalse( wp_is_application_passwords_available() );
	}

	/**
	 * The added browser headers.
	 */
	public function test_extra_headers() {
		$headers = Headers::base( true );
		$this->assertSame( 'same-origin-allow-popups', $headers['Cross-Origin-Opener-Policy'] );
		$this->assertSame( 'none', $headers['X-Permitted-Cross-Domain-Policies'] );
	}
}
