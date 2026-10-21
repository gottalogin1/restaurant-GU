<?php
/**
 * Japanese and Korean pages: addresses, dictionary, notice, switcher, what stays as written.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Photos\Cuisine_Photos;
use GuamRestaurants\Core\Ratings\Rest_Controller as Ratings_Rest;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Cache;
use GuamRestaurants\Core\Site\Restaurant_View;
use GuamRestaurants\Core\Translation\Dictionary;
use GuamRestaurants\Core\Translation\Integration;
use GuamRestaurants\Core\Translation\Languages;
use GuamRestaurants\Core\Translation\Router;
use GuamRestaurants\Core\Translation\Source_Text;

/**
 * Router::hook() and Languages::set() put a test in the state of a /ja/ or
 * /ko/ request.
 *
 * @covers \GuamRestaurants\Core\Translation\Languages
 * @covers \GuamRestaurants\Core\Translation\Router
 * @covers \GuamRestaurants\Core\Translation\Dictionary
 * @covers \GuamRestaurants\Core\Translation\Source_Text
 * @covers \GuamRestaurants\Core\Translation\Integration
 */
class Translation_Test extends Test_Case {

	private const KEEP = '<span class="notranslate" translate="no">';

	/**
	 * Request address before the test.
	 *
	 * @var string|null
	 */
	private ?string $request_uri = null;

	/**
	 * Saves the request address.
	 */
	public function set_up() {
		parent::set_up();
		$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored as it was.
		add_filter( 'guam_restaurants_dictionary', array( $this, 'words_for_tests' ), 10, 2 );
	}

	/**
	 * Back to English and the request address before the test.
	 */
	public function tear_down() {
		Router::unhook();
		remove_filter( 'guam_restaurants_dictionary', array( $this, 'words_for_tests' ), 10 );
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tear_down();
	}

	/**
	 * Dictionary entries for the tests.
	 *
	 * @param array<string, string> $words Words.
	 * @param string                $code  Language.
	 * @return array<string, string>
	 */
	public function words_for_tests( $words, $code ) {
		if ( 'ja' === $code ) {
			$words['Hello there'] = 'こんにちは';
			$words["Can't stop"]  = 'とまらない & 続く';
		}
		return $words;
	}

	/**
	 * The request address as WordPress reads it.
	 *
	 * @return string
	 */
	private function request_uri(): string {
		return (string) $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared as set by the test.
	}

	/**
	 * Shows the test as a page in a language.
	 *
	 * @param string $code ja or ko.
	 * @param string $path Path without the prefix.
	 */
	private function show_in( string $code, string $path = '/restaurants/test/' ): void {
		Languages::set( $code, $path );
		Router::hook();
	}

	/**
	 * English pages are untouched: no prefix, no notice, the switcher marks English.
	 */
	public function test_english_pages() {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->assertSame( 'en', Languages::current() );
		$this->assertFalse( Languages::is_translated() );
		$this->assertSame( '', Integration::notice() );
		$this->assertSame( $home . '/about/', home_url( '/about/' ) );
		$this->assertSame( 'Hello there', Dictionary::text( 'Hello there' ) );
		$this->assertStringContainsString( 'hreflang="en" lang="en" aria-current="true">English</a>', do_blocks( '<!-- wp:guam-restaurants/language-switcher /-->' ) );
	}

	/**
	 * The language prefix is read from the request address.
	 */
	public function test_split_reads_the_prefix() {
		$this->assertSame(
			array(
				'code' => 'ja',
				'path' => '/village/agat/',
			),
			Router::split( '/ja/village/agat/' )
		);
		$this->assertSame(
			array(
				'code' => 'ko',
				'path' => '/',
			),
			Router::split( '/ko' )
		);
		$this->assertSame( '/?s=pho', Router::split( '/ja?s=pho' )['path'] );
		$this->assertSame( '/', Router::split( '/ko/' )['path'] );
		$this->assertNull( Router::split( '/village/agat/' ) );
		$this->assertNull( Router::split( '/fr/village/agat/' ), 'Only Japanese and Korean' );
		$this->assertNull( Router::split( '/japan/' ) );
		$this->assertNull( Router::split( '/' ) );
	}

