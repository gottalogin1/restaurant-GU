<?php
/**
 * HTTP client with retries, for the importers.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a request and retries it on network errors, HTTP 429 and 5xx
 * responses (CLAUDE.md rule 9: respect rate limits).
 *
 * The transport and the sleep function can be swapped out, so tests never
 * touch the network or wait.
 */
class Retrying_Client {

	/**
	 * Retries after the first attempt.
	 */
	public const MAX_RETRIES = 5;

	/**
	 * Sends the request; same signature as wp_remote_request().
	 *
	 * @var callable(string, array<string, mixed>): (array<string, mixed>|\WP_Error)
	 */
	private $transport;

	/**
	 * Waits the given number of seconds.
	 *
	 * @var callable(int): void
	 */
	private $sleeper;

	/**
	 * Status codes that mean "slow down" and get a longer wait.
	 *
	 * @var int[]
	 */
	private array $rate_limit_codes;

	/**
	 * Seconds to wait after a rate-limit response with no Retry-After header.
	 *
	 * @var int
	 */
	private int $rate_limit_wait;

	/**
	 * Log of failed attempts, for the command output.
	 *
	 * @var string[]
	 */
	private array $attempt_log = array();

	/**
	 * Constructor.
	 *
	 * @param callable|null $transport        Defaults to wp_remote_request().
	 * @param callable|null $sleeper          Defaults to sleep().
	 * @param int[]         $rate_limit_codes Codes treated as rate limiting.
	 * @param int           $rate_limit_wait  Seconds to wait after one of them.
	 */
	public function __construct( ?callable $transport = null, ?callable $sleeper = null, array $rate_limit_codes = array( 429 ), int $rate_limit_wait = 30 ) {
		$this->transport        = $transport ?? 'wp_remote_request';
		$this->sleeper          = $sleeper ?? 'sleep';
		$this->rate_limit_codes = $rate_limit_codes;
		$this->rate_limit_wait  = $rate_limit_wait;
	}

	/**
	 * Sends a request, retrying up to MAX_RETRIES times. Waits between
	 * attempts: the Retry-After header or the rate-limit wait after a
	 * rate-limit response, otherwise 2, 4, 8, 16 and 32 seconds.
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args wp_remote_request() arguments.
	 * @return array{code: int, body: string}
	 * @throws \RuntimeException When every attempt fails or a non-retryable error comes back.
	 */
	public function request( string $url, array $args ): array {
		$last_error = '';
		$last_wait  = null;

		for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			if ( $attempt > 0 ) {
				call_user_func( $this->sleeper, $last_wait ?? 2 ** $attempt );
			}
			$last_wait = null;

			$response = call_user_func( $this->transport, $url, $args );

			if ( is_wp_error( $response ) ) {
				$last_error          = $response->get_error_message();
				$this->attempt_log[] = sprintf( 'attempt %d: %s', $attempt + 1, $last_error );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );

			if ( $code >= 200 && $code < 300 ) {
				return array(
					'code' => $code,
					'body' => $body,
				);
			}

			$last_error          = sprintf( 'HTTP %d', $code );
			$this->attempt_log[] = sprintf( 'attempt %d: %s', $attempt + 1, $last_error );

			if ( in_array( $code, $this->rate_limit_codes, true ) ) {
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$last_wait   = is_numeric( $retry_after ) ? max( 1, (int) $retry_after ) : $this->rate_limit_wait;
				continue;
			}
			if ( $code < 500 ) {
				// A client error will not fix itself; retrying would only spend quota.
				throw new \RuntimeException( esc_html( $last_error . ': ' . substr( $body, 0, 300 ) ) );
			}
		}

		throw new \RuntimeException( esc_html( sprintf( 'Gave up after %d attempts (%s).', self::MAX_RETRIES + 1, $last_error ) ) );
	}

	/**
	 * Failed attempts so far.
	 *
	 * @return string[]
	 */
	public function attempt_log(): array {
		return $this->attempt_log;
	}
}
