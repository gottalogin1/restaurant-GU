<?php
/**
 * Sitemaps, canonical links and structured data for the plugin's own pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Awards\Pages as Award_Pages;
use GuamRestaurants\Core\Awards\Repository;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Dishes\Dish_Index;
use GuamRestaurants\Core\Dishes\Dish_Pages;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Business_Data;
use GuamRestaurants\Core\Site\Core_Sitemap_Provider;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Site\Sitemaps;
use GuamRestaurants\Core\Site\Yoast_Sitemap_Provider;
use GuamRestaurants\Core\Structured_Data;

/**
 * Gaps G2 to G6 of the SEO plan: dish, awards and translated pages in the
 * sitemaps, canonical links on dish and awards pages, an ItemList on dish
 * pages, and the ZIP code and menu link in Restaurant data.
 *
 * @covers \GuamRestaurants\Core\Site\Sitemaps
 * @covers \GuamRestaurants\Core\Site\Core_Sitemap_Provider
 * @covers \GuamRestaurants\Core\Site\Yoast_Sitemap_Provider
 * @covers \GuamRestaurants\Core\Site\Seo
 * @covers \GuamRestaurants\Core\Structured_Data
 * @covers \GuamRestaurants\Core\Site\Business_Data
 */
class Seo_Pages_Test extends Guam_Test_Case {

	/**
	 * Restaurants with a dish page (Kelaguen, a Guam favorite) and an award.
	 *
	 * @var array<string, int>
	 */
	private array $r = array();

	/**
	 * Pretty permalinks, the plugin's routes, two restaurants, a dish page and an award.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		Dish_Pages::add_rewrite_rules();
		Award_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );
		delete_option( Dish_Index::OPTION );
		Dish_Index::reset();

		$this->r['proa']   = $this->restaurant( 'Proa', array( Restaurant_Meta::ADDRESS => '429 Pale San Vitores Rd, Tumon, GU 96913' ) );
		$this->r['meskla'] = $this->restaurant( 'Meskla' );
		foreach ( array( 'proa', 'meskla' ) as $label ) {
			$photo = self::factory()->attachment->create(
				array(
					'post_parent'    => $this->r[ $label ],
					'post_mime_type' => 'image/jpeg',
				)
			);
			update_post_meta( $photo, Photo_Submissions::STATUS, Photo_Submissions::APPROVED );
			update_post_meta( $photo, Photo_Submissions::DISH, 'Kelaguen' );
			update_post_meta( $photo, Photo_Submissions::NAME, 'Ana ' . $label );
			update_post_meta( $photo, Photo_Submissions::CONNECTED, 0 );
		}
		Dish_Index::rebuild();

		Repository::upsert(
			$this->r['proa'],
			array(
				'program'     => 'Test Awards',
				'year'        => 2025,
				'category'    => 'Best Fiesta Plate',
				'placement'   => 'winner',
				'source_url'  => 'https://www.example.com/2025-results',
				'source_note' => '',
			)
		);
	}

	/**
	 * Forgets the index.
	 */
	public function tear_down() {
		Dish_Index::reset();
		parent::tear_down();
	}

	/**
	 * JSON-LD printed by a callback.
	 *
	 * @param callable $printer Callback.
	 * @return array<string, mixed>|null
	 */
	private function json_ld( callable $printer ): ?array {
		ob_start();
		$printer();
		$html = (string) ob_get_clean();
		return preg_match( '#<script type="application/ld\+json">(.+)</script>#s', $html, $match ) ? json_decode( $match[1], true ) : null;
	}

