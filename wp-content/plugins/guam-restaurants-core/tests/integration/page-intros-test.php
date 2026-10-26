<?php
/**
 * Page intros shipped with the plugin (data/intros.php).
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Migrations;
use GuamRestaurants\Core\Dishes\Dish_Index;
use GuamRestaurants\Core\Dishes\Dish_Pages;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Seed_Data;
use GuamRestaurants\Core\Site\Intro;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Translation\Dictionary;
use GuamRestaurants\Core\Translation\Languages;
use GuamRestaurants\Core\Translation\Source_Text;

/**
 * Approved intros for every seeded village, mall, cuisine and Guam favorite
 * dish, in English, Japanese and Korean; filled into empty Descriptions only.
 *
 * @covers \GuamRestaurants\Core\Site\Intro
 * @covers \GuamRestaurants\Core\Database\Migrations
 * @covers \GuamRestaurants\Core\Translation\Dictionary
 */
class Page_Intros_Test extends Guam_Test_Case {

	/**
	 * Back to English.
	 */
	public function tear_down() {
		Languages::set( Languages::ORIGINAL );
		parent::tear_down();
	}

	/**
	 * A seeded term, unverified malls included.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return \WP_Term
	 */
	private function term( string $taxonomy, string $slug ): \WP_Term {
		$term = Mall_Verification::with_unverified(
			static function () use ( $slug, $taxonomy ) {
				return get_term_by( 'slug', $slug, $taxonomy );
			}
		);
		$this->assertInstanceOf( \WP_Term::class, $term, $slug );
		return $term;
	}

	/**
	 * Every seeded page has an intro, and every intro has both translations.
	 */
	public function test_every_page_has_an_intro_in_three_languages() {
		$shipped = Intro::shipped();
		$this->assertSame( array_keys( Seed_Data::villages() ), array_keys( $shipped['village'] ) );
		$this->assertSame( array_keys( Seed_Data::malls() ), array_keys( $shipped['mall'] ) );
		$this->assertSame( array_keys( Seed_Data::cuisines() ), array_keys( $shipped['cuisine'] ) );
		$this->assertSame( array_keys( Dish_Index::SEEDS ), array_keys( $shipped['dish'] ) );

		foreach ( array( 'ja', 'ko' ) as $code ) {
			$words = Dictionary::words( $code );
			foreach ( $shipped as $group => $intros ) {
				foreach ( $intros as $slug => $text ) {
					$this->assertNotEmpty( $words[ Dictionary::key( $text ) ] ?? '', "$code $group $slug" );
				}
			}
		}
	}

	/**
	 * Empty Descriptions are filled as written; one someone wrote is kept.
	 */
	public function test_fill_keeps_written_descriptions() {
		$written = $this->term( Content_Types::TAX_VILLAGE, 'dededo' );
		wp_update_term( $written->term_id, Content_Types::TAX_VILLAGE, array( 'description' => 'Our own words.' ) );

		$counts = Intro::fill();
		$this->assertSame( 0, $counts['missing'] );
		$this->assertSame( 1, $counts['kept'] );
		$this->assertSame( 19 + 6 + 20 - 1, $counts['filled'] );

		$this->assertSame( 'Our own words.', $this->term( Content_Types::TAX_VILLAGE, 'dededo' )->description );
		// Apostrophes and accents come back exactly as shipped.
		$this->assertSame( Intro::shipped()['village']['merizo'], $this->term( Content_Types::TAX_VILLAGE, 'merizo' )->description );
		$this->assertSame( Intro::shipped()['mall']['pacific-place'], $this->term( Content_Types::TAX_MALL, 'pacific-place' )->description );
		$this->assertSame( Intro::shipped()['cuisine']['chamorro'], $this->term( Content_Types::TAX_CUISINE, 'chamorro' )->description );

		$this->assertSame( 0, Intro::fill()['filled'], 'A second run changes nothing.' );
	}

