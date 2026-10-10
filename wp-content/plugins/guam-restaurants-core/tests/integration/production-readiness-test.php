<?php
/**
 * Security headers, hardening, two-factor policy, schema upgrade lock,
 * health endpoint, spend report, preflight and legal pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Legal\Markdown;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Monitoring\Health_Endpoint;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Monitoring\Spend_Report;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;
use GuamRestaurants\Core\Security\Hardening;
use GuamRestaurants\Core\Security\Headers;
use GuamRestaurants\Core\Security\Preflight;
use GuamRestaurants\Core\Security\Two_Factor_Policy;
use GuamRestaurants\Core\Site\Pages as Site_Pages;
use WP_REST_Request;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.

/**
 * Production readiness.
 *
 * @covers \GuamRestaurants\Core\Security\Headers
 * @covers \GuamRestaurants\Core\Security\Hardening
 * @covers \GuamRestaurants\Core\Security\Two_Factor_Policy
 * @covers \GuamRestaurants\Core\Security\Preflight
 * @covers \GuamRestaurants\Core\Monitoring\Health_Endpoint
 * @covers \GuamRestaurants\Core\Monitoring\Spend_Report
 * @covers \GuamRestaurants\Core\Legal\Markdown
 * @covers \GuamRestaurants\Core\Legal\Pages
 */
class Production_Readiness_Test extends Test_Case {

	/**
	 * Settings touched by these tests.
	 */
	private const ENV = array(
		'GUAM_RESTAURANTS_HSTS',
		'GUAM_RESTAURANTS_CSP',
		'GUAM_RESTAURANTS_SECURITY_HEADERS',
		'GUAM_RESTAURANTS_MAX_UPLOAD_MB',
		'GUAM_RESTAURANTS_CLIENT_IP_HEADER',
		'GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA',
		'GUAM_RESTAURANTS_ANALYTICS_SCRIPT_URL',
	);

