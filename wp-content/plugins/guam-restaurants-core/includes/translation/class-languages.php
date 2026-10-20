<?php
/**
 * The site's languages and what stays in the original wording.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * English is the original. Japanese and Korean pages are machine translated
 * by Autoglot (docs/translation.md), which serves them under /ja/ and /ko/.
 * This class reads the language from that address prefix, so it needs
 * nothing from Autoglot except knowing it is active (its [ag_switcher]
 * shortcode exists). Without it the site has English only and nothing here
 * changes a page.
 *
 * Text that must stay as written (restaurant names, addresses, village and
 * mall names, other sites' ratings and reviews, photo credits) is wrapped
 * with keep(). The marker is the `notranslate` class (honored by Autoglot,
 * Google Translate and most other translators), translate="no" (the HTML
 * standard, honored by browsers' own translators) and data-no-translation
 * (TranslatePress's attribute, in case the site ever switches back).
 */
final class Languages {

	/**
	 * The original language.
	 */
	public const ORIGINAL = 'en';

	/**
	 * Machine-translated languages, by their address prefix.
	 */
	public const TRANSLATED = array( 'ja', 'ko' );

	/**
	 * Class that marks text kept in the original wording.
	 */
	public const KEEP_CLASS = 'notranslate';

	/**
	 * Autoglot's language switcher shortcode; its presence means Autoglot is active.
	 */
	public const SWITCHER_SHORTCODE = 'ag_switcher';

	/**
	 * Whether a translation plugin serves the /ja/ and /ko/ pages.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return shortcode_exists( self::SWITCHER_SHORTCODE );
	}

	/**
	 * Short code of the language this page is shown in.
	 *
	 * @return string en, ja or ko.
	 */
	public static function current(): string {
		if ( ! self::active() ) {
			return self::ORIGINAL;
		}
		$parts = explode( '/', ltrim( self::request_path(), '/' ), 2 );
		return in_array( $parts[0], self::TRANSLATED, true ) ? $parts[0] : self::ORIGINAL;
	}

	/**
	 * Whether this page is a machine translation.
	 *
	 * @return bool
	 */
	public static function is_translated(): bool {
		return self::ORIGINAL !== self::current();
	}

	/**
	 * This page in the original language, or '' on an original page.
	 *
	 * @return string
	 */
	public static function original_url(): string {
		$code = self::current();
		if ( self::ORIGINAL === $code ) {
			return '';
		}
		$path  = substr( ltrim( self::request_path(), '/' ), strlen( $code ) );
		$query = (string) wp_parse_url( self::request_uri(), PHP_URL_QUERY );
		return untrailingslashit( (string) get_option( 'home' ) ) . '/' . ltrim( $path, '/' ) . ( '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Plain or already escaped text wrapped so translators leave it as written.
	 *
	 * Wrap the text inside a link, not the link itself, so the translation
	 * plugin still points the link at the page in the visitor's language.
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
		return 'class="' . self::KEEP_CLASS . '" translate="no" data-no-translation';
	}

	/**
	 * A URL on this site without the language prefix of a translated page.
	 *
	 * Some translation plugins put the language in home_url() (/ja/, /ko/);
	 * the saved home option never has it.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function untranslated_url( string $url ): string {
		$shown    = untrailingslashit( (string) preg_replace( '#^https?:#i', '', home_url() ) );
		$original = untrailingslashit( (string) preg_replace( '#^https?:#i', '', (string) get_option( 'home' ) ) );
		if ( $shown === $original || '' === $shown ) {
			return $url;
		}
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$rest   = (string) preg_replace( '#^https?:#i', '', $url );
		if ( 0 !== strpos( $rest, $shown . '/' ) && $rest !== $shown ) {
			return $url;
		}
		return ( '' !== $scheme ? $scheme . ':' : '' ) . $original . substr( $rest, strlen( $shown ) );
	}

	/**
	 * The requested address, as the visitor typed it.
	 *
	 * @return string
	 */
	private static function request_uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	/**
	 * The requested path below the site's home path (for sites in a subfolder).
	 *
	 * @return string Starts with /.
	 */
	private static function request_path(): string {
		$path = (string) wp_parse_url( self::request_uri(), PHP_URL_PATH );
		$base = untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return '/' . ltrim( $path, '/' );
	}
}
