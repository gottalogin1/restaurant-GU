<?php
/**
 * The site's languages and what stays in the original wording.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * English is the original. Every page also exists under /ja/ and /ko/
 * (Router), shown with the plugin's own Japanese and Korean translations:
 * WordPress translation files for interface text (languages/) and a
 * dictionary for page text, cuisine names and theme text (Dictionary).
 * Nothing is translated while visitors browse, and nothing is sent to a
 * translation service (docs/translation.md).
 *
 * Text that must stay as written (restaurant names, addresses, village and
 * mall names, other sites' ratings and reviews, photo credits) is wrapped
 * with keep(), so browsers' own translators leave it alone too: the
 * `notranslate` class (Google Translate and others) and translate="no"
 * (the HTML standard).
 */
final class Languages {

	/**
	 * The original language.
	 */
	public const ORIGINAL = 'en';

	/**
	 * Translated languages: address prefix => WordPress locale.
	 */
	public const LOCALES = array(
		'ja' => 'ja',
		'ko' => 'ko_KR',
	);

	/**
	 * Class that marks text kept in the original wording.
	 */
	public const KEEP_CLASS = 'notranslate';

	/**
	 * Language names written in their own language, for the switcher.
	 */
	public const NATIVE_NAMES = array(
		'en' => 'English',
		'ja' => '日本語',
		'ko' => '한국어',
	);

	/**
	 * Language of this request.
	 *
	 * @var string
	 */
	private static string $current = self::ORIGINAL;

	/**
	 * This request's path below the home path, without the language prefix.
	 *
	 * @var string
	 */
	private static string $path = '/';

	/**
	 * Sets this request's language and its path without the prefix (Router).
	 *
	 * @param string $code en, ja or ko.
	 * @param string $path Path below the home path, starting with /, query included.
	 * @return void
	 */
	public static function set( string $code, string $path = '/' ): void {
		self::$current = isset( self::LOCALES[ $code ] ) ? $code : self::ORIGINAL;
		self::$path    = '/' . ltrim( $path, '/' );
	}

	/**
	 * Short code of the language this page is shown in.
	 *
	 * @return string en, ja or ko.
	 */
	public static function current(): string {
		return self::$current;
	}

	/**
	 * Whether this page is a translation.
	 *
	 * @return bool
	 */
	public static function is_translated(): bool {
		return self::ORIGINAL !== self::$current;
	}

	/**
	 * WordPress locale of this page's language, or '' for English.
	 *
	 * @return string
	 */
	public static function locale(): string {
		return self::LOCALES[ self::$current ] ?? '';
	}

	/**
	 * A path on this site in a language.
	 *
	 * @param string $code Language.
	 * @param string $path Path below the home path, without a language prefix.
	 * @return string Absolute URL.
	 */
	public static function url( string $code, string $path ): string {
		$prefix = isset( self::LOCALES[ $code ] ) ? '/' . $code : '';
		return untrailingslashit( (string) get_option( 'home' ) ) . $prefix . '/' . ltrim( $path, '/' );
	}

	/**
	 * This page in every language, in the switcher's order.
	 *
	 * @return array<int, array{code: string, name: string, url: string, current: bool}>
	 */
	public static function all(): array {
		$list = array();
		foreach ( array_merge( array( self::ORIGINAL ), array_keys( self::LOCALES ) ) as $code ) {
			$list[] = array(
				'code'    => $code,
				'name'    => self::NATIVE_NAMES[ $code ],
				'url'     => self::url( $code, self::$path ),
				'current' => $code === self::$current,
			);
		}
		return $list;
	}

	/**
	 * This page in the original language.
	 *
	 * @return string
	 */
	public static function original_url(): string {
		return self::url( self::ORIGINAL, self::$path );
	}

	/**
	 * A URL on this site without the language prefix.
	 *
	 * @param string $url URL built while a translated page is shown.
	 * @return string
	 */
	public static function untranslated_url( string $url ): string {
		if ( ! self::is_translated() ) {
			return $url;
		}
		$home   = (string) preg_replace( '#^https?:#i', '', untrailingslashit( (string) get_option( 'home' ) ) );
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$rest   = (string) preg_replace( '#^https?:#i', '', $url );
		$prefix = $home . '/' . self::$current;
		if ( 0 !== strpos( $rest, $prefix . '/' ) && $rest !== $prefix ) {
			return $url;
		}
		return ( '' !== $scheme ? $scheme . ':' : '' ) . $home . substr( $rest, strlen( $prefix ) );
	}

	/**
	 * Plain or already escaped text wrapped so translators leave it as written.
	 *
	 * @param string $html Escaped text or inline HTML.
	 * @return string
	 */
	public static function keep( string $html ): string {
		if ( '' === $html ) {
			return '';
		}
		return '<span ' . self::keep_attributes() . '>' . $html . '</span>';
	}

	/**
	 * Attributes of an element whose text stays as written.
	 *
	 * @return string
	 */
	public static function keep_attributes(): string {
		return 'class="' . self::KEEP_CLASS . '" translate="no"';
	}
}
