<?php
/**
 * Japanese and Korean pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Restaurant_View;

defined( 'ABSPATH' ) || exit;

/**
 * Every page in Japanese and Korean, with translations that ship with the
 * plugin (docs/translation.md):
 *
 * - Router serves /ja/ and /ko/ in that language's locale, so the plugin's
 *   interface text comes from its translation files (languages/), scripts
 *   included; Dictionary covers saved page text, cuisine names and theme text.
 * - Restaurant names, addresses, village and mall names, other sites'
 *   ratings and reviews and visitors' reviews stay as written; the legal
 *   pages, the editorial policy and the pages for advertisers are English
 *   only (Source_Text::english_only_ids()). Nothing from Google, Yelp or
 *   Foursquare is translated or sent anywhere; Google is only asked for its
 *   own text in the page's language.
 * - Translated pages say they are AI translations and link the English
 *   original and the disclaimer; every page lists its other languages
 *   (hreflang) and the header shows the language switcher.
 */
final class Integration {

	/**
	 * The notice, written for each language.
	 */
	private const NOTICES = array(
		'ja' => array(
			'text'       => 'このページは英語の原文を AI で翻訳したもので、誤りが含まれる場合があります。店名、住所、地名、他サイトの評価とレビュー、利用者のレビューは原文のままです。',
			'original'   => '英語の原文',
			'disclaimer' => '翻訳について',
		),
		'ko' => array(
			'text'       => '이 페이지는 영어 원문을 AI로 번역한 것으로, 오류가 있을 수 있습니다. 식당 이름, 주소, 지명, 다른 사이트의 평점과 리뷰, 이용자 리뷰는 원문 그대로입니다.',
			'original'   => '영어 원문',
			'disclaimer' => '번역 안내',
		),
	);

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		Router::start();
		Dictionary::register();
		add_filter( 'load_script_translation_file', array( self::class, 'script_translation_file' ), 10, 3 );
		add_filter( 'get_the_archive_title', array( self::class, 'archive_title' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_head', array( self::class, 'alternate_links' ), 2 );
		add_action( 'wp_body_open', array( self::class, 'print_notice' ) );
	}

	/**
	 * Script translations are read from the plugin's languages/ folder.
	 *
	 * @param string|false $file   File WordPress looks for.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function script_translation_file( $file, $handle = '', $domain = '' ) {
		if ( 'guam-restaurants-core' !== $domain || ! is_string( $file ) || is_readable( $file ) ) {
			return $file;
		}
		$ours = GUAM_RESTAURANTS_CORE_DIR . 'languages/' . basename( $file );
		return is_readable( $ours ) ? $ours : $file;
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
	 * This page in each language, for search engines.
	 *
	 * @return void
	 */
	public static function alternate_links(): void {
		$english = Source_Text::english_only_ids();
		if ( is_404() || is_search() || ( array() !== $english && is_page( $english ) ) ) {
			return;
		}
		foreach ( Languages::all() as $language ) {
			printf( '<link rel="alternate" hreflang="%1$s" href="%2$s">' . "\n", esc_attr( $language['code'] ), esc_url( $language['url'] ) );
		}
		printf( '<link rel="alternate" hreflang="x-default" href="%s">' . "\n", esc_url( Languages::original_url() ) );
	}

	/**
	 * Prints the translation notice at the top of translated pages.
	 *
	 * @return void
	 */
	public static function print_notice(): void {
		echo self::notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice().
	}

	/**
	 * The notice: it is an AI translation, the English original and the
	 * disclaimer on the Data sources page.
	 *
	 * @return string '' on pages in the original language.
	 */
	public static function notice(): string {
		$code = Languages::current();
		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return '';
		}
		$words = self::NOTICES[ $code ];

		$links   = array();
		$links[] = sprintf( '<a href="%1$s" hreflang="en">%2$s</a>', esc_url( Languages::original_url() ), esc_html( $words['original'] ) );
		$sources = Legal_Pages::url( 'sources' );
		if ( '' !== $sources ) {
			$links[] = sprintf( '<a href="%1$s" hreflang="en">%2$s</a>', esc_url( Languages::untranslated_url( $sources ) . '#' . Legal_Pages::TRANSLATIONS_ANCHOR ), esc_html( $words['disclaimer'] ) );
		}

		return sprintf(
			'<aside class="gr-translated" lang="%1$s"><p class="gr-translated__text">%2$s</p><p class="gr-translated__links">%3$s</p></aside>',
			esc_attr( $code ),
			esc_html( $words['text'] ),
			implode( ' <span aria-hidden="true">·</span> ', $links )
		);
	}

	/**
	 * The header's language links.
	 *
	 * @return string
	 */
	public static function switcher(): string {
		Assets::enqueue();
		$items = '';
		foreach ( Languages::all() as $language ) {
			$code   = $language['code'];
			$items .= sprintf(
				'<li><a href="%1$s" hreflang="%2$s" lang="%2$s"%3$s>%4$s</a></li>',
				esc_url( $language['url'] ),
				esc_attr( $code ),
				$language['current'] ? ' aria-current="true"' : '',
				esc_html( $language['name'] )
			);
		}
		return sprintf(
			'<nav %1$s><ul class="gr-languages__list">%2$s</ul></nav>',
			get_block_wrapper_attributes(
				array(
					'class'      => 'gr-languages ' . Languages::KEEP_CLASS,
					'aria-label' => 'Language / 言語 / 언어',
					'translate'  => 'no',
				)
			),
			$items
		);
	}
}
