<?php
/**
 * Village alias search tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Seeder;
use GuamRestaurants\Core\Village_Aliases;
use WP_REST_Request;

/**
 * Tumon and other aliases find their village.
 *
 * @covers \GuamRestaurants\Core\Village_Aliases
 */
class Village_Aliases_Test extends Test_Case {

	/**
	 * Seeds villages.
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_villages();
	}

	/**
	 * Village term ID by slug.
	 */
	private function village( string $slug ): int {
		return (int) get_term_by( 'slug', $slug, Content_Types::TAX_VILLAGE )->term_id;
	}

	/**
	 * Tumon is an alias of Tamuning, not a term of its own.
	 */
	public function test_tumon_is_an_alias_of_tamuning() {
		$this->assertFalse( get_term_by( 'slug', 'tumon', Content_Types::TAX_VILLAGE ) );

		$match = Village_Aliases::extract( 'Tumon' );

		$this->assertSame( $this->village( 'tamuning' ), $match['village_id'] );
		$this->assertSame( '', $match['search'] );
	}

	/**
	 * The alias is removed and the rest of the search is kept.
	 */
	public function test_extract_keeps_other_words() {
		$match = Village_Aliases::extract( 'sushi in Upper Tumon' );

		$this->assertSame( $this->village( 'tamuning' ), $match['village_id'] );
		$this->assertSame( 'sushi in', $match['search'] );
	}

	/**
	 * Longer names win, accents and case are ignored.
	 */
	public function test_matching_is_accent_and_case_insensitive_and_prefers_longer_names() {
		$this->assertSame( $this->village( 'agana-heights' ), Village_Aliases::extract( 'AGANA HEIGHTS' )['village_id'] );
		$this->assertSame( $this->village( 'hagatna' ), Village_Aliases::extract( 'agana' )['village_id'] );
		$this->assertSame( $this->village( 'hagatna' ), Village_Aliases::extract( 'Hagåtña' )['village_id'] );
		$this->assertSame( $this->village( 'merizo' ), Village_Aliases::extract( 'malesso' )['village_id'] );
	}

	/**
	 * Words inside other words do not match.
	 */
	public function test_no_partial_word_matches() {
		$this->assertSame( 0, Village_Aliases::extract( 'tumonese pizza' )['village_id'] );
		$this->assertSame( 0, Village_Aliases::extract( 'pizza' )['village_id'] );
	}

	/**
	 * REST search for "tumon" returns Tamuning restaurants only.
	 */
	public function test_rest_search_by_alias() {
		$tamuning = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => 'Beachside Grill',
			)
		);
		$dededo   = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => 'Northern Kitchen',
			)
		);
		wp_set_object_terms( $tamuning, 'tamuning', Content_Types::TAX_VILLAGE );
		wp_set_object_terms( $dededo, 'dededo', Content_Types::TAX_VILLAGE );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Content_Types::POST_TYPE );
		$request->set_param( 'search', 'tumon' );
		$ids = wp_list_pluck( rest_get_server()->dispatch( $request )->get_data(), 'id' );

		$this->assertSame( array( $tamuning ), $ids );
	}

	/**
	 * Front-end search for "tumon" stays a search and lists Tamuning restaurants.
	 */
	public function test_front_end_search_by_alias() {
		$post = self::factory()->post->create(
			array(
				'post_type'  => Content_Types::POST_TYPE,
				'post_title' => 'Beachside Grill',
			)
		);
		wp_set_object_terms( $post, 'tamuning', Content_Types::TAX_VILLAGE );

		$this->go_to( home_url( '/?s=tumon' ) );

		$this->assertTrue( is_search() );
		$this->assertSame( array( $post ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
	}
}
