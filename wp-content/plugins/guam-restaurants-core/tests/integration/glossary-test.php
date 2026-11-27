<?php
/**
 * CHamoru food glossary tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Dishes\Dish_Index;
use GuamRestaurants\Core\Dishes\Dish_Pages;
use GuamRestaurants\Core\Dishes\Glossary;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Site\Sitemaps;
use GuamRestaurants\Core\Translation\Dictionary;
use GuamRestaurants\Core\Translation\Languages;

/**
 * The glossary page, its links to and from dish pages, its sources and its
 * translations (English audience idea E26).
 *
 * @covers \GuamRestaurants\Core\Dishes\Glossary
 * @covers \GuamRestaurants\Core\Dishes\Dish_Pages
 */
class Glossary_Test extends Guam_Test_Case {

	/**
	 * Fresh index, English, pretty links.
	 */
	public function set_up() {
		parent::set_up();
		Languages::set( Languages::ORIGINAL );
		delete_option( Dish_Index::OPTION );
		Dish_Index::reset();
		Glossary::reset();
		$this->set_permalink_structure( '/%postname%/' );
		Dish_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Back to English.
	 */
	public function tear_down() {
		Languages::set( Languages::ORIGINAL );
		Dish_Index::reset();
		parent::tear_down();
	}

	/**
	 * A restaurant whose owner lists these dishes.
	 *
	 * @param string   $name   Name.
	 * @param string[] $dishes Dish names.
	 * @return int
	 */
	private function serves( string $name, array $dishes ): int {
		$id = $this->restaurant( $name );
		update_post_meta(
			$id,
			Owner_Content::MENU_DISHES,
			array_map(
				static function ( string $dish ): array {
					return array(
						'name'    => $dish,
						'price'   => '',
						'details' => '',
					);
				},
				$dishes
			)
		);
		return $id;
	}

	/**
	 * Every entry is complete, A to Z, with its source in the register and
	 * its definition in Japanese and Korean.
	 */
	public function test_entries_are_complete() {
		$entries = Glossary::entries();
		$this->assertCount( 25, $entries );

		$terms = array_map( 'remove_accents', array_column( $entries, 'term' ) );
		$order = $terms;
		natcasesort( $order );
		$this->assertSame( array_values( $order ), $terms, 'A to Z' );

		$register = (string) file_get_contents( dirname( GUAM_RESTAURANTS_CORE_DIR, 3 ) . '/docs/data-sources.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		foreach ( $entries as $anchor => $entry ) {
			$this->assertSame( sanitize_title( remove_accents( (string) $anchor ) ), $anchor );
			$this->assertNotEmpty( $entry['term'] );
			$this->assertNotEmpty( $entry['text'] );
			$this->assertStringNotContainsStringIgnoringCase( 'best', $entry['text'], $anchor );
			$this->assertStringStartsWith( 'https://', $entry['source'][1] );
			$this->assertStringContainsString( $entry['source'][1], $register, "$anchor source is registered" );
			foreach ( array( 'ja', 'ko' ) as $code ) {
				$this->assertNotEmpty( Dictionary::words( $code )[ Dictionary::key( $entry['text'] ) ] ?? '', "$code $anchor" );
			}
		}
	}

	/**
	 * Dish pages find their entry, including longer dish names; others find none.
	 */
	public function test_anchor_for() {
		$entries = Glossary::entries();
		$this->assertSame( 'kelaguen', Glossary::anchor_for( 'kelaguen', $entries ) );
		$this->assertSame( 'kelaguen', Glossary::anchor_for( 'chicken kelaguen', $entries ) );
		$this->assertSame( 'titiyas', Glossary::anchor_for( 'titiya', $entries ) );
		$this->assertSame( 'finadenne', Glossary::anchor_for( 'finadene', $entries ) );
		$this->assertSame( 'bunelos-aga', Glossary::anchor_for( 'bunelo', $entries ), 'The first entry wins' );
		$this->assertSame( 'bunelos-dagu', Glossary::anchor_for( 'bunelo dagu', $entries ) );
		$this->assertSame( '', Glossary::anchor_for( 'spam musubi', $entries ) );
		$this->assertSame( '', Glossary::anchor_for( 'achote', $entries ), 'Ingredients have no dish' );
	}

	/**
	 * The page lists every entry, links only to dish pages that exist, and
	 * has its own title, canonical URL, description and structured data.
	 */
	public function test_glossary_page() {
		$this->serves( 'Proa', array( 'Chicken Kelaguen', 'Buñelos' ) );
		$this->serves( 'Meskla', array( 'Banana buñelos', 'Buñelos dågu' ) );
		Dish_Index::rebuild();

		$this->assertSame( home_url( '/dishes/glossary/' ), Glossary::url() );
		$this->assertSame( home_url( '/dishes/glossary/#kelaguen' ), Glossary::url( 'kelaguen' ) );
		$this->go_to( Glossary::url() );
		$this->assertSame( 'glossary', Dish_Pages::current_page() );
		$this->assertFalse( is_404() );
		$this->assertFalse( is_home() );
		$this->assertSame( 'CHamoru food glossary', Dish_Pages::current_title() );
		$this->assertSame( Glossary::url(), Dish_Pages::current_url() );
		$this->assertSame( Glossary::url(), Seo::plugin_page()['canonical'] ?? '' );
		$this->assertStringStartsWith( 'What kelaguen', Dish_Pages::description() );

		$html = Dish_Pages::render_current();
		foreach ( Glossary::entries() as $anchor => $entry ) {
			$this->assertStringContainsString( 'id="' . $anchor . '"', $html );
			$this->assertStringContainsString( 'href="#' . $anchor . '"', $html );
		}
		$this->assertStringContainsString( 'href="' . home_url( '/dishes/kelaguen/' ) . '">Where to get Kelaguen on Guam</a>', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/dishes/bunelo/' ) . '"', $html, 'Two places serve buñelos' );
		$this->assertStringNotContainsString( '/dishes/latiya/', $html, 'No page, no link' );
		$this->assertStringContainsString( 'Source: <a href="https://www.guampedia.com/chalakiles-recipe/" rel="noopener"><span class="notranslate" translate="no">Guampedia</span></a>', $html );
		$this->assertStringContainsString( '<span class="notranslate" translate="no">Fina&#039;denne&#039;</span>', $html );

		$data = Glossary::structured_data();
		$this->assertSame( 'DefinedTermSet', $data['@type'] );
		$this->assertCount( 25, $data['hasDefinedTerm'] );
		$this->assertSame( Glossary::url( 'kelaguen' ), $data['hasDefinedTerm'][16]['@id'] );

		$this->go_to( home_url( '/dishes/kelaguen/' ) );
		$this->assertSame( 'dish', Dish_Pages::current_page(), 'Dish pages still work' );
	}

	/**
	 * Every dish page and the dish list link to the glossary, to the dish's
	 * own entry when it has one.
	 */
	public function test_dish_pages_link_to_the_glossary() {
		$this->serves( 'Proa', array( 'Chicken Kelaguen', 'Spam musubi' ) );
		$this->serves( 'Meskla', array( 'Spam musubi' ) );
		Dish_Index::rebuild();

		$this->go_to( Dish_Pages::url( 'kelaguen' ) );
		$this->assertStringContainsString( '<a href="' . home_url( '/dishes/glossary/#kelaguen' ) . '">What is <span class="notranslate" translate="no">Kelaguen</span>? Read it in the food glossary.</a>', Dish_Pages::render_current() );

		$this->go_to( Dish_Pages::url( 'spam-musubi' ) );
		$this->assertStringContainsString( '<a href="' . home_url( '/dishes/glossary/' ) . '">New to CHamoru food? Read the food glossary.</a>', Dish_Pages::render_current() );

		$this->go_to( Dish_Pages::url() );
		$this->assertStringContainsString( 'href="' . home_url( '/dishes/glossary/' ) . '"', Dish_Pages::render_current() );
	}

	/**
	 * The glossary is in the sitemap even before any dish has a page.
	 */
	public function test_sitemap() {
		$this->assertContains( Glossary::url(), array_column( Sitemaps::plugin_pages(), 'loc' ) );
	}

	/**
	 * Japanese pages translate the definitions and keep the terms.
	 */
	public function test_translated_page() {
		Languages::set( 'ja' );
		$entry = Glossary::entries()['kelaguen'];
		$this->assertSame( Dictionary::words( 'ja' )[ $entry['text'] ], Glossary::text( $entry ) );
		$html = Glossary::render();
		$this->assertStringContainsString( esc_html( Dictionary::words( 'ja' )[ $entry['text'] ] ), $html );
		$this->assertStringContainsString( '<span class="notranslate" translate="no">Kelaguen</span>', $html );
		$this->assertStringContainsString( 'フィエスタの食卓（Fiesta table）', $html, 'English terms are translated' );
	}
}
