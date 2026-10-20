<?php
/**
 * Machine-translated Japanese and Korean pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Restaurant_View;

defined( 'ABSPATH' ) || exit;

/**
 * TranslatePress makes /ja/ and /ko/ copies of every page with Google
 * Translate and keeps each translated string in its own tables
 * (docs/translation.md). This class fits it to the directory's rules:
 *
 * - Text wrapped with Languages::keep() is excluded from translation, both
 *   when the page is built and when scripts add text later.
 * - The plugin's REST URLs keep the original-language address, so
 *   TranslatePress never translates (or stores) the JSON, which carries
 *   Google, Yelp and Foursquare content. Visible text that scripts insert
 *   from it is translated in the browser like the rest of the page,
 *   except what is kept.
 * - Translated pages say they are machine translations by Google Translate,
 *   link the English original, and mark themselves as machine translated
 *   (Google's attribution and markup rules for Cloud Translation).
 * - The language switcher block lists the published languages.
 */
final class Integration {

	/**
	 * The notice in the languages written for it; others get the English text.
	 */
	private const NOTICES = array(
		'ja' => array(
			'text'       => 'このページは Google 翻訳による機械翻訳のため、誤りが含まれる場合があります。店名、住所、地名、他サイトの評価とレビューは原文のままです。',
			'original'   => '英語の原文',
			'disclaimer' => '翻訳に関する免責事項',
		),
		'ko' => array(
			'text'       => '이 페이지는 Google 번역으로 기계 번역되어 오류가 있을 수 있습니다. 식당 이름, 주소, 지명, 다른 사이트의 평점과 리뷰는 원문 그대로입니다.',
			'original'   => '영어 원문',
			'disclaimer' => '번역 면책 조항',
		),
	);

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'trp_no_translate_selectors', array( self::class, 'add_keep_selector' ) );
		add_filter( 'trp_skip_selectors_from_dynamic_translation', array( self::class, 'add_keep_selector' ) );
		add_filter( 'rest_url', array( self::class, 'rest_url' ), 10, 2 );
		add_filter( 'get_the_archive_title', array( self::class, 'archive_title' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_head', array( self::class, 'machine_translation_link' ), 2 );
		add_action( 'wp_body_open', array( self::class, 'print_notice' ) );
	}

	/**
	 * Adds the keep class to a TranslatePress selector list.
	 *
	 * @param mixed $selectors Selectors.
	 * @return mixed
	 */
	public static function add_keep_selector( $selectors ) {
		if ( is_array( $selectors ) && ! in_array( '.' . Languages::KEEP_CLASS, $selectors, true ) ) {
			$selectors[] = '.' . Languages::KEEP_CLASS;
		}
		return $selectors;
	}

	/**
	 * The plugin's own endpoints always use the original-language address.
	 *
	 * @param string $url  REST URL.
	 * @param string $path Route.
	 * @return string
	 */
	public static function rest_url( $url, $path = '' ) {
		if ( ! is_string( $url ) || 0 !== strpos( ltrim( (string) $path, '/' ), 'guam-restaurants/' ) ) {
			return $url;
		}
		return Languages::untranslated_url( $url );
	}

	/**
	 * Village and mall page headings keep the local spelling.
	 *
	 * @param string $title          Title (HTML).
	 * @param string $original_title Title without prefix.
	 * @param string $prefix         Prefix.
	 * @return string
	 */
	public static function archive_title( $title, $original_title = '', $prefix = '' ) {
		$object = get_queried_object();
		if ( ! is_string( $title ) || '' === $title || ! $object instanceof \WP_Term || ! Restaurant_View::is_place_taxonomy( $object->taxonomy ) ) {
			return $title;
		}
		if ( '' !== (string) $prefix && '' !== (string) $original_title ) {
			return $prefix . Languages::keep( (string) $original_title );
		}
		return Languages::keep( $title );
	}

	/**
	 * The notice's styles, in the head of translated pages.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( Languages::is_translated() ) {
			Assets::enqueue();
		}
	}

	/**
	 * Marks a translated page as machine translated from its English original.
	 *
	 * @return void
	 */
	public static function machine_translation_link(): void {
		if ( ! Languages::is_translated() ) {
			return;
		}
		$original = Languages::original_url();
		if ( '' !== $original ) {
			printf( '<link rel="alternate machine-translated-from" hreflang="%1$s" href="%2$s">' . "\n", esc_attr( Languages::ORIGINAL ), esc_url( $original ) );
		}
	}

	/**
	 * Prints the machine-translation notice at the top of translated pages.
	 *
	 * @return void
	 */
	public static function print_notice(): void {
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice().
	}

	/**
	 * The notice: it is a Google machine translation, the English original,
	 * Google's badge and the disclaimer on the Data sources page.
	 *
	 * @return string '' on pages in the original language.
	 */
	public static function notice(): string {
		$code = Languages::current();
		if ( Languages::ORIGINAL === $code ) {
			return '';
		}

		$words = self::NOTICES[ $code ] ?? array(
			'text'       => 'This page was translated automatically by Google Translate and may contain mistakes. Restaurant names, addresses, place names, and other sites\' ratings and reviews are left as written.',
			'original'   => 'English original',
			'disclaimer' => 'Translation disclaimer',
		);
		$lang  = isset( self::NOTICES[ $code ] ) ? $code : Languages::ORIGINAL;

		$links    = array();
		$original = Languages::original_url();
		if ( '' !== $original ) {
			$links[] = sprintf( '<a href="%1$s" hreflang="en">%2$s</a>', esc_url( $original ), esc_html( $words['original'] ) );
		}
		$sources = Legal_Pages::url( 'sources' );
		if ( '' !== $sources ) {
			$links[] = sprintf( '<a href="%1$s" hreflang="en">%2$s</a>', esc_url( Languages::untranslated_url( $sources ) . '#' . Legal_Pages::TRANSLATIONS_ANCHOR ), esc_html( $words['disclaimer'] ) );
		}

		return sprintf(
			'<aside class="gr-translated %1$s" translate="no" data-no-translation lang="%2$s"><p class="gr-translated__text">%3$s</p><p class="gr-translated__links">%4$s%5$s</p></aside>',
			esc_attr( Languages::KEEP_CLASS ),
			esc_attr( $lang ),
			esc_html( $words['text'] ),
			implode( ' <span aria-hidden="true">·</span> ', $links ),
			self::badge()
		);
	}

	/**
	 * "Powered by Google Translate": Google's badge when its URL is set, else the words.
	 *
	 * @return string
	 */
	public static function badge(): string {
		$url  = Config::get( Config::GOOGLE_TRANSLATE_BADGE_URL );
		$mark = '' !== $url
			? sprintf( '<img src="%1$s" alt="%2$s" height="16" loading="lazy">', esc_url( $url ), esc_attr( 'powered by Google Translate' ) )
			: esc_html( 'powered by Google Translate' );
		return sprintf( '<a class="gr-translated__badge" href="https://translate.google.com/" rel="noopener" lang="en">%s</a>', $mark );
	}

	/**
	 * The header's language links, or '' with one language.
	 *
	 * @return string
	 */
	public static function switcher(): string {
		$languages = Languages::all();
		if ( count( $languages ) < 2 ) {
			return '';
		}
		Assets::enqueue();
		$items = '';
		foreach ( $languages as $language ) {
			$code   = (string) $language['code'];
			$items .= sprintf(
				'<li><a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s</a></li>',
				esc_url( (string) $language['url'] ),
				esc_attr( $code ),
				empty( $language['current'] ) ? '' : ' aria-current="true"',
				esc_html( (string) $language['name'] )
			);
		}
		return sprintf(
			'<nav %1$s><ul class="gr-languages__list">%2$s</ul></nav>',
			get_block_wrapper_attributes(
				array(
					'class'               => 'gr-languages ' . Languages::KEEP_CLASS,
					'aria-label'          => 'Language / 言語 / 언어',
					'translate'           => 'no',
					'data-no-translation' => '',
				)
			),
			$items
		);
	}
}
