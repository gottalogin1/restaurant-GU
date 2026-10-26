<?php
/**
 * "Where to get it" dish page tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Dishes\Dish_Index;
use GuamRestaurants\Core\Dishes\Dish_Pages;
use GuamRestaurants\Core\Dishes\Display;
use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;

/**
 * Which places serve a dish, how they are ordered, which dishes get a
 * page, and the pages themselves (comparison addition 12).
 *
 * @covers \GuamRestaurants\Core\Dishes\Dish_Index
 * @covers \GuamRestaurants\Core\Dishes\Dish_Pages
 */
class Dish_Pages_Test extends Guam_Test_Case {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Fresh index for each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		delete_option( Dish_Index::OPTION );
		Dish_Index::reset();
	}

	/**
	 * Forgets the index.
	 */
	public function tear_down() {
		Dish_Index::reset();
		parent::tear_down();
	}

	/**
	 * A mention, as Popular_Dishes::mentions() gives it.
	 *
	 * @param string $dish      Dish.
	 * @param string $person    Person.
	 * @param bool   $connected Connected.
	 * @return array<string, mixed>
	 */
	private function mention( string $dish, string $person, bool $connected = false ): array {
		return array(
			'dish'      => $dish,
			'source'    => 'photo',
			'person'    => $person,
			'rating'    => 0,
			'connected' => $connected,
			'photo'     => 0,
			'date'      => '2026-10-01 12:00:00',
		);
	}

	/**
	 * An approved dish photo (meta only).
	 *
	 * @param int    $restaurant Restaurant.
	 * @param string $dish       Dish.
	 * @param string $name       Uploader.
	 * @param bool   $connected  Connected.
	 * @return int
	 */
	private function photo( int $restaurant, string $dish, string $name, bool $connected = false ): int {
		$id = self::factory()->attachment->create(
			array(
				'post_parent'    => $restaurant,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $dish,
			)
		);
		update_post_meta( $id, Photo_Submissions::STATUS, Photo_Submissions::APPROVED );
		update_post_meta( $id, Photo_Submissions::DISH, $dish );
		update_post_meta( $id, Photo_Submissions::NAME, $name );
		update_post_meta( $id, Photo_Submissions::CONNECTED, $connected ? 1 : 0 );
		return $id;
	}

	/**
	 * An approved visitor review naming dishes.
	 *
	 * @param int    $restaurant Restaurant.
	 * @param string $dishes     Dishes, one per line.
	 * @param string $name       Reviewer.
	 * @return void
	 */
	private function review( int $restaurant, string $dishes, string $name ): void {
		$id = Highlights::insert(
			array(
				'restaurant_id' => $restaurant,
				'type'          => Visitor_Reviews::TYPE,
				'rating'        => 4,
				'body'          => 'Good food and friendly staff, we will be back soon.',
				'author_name'   => $name,
				'dishes'        => $dishes,
			)
		);
		$this->assertIsInt( $id );
		$this->assertTrue( Highlights::moderate( $id, 'approved', '', $this->admin ) );
	}

	/**
	 * Each person once per dish; owners and connected people never count;
	 * the owner's menu marks the dish.
	 */
	public function test_entries() {
		$entries = Dish_Index::entries(
			array(
				$this->mention( 'Chicken Kelaguen', 'name:Ana' ),
				$this->mention( 'chicken kelaguen!', 'name:ana' ),
				$this->mention( 'Chicken Kelaguen', 'name:Ben' ),
				$this->mention( 'Chicken Kelaguen', 'name:Staff', true ),
				$this->mention( 'Red Rice', '' ),
				$this->mention( 'Red Rice', '' ),
			),
			array(
				array( 'name' => 'Chicken kelaguen' ),
				array( 'name' => 'Kådon pika' ),
			)
		);
		$this->assertSame( array( 'chicken kelaguen', 'red rice', 'kadon pika' ), array_keys( $entries ) );
		$this->assertSame( 'Chicken Kelaguen', $entries['chicken kelaguen']['name'], 'Most common spelling' );
		$this->assertCount( 2, $entries['chicken kelaguen']['people'], 'Ana once, Ben, not staff' );
		$this->assertTrue( $entries['chicken kelaguen']['owner'] );
		$this->assertCount( 2, $entries['red rice']['people'], 'Anonymous mentions count separately' );
		$this->assertFalse( $entries['red rice']['owner'] );
		$this->assertSame( array(), $entries['kadon pika']['people'] );
		$this->assertTrue( $entries['kadon pika']['owner'] );
		$this->assertSame( 10, strlen( $entries['chicken kelaguen']['people'][0] ), 'Hashed, not names' );
	}

	/**
	 * A dish matches its own page and pages for the name it ends with.
	 */
	public function test_matches() {
		$this->assertTrue( Dish_Index::matches( 'kelaguen', 'kelaguen' ) );
		$this->assertTrue( Dish_Index::matches( 'kelaguen', 'beef kelaguen' ) );
		$this->assertFalse( Dish_Index::matches( 'kelaguen', 'kelaguen plate' ) );
		$this->assertFalse( Dish_Index::matches( 'rice', 'licorice' ) );
		$this->assertFalse( Dish_Index::matches( 'red rice', 'fried rice' ) );
	}

	/**
	 * Pages: a name at two places, or a Guam favorite at one; menu words and plain ingredients never.
	 */
	public function test_pages() {
		$entry  = static function ( string $name, int $people = 1, bool $owner = false ): array {
			return array(
				'name'   => $name,
				'people' => array_slice( array( 'a', 'b', 'c', 'd' ), 0, $people ),
				'owner'  => $owner,
			);
		};
		$places = array(
			1 => array(
				'chicken kelaguen' => $entry( 'Chicken kelaguen' ),
				'pancit'           => $entry( 'Pancit' ),
				'fried rice'       => $entry( 'Fried rice' ),
				'rice'             => $entry( 'Rice' ),
				'combo'            => $entry( 'Combo' ),
			),
			2 => array(
				'beef kelaguen' => $entry( 'Beef kelaguen' ),
				'pancit'        => $entry( 'pancit' ),
				'fried rice'    => $entry( 'Fried Rice' ),
				'rice'          => $entry( 'Rice' ),
				'combo'         => $entry( 'Combo' ),
			),
			3 => array(
				'latiya'      => $entry( 'Latiya', 0, true ),
				'spam musubi' => $entry( 'Spam musubi' ),
			),
		);
		$pages  = Dish_Index::pages( $places );
		$this->assertSame( array( 'fried-rice', 'kelaguen', 'latiya', 'pancit' ), array_keys( $pages ) );
		$this->assertSame( 'Kelaguen', $pages['kelaguen']['name'], 'Favorites use our spelling' );
		$this->assertSame( 2, $pages['kelaguen']['places'], 'Chicken and beef kelaguen both count' );
		$this->assertTrue( $pages['kelaguen']['seed'] );
		$this->assertSame( 'Pancit', $pages['pancit']['name'] );
		$this->assertSame( 1, $pages['latiya']['places'], 'A favorite needs one place' );
		$this->assertArrayNotHasKey( 'spam-musubi', $pages, 'Other dishes need two places' );
		$this->assertArrayNotHasKey( 'rice', $pages );
		$this->assertArrayNotHasKey( 'combo', $pages );
	}

	/**
	 * Places by distinct visitors, then owner-only, then name; never by anything paid.
	 */
	public function test_places_order() {
		$places = array(
			10 => array(
				'kelaguen'         => array(
					'name'   => 'Kelaguen',
					'people' => array( 'a' ),
					'owner'  => false,
				),
				'chicken kelaguen' => array(
					'name'   => 'Chicken kelaguen',
					'people' => array( 'a', 'b' ),
					'owner'  => false,
				),
			),
			20 => array(
				'beef kelaguen' => array(
					'name'   => 'Beef kelaguen',
					'people' => array( 'c', 'd', 'e' ),
					'owner'  => true,
				),
			),
			30 => array(
				'kelaguen' => array(
					'name'   => 'Kelaguen',
					'people' => array(),
					'owner'  => true,
				),
			),
			40 => array(
				'kelaguen' => array(
					'name'   => 'Kelaguen',
					'people' => array( 'f', 'g' ),
					'owner'  => false,
				),
			),
			50 => array(
				'red rice' => array(
					'name'   => 'Red rice',
					'people' => array( 'h' ),
					'owner'  => false,
				),
			),
		);
		$order  = Dish_Index::places(
			$places,
			'kelaguen',
			array(
				10 => 'Zebra Grill',
				20 => 'Beach Bar',
				30 => 'Owner Only',
				40 => 'Alpha Cafe',
			)
		);
		$this->assertSame( array( 20, 40, 10, 30 ), array_keys( $order ), 'Ties by name: Alpha before Zebra' );
		$this->assertSame( 2, $order[10]['visitors'], 'Person a counted once across both dishes' );
		$this->assertSame( array( 'Kelaguen', 'Chicken kelaguen' ), $order[10]['names'] );
		$this->assertSame( 0, $order[30]['visitors'] );
		$this->assertTrue( $order[30]['owner'] );
		$this->assertArrayNotHasKey( 50, $order );
	}

	/**
	 * The index from real photos, reviews and menus; closed and draft places are left out.
	 */
	public function test_rebuild_from_content() {
		$proa   = $this->restaurant( 'Proa' );
		$meskla = $this->restaurant( 'Meskla' );
		$closed = $this->restaurant( 'Old Diner' );
		$draft  = $this->restaurant( 'Draft Place', array(), 'draft' );
		update_post_meta( $closed, Restaurant_Meta::STATUS, 'permanently_closed' );

		$this->photo( $proa, 'Chicken Kelaguen', 'Ana' );
		$this->photo( $proa, 'Chicken Kelaguen', 'Owner Friend', true );
		$this->review( $proa, "Chicken kelaguen\nRed rice", 'Ben' );
		$this->review( $meskla, 'Kelaguen', 'Cara' );
		update_post_meta(
			$meskla,
			Owner_Content::MENU_DISHES,
			array(
				array(
					'name'    => 'Shrimp kelaguen',
					'price'   => '$12',
					'details' => '',
				),
			)
		);
		$this->photo( $closed, 'Kelaguen', 'Dan' );
		$this->photo( $draft, 'Kelaguen', 'Eve' );

		$index = Dish_Index::rebuild();
		$this->assertSame( array( $proa, $meskla ), array_keys( $index['places'] ) );
		$this->assertSame( 2, $index['pages']['kelaguen']['places'] );
		$this->assertSame( 1, $index['pages']['red-rice']['places'] );
		$this->assertArrayNotHasKey( Dish_Index::OPTION, wp_load_alloptions(), 'Not autoloaded' );

		$order = Dish_Index::places( $index['places'], 'kelaguen' );
		$this->assertSame( array( $proa, $meskla ), array_keys( $order ) );
		$this->assertSame( 2, $order[ $proa ]['visitors'], 'Ana and Ben; not the connected uploader' );
		$this->assertSame( 1, $order[ $meskla ]['visitors'] );
		$this->assertTrue( $order[ $meskla ]['owner'] );

		$this->assertSame( 'kelaguen', Dish_Index::page_for( 'Chicken Kelaguen' ) );
		$this->assertSame( 'red-rice', Dish_Index::page_for( 'Red rice' ) );
		$this->assertSame( '', Dish_Index::page_for( 'Fried rice' ) );
	}

	/**
	 * Changes schedule one background rebuild.
	 */
	public function test_changes_schedule_rebuild() {
		wp_clear_scheduled_hook( Dish_Index::REBUILD_HOOK );
		$proa = $this->restaurant( 'Proa' );
		wp_clear_scheduled_hook( Dish_Index::REBUILD_HOOK );
		$this->photo( $proa, 'Kelaguen', 'Ana' );
		$this->assertNotFalse( wp_next_scheduled( Dish_Index::REBUILD_HOOK ) );

		wp_clear_scheduled_hook( Dish_Index::REBUILD_HOOK );
		update_post_meta( $proa, 'some_other_key', 'x' );
		$this->assertFalse( wp_next_scheduled( Dish_Index::REBUILD_HOOK ) );

		Dish_Index::rebuild();
		Dish_Index::reset();
		$stored          = get_option( Dish_Index::OPTION );
		$stored['built'] = time() - 2 * DAY_IN_SECONDS;
		update_option( Dish_Index::OPTION, $stored, false );
		Dish_Index::get();
		$this->assertNotFalse( wp_next_scheduled( Dish_Index::REBUILD_HOOK ), 'A day-old index is refreshed' );
	}

	/**
	 * The dish page lists each place with why, a map, and the ordering note; unknown dishes 404.
	 */
	public function test_dish_page() {
		$this->set_permalink_structure( '/%postname%/' );
		Dish_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );

		$proa   = $this->restaurant(
			'Proa',
			array(
				Restaurant_Meta::LATITUDE  => 13.513,
				Restaurant_Meta::LONGITUDE => 144.807,
			)
		);
		$meskla = $this->restaurant( 'Meskla' );
		$this->photo( $proa, 'Chicken Kelaguen', 'Ana' );
		$this->review( $proa, 'Kelaguen', 'Ben' );
		update_post_meta(
			$meskla,
			Owner_Content::MENU_DISHES,
			array(
				array(
					'name'    => 'Kelaguen',
					'price'   => '',
					'details' => '',
				),
			)
		);
		Dish_Index::rebuild();

		$this->assertSame( home_url( '/dishes/kelaguen/' ), Dish_Pages::url( 'kelaguen' ) );
		$this->go_to( Dish_Pages::url( 'kelaguen' ) );
		$this->assertSame( 'dish', Dish_Pages::current_page() );
		$this->assertFalse( is_home() );
		$this->assertSame( 'Where to get Kelaguen on Guam', Dish_Pages::title( Dish_Pages::current_dish() ) );

		$html = Dish_Pages::render_current();
		$this->assertStringContainsString( '2 places serve it', $html );
		$this->assertStringContainsString( 'This is not a ranking of who makes it best', $html );
		$this->assertStringNotContainsString( 'Best ', $html );
		$this->assertStringContainsString( 'Named by 2 visitors · As Chicken Kelaguen', $html );
		$this->assertStringContainsString( 'On the owner&#039;s menu', $html );
		$this->assertStringContainsString( 'data-gr-results-map', $html );
		$this->assertStringContainsString( 'data-gr-point="144.807,13.513"', $html );
		$this->assertLessThan( strpos( $html, 'Meskla' ), strpos( $html, 'Proa' ), 'Visitors first, owner-only next' );
		$this->assertStringStartsWith( 'Kelaguen is chopped chicken', Dish_Pages::description(), 'The dish intro, when there is one.' );

		$this->go_to( home_url( '/dishes/not-a-dish/' ) );
		$this->assertTrue( is_404() );
	}

	/**
	 * The list page puts Guam favorites first; popular dishes link to their page.
	 */
	public function test_index_page_and_links() {
		$this->set_permalink_structure( '/%postname%/' );
		Dish_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );

		$this->go_to( Dish_Pages::url() );
		$this->assertStringContainsString( 'No dishes yet', Dish_Pages::render_current() );

		$proa = $this->restaurant( 'Proa' );
		$this->photo( $proa, 'Red rice', 'Ana' );
		$this->photo( $proa, 'Spam musubi', 'Ben' );
		Dish_Index::rebuild();

		$this->go_to( Dish_Pages::url() );
		$this->assertSame( 'index', Dish_Pages::current_page() );
		$html = Dish_Pages::render_current();
		$this->assertStringContainsString( 'Guam favorites', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/dishes/red-rice/' ) . '">Red rice</a> <span class="gr-dish-index__count">1 place</span>', $html );
		$this->assertStringNotContainsString( 'Spam musubi', $html, 'One place is not enough for other dishes' );

		$block = Display::render( $proa );
		$this->assertStringContainsString( '<a href="' . home_url( '/dishes/red-rice/' ) . '"', $block );
		$this->assertStringContainsString( 'Where to get other Guam dishes', $block );
		$this->assertStringContainsString( '<h3 class="gr-dish__name">Spam musubi</h3>', $block );
	}
}
