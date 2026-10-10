<?php
/**
 * Seeding and mall verification tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Seeder;
use GuamRestaurants\Core\Term_Meta;
use WP_REST_Request;

/**
 * Seed lists and mall visibility.
 *
 * @covers \GuamRestaurants\Core\Seeder
 * @covers \GuamRestaurants\Core\Seed_Data
 * @covers \GuamRestaurants\Core\Mall_Verification
 */
class Seeder_Test extends Test_Case {

	/**
	 * Term count in a taxonomy.
	 */
	private function count_terms( string $taxonomy ): int {
		return (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
	}

	/**
	 * A mall by slug, verified or not.
	 */
	private function mall( string $slug ): \WP_Term {
		$terms = get_terms(
			array(
				'taxonomy'                                => Content_Types::TAX_MALL,
				'slug'                                    => $slug,
				'hide_empty'                              => false,
				Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
			)
		);
		return $terms[0];
	}

	/**
	 * Seeding creates every term once, and again creates nothing.
	 */
	public function test_seed_all_is_idempotent() {
		$first = Seeder::seed_all();

		$this->assertSame( 19, $first['villages']['created'] );
		$this->assertSame( 6, $first['malls']['created'] );
		$this->assertSame( 17, $first['cuisines']['created'] );
		$this->assertSame( 4, $first['price_levels']['created'] );

		$second = Seeder::seed_all();

		foreach ( $second as $name => $counts ) {
			$this->assertSame( 0, $counts['created'], $name );
			$this->assertSame( 0, $counts['failed'], $name );
		}
		$this->assertSame( 19, $this->count_terms( Content_Types::TAX_VILLAGE ) );
		$this->assertSame( 17, $this->count_terms( Content_Types::TAX_CUISINE ) );
	}

	/**
	 * Villages carry their Census GEOID and aliases; Tamuning has Tumon.
	 */
	public function test_village_meta() {
		Seeder::seed_villages();
		$tamuning = get_term_by( 'slug', 'tamuning', Content_Types::TAX_VILLAGE );

		$this->assertSame( '6601071600', get_term_meta( $tamuning->term_id, Term_Meta::CENSUS_GEOID, true ) );
		$this->assertContains( 'Tumon', get_term_meta( $tamuning->term_id, Term_Meta::ALIASES, true ) );
	}

	/**
	 * Re-seeding keeps an editor's changes to aliases.
	 */
	public function test_reseed_keeps_edited_meta() {
		Seeder::seed_villages();
		$tamuning = get_term_by( 'slug', 'tamuning', Content_Types::TAX_VILLAGE );
		update_term_meta( $tamuning->term_id, Term_Meta::ALIASES, array( 'Tumon' ) );

		Seeder::seed_villages();

		$this->assertSame( array( 'Tumon' ), get_term_meta( $tamuning->term_id, Term_Meta::ALIASES, true ) );
	}

	/**
	 * All malls start unverified and hidden from visitors, but editors see them.
	 */
	public function test_malls_start_unverified_and_hidden() {
		Seeder::seed_all();
		$args = array(
			'taxonomy'   => Content_Types::TAX_MALL,
			'hide_empty' => false,
			'fields'     => 'slugs',
		);

		wp_set_current_user( 0 );
		$this->assertSame( array(), get_terms( $args ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/' . Content_Types::TAX_MALL ) );
		$this->assertSame( array(), $response->get_data() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertCount( 6, get_terms( $args ) );
	}

	/**
	 * Verifying a mall shows it to visitors and records who and when.
	 */
	public function test_verifying_a_mall() {
		Seeder::seed_all();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$mall   = $this->mall( 'micronesia-mall' );

		Mall_Verification::set_verified( $mall->term_id, true, $editor );

		$this->assertSame( $editor, (int) get_term_meta( $mall->term_id, Term_Meta::VERIFIED_BY, true ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', get_term_meta( $mall->term_id, Term_Meta::VERIFIED_ON, true ) );

		wp_set_current_user( 0 );
		$this->assertSame(
			array( 'micronesia-mall' ),
			get_terms(
				array(
					'taxonomy'   => Content_Types::TAX_MALL,
					'hide_empty' => false,
					'fields'     => 'slugs',
				)
			)
		);

		// Re-seeding never flips a verified mall back.
		Seeder::seed_malls();
		$this->assertTrue( Mall_Verification::is_verified( $mall->term_id ) );
	}

	/**
	 * Malls point at the village they were guessed to be in.
	 */
	public function test_mall_village_guess() {
		Seeder::seed_all();
		$mall    = $this->mall( 'guam-premier-outlets' );
		$village = get_term( (int) get_term_meta( $mall->term_id, Term_Meta::MALL_VILLAGE, true ) );

		$this->assertSame( 'tamuning', $village->slug );
	}

	/**
	 * Cuisine is hierarchical and price levels sort by rank.
	 */
	public function test_cuisine_and_price() {
		Seeder::seed_all();

		$this->assertTrue( is_taxonomy_hierarchical( Content_Types::TAX_CUISINE ) );
		$this->assertSame( array( 'chamorro' ), get_term_meta( get_term_by( 'slug', 'chamorro', Content_Types::TAX_CUISINE )->term_id, Term_Meta::OSM_CUISINE, true ) );
		$this->assertSame( '4', get_term_meta( get_term_by( 'slug', 'p4', Content_Types::TAX_PRICE )->term_id, Term_Meta::RANK, true ) );
	}
}
