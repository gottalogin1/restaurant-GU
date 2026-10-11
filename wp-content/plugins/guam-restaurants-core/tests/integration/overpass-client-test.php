<?php
/**
 * Overpass client tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Osm\Overpass_Client;

/**
 * Query building and response handling, with a fake transport.
 *
 * @covers \GuamRestaurants\Core\Osm\Overpass_Client
 */
class Overpass_Client_Test extends Test_Case {

	/**
	 * The query covers every requested amenity in Guam's box, with centers for ways.
	 */
	public function test_query() {
		$query = Overpass_Client::query();

		$this->assertStringContainsString( '[out:json]', $query );
		$this->assertStringContainsString( 'nwr["amenity"~"^(restaurant|cafe|fast_food|bar|pub|food_court)$"](13.18,144.56,13.71,145.01);', $query );
		$this->assertStringContainsString( "out center;\n", $query );
		$this->assertStringContainsString( 'nwr["shop"="mall"](13.18,144.56,13.71,145.01);', $query );
		$this->assertStringContainsString( "out geom;\n", $query );
	}

	/**
	 * One POST with an identifying user agent; elements decoded.
	 */
	public function test_fetch_sends_user_agent_and_decodes() {
		$sent   = array();
		$client = new Overpass_Client(
			new Retrying_Client(
				static function ( $url, $args ) use ( &$sent ) {
					$sent[] = array( $url, $args );
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"elements":[{"type":"node","id":1}]}',
					);
				},
				static function () {}
			)
		);

		$result = $client->fetch();

		$this->assertCount( 1, $sent );
		$this->assertSame( Overpass_Client::DEFAULT_URL, $sent[0][0] );
		$this->assertSame( 'POST', $sent[0][1]['method'] );
		$this->assertStringStartsWith( 'guam-restaurants-core/', $sent[0][1]['user-agent'] );
		$this->assertSame( Overpass_Client::query(), $sent[0][1]['body']['data'] );
		$this->assertSame( array( array( 'type' => 'node', 'id' => 1 ) ), $result['elements'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * After the main instance fails every retry, the fallback gets the query once.
	 */
	public function test_falls_back_after_main_instance_gives_up() {
		$sent   = array();
		$client = new Overpass_Client(
			new Retrying_Client(
				static function ( $url ) use ( &$sent ) {
					$sent[] = $url;
					return Overpass_Client::DEFAULT_URL === $url
						? array(
							'response' => array( 'code' => 504 ),
							'body'     => 'Gateway Timeout',
						)
						: array(
							'response' => array( 'code' => 200 ),
							'body'     => '{"elements":[{"type":"node","id":2}]}',
						);
				},
				static function () {}
			)
		);

		$result = $client->fetch();

		$this->assertSame( array_fill( 0, Retrying_Client::MAX_RETRIES + 1, Overpass_Client::DEFAULT_URL ), array_slice( $sent, 0, -1 ) );
		$this->assertSame( Overpass_Client::DEFAULT_FALLBACK_URL, end( $sent ) );
		$this->assertSame( 2, $result['elements'][0]['id'] );
		$log = $client->attempt_log();
		$this->assertSame( 'overpass-api.de attempt 1: HTTP 504', $log[0] );
		$this->assertCount( Retrying_Client::MAX_RETRIES + 1, $log, 'Only failed attempts are logged.' );
	}

	/**
	 * An unusable answer (runtime error remark) also moves on; when every
	 * instance fails, the last error is thrown.
	 */
	public function test_throws_when_every_instance_fails() {
		$sent   = array();
		$client = new Overpass_Client(
			new Retrying_Client(
				static function ( $url ) use ( &$sent ) {
					$sent[] = $url;
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => '{"elements":[],"remark":"runtime error: Query timed out"}',
					);
				},
				static function () {}
			)
		);

		try {
			$client->fetch();
			$this->fail( 'Expected an exception.' );
		} catch ( Request_Failed $e ) {
			$this->assertStringContainsString( 'Query timed out', $e->getMessage() );
			$this->assertSame( array(), $e->attempt_log(), 'HTTP 200 answers are not failed attempts.' );
		}
		$this->assertSame( array( Overpass_Client::DEFAULT_URL, Overpass_Client::DEFAULT_FALLBACK_URL ), $sent );
	}

	/**
	 * "none" turns the fallback off.
	 */
	public function test_fallback_can_be_turned_off() {
		putenv( 'GUAM_RESTAURANTS_OVERPASS_FALLBACK_URL=none' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		try {
			$this->assertSame( array( Overpass_Client::DEFAULT_URL ), Overpass_Client::urls() );
		} finally {
			putenv( 'GUAM_RESTAURANTS_OVERPASS_FALLBACK_URL' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		}
		$this->assertSame( array( Overpass_Client::DEFAULT_URL, Overpass_Client::DEFAULT_FALLBACK_URL ), Overpass_Client::urls() );
	}

	/**
	 * Overpass runtime errors (HTTP 200 with a remark) are failures, not empty data.
	 */
	public function test_runtime_error_remark_throws() {
		$this->expectException( \RuntimeException::class );
		Overpass_Client::decode( '{"elements":[],"remark":"runtime error: Query timed out in \"query\" at line 2"}' );
	}

	/**
	 * Non-JSON responses throw.
	 */
	public function test_invalid_json_throws() {
		$this->expectException( \RuntimeException::class );
		Overpass_Client::decode( '<html>Too many requests</html>' );
	}
}
