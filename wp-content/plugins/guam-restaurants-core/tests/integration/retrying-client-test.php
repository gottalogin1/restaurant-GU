<?php
/**
 * Retry behaviour of the importers' HTTP client.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Http\Retrying_Client;
use WP_Error;

/**
 * Retries and waits, with a fake transport and no real sleeping.
 *
 * @covers \GuamRestaurants\Core\Http\Retrying_Client
 */
class Retrying_Client_Test extends Test_Case {

	/**
	 * Seconds the client asked to wait.
	 *
	 * @var int[]
	 */
	private array $waits = array();

	/**
	 * Client whose transport returns the given responses in order.
	 *
	 * @param array<int, array<string, mixed>|WP_Error> $responses Responses.
	 * @param int                                       $calls     Number of calls made, by reference.
	 */
	private function client( array $responses, &$calls ): Retrying_Client {
		$calls = 0;
		return new Retrying_Client(
			function () use ( &$responses, &$calls ) {
				++$calls;
				return array_shift( $responses );
			},
			function ( $seconds ) {
				$this->waits[] = $seconds;
			},
			array( 429, 406 ),
			30
		);
	}

	/**
	 * A response with a status code.
	 *
	 * @param int                   $code    Code.
	 * @param string                $body    Body.
	 * @param array<string, string> $headers Headers.
	 * @return array<string, mixed>
	 */
	private static function response( int $code, string $body = '', array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	/**
	 * Network errors and 5xx are retried with backoff until one succeeds.
	 */
	public function test_retries_until_success() {
		$client = $this->client( array( new WP_Error( 'http', 'timeout' ), self::response( 504 ), self::response( 200, 'ok' ) ), $calls );

		$this->assertSame(
			array(
				'code' => 200,
				'body' => 'ok',
			),
			$client->request( 'https://example.test', array() )
		);
		$this->assertSame( 3, $calls );
		$this->assertSame( array( 2, 4 ), $this->waits );
		$this->assertCount( 2, $client->attempt_log() );
	}

	/**
	 * Five retries after the first attempt, then it gives up.
	 */
	public function test_gives_up_after_five_retries() {
		$client = $this->client( array_fill( 0, 10, self::response( 500 ) ), $calls );

		try {
			$client->request( 'https://example.test', array() );
			$this->fail( 'Expected an exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Gave up after 6 attempts', $e->getMessage() );
		}
		$this->assertSame( 6, $calls );
		$this->assertSame( array( 2, 4, 8, 16, 32 ), $this->waits );
	}

	/**
	 * Rate-limit responses wait 30 s, or what Retry-After asks.
	 */
	public function test_rate_limit_waits() {
		$client = $this->client( array( self::response( 429 ), self::response( 429, '', array( 'retry-after' => '7' ) ), self::response( 200 ) ), $calls );

		$client->request( 'https://example.test', array() );

		$this->assertSame( array( 30, 7 ), $this->waits );
	}

	/**
	 * Other 4xx errors are not retried.
	 */
	public function test_client_errors_are_not_retried() {
		$client = $this->client( array( self::response( 400, 'bad query' ), self::response( 200 ) ), $calls );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 400: bad query' );
		try {
			$client->request( 'https://example.test', array() );
		} finally {
			$this->assertSame( 1, $calls );
		}
	}
}
