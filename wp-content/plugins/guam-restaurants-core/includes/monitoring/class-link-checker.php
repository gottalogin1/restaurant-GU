<?php
/**
 * Polite single-page link checks for the monthly link sweep.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Asks whether one link still works, the way a careful crawler would:
 *
 * - It reads the site's robots.txt first (once per site per run) and skips
 *   a page the file disallows for our user agent or for `*`. A missing
 *   robots.txt (HTTP 4xx) allows everything; one that answers 5xx or 429
 *   disallows everything (RFC 9309).
 * - It waits DELAY seconds between any two requests and HOST_GAP seconds
 *   between two requests to the same site, never retries in a loop, and
 *   sends a HEAD request first, falling back to one GET (body capped at
 *   MAX_BODY bytes and never stored) only when HEAD does not succeed.
 * - It refuses addresses that resolve to private or reserved networks, and
 *   unusual ports, since owners type some of these links.
 *
 * Nothing from the page is kept: only our own verdict, the HTTP status and
 * a short reason.
 *
 * Verdicts: `ok`; `broken` (`hard` for HTTP 404 and 410, which a later
 * check is unlikely to reverse; otherwise a timeout, a refused connection,
 * an unknown domain, a certificate error or HTTP 5xx); `unknown` (the site
 * blocks automated checks, for example HTTP 403 or 429, or the address is
 * not public); `robots` (robots.txt says not to fetch it).
 */
final class Link_Checker {

	public const OK      = 'ok';
	public const BROKEN  = 'broken';
	public const UNKNOWN = 'unknown';
	public const ROBOTS  = 'robots';

	public const TIMEOUT    = 15;
	public const DELAY      = 2;
	public const HOST_GAP   = 10;
	public const MAX_BODY   = 65536;
	public const ROBOTS_MAX = 512000;

	/**
	 * Codes that mean the page is gone.
	 */
	public const GONE_CODES = array( 404, 410 );

	/**
	 * Ports a link may use (the same ones WordPress allows for safe requests).
	 */
	private const PORTS = array( 80, 443, 8080 );

	/**
	 * Sends a request; same signature as wp_remote_request().
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
	 * Host name => IP addresses, or false when it does not resolve.
	 *
	 * @var callable(string): (string[]|false)
	 */
	private $resolver;

	/**
	 * Current time in seconds.
	 *
	 * @var callable(): float
	 */
	private $clock;

	/**
	 * Seconds between any two requests.
	 *
	 * @var int
	 */
	private int $delay;

	/**
	 * Seconds between two requests to the same host.
	 *
	 * @var int
	 */
	private int $host_gap;

	/**
	 * Robots rules per origin, or the verdict when robots.txt could not be reached.
	 *
	 * @var array<string, Robots_Txt|array{state: string, detail: string, code: int, hard: bool}>
	 */
	private array $robots = array();

	/**
	 * When each host was last asked.
	 *
	 * @var array<string, float>
	 */
	private array $host_times = array();

	/**
	 * When any request was last sent.
	 *
	 * @var float|null
	 */
	private ?float $last_request = null;

	/**
	 * Requests sent.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Constructor.
	 *
	 * @param callable|null $transport Defaults to wp_remote_request().
	 * @param callable|null $sleeper   Defaults to sleep().
	 * @param callable|null $resolver  Defaults to a DNS lookup (A, then AAAA records).
	 * @param callable|null $clock     Defaults to microtime( true ).
	 * @param int           $delay     Seconds between any two requests.
	 * @param int           $host_gap  Seconds between two requests to one host.
	 */
	public function __construct( ?callable $transport = null, ?callable $sleeper = null, ?callable $resolver = null, ?callable $clock = null, int $delay = self::DELAY, int $host_gap = self::HOST_GAP ) {
		$this->transport = $transport ?? 'wp_remote_request';
		$this->sleeper   = $sleeper ?? 'sleep';
		$this->resolver  = $resolver ?? array( self::class, 'resolve' );
		$this->clock     = $clock ?? static function (): float {
			return microtime( true );
		};
		$this->delay     = max( 0, $delay );
		$this->host_gap  = max( 0, $host_gap );
	}