	/**
	 * A /ko/ request is parsed without the prefix, in the Korean locale, and the address is put back.
	 */
	public function test_start_strips_and_restores_the_prefix() {
		$_SERVER['REQUEST_URI'] = '/ko/village/agat/?sort=new';
		Router::start();

		$this->assertSame( '/village/agat/?sort=new', $this->request_uri() );
		$this->assertSame( 'ko', Languages::current() );
		$this->assertSame( 'ko_KR', get_locale() );
		$this->assertSame( 'ko_KR', determine_locale() );

		Router::restore_uri();
		$this->assertSame( '/ko/village/agat/?sort=new', $this->request_uri() );

		Router::unhook();
		$_SERVER['REQUEST_URI'] = '/village/agat/';
		Router::start();
		$this->assertSame( 'en', Languages::current() );
		$this->assertSame( '/village/agat/', $this->request_uri() );
		$this->assertNotSame( 'ko_KR', get_locale() );
	}

	/**
	 * Links on the site keep the language; admin, login and files do not.
	 */
	public function test_links_carry_the_prefix() {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->show_in( 'ja' );

		$this->assertSame( $home . '/ja/about/', home_url( '/about/' ) );
		$this->assertSame( $home . '/ja', home_url() );
		$this->assertSame( $home . '/ja/?s=pho', home_url( '?s=pho' ) );
		$this->assertSame( $home . '/wp-login.php', home_url( '/wp-login.php' ) );
		$this->assertSame( $home . '/wp-content/x.css', home_url( '/wp-content/x.css' ) );
		$this->assertStringContainsString( '/ja/', rest_url( 'guam-restaurants/v1/map' ) );
		$this->assertSame( 'https://elsewhere.example/about/', Router::home_url( 'https://elsewhere.example/about/', '/about/' ) );

		$this->assertSame( $home . '/about/', Languages::untranslated_url( home_url( '/about/' ) ) );
		$this->assertSame( 'https://elsewhere.example/ja/x', Languages::untranslated_url( 'https://elsewhere.example/ja/x' ) );
		$this->assertSame( $home . '/restaurants/test/', Languages::original_url() );
	}

	/**
	 * Every language of the page, in the switcher's order.
	 */
	public function test_all_languages() {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$this->show_in( 'ko', '/village/agat/' );
		$this->assertSame(
			array(
				array(
					'code'    => 'en',
					'name'    => 'English',
					'url'     => $home . '/village/agat/',
					'current' => false,
				),
				array(
					'code'    => 'ja',
					'name'    => '日本語',
					'url'     => $home . '/ja/village/agat/',
					'current' => false,
				),
				array(
					'code'    => 'ko',
					'name'    => '한국어',
					'url'     => $home . '/ko/village/agat/',
					'current' => true,
				),
			),
			Languages::all()
		);
	}

	/**
	 * The ratings placeholder asks for Google's text in the page's language.
	 */
	public function test_ratings_url_carries_the_language() {
		$this->assertStringNotContainsString( 'lang=', Ratings_Rest::url( 5 ) );
		$this->show_in( 'ko' );
		$this->assertStringContainsString( 'lang=ko', Ratings_Rest::url( 5 ) );
	}

