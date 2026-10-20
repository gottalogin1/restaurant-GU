<?php
/**
 * Machine-translated Japanese and Korean pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Restaurant_View;

defined( 'ABSPATH' ) || exit;

/**
 * Autoglot makes /ja/ and /ko/ copies of every page with its machine
 * translation service and keeps each translated phrase in its own table
 * (docs/translation.md). This class fits it to the directory's rules:
 *
 * - Text wrapped with Languages::keep() is excluded from translation.
 * - The plugin's REST URLs keep the original-language address. Autoglot is
 *   also told to skip /wp-json/ (owner setup), so it never translates or
 *   stores the JSON, which carries Google, Yelp and Foursquare content.
 *   Text that scripts insert from it stays in English.
 * - Translated pages say they are machine translations and link the
 *   English original and the disclaimer.
 * - The language switcher block shows Autoglot's switcher in the header.
 */
final class Integration {

	/**
	 * The notice in the languages written for it; others get the English text.
	 */
	private const NOTICES = array(
		'ja' => array(
			'text'       => 'このページは英語から自動で機械翻訳したもので、誤りが含まれる場合があります。店名、住所、地名、他サイトの評価とレビューは原文のままです。',
			'original'   => '英語の原文',
			'disclaimer' => '翻訳に関する免責事項',
		),
		'ko' => array(
			'text'       => '이 페이지는 영어에서 자동으로 기계 번역되어 오류가 있을 수 있습니다. 식당 이름, 주소, 지명, 다른 사이트의 평점과 리뷰는 원문 그대로입니다.',
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
		add_filter( 'rest_url', array( self::class, 'rest_url' ), 10, 2 );
		add_filter( 'get_the_archive_title', array( self::class, 'archive_title' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_body_open', array( self::class, 'print_notice' ) );
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
	 * Prints the machine-translation notice at the top of translated pages.
	 *
	 * @return void
	 */
	public static function print_notice(): void {
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice().
	}

	/**
	 * The notice: it is a machine translation, the English original and the
	 * disclaimer on the Data sources page.
	 *
	 * @return string '' on pages in the original language.
	 */
	public static function notice(): string {
		$code = Languages::current();
		if ( Languages::ORIGINAL === $code ) {
			return '';
		}

		$words = self::NOTICES[ $code ] ?? array(
			'text'       => 'This page was machine translated from English and may contain mistakes. Restaurant names, addresses, place names, and other sites\' ratings and reviews are left as written.',
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
			'<aside class="gr-translated %1$s" translate="no" data-no-translation lang="%2$s"><p class="gr-translated__text">%3$s</p><p class="gr-translated__links">%4$s</p></aside>',
			esc_attr( Languages::KEEP_CLASS ),
			esc_attr( $lang ),
			esc_html( $words['text'] ),
			implode( ' <span aria-hidden="true">·</span> ', $links )
		);
	}

	/**
	 * Autoglot's language switcher in the header row, or '' without Autoglot.
	 *
	 * Autoglot builds the links itself, so they always point at this page
	 * in each language.
	 *
	 * @return string
	 */
	public static function switcher(): string {
		if ( ! Languages::active() ) {
			return '';
		}
		$links = trim( do_shortcode( '[' . Languages::SWITCHER_SHORTCODE . ']' ) );
		if ( '' === $links ) {
			return '';
		}
		Assets::enqueue();
		return sprintf(
			'<nav %1$s>%2$s</nav>',
			get_block_wrapper_attributes(
				array(
					'class'      => 'gr-languages',
					'aria-label' => 'Language / 言語 / 언어',
				)
			),
			$links // Autoglot's own markup.
		);
	}
}