	/**
	 * Our robots.txt product token: the start of the user agent.
	 *
	 * @return string
	 */
	public static function agent_token(): string {
		return preg_match( '/^[A-Za-z0-9._-]+/', Config::user_agent(), $m ) ? strtolower( $m[0] ) : 'guam-restaurants-core';
	}

	/**
	 * Requests sent so far (robots.txt included).
	 *
	 * @return int
	 */
	public function requests(): int {
		return $this->requests;
	}

	/**
	 * Checks one link.
	 *
	 * @param string $url Link.
	 * @return array{state: string, detail: string, code: int, hard: bool}
	 */
	public function check( string $url ): array {
		$parts  = wp_parse_url( trim( $url ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! is_array( $parts ) || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return self::verdict( self::BROKEN, 'not a web address', 0, true );
		}
		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], self::PORTS, true ) ) {
			return self::verdict( self::UNKNOWN, 'unusual port, not checked' );
		}

		$ips = filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ? array( trim( $host, '[]' ) ) : call_user_func( $this->resolver, $host );
		if ( ! $ips ) {
			return self::verdict( self::BROKEN, 'domain not found' );
		}
		foreach ( (array) $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return self::verdict( self::UNKNOWN, 'not a public address, not checked' );
			}
		}

		$origin = $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		$robots = $this->robots_for( $origin, $host );
		if ( is_array( $robots ) ) {
			return $robots;
		}
		$path = (string) ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		if ( ! $robots->allowed( $path ) ) {
			return self::verdict( self::ROBOTS, 'robots.txt asks crawlers not to fetch this page' );
		}

		$head = $this->send( $url, $host, 'HEAD' );
		if ( self::OK === $head['state'] ) {
			return $head;
		}
		return $this->send( $url, $host, 'GET' );
	}

	/**
	 * Robots rules for an origin, fetched once per run. An array is the
	 * verdict for every link on a site that did not answer at all.
	 *
	 * @param string $origin Scheme, host and port.
	 * @param string $host   Host.
	 * @return Robots_Txt|array{state: string, detail: string, code: int, hard: bool}
	 */
	private function robots_for( string $origin, string $host ) {
		if ( isset( $this->robots[ $origin ] ) ) {
			return $this->robots[ $origin ];
		}
		$this->wait( $host );
		$response = call_user_func( $this->transport, $origin . '/robots.txt', $this->args( 'GET', self::ROBOTS_MAX ) );
		if ( is_wp_error( $response ) ) {
			$verdict = self::from_error( $response );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$verdict = new Robots_Txt( (string) wp_remote_retrieve_body( $response ), self::agent_token() );
			} elseif ( $code >= 400 && $code < 500 && 429 !== $code ) {
				$verdict = Robots_Txt::allow_all();
			} else {
				$verdict = self::verdict( self::ROBOTS, sprintf( 'robots.txt could not be read (HTTP %d), so the page was not checked', $code ), $code );
			}
		}
		$this->robots[ $origin ] = $verdict;
		return $verdict;
	}

	/**
	 * Sends one request and classifies the answer.
	 *
	 * @param string $url    Link.
	 * @param string $host   Host.
	 * @param string $method HEAD or GET.
	 * @return array{state: string, detail: string, code: int, hard: bool}
	 */
	private function send( string $url, string $host, string $method ): array {
		$this->wait( $host );
		$response = call_user_func( $this->transport, $url, $this->args( $method, self::MAX_BODY ) );
		if ( is_wp_error( $response ) ) {
			return self::from_error( $response );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 400 ) {
			return self::verdict( self::OK, '', $code );
		}
		if ( in_array( $code, self::GONE_CODES, true ) ) {
			return self::verdict( self::BROKEN, sprintf( 'page not found (HTTP %d)', $code ), $code, true );
		}
		if ( $code >= 500 ) {
			return self::verdict( self::BROKEN, sprintf( 'server error (HTTP %d)', $code ), $code );
		}
		return self::verdict( self::UNKNOWN, sprintf( 'the site refused an automated check (HTTP %d)', $code ), $code );
	}

	/**
	 * Request arguments.
	 *
	 * @param string $method HEAD or GET.
	 * @param int    $limit  Largest body to read, in bytes.
	 * @return array<string, mixed>
	 */
	private function args( string $method, int $limit ): array {
		return array(
			'method'              => $method,
			'timeout'             => self::TIMEOUT,
			'redirection'         => 5,
			'user-agent'          => Config::user_agent(),
			'reject_unsafe_urls'  => true,
			'limit_response_size' => $limit,
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ),
		);
	}

	/**
	 * Waits so requests are DELAY seconds apart, and HOST_GAP seconds apart for one host.
	 *
	 * @param string $host Host.
	 * @return void
	 */
	private function wait( string $host ): void {
		$now  = (float) call_user_func( $this->clock );
		$wait = 0.0;
		if ( null !== $this->last_request ) {
			$wait = max( $wait, $this->last_request + $this->delay - $now );
		}
		if ( isset( $this->host_times[ $host ] ) ) {
			$wait = max( $wait, $this->host_times[ $host ] + $this->host_gap - $now );
		}
		if ( $wait > 0 ) {
			call_user_func( $this->sleeper, (int) ceil( $wait ) );
			$now += ceil( $wait );
		}
		$this->last_request        = $now;
		$this->host_times[ $host ] = $now;
		++$this->requests;
	}

	/**
	 * Verdict for a request that got no HTTP answer.
	 *
	 * @param \WP_Error $error Error.
	 * @return array{state: string, detail: string, code: int, hard: bool}
	 */
	private static function from_error( \WP_Error $error ): array {
		$message = $error->get_error_message();
		if ( false !== stripos( $message, 'valid URL' ) ) {
			// WordPress refused the address itself (reject_unsafe_urls).
			return self::verdict( self::UNKNOWN, 'not a public address, not checked' );
		}
		if ( preg_match( '/cURL error (\d+)/', $message, $m ) ) {
			$reasons = array(
				'6'  => 'domain not found',
				'7'  => 'the site refused the connection',
				'28' => 'the site did not answer in time',
				'35' => 'secure connection failed',
				'47' => 'too many redirects',
				'51' => 'certificate does not match the site',
				'60' => 'certificate is not valid',
			);
			if ( isset( $reasons[ $m[1] ] ) ) {
				return self::verdict( self::BROKEN, $reasons[ $m[1] ] );
			}
		}
		return self::verdict( self::BROKEN, 'no answer: ' . mb_substr( sanitize_text_field( $message ), 0, 120 ) );
	}

	/**
	 * DNS lookup: IPv4 addresses, else IPv6 addresses.
	 *
	 * @param string $host Host.
	 * @return string[]|false
	 */
	public static function resolve( string $host ) {
		$ips = gethostbynamel( $host );
		if ( $ips ) {
			return $ips;
		}
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an answer here.
		$ipv6    = is_array( $records ) ? array_values( array_filter( wp_list_pluck( $records, 'ipv6' ) ) ) : array();
		return $ipv6 ? $ipv6 : false;
	}

	/**
	 * Builds a verdict.
	 *
	 * @param string $state  State.
	 * @param string $detail Reason, in plain words.
	 * @param int    $code   HTTP status, or 0.
	 * @param bool   $hard   Whether a later check is unlikely to reverse it.
	 * @return array{state: string, detail: string, code: int, hard: bool}
	 */
	private static function verdict( string $state, string $detail, int $code = 0, bool $hard = false ): array {
		return array(
			'state'  => $state,
			'detail' => $detail,
			'code'   => $code,
			'hard'   => $hard,
		);
	}
}
