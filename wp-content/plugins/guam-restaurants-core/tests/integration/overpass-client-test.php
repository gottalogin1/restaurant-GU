<?php
/**
 * Overpass client tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

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
