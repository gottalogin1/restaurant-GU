<?php
/**
 * Rules from a site's robots.txt file (RFC 9309), for the monthly link sweep.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a robots.txt file and answers whether our crawler may fetch a path.
 *
 * - Groups naming our product token apply; otherwise the `*` groups;
 *   otherwise everything is allowed. Groups for the same agent are merged.
 * - The longest matching rule wins; on a tie, Allow wins.
 * - `*` matches any run of characters and a trailing `$` anchors the end.
 * - An empty Disallow line allows everything.
 */
final class Robots_Txt {

	/**
	 * Groups that apply to the agent, merged into one rule list.
	 *
	 * @var array<int, array{allow: bool, path: string}>
	 */
	private array $rules;

	/**
	 * Constructor.
	 *
	 * @param string $body  robots.txt contents.
	 * @param string $agent Our product token, for example `guam-restaurants-core`.
	 */
	public function __construct( string $body, string $agent ) {
		$this->rules = self::rules_for( self::parse( $body ), strtolower( $agent ) );
	}

	/**
	 * A file that allows everything (robots.txt missing: HTTP 4xx).
	 *
	 * @return self
	 */
	public static function allow_all(): self {
		return new self( '', '*' );
	}

	/**
	 * A file that disallows everything (robots.txt unreachable: HTTP 5xx or 429).
	 *
	 * @return self
	 */
	public static function disallow_all(): self {
		return new self( "User-agent: *\nDisallow: /", '*' );
	}

	/**
	 * Whether the path (with its query string) may be fetched.
	 *
	 * @param string $path Path, for example `/menu?lang=en`.
	 * @return bool
	 */
	public function allowed( string $path ): bool {
		$path = '' === $path ? '/' : $path;
		if ( '/robots.txt' === $path ) {
			return true;
		}
		$best_length = -1;
		$best_allow  = true;
		foreach ( $this->rules as $rule ) {
			if ( ! self::matches( $rule['path'], $path ) ) {
				continue;
			}
			$length = strlen( $rule['path'] );
			if ( $length > $best_length || ( $length === $best_length && $rule['allow'] ) ) {
				$best_length = $length;
				$best_allow  = $rule['allow'];
			}
		}
		return $best_allow;
	}

	/**
	 * Groups in the file: agents and their rules.
	 *
	 * @param string $body Contents.
	 * @return array<int, array{agents: string[], rules: array<int, array{allow: bool, path: string}>}>
	 */
	public static function parse( string $body ): array {
		$groups  = array();
		$current = null;
		$in_rule = false;
		foreach ( explode( "\n", str_replace( "\r", "\n", $body ) ) as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$field = strtolower( trim( substr( $line, 0, $colon ) ) );
			$value = trim( substr( $line, $colon + 1 ) );

			if ( 'user-agent' === $field ) {
				if ( null === $current || $in_rule ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current  = count( $groups ) - 1;
					$in_rule  = false;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
				continue;
			}
			if ( ( 'allow' === $field || 'disallow' === $field ) && null !== $current ) {
				$in_rule = true;
				if ( '' === $value ) {
					continue;
				}
				$groups[ $current ]['rules'][] = array(
					'allow' => 'allow' === $field,
					'path'  => $value,
				);
			}
		}
		return $groups;
	}

	/**
	 * The merged rules of the groups for the agent, or of the `*` groups.
	 *
	 * @param array<int, array{agents: string[], rules: array<int, array{allow: bool, path: string}>}> $groups Groups.
	 * @param string                                                                                   $agent  Product token, lowercase.
	 * @return array<int, array{allow: bool, path: string}>
	 */
	private static function rules_for( array $groups, string $agent ): array {
		$specific = null;
		$any      = array();
		foreach ( $groups as $group ) {
			if ( '*' !== $agent && in_array( $agent, $group['agents'], true ) ) {
				$specific = array_merge( $specific ?? array(), $group['rules'] );
			} elseif ( in_array( '*', $group['agents'], true ) ) {
				$any = array_merge( $any, $group['rules'] );
			}
		}
		return $specific ?? $any;
	}

	/**
	 * Whether a rule path matches the request path.
	 *
	 * @param string $pattern Rule path, with `*` and `$`.
	 * @param string $path    Request path.
	 * @return bool
	 */
	private static function matches( string $pattern, string $path ): bool {
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}
}
