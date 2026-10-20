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
 * by TranslatePress (docs/translation.md), which this class reads through
 * its public function trp_custom_language_switcher() and its
 * $TRP_LANGUAGE global. Without TranslatePress the site has English only
 * and nothing here changes a page.
 *
 * Text that must stay as written (restaurant names, addresses, village and
 * mall names, other sites' ratings and reviews, photo credits) is wrapped
 * with keep(). The marker is the `notranslate` class plus translate="no"
 * (the HTML standard, honored by browsers' own translators) and
 * TranslatePress's data-no-translation attribute; Integration adds the
 * class to TranslatePress's exclusion lists too.
 */
final class Languages {

	/**
	 * The original language.
	 */
	public const ORIGINAL = 'en';

	/**
	 * Class that marks text kept in the original wording.
	 */
	public const KEEP_CLASS = 'notranslate';

	/**
	 * Filter for the language list (tests use it).
	 */
	public const FILTER = 'guam_restaurants_languages';

	/**
	 * Language names written in their own language, for the switcher.
	 */
	public const NATIVE_NAMES = array(
		'en' => 'English',
		'ja' => '日本語',
		'ko' => '한국어',
	);

	/**
	 * Published languages, in the switcher's order.
	 *
	 * Each has: code (en, ja, ko), locale (TranslatePress's code, e.g.
	 * ko_KR), name (in its own language), url (this page in that language)
	 * and current.
	 *
	 * @return array<int, array{code: string, locale: string, name: string, url: string, current: bool}>
	 */
	public static function all(): array {
		$list = array();
		if ( function_exists( 'trp_custom_language_switcher' ) ) {
			$current = (string) ( $GLOBALS['TRP_LANGUAGE'] ?? '' );
			foreach ( (array) trp_custom_language_switcher() as $key => $item ) {
				$locale = (string) ( $item['language_code'] ?? $key );
				$code   = self::short( $locale );
				$list[] = array(
					'code'    => $code,
					'locale'  => $locale,
					'name'    => self::NATIVE_NAMES[ $code ] ?? (string) ( $item['language_name'] ?? $code ),
					'url'     => (string) ( $item['current_page_url'] ?? '' ),
					'current' => $locale === $current,
				);
			}
		}

		/**
		 * Filters the published languages.
		 *
		 * @param array<int, array<string, mixed>> $list Languages.
		 */
		$list = apply_filters( 'guam_restaurants_languages', $list );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Short code of the language this page is shown in.
	 *
	 * @return string en, ja, ko, ...
	 */
	public static function current(): string {
		foreach ( self::all() as $language ) {
			if ( ! empty( $language['current'] ) ) {
				return (string) $language['code'];
			}
		}
		return self::ORIGINAL;
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
	 * This page in the original language, or '' when unknown.
	 *
	 * @return string
	 */
	public static function original_url(): string {
		foreach ( self::all() as $language ) {
			if ( self::ORIGINAL === $language['code'] ) {
				return (string) $language['url'];
			}
		}
		return '';
	}

	/**
	 * Short code of a locale: ko_KR and ko-KR give ko.
	 *
	 * @param string $locale Locale or language code.
	 * @return string
	 */
	public static function short( string $locale ): string {
		$parts = preg_split( '/[_-]/', strtolower( $locale ) );
		return sanitize_key( (string) ( $parts[0] ?? '' ) );
	}

	/**
	 * Plain or already escaped text wrapped so translators leave it as written.
	 *
	 * Wrap the text inside a link, not the link itself, so TranslatePress
	 * still points the link at the page in the visitor's language.
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
	 * Translation plugins put the language in home_url() (/ja/, /ko/); the
	 * saved home option never has it.
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
}