	/**
	 * The migration renames the two seeded terms while they keep their old names.
	 */
	public function test_migration_renames_and_fills() {
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		$this->assertGreaterThan( has_action( 'init', array( Content_Types::class, 'register' ) ), has_action( 'init', array( Migrations::class, 'maybe_run' ) ), 'Migrations run after the taxonomies register.' );
		$cuisine = $this->term( Content_Types::TAX_CUISINE, 'chamorro' );
		$mall    = $this->term( Content_Types::TAX_MALL, 'the-plaza' );
		wp_update_term( $cuisine->term_id, Content_Types::TAX_CUISINE, array( 'name' => 'Chamorro' ) );
		wp_update_term( $mall->term_id, Content_Types::TAX_MALL, array( 'name' => 'The Plaza' ) );
		update_term_meta( $mall->term_id, Term_Meta::ALIASES, array( 'The Plaza Shopping Center' ) );

		delete_option( Migrations::OPTION );
		Migrations::run( array_intersect_key( Migrations::definitions(), array( '2026-10-26-page-intros' => true ) ) );

		$cuisine = $this->term( Content_Types::TAX_CUISINE, 'chamorro' );
		$mall    = $this->term( Content_Types::TAX_MALL, 'the-plaza' );
		$this->assertSame( 'CHamoru', $cuisine->name );
		$this->assertSame( 'Dusit Place', $mall->name );
		$this->assertSame( array( 'The Plaza', 'The Plaza Shopping Center' ), get_term_meta( $mall->term_id, Term_Meta::ALIASES, true ) );
		$this->assertSame( Intro::shipped()['cuisine']['chamorro'], $cuisine->description );
		$this->assertStringEndsWith( '/cuisine/chamorro/', (string) get_term_link( $cuisine ), 'The address stays.' );

		// A name someone chose is kept.
		wp_update_term( $mall->term_id, Content_Types::TAX_MALL, array( 'name' => 'Dusit Place Tumon' ) );
		delete_option( Migrations::OPTION );
		Migrations::run( array_intersect_key( Migrations::definitions(), array( '2026-10-26-page-intros' => true ) ) );
		$this->assertSame( 'Dusit Place Tumon', $this->term( Content_Types::TAX_MALL, 'the-plaza' )->name );
	}

	/**
	 * Village intros show in the page's language; the village name stays as written.
	 */
	public function test_translated_intros() {
		Intro::fill();
		$village = $this->term( Content_Types::TAX_VILLAGE, 'tamuning' );

		Languages::set( 'ja' );
		$this->assertSame( Dictionary::words( 'ja' )[ Intro::shipped()['village']['tamuning'] ], get_term( $village->term_id )->description );
		$this->assertSame( 'Tamuning', get_term( $village->term_id )->name );
		$this->assertStringContainsString( '恋人岬', Seo::term_description( get_term( $village->term_id ) ) );

		Languages::set( 'ko' );
		$this->assertStringContainsString( '사랑의 절벽', wp_strip_all_tags( term_description( $village ) ) );
		$this->assertSame( Dictionary::words( 'ko' )['CHamoru'], get_term( $this->term( Content_Types::TAX_CUISINE, 'chamorro' )->term_id )->name );

		Languages::set( Languages::ORIGINAL );
		$this->assertContains( Intro::shipped()['village']['tamuning'], Source_Text::all(), 'Listed for `translations missing`.' );
	}

	/**
	 * Guam favorite dish pages get their intro; other dishes have none.
	 */
	public function test_dish_intros() {
		$this->assertStringStartsWith( 'Kelaguen is chopped chicken', Dish_Pages::intro( array( 'key' => 'kelaguen' ) ) );
		$this->assertSame( '', Dish_Pages::intro( array( 'key' => 'pancit' ) ) );

		Languages::set( 'ja' );
		$this->assertStringStartsWith( 'ケラグエン', Dish_Pages::intro( array( 'key' => 'kelaguen' ) ) );

		Languages::set( Languages::ORIGINAL );
		$html = Dish_Pages::render_dish(
			array(
				'slug'   => 'latiya',
				'key'    => 'latiya',
				'name'   => 'Latiya',
				'places' => 0,
				'seed'   => true,
			)
		);
		$this->assertStringContainsString( '<p class="gr-dish-page__about">Latiya is vanilla custard', $html );
	}
}
