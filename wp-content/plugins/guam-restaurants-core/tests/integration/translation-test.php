<?php
/**
 * Machine-translated pages: what stays as written, REST addresses, notice, switcher.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Photos\Cuisine_Photos;
use GuamRestaurants\Core\Ratings\Rest_Controller as Ratings_Rest;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Restaurant_View;
use GuamRestaurants\Core\Translation\Integration;
use GuamRestaurants\Core\Translation\Languages;

/**
 * TranslatePress is not installed in tests; the languages filter and a
 * home_url filter stand in for what it does on a /ja/ page.
 *
 * @covers \GuamRestaurants\Core\Translation\Languages
 * @covers \GuamRestaurants\Core\Translation\Integration
 */
class Translation_Test extends Test_Case {

	private const KEEP = '<span class="notranslate" translate="no" data-no-translation>';

	/**
	 * Stand-in filters added by show_in(), as hook => callback.
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private array $stand_ins = array();

	/**
	 * Removes the stand-ins and the badge setting.
	 */
	public function tear_down() {
		foreach ( $this->stand_ins as $stand_in ) {
			remove_filter( $stand_in[0], $stand_in[1] );
		}
		$this->stand_ins = array();
		putenv( 'GUAM_RESTAURANTS_GOOGLE_TRANSLATE_BADGE_URL' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		parent::tear_down();
	}

	/**
	 * Pretends the page is shown in a language, the way TranslatePress does it.
	 *
	 * @param string $current en, ja or ko.
	 */
	private function show_in( string $current ): void {
		$home      = untrailingslashit( (string) get_option( 'home' ) );
		$languages = static function () use ( $current, $home ) {
				$list = array();
			foreach ( array(
				'en' => 'en_US',
				'ja' => 'ja',
				'ko' => 'ko_KR',
			) as $code => $locale ) {
				$list[] = array(
					'code'    => $code,
					'locale'  => $locale,
					'name'    => Languages::NATIVE_NAMES[ $code ],
					'url'     => $home . ( 'en' === $code ? '' : '/' . $code ) . '/restaurants/test/',
					'current' => $code === $current,
				);
			}
				return $list;
		};
		add_filter( Languages::FILTER, $languages );
		$this->stand_ins[] = array( Languages::FILTER, $languages );
		if ( 'en' !== $current ) {
			$prefix = static function ( $url ) use ( $home, $current ) {
				return 0 === strpos( $url, $home ) ? $home . '/' . $current . substr( $url, strlen( $home ) ) : $url;
			};
			add_filter( 'home_url', $prefix );
			$this->stand_ins[] = array( 'home_url', $prefix );
		}
	}

	/**
	 * Without a translation plugin the site is English and nothing is added.
	 */
	public function test_english_only_without_a_translation_plugin() {
		$this->assertSame( array(), Languages::all() );
		$this->assertSame( 'en', Languages::current() );
		$this->assertFalse( Languages::is_translated() );
		$this->assertSame( '', Integration::notice() );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:guam-restaurants/language-switcher /-->' ) ) );
	}

	/**
	 * Locale codes shorten to the language.
	 */
	public function test_short_codes() {
		$this->assertSame( 'ko', Languages::short( 'ko_KR' ) );
		$this->assertSame( 'ja', Languages::short( 'ja' ) );
		$this->assertSame( 'en', Languages::short( 'en-US' ) );
	}

	/**
	 * Kept text carries every marker translators honor.
	 */
	public function test_keep_marks_text() {
		$this->assertSame( self::KEEP . 'Hagåtña</span>', Languages::keep( 'Hagåtña' ) );
		$this->assertSame( '', Languages::keep( '' ) );

		$selectors = Integration::add_keep_selector( array( '#wpadminbar' ) );
		$this->assertSame( array( '#wpadminbar', '.notranslate' ), $selectors );
		$this->assertSame( $selectors, Integration::add_keep_selector( $selectors ) );
	}

	/**
	 * Our REST URLs drop the /ja/ prefix, so TranslatePress never translates or stores the JSON; others keep it.
	 */
	public function test_rest_urls_stay_in_the_original_language() {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->show_in( 'ja' );

		$this->assertSame( $home . '/ja/about/', home_url( '/about/' ) );
		$this->assertSame( $home . '/about/', Languages::untranslated_url( home_url( '/about/' ) ) );
		$this->assertSame( 'https://elsewhere.example/ja/x', Languages::untranslated_url( 'https://elsewhere.example/ja/x' ) );

		$ours = rest_url( 'guam-restaurants/v1/map' );
		$this->assertStringNotContainsString( '/ja/', $ours );
		$this->assertStringContainsString( 'guam-restaurants/v1/map', $ours );
		$this->assertStringContainsString( '/ja/', rest_url( 'wp/v2/posts' ) );
	}

	/**
	 * The ratings placeholder asks for Google's text in the page's language.
	 */
	public function test_ratings_url_carries_the_language() {
		$this->assertStringNotContainsString( 'lang=', Ratings_Rest::url( 5 ) );
		$this->show_in( 'ko' );
		$url = Ratings_Rest::url( 5 );
		$this->assertStringContainsString( 'lang=ko', $url );
		$this->assertStringNotContainsString( '/ko/', $url );
	}

	/**
	 * Translated pages say they are Google machine translations, link the original and the disclaimer, and show the badge.
	 */
	public function test_notice_on_translated_pages() {
		Legal_Pages::ensure();
		$this->show_in( 'ja' );

		$notice = Integration::notice();
		$this->assertStringContainsString( 'lang="ja"', $notice );
		$this->assertStringContainsString( 'data-no-translation', $notice );
		$this->assertStringContainsString( 'Google 翻訳による機械翻訳', $notice );
		$this->assertStringContainsString( '/restaurants/test/" hreflang="en"', $notice );
		$this->assertStringContainsString( '/data-sources/#translations', $notice );
		$this->assertStringNotContainsString( '/ja/data-sources/', $notice );
		$this->assertStringContainsString( 'href="https://translate.google.com/"', $notice );
		$this->assertStringContainsString( '>powered by Google Translate</a>', $notice );

		putenv( 'GUAM_RESTAURANTS_GOOGLE_TRANSLATE_BADGE_URL=https://cdn.example.com/badge.png' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		$this->assertStringContainsString( '<img src="https://cdn.example.com/badge.png" alt="powered by Google Translate"', Integration::notice() );

		ob_start();
		Integration::machine_translation_link();
		$this->assertStringContainsString( 'rel="alternate machine-translated-from" hreflang="en"', (string) ob_get_clean() );
	}

	/**
	 * Korean pages get the Korean notice.
	 */
	public function test_korean_notice() {
		$this->show_in( 'ko' );
		$notice = Integration::notice();
		$this->assertStringContainsString( 'lang="ko"', $notice );
		$this->assertStringContainsString( 'Google 번역으로 기계 번역', $notice );
	}

	/**
	 * The switcher lists every language in its own name and marks the current one.
	 */
	public function test_language_switcher() {
		$this->show_in( 'ja' );
		$html = do_blocks( '<!-- wp:guam-restaurants/language-switcher /-->' );

		$this->assertStringContainsString( 'gr-languages notranslate', $html );
		$this->assertStringContainsString( '>English</a>', $html );
		$this->assertStringContainsString( 'hreflang="ja" lang="ja" aria-current="true">日本語</a>', $html );
		$this->assertStringContainsString( 'hreflang="ko" lang="ko">한국어</a>', $html );
	}

	/**
	 * Names, addresses and place names keep their spelling; the links inside stay translatable.
	 */
	public function test_names_addresses_and_places_are_kept() {
		$village = self::factory()->term->create(
			array(
				'taxonomy' => Content_Types::TAX_VILLAGE,
				'name'     => 'Hagåtña',
			)
		);
		$cuisine = self::factory()->term->create(
			array(
				'taxonomy' => Content_Types::TAX_CUISINE,
				'name'     => 'Chamorro',
			)
		);
		$id      = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => 'Proa',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $id, array( $village ), Content_Types::TAX_VILLAGE );
		wp_set_object_terms( $id, array( $cuisine ), Content_Types::TAX_CUISINE );
		update_post_meta( $id, Restaurant_Meta::ADDRESS, '429 Route 1' );

		$card = Restaurant_View::card( $id );
		$this->assertStringContainsString( '"><a href="' . get_permalink( $id ) . '">' . self::KEEP . 'Proa</span></a>', $card );
		$this->assertStringContainsString( self::KEEP . 'Hagåtña</span>', $card );
		$this->assertStringContainsString( self::KEEP . '429 Route 1</span>', $card );
		$this->assertStringContainsString( 'Chamorro', $card );
		$this->assertStringNotContainsString( self::KEEP . 'Chamorro', $card );

		$this->assertStringContainsString( '">' . self::KEEP . 'Hagåtña</span></a>', Restaurant_View::term_links( $id, Content_Types::TAX_VILLAGE ) );
		$this->assertStringNotContainsString( 'notranslate', Restaurant_View::term_links( $id, Content_Types::TAX_CUISINE ) );
	}

	/**
	 * Photo credits keep the author and licence as written; the label is translated.
	 */
	public function test_photo_credit_keeps_author_and_licence() {
		$html = Cuisine_Photos::credit_html(
			array(
				'creator'     => 'Jane Doe',
				'source_url'  => 'https://example.org/p',
				'license'     => 'by',
				'license_url' => 'https://creativecommons.org/licenses/by/2.0/',
			),
			true
		);
		$this->assertStringContainsString( self::KEEP . 'Jane Doe</span>', $html );
		$this->assertStringContainsString( self::KEEP . 'CC BY</span>', $html );
		$this->assertStringNotContainsString( self::KEEP . 'Illustrative', $html );
	}

	/**
	 * The Data sources page gets the Translations section with Google's disclaimer, once.
	 */
	public function test_translations_section_on_data_sources_page() {
		Legal_Pages::ensure();
		$page = get_page_by_path( 'data-sources', OBJECT, 'page' );
		$this->assertInstanceOf( \WP_Post::class, $page );
		$this->assertStringContainsString( 'id="translations"', $page->post_content );
		$this->assertStringContainsString( 'GOOGLE DISCLAIMS ALL WARRANTIES RELATED TO THE TRANSLATIONS', $page->post_content );
		$this->assertLessThan( strpos( $page->post_content, 'Report a problem' ), strpos( $page->post_content, 'id="translations"' ) );

		$this->assertSame( 'kept (already there)', Legal_Pages::add_translations_section() );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $page->ID ), 'id="translations"' ) );
	}
}
