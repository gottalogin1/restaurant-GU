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
 * Autoglot is not installed in tests; a stand-in [ag_switcher] shortcode and
 * the request address stand in for what it does on a /ja/ page.
 *
 * @covers \GuamRestaurants\Core\Translation\Languages
 * @covers \GuamRestaurants\Core\Translation\Integration
 */
class Translation_Test extends Test_Case {

	private const KEEP = '<span class="notranslate" translate="no" data-no-translation>';

	/**
	 * Request address before the test.
	 *
	 * @var string|null
	 */
	private $request_uri;

	/**
	 * Remembers the request address.
	 */
	public function set_up() {
		parent::set_up();
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored in tear_down.
	}

	/**
	 * Removes the stand-in and restores the request address.
	 */
	public function tear_down() {
		remove_shortcode( Languages::SWITCHER_SHORTCODE );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tear_down();
	}

	/**
	 * Pretends Autoglot is active and the visitor opened a page.
	 *
	 * @param string $uri Requested address, e.g. /ja/restaurants/test/.
	 */
	private function open( string $uri ): void {
		add_shortcode(
			Languages::SWITCHER_SHORTCODE,
			static function () {
				return '<ul class="ag-switcher"><li><a href="/restaurants/test/">English</a></li><li><a href="/ja/restaurants/test/">日本語</a></li></ul>';
			}
		);
		$_SERVER['REQUEST_URI'] = $uri;
	}

	/**
	 * Without a translation plugin the site is English and nothing is added.
	 */
	public function test_english_only_without_a_translation_plugin() {
		$_SERVER['REQUEST_URI'] = '/ja/restaurants/test/';
		$this->assertFalse( Languages::active() );
		$this->assertSame( 'en', Languages::current() );
		$this->assertFalse( Languages::is_translated() );
		$this->assertSame( '', Integration::notice() );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:guam-restaurants/language-switcher /-->' ) ) );
	}

	/**
	 * The language comes from the address prefix; other first segments are English.
	 */
	public function test_language_from_the_address() {
		$home = untrailingslashit( (string) get_option( 'home' ) );

		$this->open( '/ko/villages/yona/?page=2' );
		$this->assertSame( 'ko', Languages::current() );
		$this->assertTrue( Languages::is_translated() );
		$this->assertSame( $home . '/villages/yona/?page=2', Languages::original_url() );

		$this->open( '/ja' );
		$this->assertSame( 'ja', Languages::current() );
		$this->assertSame( $home . '/', Languages::original_url() );

		$this->open( '/japanese-food/' );
		$this->assertSame( 'en', Languages::current() );
		$this->assertSame( '', Languages::original_url() );
	}

	/**
	 * Kept text carries every marker translators honor.
	 */
	public function test_keep_marks_text() {
		$this->assertSame( self::KEEP . 'Hagåtña</span>', Languages::keep( 'Hagåtña' ) );
		$this->assertSame( '', Languages::keep( '' ) );
	}

	/**
	 * If the translation plugin puts /ja/ in home_url(), our REST URLs still drop it, so it never translates or stores the JSON; others keep it.
	 */
	public function test_rest_urls_stay_in_the_original_language() {
		$home   = untrailingslashit( (string) get_option( 'home' ) );
		$prefix = static function ( $url ) use ( $home ) {
			return 0 === strpos( $url, $home ) ? $home . '/ja' . substr( $url, strlen( $home ) ) : $url;
		};
		add_filter( 'home_url', $prefix );

		$this->assertSame( $home . '/ja/about/', home_url( '/about/' ) );
		$this->assertSame( $home . '/about/', Languages::untranslated_url( home_url( '/about/' ) ) );
		$this->assertSame( 'https://elsewhere.example/ja/x', Languages::untranslated_url( 'https://elsewhere.example/ja/x' ) );

		$ours = rest_url( 'guam-restaurants/v1/map' );
		$this->assertStringNotContainsString( '/ja/', $ours );
		$this->assertStringContainsString( 'guam-restaurants/v1/map', $ours );
		$this->assertStringContainsString( '/ja/', rest_url( 'wp/v2/posts' ) );

		remove_filter( 'home_url', $prefix );
	}

	/**
	 * The ratings placeholder asks for Google's text in the page's language.
	 */
	public function test_ratings_url_carries_the_language() {
		$this->assertStringNotContainsString( 'lang=', Ratings_Rest::url( 5 ) );
		$this->open( '/ko/restaurants/test/' );
		$url = Ratings_Rest::url( 5 );
		$this->assertStringContainsString( 'lang=ko', $url );
		$this->assertStringNotContainsString( '/ko/', $url );
	}

	/**
	 * Translated pages say they are machine translations and link the original and the disclaimer.
	 */
	public function test_notice_on_translated_pages() {
		Legal_Pages::ensure();
		$this->open( '/ja/restaurants/test/' );

		$notice = Integration::notice();
		$this->assertStringContainsString( 'lang="ja"', $notice );
		$this->assertStringContainsString( 'class="gr-translated notranslate" translate="no" data-no-translation', $notice );
		$this->assertStringContainsString( '英語から自動で機械翻訳', $notice );
		$this->assertStringContainsString( 'href="' . untrailingslashit( (string) get_option( 'home' ) ) . '/restaurants/test/" hreflang="en"', $notice );
		$this->assertStringContainsString( '/data-sources/#translations', $notice );
		$this->assertStringNotContainsString( '/ja/data-sources/', $notice );
		$this->assertStringNotContainsString( 'Google', $notice );
	}

	/**
	 * Korean pages get the Korean notice.
	 */
	public function test_korean_notice() {
		$this->open( '/ko/restaurants/test/' );
		$notice = Integration::notice();
		$this->assertStringContainsString( 'lang="ko"', $notice );
		$this->assertStringContainsString( '영어에서 자동으로 기계 번역', $notice );
	}

	/**
	 * The header block shows Autoglot's switcher in our row.
	 */
	public function test_language_switcher() {
		$this->open( '/ja/restaurants/test/' );
		$html = do_blocks( '<!-- wp:guam-restaurants/language-switcher /-->' );

		$this->assertStringContainsString( '<nav class="gr-languages wp-block-guam-restaurants-language-switcher" aria-label="Language / 言語 / 언어"><ul class="ag-switcher">', $html );
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
	 * The Data sources page gets the Translations section with the disclaimer, once.
	 */
	public function test_translations_section_on_data_sources_page() {
		Legal_Pages::ensure();
		$page = get_page_by_path( 'data-sources', OBJECT, 'page' );
		$this->assertInstanceOf( \WP_Post::class, $page );
		$this->assertStringContainsString( 'id="translations"', $page->post_content );
		$this->assertStringContainsString( 'the English page applies', $page->post_content );
		$this->assertLessThan( strpos( $page->post_content, 'Report a problem' ), strpos( $page->post_content, 'id="translations"' ) );

		$this->assertSame( 'kept (already there)', Legal_Pages::add_translations_section() );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $page->ID ), 'id="translations"' ) );
	}
}
