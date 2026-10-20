<?php
/**
 * Branch name tests: restaurants that share a name are titled "Name, Village".
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Tagging\Branch_Names;

/**
 * Branch names.
 *
 * @covers \GuamRestaurants\Core\Tagging\Branch_Names
 * @covers \GuamRestaurants\Core\Names
 */
class Branch_Names_Test extends Guam_Test_Case {

	/**
	 * A restaurant in a village.
	 *
	 * @param string $title   Name.
	 * @param string $village Village slug, or '' for none.
	 * @param string $status  Post status.
	 * @return int
	 */
	private function in_village( string $title, string $village, string $status = 'publish' ): int {
		$id = $this->restaurant( $title, array(), $status );
		if ( '' !== $village ) {
			// As an import would, so the village hook does not rename during setup.
			Change_Tracker::suppress(
				static function () use ( $id, $village ) {
					wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
				}
			);
		}
		return $id;
	}

	/**
	 * Title as stored.
	 *
	 * @param int $id Restaurant.
	 * @return string
	 */
	private function title( int $id ): string {
		return html_entity_decode( (string) get_post_field( 'post_title', $id, 'raw' ), ENT_QUOTES );
	}

	/**
	 * Pending branch-village requests for a restaurant.
	 *
	 * @param int $id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	private function flags( int $id ): array {
		return array_values(
			array_filter(
				Review_Queue::pending( 'village_unresolved', 500 ),
				static function ( $item ) use ( $id ) {
					return (int) $item['restaurant_id'] === $id && Branch_Names::QUEUE_REASON === ( $item['payload']['reason'] ?? '' );
				}
			)
		);
	}

	/**
	 * Matching ignores a village suffix, and only a real village counts as one.
	 */
	public function test_name_key_ignores_village_suffix() {
		$this->assertSame( Names::key( "McDonald's" ), Names::key( "McDonald's, Mangilao" ) );
		$this->assertSame( Names::key( 'Kings' ), Names::key( 'Kings, Hagåtña' ) );
		$this->assertSame( Names::key( 'Kings' ), Names::key( 'Kings , Tumon' ), 'Aliases count.' );
		$this->assertSame( Names::key( 'Kings' ), Names::key( 'Kings, Hågat' ), 'Chamorro names count.' );
		$this->assertSame( 'pizza pasta', Names::key( 'Pizza, Pasta' ), 'Other text after a comma is kept.' );
		$this->assertSame( array( 'Ben &amp; Jerry', 'Tamuning' ), Names::split_village( 'Ben &amp; Jerry, Tamuning' ) );
		$this->assertSame( array( 'Tamuning', '' ), Names::split_village( 'Tamuning' ) );
	}

	/**
	 * Shared names get their village; a name of its own is left alone; a
	 * branch with no village is flagged once and renamed when it gets one.
	 */
	public function test_branches_are_named_by_village() {
		$mangilao = $this->in_village( "McDonald's", 'mangilao' );
		$dededo   = $this->in_village( "McDonald's", 'dededo', 'draft' );
		$unknown  = $this->in_village( "McDonald's", '' );
		$kings    = $this->in_village( 'Kings', 'tamuning' );
		$trashed  = $this->in_village( 'Kings', 'yigo', 'trash' );

		$dry = Branch_Names::apply( true );
		$this->assertCount( 2, $dry['renamed'] );
		$this->assertSame( "McDonald's", $this->title( $mangilao ), 'A dry run changes nothing.' );
		$this->assertSame( array(), $this->flags( $unknown ) );

		$result = Branch_Names::apply();
		$this->assertSame( "McDonald's, Mangilao", $this->title( $mangilao ) );
		$this->assertSame( "McDonald's, Dededo", $this->title( $dededo ) );
		$this->assertSame( "McDonald's", $this->title( $unknown ) );
		$this->assertSame( array( $unknown ), $result['no_village'] );
		$this->assertCount( 1, $this->flags( $unknown ) );
		$this->assertSame( 'Kings', $this->title( $kings ), 'A trashed listing does not count as a branch.' );
		$this->assertSame( 'Kings', $this->title( $trashed ) );

		$again = Branch_Names::apply();
		$this->assertSame( array(), $again['renamed'], 'Running again changes nothing.' );
		$this->assertCount( 1, $this->flags( $unknown ), 'A branch is flagged once.' );

		wp_set_object_terms( $unknown, 'yigo', Content_Types::TAX_VILLAGE );
		$this->assertSame( "McDonald's, Yigo", $this->title( $unknown ), 'Setting the village renames it.' );
		$this->assertSame( array(), $this->flags( $unknown ), 'And closes its review item.' );

		wp_set_object_terms( $mangilao, 'yona', Content_Types::TAX_VILLAGE );
		$this->assertSame( "McDonald's, Yona", $this->title( $mangilao ), 'A new village replaces the old suffix.' );
	}

	/**
	 * Two branches in one village keep the same name and are reported.
	 */
	public function test_same_village_is_reported() {
		$a      = $this->in_village( 'Kings', 'tamuning' );
		$b      = $this->in_village( 'Kings', 'tamuning' );
		$result = Branch_Names::apply();
		$this->assertSame( array( $a, $b ), $result['same_village'] );
		$this->assertSame( 'Kings, Tamuning', $this->title( $b ) );
	}

	/**
	 * Adding a discovered place with an existing name names both branches.
	 */
	public function test_review_queue_add_names_branches() {
		$admin    = $this->login_admin();
		$existing = $this->in_village( "McDonald's", 'mangilao' );
		$item     = Review_Queue::enqueue(
			'new_place',
			array(
				'place_id' => 'P-MCD',
				'found_by' => array(
					'kind'  => 'village',
					'slug'  => 'dededo',
					'query' => 'restaurants in Dededo, Guam',
				),
			),
			'google'
		);

		$post_id = Review_Queue_Screen::add_restaurant( $item, "McDonald's", $admin );

		$this->assertSame( "McDonald's, Dededo", $this->title( $post_id ) );
		$this->assertSame( "McDonald's, Mangilao", $this->title( $existing ) );
	}

	/**
	 * The OSM import names same-name places by village, and a later run keeps
	 * the suffix instead of resetting the title to the OSM name.
	 */
	public function test_osm_import_keeps_branch_names() {
		$result = $this->import();
		$first  = $this->post_for( 5005 );
		$second = $this->post_for( 5006 );

		$this->assertCount( 2, $result['branch_names']['renamed'] );
		$this->assertSame( 'Jamaican Grill, Tamuning', $this->title( $first ) );
		$this->assertStringStartsWith( 'Jamaican Grill, ', $this->title( $second ) );
		$this->assertNotSame( $this->title( $first ), $this->title( $second ) );

		$again = $this->import();
		$this->assertSame( 0, $again['counts']['updated'], 'The suffix is not seen as a change.' );
		$this->assertSame( array(), $again['branch_names']['renamed'] );
		$this->assertSame( 'Jamaican Grill, Tamuning', $this->title( $first ) );
	}
}