	/**
	 * Page titles and text blocks come from the dictionary; restaurant names and unknown text stay.
	 */
	public function test_dictionary_titles_and_blocks() {
		$page       = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Hello there',
			)
		);
		$restaurant = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => 'Hello there',
			)
		);
		$this->show_in( 'ja' );

		$this->assertSame( 'こんにちは', get_the_title( $page ) );
		$this->assertSame( 'Hello there', get_the_title( $restaurant ) );
		$this->assertMatchesRegularExpression( '#<p class="x[^"]*">こんにちは</p>#', do_blocks( "<!-- wp:paragraph {\"className\":\"x\"} -->\n<p class=\"x\">Hello   there\n</p>\n<!-- /wp:paragraph -->" ) );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Not in the dictionary</h2>', do_blocks( '<!-- wp:heading --><h2 class="wp-block-heading">Not in the dictionary</h2><!-- /wp:heading -->' ) );
		$this->assertStringContainsString( '<p>Hello there</p>', do_blocks( '<!-- wp:html --><p>Hello there</p><!-- /wp:html -->' ), 'Only text blocks' );
	}

	/**
	 * Text saved escaped is found and escaped again.
	 */
	public function test_dictionary_escaped_text() {
		$this->show_in( 'ja' );
		$this->assertSame( 'とまらない &amp; 続く', Dictionary::lookup( 'Can&#039;t stop' ) );
		$this->assertSame( 'とまらない & 続く', Dictionary::lookup( "Can't stop" ) );

		Router::unhook();
		$this->show_in( 'ko' );
		$this->assertNull( Dictionary::lookup( 'Hello there' ), 'Not in the Korean dictionary' );
	}

	/**
	 * Headings and labels set on the plugin's blocks and menu links; other settings stay.
	 */
	public function test_dictionary_block_settings() {
		$this->show_in( 'ja' );
		$block = Dictionary::block_data(
			array(
				'blockName' => 'guam-restaurants/restaurant-list',
				'attrs'     => array(
					'heading' => 'Hello there',
					'cuisine' => 'Hello there',
				),
			)
		);
		$this->assertSame( 'こんにちは', $block['attrs']['heading'] );
		$this->assertSame( 'Hello there', $block['attrs']['cuisine'] );

		$link = Dictionary::block_data(
			array(
				'blockName' => 'core/navigation-link',
				'attrs'     => array( 'label' => 'Hello there' ),
			)
		);
		$this->assertSame( 'こんにちは', $link['attrs']['label'] );

		$other = array(
			'blockName' => 'core/group',
			'attrs'     => array( 'label' => 'Hello there' ),
		);
		$this->assertSame( $other, Dictionary::block_data( $other ) );
	}

	/**
	 * Cuisine names come from the shipped dictionary; lists are joined the language's way.
	 */
	public function test_dictionary_cuisines_and_lists() {
		$cuisine = self::factory()->term->create(
			array(
				'taxonomy' => Content_Types::TAX_CUISINE,
				'name'     => 'Japanese',
			)
		);
		$this->assertSame( 'Japanese', get_term( $cuisine )->name );
		$this->show_in( 'ja' );
		$this->assertSame( '日本料理', get_term( $cuisine )->name );
		$this->assertSame( 'a、b、c', wp_sprintf( '%l', array( 'a', 'b', 'c' ) ) );

		Router::unhook();
		$this->show_in( 'ko' );
		$this->assertSame( '일식', get_term( $cuisine )->name );
		$this->assertSame( 'a, b', wp_sprintf( '%l', array( 'a', 'b' ) ) );
	}

	/**
	 * The missing list has saved text with no translation yet, and leaves out English-only pages.
	 */
	public function test_missing_lists_new_text() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Hello there',
				'post_content' => '<!-- wp:paragraph --><p>A sentence only   this test has.</p><!-- /wp:paragraph -->',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_name'    => Policy_Page::SLUG,
				'post_title'   => 'Editorial policy',
				'post_content' => '<!-- wp:paragraph --><p>Policy text stays English.</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertContains( 'A sentence only this test has.', Source_Text::all() );
		$this->assertNotContains( 'Policy text stays English.', Source_Text::all() );

		$missing = Source_Text::missing( 'ja' );
		$this->assertContains( 'A sentence only this test has.', $missing );
		$this->assertNotContains( 'Hello there', $missing, 'Already in the dictionary' );
		$this->assertContains( 'Hello there', Source_Text::missing( 'ko' ) );
		$this->assertSame( 'en', Languages::current(), 'Language put back' );
	}

	/**
	 * Legal pages, the editorial policy and the advertiser pages stay English and get no alternates.
	 */
	public function test_english_only_pages() {
		Legal_Pages::ensure();
		$policy = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => Policy_Page::SLUG,
			)
		);
		$about  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'about-us',
			)
		);
		$ids    = Source_Text::english_only_ids();
		$this->assertContains( $policy, $ids );
		$this->assertContains( url_to_postid( Legal_Pages::url( 'sources' ) ), $ids );
		$this->assertNotContains( $about, $ids );

		$this->go_to( get_permalink( $about ) );
		ob_start();
		Integration::alternate_links();
		$links = (string) ob_get_clean();
		$this->assertStringContainsString( 'hreflang="ja" href="' . esc_url( Languages::url( 'ja', '/' ) ), $links );
		$this->assertStringContainsString( 'hreflang="x-default"', $links );

		$this->go_to( get_permalink( $policy ) );
		ob_start();
		Integration::alternate_links();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Cached site queries are kept apart by language.
	 */
	public function test_cache_key_per_language() {
		$english = Cache::key( 'list', array( 1 ) );
		$this->show_in( 'ja' );
		$this->assertNotSame( $english, Cache::key( 'list', array( 1 ) ) );
	}

	/**
	 * Translated pages say they are AI translations and link the original and the disclaimer.
	 */
	public function test_notice_on_translated_pages() {
		Legal_Pages::ensure();
		$this->show_in( 'ja' );

		$notice = Integration::notice();
		$this->assertStringContainsString( '<aside class="gr-translated" lang="ja">', $notice );
		$this->assertStringContainsString( 'AI で翻訳', $notice );
		$this->assertStringContainsString( '/restaurants/test/" hreflang="en">英語の原文</a>', $notice );
		$this->assertStringContainsString( '#translations" hreflang="en">翻訳について</a>', $notice );
		$this->assertStringNotContainsString( '/ja/', $notice, 'Both links go to English pages' );
		$this->assertStringNotContainsString( 'Google', $notice );

		Router::unhook();
		$this->show_in( 'ko' );
		$notice = Integration::notice();
		$this->assertStringContainsString( 'lang="ko"', $notice );
		$this->assertStringContainsString( 'AI로 번역', $notice );
	}

	/**
	 * The switcher lists every language in its own name and marks the current one.
	 */
	public function test_language_switcher() {
		$this->show_in( 'ja' );
		$html = do_blocks( '<!-- wp:guam-restaurants/language-switcher /-->' );

		$this->assertStringContainsString( 'gr-languages notranslate', $html );
		$this->assertStringContainsString( 'translate="no"', $html );
		$this->assertStringContainsString( '/restaurants/test/" hreflang="en" lang="en">English</a>', $html );
		$this->assertStringContainsString( '/ja/restaurants/test/" hreflang="ja" lang="ja" aria-current="true">日本語</a>', $html );
		$this->assertStringContainsString( 'hreflang="ko" lang="ko">한국어</a>', $html );
	}

	/**
	 * Kept text carries the markers browser translators honor.
	 */
	public function test_keep_marks_text() {
		$this->assertSame( self::KEEP . 'Hagåtña</span>', Languages::keep( 'Hagåtña' ) );
		$this->assertSame( '', Languages::keep( '' ) );
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
	 * The Data sources page gets the Translations section, once.
	 */
	public function test_translations_section_on_data_sources_page() {
		Legal_Pages::ensure();
		$page = get_page_by_path( 'data-sources', OBJECT, 'page' );
		$this->assertInstanceOf( \WP_Post::class, $page );
		$this->assertStringContainsString( 'id="translations"', $page->post_content );
		$this->assertStringContainsString( 'translated from English with AI', $page->post_content );
		$this->assertStringNotContainsString( 'GOOGLE DISCLAIMS', $page->post_content );
		$this->assertLessThan( strpos( $page->post_content, 'Report a problem' ), strpos( $page->post_content, 'id="translations"' ) );

		$this->assertSame( 'kept (already there)', Legal_Pages::add_translations_section() );
		$this->assertSame( 1, substr_count( (string) get_post_field( 'post_content', $page->ID ), 'id="translations"' ) );
	}
}
