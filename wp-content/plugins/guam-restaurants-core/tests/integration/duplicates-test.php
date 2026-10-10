<?php
/**
 * Duplicate detection and merging.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Awards\Repository;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;
use GuamRestaurants\Core\Tagging\Merger;

/**
 * "Meskla Chamoru Fusion Bistro" (OSM) and "Meskla Chamorro Fusion Bistro"
 * (entered by hand or from an award list) are the same Hagåtña restaurant.
 *
 * @covers \GuamRestaurants\Core\Tagging\Duplicate_Detector
 * @covers \GuamRestaurants\Core\Tagging\Merger
 */
class Duplicates_Test extends Guam_Test_Case {

	/**
	 * The other spelling, entered by hand a few metres from the OSM point.
	 *
	 * @param array<string, mixed> $meta Extra meta.
	 * @return int
	 */
	private function meskla_by_hand( array $meta = array() ): int {
		return $this->restaurant(
			'Meskla Chamorro Fusion Bistro',
			$meta + array(
				Restaurant_Meta::PHONE        => '671-555-0177',
				Restaurant_Meta::LATITUDE     => 13.4770,
				Restaurant_Meta::LONGITUDE    => 144.7531,
				Restaurant_Meta::COORD_SOURCE => 'manual',
				Restaurant_Meta::WEBSITE      => 'https://meskla.example.com',
			)
		);
	}

	/**
	 * Scores: same phone, close and near-identical names merge; without a phone they only queue.
	 */
	public function test_scores() {
		$osm  = array(
			'name'  => 'Meskla Chamoru Fusion Bistro',
			'phone' => '+16715550177',
			'lat'   => 13.4769,
			'lng'   => 144.7531,
		);
		$hand = array(
			'name'  => 'Meskla Chamorro Fusion Bistro',
			'phone' => '+16715550177',
			'lat'   => 13.4770,
			'lng'   => 144.7531,
		);

		$score = Duplicate_Detector::score( $osm, $hand );
		$this->assertTrue( $score['phone_match'] );
		$this->assertLessThan( 20, $score['distance_m'] );
		$this->assertGreaterThanOrEqual( Duplicate_Detector::AUTO_MERGE, $score['confidence'] );
		$this->assertTrue( Duplicate_Detector::is_auto_merge( $score ) );

		$no_phone = Duplicate_Detector::score( $osm, array( 'phone' => '' ) + $hand );
		$this->assertFalse( Duplicate_Detector::is_auto_merge( $no_phone ) );
		$this->assertGreaterThanOrEqual( Duplicate_Detector::REVIEW, $no_phone['confidence'] );

		// Two Tumon neighbours with different names and phones are not duplicates.
		$neighbours = Duplicate_Detector::score(
			array(
				'name'  => 'Proa Restaurant',
				'phone' => '+16715550142',
				'lat'   => 13.5097,
				'lng'   => 144.8044,
			),
			array(
				'name'  => 'Tumon Bay Coffee',
				'phone' => '+16715550150',
				'lat'   => 13.5099,
				'lng'   => 144.8045,
			)
		);
		$this->assertLessThan( Duplicate_Detector::REVIEW, $neighbours['confidence'] );
	}

	/**
	 * A near-certain pair is merged: OSM post kept, the other spelling saved
	 * as a former name, blanks filled, awards moved, other post trashed.
	 */
	public function test_auto_merges_same_place_spelled_two_ways() {
		$this->import();
		$osm  = $this->post_for( 5003 );
		$hand = $this->meskla_by_hand();
		Repository::upsert(
			$hand,
			array(
				'program'     => 'Example Awards',
				'year'        => 2024,
				'category'    => 'Best Chamorro',
				'placement'   => 'winner',
				'source_url'  => 'https://example.com/awards-2024',
				'source_note' => '',
			)
		);

		$result = ( new Duplicate_Detector() )->run();

		$this->assertSame( 1, $result['counts'][ Duplicate_Detector::MERGED ] );
		$this->assertSame( 0, $result['counts'][ Duplicate_Detector::QUEUED ] );
		$this->assertSame( 'trash', get_post_status( $hand ) );
		$this->assertSame( 'publish', get_post_status( $osm ) );
		$this->assertSame( (string) $osm, get_post_meta( $hand, Merger::MERGED_INTO_META, true ) );
		$this->assertSame( array( 'Meskla Chamorro Fusion Bistro' ), Restaurant_Meta::former_names( $osm ) );
		$this->assertSame( 'https://meskla.example.com', get_post_meta( $osm, Restaurant_Meta::WEBSITE, true ), 'Blank website filled.' );
		$this->assertSame( '+16715550177', $this->phone( $osm ) );
		$this->assertCount( 1, $this->rows( Schema::AWARDS, 'restaurant_id = ' . $osm ) );
		$this->assertCount( 1, $this->rows( Schema::CHANGE_LOG, "field = 'merged' AND source = 'system' AND restaurant_id = " . $osm ) );

		$this->assertSame( 0, ( new Duplicate_Detector() )->run()['counts'][ Duplicate_Detector::MERGED ], 'Nothing left to merge.' );
	}

