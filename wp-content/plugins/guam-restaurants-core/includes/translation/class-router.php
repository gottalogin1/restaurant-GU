<?php
/**
 * /ja/ and /ko/ addresses.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * Serves every page, and the plugin's REST routes, under /ja/ and /ko/.
 *
 * The prefix is taken off the request address before WordPress reads it,
 * so /ja/villages/yona/ is the same query as /villages/yona/, and put back
 * once WordPress has parsed the request, so canonical redirects and
 * add_query_arg() see the address the visitor opened. While the page is
 * built, WordPress runs in that language's locale (interface text from
 * languages/) and home_url() carries the prefix, so links stay in the
 * language. Page caches store each address on its own.
 */
final class Router {

	/**
	 * The request address as the visitor sent it.
	 *
	 * @var string|null
	 */
	private static ?string $original_uri = null;

	/**
	 * Reads the language from the request address and hooks the language in.
	 *
	 * @return void
	 */
	public static function start(): void {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$hit = self::split( $uri );
		if ( null === $hit ) {
			Languages::set( Languages::ORIGINAL, self::below_home( $uri ) );
			return;
		}

		Languages::set( $hit['code'], $hit['path'] );
		self::$original_uri     = $uri;
		$_SERVER['REQUEST_URI'] = self::home_path() . $hit['path'];
		self::hook();
	}

	/**
	 * Hooks the language into WordPress (also used by tests).
	 *
	 * @return void
	 */
	public static function hook(): void {
		add_filter( 'locale', array( self::class, 'locale' ) );
		add_filter( 'determine_locale', array( self::class, 'locale' ) );
		add_filter( 'home_url', array( self::class, 'home_url' ), 10, 2 );
		add_action( 'parse_request', array( self::class, 'restore_uri' ), 0 );
		add_action( 'template_redirect', array( self::class, 'english_only' ), 1 );
	}

	/**
	 * Takes the hooks off and returns to English (tests).
	 *
	 * @return void
	 */
	public static function unhook(): void {
		remove_filter( 'locale', array( self::class, 'locale' ) );
		remove_filter( 'determine_locale', array( self::class, 'locale' ) );
		remove_filter( 'home_url', array( self::class, 'home_url' ), 10 );
		remove_action( 'parse_request', array( self::class, 'restore_uri' ), 0 );
		remove_action( 'template_redirect', array( self::class, 'english_only' ), 1 );
		Languages::set( Languages::ORIGINAL );
	}

	/**
	 * The language and the rest of the path of a request address, or null for English.
	 *
	 * @param string $uri Request address (path and query).
	 * @return array{code: string, path: string}|null
	 */
	public static function split( string $uri ): ?array {
		$path = self::below_home( $uri );
		if ( ! preg_match( '#^/([a-z]{2})(/.*|\?.*)?$#', $path, $match ) || ! isset( Languages::LOCALES[ $match[1] ] ) ) {
			return null;
		}
		return array(
			'code' => $match[1],
			'path' => '/' . ltrim( $match[2] ?? '', '/' ),
		);
	}

	/**
	 * The locale of the page's language.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function locale( $locale ) {
		$translated = Languages::locale();
		return '' !== $translated ? $translated : $locale;
	}

	/**
	 * Puts the language prefix in addresses on this site.
	 *
	 * @param string $url  URL.
	 * @param string $path Path asked for.
	 * @return string
	 */
	public static function home_url( $url, $path = '' ) {
		if ( ! is_string( $url ) || ! Languages::is_translated() ) {
			return $url;
		}
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$bare = (string) preg_replace( '#^https?:#i', '', $home );
		$rest = (string) preg_replace( '#^https?:#i', '', $url );
		if ( 0 !== strpos( $rest, $bare ) || preg_match( '#^/?(wp-admin|wp-login\.php|wp-content|wp-includes)\b#', ltrim( (string) $path ) ) ) {
			return $url;
		}
		$tail = substr( $rest, strlen( $bare ) );
		if ( '' !== $tail && '/' !== $tail[0] && '?' !== $tail[0] ) {
			return $url;
		}
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		return ( '' !== $scheme ? $scheme . ':' : '' ) . $bare . '/' . Languages::current() . ( '' === $tail ? '' : ( '?' === $tail[0] ? '/' . $tail : $tail ) );
	}

	/**
	 * Pages that stay in English send /ja/ and /ko/ visitors to the English page.
	 *
	 * @return void
	 */
	public static function english_only(): void {
		$english = Source_Text::english_only_ids();
		if ( Languages::is_translated() && array() !== $english && is_page( $english ) ) {
			wp_safe_redirect( Languages::original_url(), 302, 'Guam Restaurants' );
			exit;
		}
	}

	/**
	 * Puts the visitor's address back once WordPress has parsed it.
	 *
	 * @return void
	 */
	public static function restore_uri(): void {
		if ( null !== self::$original_uri ) {
			$_SERVER['REQUEST_URI'] = self::$original_uri;
		}
	}

	/**
	 * The path of the home address, without a trailing slash ('' at the root).
	 *
	 * @return string
	 */
	private static function home_path(): string {
		return untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
	}

	/**
	 * A request address below the home path (for sites in a subfolder).
	 *
	 * @param string $uri Request address.
	 * @return string Starts with /.
	 */
	private static function below_home( string $uri ): string {
		$base = self::home_path();
		if ( '' !== $base && ( 0 === strpos( $uri, $base . '/' ) || $uri === $base ) ) {
			$uri = substr( $uri, strlen( $base ) );
		}
		return '/' . ltrim( $uri, '/' );
	}
}