	/**
	 * Clears settings and server values.
	 */
	public function tear_down() {
		foreach ( self::ENV as $name ) {
			putenv( $name );
		}
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_GET['author'] );
		delete_option( Schema::LOCK_OPTION );
		delete_option( Budget::MONTHLY_OPTION );
		delete_option( Budget::HISTORY_OPTION );
		delete_option( Jobs::STATE_OPTION );
		parent::tear_down();
	}

	/**
	 * Base headers always; HSTS only over HTTPS and when turned on.
	 */
	public function test_base_headers_and_hsts() {
		$headers = Headers::base( true );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
		$this->assertStringContainsString( 'geolocation=()', $headers['Permissions-Policy'] );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );

		putenv( 'GUAM_RESTAURANTS_HSTS=1' );
		$this->assertSame( Headers::HSTS_VALUE, Headers::base( true )['Strict-Transport-Security'] );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', Headers::base( false ), 'Never over plain HTTP.' );
	}

	/**
	 * CSP is report-only by default, enforced or off on request, and
	 * allows the hosts the site really uses.
	 */
	public function test_csp_modes_and_sources() {
		$headers = Headers::public_headers( array( 'X-Pingback' => 'https://example.com/xmlrpc.php' ) );
		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', $headers );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $headers );
		$this->assertArrayNotHasKey( 'X-Pingback', $headers );

		$csp = $headers['Content-Security-Policy-Report-Only'];
		$this->assertStringContainsString( "object-src 'none'", $csp );
		$this->assertStringContainsString( "frame-ancestors 'self'", $csp );
		$this->assertStringContainsString( 'frame-src \'self\' https://www.google.com', $csp, 'Google Maps Embed.' );
		$this->assertStringContainsString( 'https://*.paypal.com', $csp );
		$this->assertStringContainsString( "worker-src 'self' blob:", $csp, 'MapLibre workers.' );

		putenv( 'GUAM_RESTAURANTS_ANALYTICS_SCRIPT_URL=https://cloud.umami.is/script.js' );
		$this->assertStringContainsString( 'https://cloud.umami.is', Headers::csp() );

		putenv( 'GUAM_RESTAURANTS_CSP=enforce' );
		$this->assertArrayHasKey( 'Content-Security-Policy', Headers::public_headers( array() ) );
		putenv( 'GUAM_RESTAURANTS_CSP=off' );
		$headers = Headers::public_headers( array() );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $headers );
		$this->assertArrayNotHasKey( 'Content-Security-Policy-Report-Only', $headers );

		putenv( 'GUAM_RESTAURANTS_SECURITY_HEADERS=0' );
		$this->assertFalse( Headers::enabled() );
	}

	/**
	 * Visitors cannot list users; signed-in users can.
	 */
	public function test_user_routes_need_sign_in() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/users' );
		$result  = Hardening::protect_user_routes( null, rest_get_server(), $request );
		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );

		$this->assertNull( Hardening::protect_user_routes( null, rest_get_server(), new WP_REST_Request( 'GET', '/guam-restaurants/v1/health' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertNull( Hardening::protect_user_routes( null, rest_get_server(), $request ) );
	}

	/**
	 * `?author=` scans are blocked for visitors and the users sitemap is gone.
	 */
	public function test_author_scans_and_user_sitemap() {
		$_GET['author'] = '1';
		$this->assertTrue( Hardening::is_author_scan() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( Hardening::is_author_scan() );

		$this->assertFalse( Hardening::drop_user_sitemap( 'provider', 'users' ) );
		$this->assertSame( 'provider', Hardening::drop_user_sitemap( 'provider', 'posts' ) );
	}

	/**
	 * Upload cap and allowed types.
	 */
	public function test_upload_limits() {
		$this->assertSame( 10 * MB_IN_BYTES, Hardening::upload_size_limit( 64 * MB_IN_BYTES ) );
		$this->assertSame( 2 * MB_IN_BYTES, Hardening::upload_size_limit( 2 * MB_IN_BYTES ), 'A lower server limit wins.' );
		putenv( 'GUAM_RESTAURANTS_MAX_UPLOAD_MB=5' );
		$this->assertSame( 5 * MB_IN_BYTES, Hardening::upload_size_limit( 64 * MB_IN_BYTES ) );

		$all   = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'webp'         => 'image/webp',
			'pdf'          => 'application/pdf',
			'zip'          => 'application/zip',
			'mp4|m4v'      => 'video/mp4',
		);
		$owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( array( 'jpg|jpeg|jpe', 'webp', 'pdf' ), array_keys( Hardening::upload_mimes( $all, $owner ) ) );
		$this->assertSame( $all, Hardening::upload_mimes( $all, $admin ) );

		$file = Hardening::check_image_size(
			array(
				'name'     => 'menu.jpg',
				'size'     => 6 * MB_IN_BYTES,
				'tmp_name' => '',
				'error'    => 0,
			)
		);
		$this->assertStringContainsString( '5 MB', $file['error'] );
	}

	/**
	 * Images above the megapixel limit are refused; normal photos pass.
	 */
	public function test_megapixel_limit() {
		$path = wp_tempnam( 'gr-photo' );
		$this->assertTrue( imagepng( imagecreatetruecolor( 40, 30 ), $path ) );
		$ok = Hardening::check_image_size(
			array(
				'size'     => filesize( $path ),
				'tmp_name' => $path,
				'error'    => 0,
			)
		);
		$this->assertArrayNotHasKey( 'error', array_filter( $ok ) );

		// A PNG header claiming 9000 x 6000: the size is read from the header, never decoded.
		$ihdr = pack( 'NNCCCCC', 9000, 6000, 8, 2, 0, 0, 0 );
		file_put_contents( $path, "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file.
		$big = Hardening::check_image_size(
			array(
				'size'     => filesize( $path ),
				'tmp_name' => $path,
				'error'    => 0,
			)
		);
		wp_delete_file( $path );
		$this->assertStringContainsString( '40 megapixels', $big['error'] );
	}

	/**
	 * The CDN's client IP header is used only when configured and valid.
	 */
	public function test_client_ip_header() {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';
		$direct                           = Visitor_Reviews::client_key();
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$this->assertSame( $direct, Visitor_Reviews::client_key(), 'Ignored until configured.' );

		putenv( 'GUAM_RESTAURANTS_CLIENT_IP_HEADER=HTTP_CF_CONNECTING_IP' );
		$this->assertNotSame( $direct, Visitor_Reviews::client_key() );
		$this->assertSame( hash_hmac( 'sha256', '198.51.100.7', wp_salt( 'nonce' ) ), Visitor_Reviews::client_key() );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$this->assertSame( $direct, Visitor_Reviews::client_key(), 'Invalid values fall back to REMOTE_ADDR.' );
	}

	/**
	 * Without the Two Factor plugin nobody is redirected, even when required.
	 */
	public function test_two_factor_policy_without_plugin() {
		putenv( 'GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA=1' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertSame( class_exists( 'Two_Factor_Core' ), Two_Factor_Policy::plugin_available() );
		if ( ! class_exists( 'Two_Factor_Core' ) ) {
			$this->assertFalse( Two_Factor_Policy::needs_setup( $admin ) );
		}
		$this->assertFalse( Two_Factor_Policy::needs_setup( self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
	}

	/**
	 * A held upgrade lock stops a second upgrade.
	 */
	public function test_schema_upgrade_lock() {
		$this->assertTrue( Schema::is_current() );
		add_option( Schema::LOCK_OPTION, time() );
		$this->assertFalse( Schema::upgrade() );
		$this->assertNotFalse( get_option( Schema::LOCK_OPTION ), 'The other run keeps its lock.' );
	}

	/**
	 * Health answers ok, then degraded when a job is overdue.
	 */
	public function test_health_endpoint() {
		$response = Health_Endpoint::respond();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $response->get_data()['status'] );
		$this->assertSame( 'no-store, max-age=0', $response->get_headers()['Cache-Control'] );

		update_option( Jobs::SINCE_OPTION, time() - 30 * DAY_IN_SECONDS );
		$data = Health_Endpoint::respond()->get_data();
		$this->assertSame( 'degraded', $data['status'] );
		$this->assertFalse( $data['checks']['jobs'] );
		$this->assertSame( array( 'status', 'checks' ), array_keys( $data ), 'Nothing else is disclosed.' );
	}

	/**
	 * Calls are grouped per source, free calls are not charged, and a
	 * finished month is archived for the report.
	 */
	public function test_spend_report() {
		update_option(
			Budget::MONTHLY_OPTION,
			array(
				'month' => '2026-09',
				'calls' => array(
					Pricing::GOOGLE_TEXT_SEARCH_PRO    => 4600,
					Pricing::GOOGLE_DETAILS_ENTERPRISE => 100,
					Pricing::FOURSQUARE_PRO            => 10,
				),
				'spend' => array( 'google' => 0.0 ),
			)
		);
		$this->assertSame( 4600, Budget::usage_for( '2026-09' )['calls'][ Pricing::GOOGLE_TEXT_SEARCH_PRO ], 'Readable before archiving.' );

		Budget::archive_previous_month();
		$this->assertFalse( get_option( Budget::MONTHLY_OPTION ) );

		$report = Spend_Report::build( '2026-09' );
		$rows   = array_column( $report['rows'], null, 'source' );
		$this->assertSame( 4700, $rows['Google Places']['calls'] );
		$this->assertSame( 100, $rows['Google Places']['billable_calls'], '4,500 Text Search calls are free; 100 Details calls are within 900.' );
		$this->assertSame( 3.2, $rows['Google Places']['estimated_cost'] );
		$this->assertSame( 0.0, $rows['Foursquare']['estimated_cost'] );
		$this->assertSame( 0, $rows['Yelp']['calls'] );
		$this->assertSame( 3.2, $report['total_cost'] );
		$this->assertStringContainsString( 'Google Places: 4,700 calls (4,600 free, 100 billable), estimated $3.20', implode( "\n", Spend_Report::lines( $report ) ) );

		$this->assertSame( '2026-09', Spend_Report::previous_month( gmmktime( 3, 0, 0, 10, 1, 2026 ) ) );
		$this->assertSame( '2025-12', Spend_Report::previous_month( gmmktime( 3, 0, 0, 1, 15, 2026 ) ) );
	}

	/**
	 * The job emails each month's report once.
	 */
	public function test_spend_report_job_sends_once() {
		reset_phpmailer_instance();
		$now   = gmmktime( 22, 0, 0, 10, 1, 2026 );
		$first = Spend_Report::run( $now );
		$this->assertStringContainsString( 'email sent', $first );
		$this->assertStringContainsString( 'API spend report for 2026-09', tests_retrieve_phpmailer_instance()->get_sent()->subject );
		$this->assertStringContainsString( 'already sent', Spend_Report::run( $now ) );
	}

	/**
	 * Preflight fails on a dangerous default role.
	 */
	public function test_preflight_flags_default_role() {
		update_option( 'default_role', 'administrator' );
		$results = array_column( Preflight::run(), null, 'check' );
		$this->assertSame( Preflight::FAIL, $results['New user role']['status'] );
		$this->assertTrue( Preflight::has_failures( array_values( $results ) ) );

		update_option( 'default_role', 'subscriber' );
		$results = array_column( Preflight::run(), null, 'check' );
		$this->assertSame( Preflight::OK, $results['New user role']['status'] );
		$this->assertSame( Preflight::OK, $results['Custom tables current']['status'] );
	}

	/**
	 * The Markdown subset becomes core blocks, escaped.
	 */
	public function test_markdown_to_blocks() {
		$html = Markdown::to_blocks(
			"Intro for {site} <b>here</b>.\n\n## Heading\n\n### Sub\n\n- **Bold** item\n- [Link]({url})\n\n| A | B |\n|---|---|\n| one | two |\n\n<!-- wp:guam-restaurants/data-attributions /-->",
			array(
				'site' => 'Guam & Co',
				'url'  => 'https://example.com/a?b=1&c=2',
			)
		);
		$this->assertStringContainsString( '<p>Intro for Guam &amp; Co &lt;b&gt;here&lt;/b&gt;.</p>', $html );
		$this->assertStringContainsString( '<!-- wp:heading --><h2 class="wp-block-heading">Heading</h2>', $html );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Sub</h3>', $html );
		$this->assertStringContainsString( '<li><strong>Bold</strong> item</li>', $html );
		$this->assertStringContainsString( '<a href="https://example.com/a?b=1&#038;c=2">Link</a>', $html );
		$this->assertStringContainsString( '<thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>one</td><td>two</td></tr></tbody>', $html );
		$this->assertStringContainsString( "\n\n<!-- wp:guam-restaurants/data-attributions /-->", $html );
	}

	/**
	 * Legal pages: drafts filled, public pages created, statement added
	 * once, and edited pages left alone.
	 */
	public function test_legal_pages() {
		Site_Pages::ensure();
		Policy_Page::ensure();
		$privacy = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Privacy Policy',
				'post_content' => 'WordPress default text',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $privacy );

		$done = Legal_Pages::ensure();
		$this->assertSame( 'filled draft', $done['privacy'] );
		$this->assertSame( 'filled draft', $done['terms'] );
		$this->assertSame( 'added statement', $done['editorial'] );
		$this->assertSame( 'created', $done['disclosure'] );
		$this->assertSame( 'created', $done['sources'] );

		$this->assertSame( 'draft', get_post_status( $privacy ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Cookies</h2>', get_post_field( 'post_content', $privacy ) );
		$terms = get_page_by_path( 'terms', OBJECT, 'page' );
		$this->assertSame( 'draft', $terms->post_status );
		$this->assertStringContainsString( 'Governing law', $terms->post_content );
		$this->assertNotSame( '', Legal_Pages::url( 'disclosure' ) );
		$this->assertStringContainsString( 'What can never be bought', get_page_by_path( 'advertising-disclosure', OBJECT, 'page' )->post_content );

		$policy = get_page_by_path( Policy_Page::SLUG, OBJECT, 'page' )->post_content;
		$this->assertLessThan( strpos( $policy, '[' . Policy_Page::SHORTCODE . ']' ), strpos( $policy, Legal_Pages::STATEMENT_MARKER ), 'Statement comes before the highlights rules.' );

		$again = Legal_Pages::ensure();
		$this->assertSame( 'kept (already there)', $again['editorial'] );
		$this->assertSame( 'kept', $again['disclosure'] );
		$this->assertStringStartsWith( 'kept', $again['terms'] );
		$this->assertSame( 1, substr_count( get_page_by_path( Policy_Page::SLUG, OBJECT, 'page' )->post_content, Legal_Pages::STATEMENT_MARKER ) );
	}

	/**
	 * A published privacy page is never touched.
	 */
	public function test_published_privacy_page_is_kept() {
		$privacy = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Our own policy',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$this->assertStringStartsWith( 'kept', Legal_Pages::fill_privacy() );
		$this->assertSame( 'Our own policy', get_post_field( 'post_content', $privacy ) );
	}
}