	/**
	 * A weaker pair goes to the review queue once; "not the same place" is remembered.
	 */
	public function test_uncertain_pair_is_queued_and_rejection_is_remembered() {
		$this->import();
		$osm   = $this->post_for( 5003 );
		$draft = $this->restaurant( 'Meskla Chamorro Fusion Bistro', array(), 'draft' ); // From an award list: no phone, no coordinates.

		$first = ( new Duplicate_Detector() )->run();
		$this->assertSame( 1, $first['counts'][ Duplicate_Detector::QUEUED ] );
		$this->assertSame( 'publish', get_post_status( $osm ) );
		$this->assertSame( 'draft', get_post_status( $draft ) );

		$items = $this->rows( Schema::REVIEW_QUEUE, "type = 'duplicate' AND status = 'pending'" );
		$this->assertCount( 1, $items );
		$payload = json_decode( $items[0]['payload'], true );
		$this->assertSame( array( $osm, $draft ), array( (int) $items[0]['restaurant_id'], $payload['duplicate_of'] ) );
		$this->assertNull( $payload['phone_match'] );

		$this->assertSame( 0, ( new Duplicate_Detector() )->run()['counts'][ Duplicate_Detector::QUEUED ], 'Queued once while pending.' );

		$admin = $this->login_admin();
		$this->assertTrue( Review_Queue_Screen::decide( (int) $items[0]['id'], 'not_duplicate', $admin ) );
		$this->assertSame( 0, ( new Duplicate_Detector() )->run()['counts'][ Duplicate_Detector::QUEUED ], 'Not queued again after "not the same place".' );
	}

	/**
	 * An administrator can merge a queued pair from the review queue.
	 */
	public function test_merge_from_review_queue() {
		$this->import();
		$osm   = $this->post_for( 5003 );
		$draft = $this->restaurant( 'Meskla Chamorro Fusion Bistro', array( Restaurant_Meta::OPENED_ON => '2016' ), 'draft' );
		( new Duplicate_Detector() )->run();
		$item = $this->rows( Schema::REVIEW_QUEUE, "type = 'duplicate'" )[0];

		$this->assertFalse( Review_Queue_Screen::decide( (int) $item['id'], 'merge', self::factory()->user->create( array( 'role' => 'editor' ) ) ), 'Editors cannot decide.' );
		$this->assertTrue( Review_Queue_Screen::decide( (int) $item['id'], 'merge', $this->login_admin() ) );

		$this->assertSame( 'trash', get_post_status( $draft ) );
		$this->assertSame( '2016', get_post_meta( $osm, Restaurant_Meta::OPENED_ON, true ) );
		$this->assertSame( 'merged', $this->rows( Schema::REVIEW_QUEUE, 'id = ' . (int) $item['id'] )[0]['status'] );
		$this->assertCount( 1, $this->rows( Schema::CHANGE_LOG, "field = 'merged' AND source = 'editor'" ) );
	}

	/**
	 * Two branches of a chain with the same name, far apart with their own phones, are left alone.
	 */
	public function test_chain_branches_are_not_duplicates() {
		$this->import();

		$result = ( new Duplicate_Detector() )->run( true );

		$this->assertSame( array(), $result['pairs'] );
		$this->assertNotSame( $this->post_for( 5005 ), $this->post_for( 5006 ) );
		$this->assertSame( 'publish', get_post_status( $this->post_for( 5006 ) ) );
	}

	/**
	 * The kept post is the OSM one even when the other is older; the OSM id moves when only the other has one.
	 */
	public function test_merge_keeps_the_osm_post() {
		$older = $this->restaurant( 'Proa', array( Restaurant_Meta::PHONE => '671-555-0142' ) );
		$this->import();
		$osm = $this->post_for( 5002 );

		$this->assertSame( array( $osm, $older ), Merger::choose( $older, $osm ) );

		$no_osm = $this->restaurant( 'Proa Restaurant Tumon' );
		$result = ( new Merger() )->merge( $no_osm, $osm );
		$this->assertSame( $osm, $result['kept_id'] );
		$this->assertSame( array( 'chamorro' ), $this->terms( $osm, Content_Types::TAX_CUISINE ) );
	}
}