	/**
	 * The dish and awards pages are listed; so is every public page in ja and ko.
	 */
	public function test_sitemap_lists() {
		$pages = wp_list_pluck( Sitemaps::urls( Sitemaps::PAGES ), 'loc' );
		$this->assertSame(
			array(
				home_url( '/dishes/' ),
				home_url( '/dishes/kelaguen/' ),
				home_url( '/awards/' ),
				home_url( '/awards/most-awarded/' ),
				home_url( '/awards/category/best-fiesta-plate/' ),
			),
			$pages
		);

		Legal_Pages::ensure();
		$about   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About',
				'post_name'   => 'about',
				'post_status' => 'publish',
			)
		);
		$village = self::factory()->term->create(
			array(
				'taxonomy' => Content_Types::TAX_VILLAGE,
				'name'     => 'Tumon',
			)
		);
		wp_set_object_terms( $this->r['proa'], array( $village ), Content_Types::TAX_VILLAGE );

		$korean = Sitemaps::urls( 'ko' );
		$urls   = wp_list_pluck( $korean, 'loc' );
		$home   = untrailingslashit( (string) get_option( 'home' ) );
		$this->assertContains( $home . '/ko/', $urls );
		$this->assertContains( $home . '/ko/about/', $urls );
		$this->assertContains( $home . '/ko' . substr( (string) get_permalink( $this->r['proa'] ), strlen( $home ) ), $urls );
		$this->assertContains( $home . '/ko' . substr( (string) get_term_link( $village ), strlen( $home ) ), $urls );
		$this->assertContains( $home . '/ko/dishes/kelaguen/', $urls );
		$this->assertNotContains( $home . '/ko' . substr( Legal_Pages::url( 'sources' ), strlen( $home ) ), $urls, 'Pages kept in English are left out' );
		foreach ( $urls as $url ) {
			$this->assertStringStartsWith( $home . '/ko/', $url );
		}
		$this->assertNotEmpty( array_column( $korean, 'lastmod' ), 'Posts carry their last change' );
		$this->assertCount( count( $korean ), Sitemaps::urls( 'ja' ) );
		$this->assertSame( array(), Sitemaps::urls( 'fr' ) );
		$this->assertGreaterThan( 0, $about );
	}

	/**
	 * WordPress's sitemap serves the lists and puts them in its index.
	 */
	public function test_core_provider() {
		$provider = new Core_Sitemap_Provider();
		$this->assertSame( array( 'pages', 'ja', 'ko' ), array_keys( $provider->get_object_subtypes() ) );
		$this->assertSame( 1, $provider->get_max_num_pages( 'pages' ) );
		$this->assertSame( 0, $provider->get_max_num_pages( 'fr' ) );
		$this->assertSame( home_url( '/dishes/' ), $provider->get_url_list( 1, 'pages' )[0]['loc'] );
		$this->assertSame( array(), $provider->get_url_list( 2, 'pages' ) );

		$restaurant = wp_list_filter( $provider->get_url_list( 1, 'ja' ), array( 'loc' => untrailingslashit( (string) get_option( 'home' ) ) . '/ja' . substr( (string) get_permalink( $this->r['meskla'] ), strlen( untrailingslashit( (string) get_option( 'home' ) ) ) ) ) );
		$this->assertCount( 1, $restaurant );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', array_values( $restaurant )[0]['lastmod'] );

		$this->assertArrayHasKey( Core_Sitemap_Provider::NAME, wp_get_sitemap_providers() );
	}

	/**
	 * Yoast's sitemap index lists our sitemaps, and each serves its page of entries.
	 */
	public function test_yoast_provider() {
		require_once dirname( __DIR__ ) . '/fixtures/yoast-sitemap-stubs.php';
		$providers = Sitemaps::yoast_providers( array() );
		$this->assertCount( 1, $providers );
		$provider = $providers[0];
		$this->assertInstanceOf( Yoast_Sitemap_Provider::class, $provider );

		$this->assertTrue( $provider->handles_type( 'guam-pages' ) );
		$this->assertTrue( $provider->handles_type( 'guam-ko' ) );
		$this->assertFalse( $provider->handles_type( 'guam-fr' ) );
		$this->assertFalse( $provider->handles_type( 'page' ) );

		$index = wp_list_pluck( $provider->get_index_links( 3 ), 'loc' );
		$this->assertSame( home_url( '/guam-pages-sitemap.xml' ), $index[0] );
		$this->assertSame( home_url( '/guam-pages-sitemap2.xml' ), $index[1], 'Five pages at three per sitemap' );
		$this->assertContains( home_url( '/guam-ja-sitemap.xml' ), $index );

		$this->assertSame( array( home_url( '/awards/most-awarded/' ), home_url( '/awards/category/best-fiesta-plate/' ) ), wp_list_pluck( $provider->get_sitemap_links( 'guam-pages', 3, 2 ), 'loc' ) );
		$this->expectException( \OutOfBoundsException::class );
		$provider->get_sitemap_links( 'guam-pages', 3, 3 );
	}

	/**
	 * Dish and awards pages get a canonical link; narrowed awards pages are noindex instead.
	 */
	public function test_canonical_and_robots() {
		$this->go_to( Dish_Pages::url( 'kelaguen' ) );
		$this->assertSame( home_url( '/dishes/kelaguen/' ), Seo::yoast_canonical( 'https://example.com/wrong/' ) );
		$this->assertSame( 'Where to get Kelaguen on Guam - ' . get_bloginfo( 'name' ), Seo::yoast_title( 'Yoast title' ) );
		$this->assertStringContainsString( 'places on Guam serve Kelaguen', Seo::yoast_description( '' ) );
		ob_start();
		Seo::canonical();
		$this->assertSame( '<link rel="canonical" href="' . home_url( '/dishes/kelaguen/' ) . '">' . "\n", (string) ob_get_clean() );
		$this->assertArrayNotHasKey( 'noindex', Seo::robots( array() ) );

		$this->go_to( Award_Pages::url( 'category', 'best-fiesta-plate' ) );
		$this->assertSame( home_url( '/awards/category/best-fiesta-plate/' ), Seo::plugin_page()['canonical'] );
		$this->assertFalse( Seo::plugin_page()['filtered'] );

		$_GET[ Award_Pages::FIELD_YEAR ] = '2025';
		$this->go_to( add_query_arg( Award_Pages::FIELD_YEAR, '2025', Award_Pages::url( 'archive' ) ) );
		$this->assertTrue( Seo::is_filtered_plugin_page() );
		$this->assertTrue( Seo::robots( array() )['noindex'] );
		$this->assertSame( 'noindex', Seo::yoast_robots( array() )['index'] );
		$this->assertFalse( Seo::yoast_canonical( 'https://example.com/' ) );
		ob_start();
		Seo::canonical();
		$this->assertSame( '', (string) ob_get_clean(), 'No canonical on a noindex page' );
		unset( $_GET[ Award_Pages::FIELD_YEAR ] );

		$this->go_to( home_url( '/dishes/not-a-dish/' ) );
		$this->assertNull( Seo::plugin_page() );

		$this->go_to( get_permalink( $this->r['proa'] ) );
		$this->assertNull( Seo::plugin_page() );
		$this->assertSame( 'https://example.com/kept/', Seo::yoast_canonical( 'https://example.com/kept/' ) );
	}

	/**
	 * A dish page lists its restaurants as an ItemList, in page order.
	 */
	public function test_dish_item_list() {
		$this->go_to( Dish_Pages::url( 'kelaguen' ) );
		$data = $this->json_ld( array( Structured_Data::class, 'print_item_list' ) );
		$this->assertNotNull( $data );
		$this->assertSame( 'ItemList', $data['@type'] );
		$this->assertSame( 'Where to get Kelaguen on Guam', $data['name'] );
		$this->assertSame( home_url( '/dishes/kelaguen/' ), $data['url'] );
		$this->assertSame( 2, $data['numberOfItems'] );
		$expected = array_map( 'get_permalink', array_keys( Dish_Pages::places( Dish_Pages::current_dish() ) ) );
		$this->assertSame( $expected, wp_list_pluck( $data['itemListElement'], 'url' ) );

		$this->go_to( Dish_Pages::url() );
		$this->assertNull( $this->json_ld( array( Structured_Data::class, 'print_item_list' ) ), 'The dish list itself has no ItemList' );
	}

	/**
	 * The ZIP code comes from the address; the menu link only while the listing is claimed.
	 */
	public function test_restaurant_data() {
		$this->assertSame( '96913', Business_Data::postal_code( '429 Pale San Vitores Rd, Tumon, GU 96913' ) );
		$this->assertSame( '96929', Business_Data::postal_code( 'Route 1, Yigo 96929-1234' ) );
		$this->assertSame( '', Business_Data::postal_code( 'Unit 96913A, Tamuning' ) );
		$this->assertSame( '', Business_Data::postal_code( 'Suite 12345' ) );

		$data = Business_Data::properties( $this->r['proa'] );
		$this->assertSame( '96913', $data['address']['postalCode'] );
		$this->assertArrayNotHasKey( 'postalCode', Business_Data::properties( $this->r['meskla'] )['address'] ?? array() );

		update_post_meta(
			$this->r['proa'],
			Owner_Content::MENU_LINKS,
			array(
				array(
					'label' => 'Menu',
					'url'   => 'https://proa.example.com/menu',
				),
			)
		);
		$this->assertArrayNotHasKey( 'hasMenu', Business_Data::properties( $this->r['proa'] ), 'Unclaimed: owner links do not show' );
		update_post_meta( $this->r['proa'], Restaurant_Meta::CLAIMED_BY_USER_ID, self::factory()->user->create() );
		$this->assertSame( 'https://proa.example.com/menu', Business_Data::properties( $this->r['proa'] )['hasMenu'] );
	}
}
