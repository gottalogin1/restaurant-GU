<?php
/**
 * Village tagging tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Geo\Village_Tagger;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seeder;

/**
 * Tagging restaurants from coordinates.
 *
 * @covers \GuamRestaurants\Core\Geo\Village_Tagger
 */
class Village_Tagger_Test extends Test_Case {

	/**
	 * Seeds villages.
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_villages();
	}

	/**
	 * A restaurant with coordinates.
	 */
	private function restaurant( float $lat, float $lng, string $source ): int {
		$post_id = self::factory()->post->create( array( 'post_type' => Content_Types::POST_TYPE ) );
		update_post_meta( $post_id, Restaurant_Meta::LATITUDE, $lat );
		update_post_meta( $post_id, Restaurant_Meta::LONGITUDE, $lng );
		update_post_meta( $post_id, Restaurant_Meta::COORD_SOURCE, $source );
		return $post_id;
	}

	/**
	 * Village slugs on a post.
	 *
	 * @return string[]
	 */
	private function villages( int $post_id ): array {
		return wp_get_object_terms( $post_id, Content_Types::TAX_VILLAGE, array( 'fields' => 'slugs' ) );
	}

	/**
	 * A Dededo point gets Dededo; running again changes nothing.
	 */
	public function test_tags_dededo_and_is_idempotent() {
		$post_id = $this->restaurant( 13.5180, 144.8390, 'osm' );
		$tagger  = new Village_Tagger();

		$this->assertSame( Village_Tagger::CREATED, $tagger->tag( $post_id ) );
		$this->assertSame( array( 'dededo' ), $this->villages( $post_id ) );
		$this->assertSame( Village_Tagger::UNCHANGED, $tagger->tag( $post_id ) );
	}

	/**
	 * A wrong village is corrected and the change is logged.
	 */
	public function test_corrects_wrong_village_and_logs_it() {
		global $wpdb;

		$post_id = $this->restaurant( 13.5140, 144.8040, 'owner' );
		wp_set_object_terms( $post_id, 'dededo', Content_Types::TAX_VILLAGE );

		$this->assertSame( Village_Tagger::UPDATED, ( new Village_Tagger() )->tag( $post_id ) );
		$this->assertSame( array( 'tamuning' ), $this->villages( $post_id ) );

		$log = $wpdb->get_row( $wpdb->prepare( 'SELECT old_value, new_value, source FROM ' . Schema::table( Schema::CHANGE_LOG ) . ' WHERE restaurant_id = %d', $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array(
				'old_value' => 'dededo',
				'new_value' => 'tamuning',
				'source'    => 'census',
			),
			$log
		);
	}

	/**
	 * Dry runs report without writing.
	 */
	public function test_dry_run_does_not_write() {
		$post_id = $this->restaurant( 13.5180, 144.8390, 'osm' );

		$this->assertSame( Village_Tagger::CREATED, ( new Village_Tagger() )->tag( $post_id, null, true ) );
		$this->assertSame( array(), $this->villages( $post_id ) );
	}

	/**
	 * Coordinates of unknown origin (for example Google) are never used.
	 */
	public function test_refuses_coordinates_without_allowed_source() {
		$post_id = $this->restaurant( 13.5180, 144.8390, 'google' );

		$this->assertSame( Village_Tagger::FAILED, ( new Village_Tagger() )->tag( $post_id ) );
		$this->assertSame( array(), $this->villages( $post_id ) );
	}

	/**
	 * An offshore point goes to the review queue instead of being guessed.
	 */
	public function test_offshore_point_goes_to_review_queue() {
		global $wpdb;

		$post_id = $this->restaurant( 13.52, 144.70, 'osm' );

		$this->assertSame( Village_Tagger::FAILED, ( new Village_Tagger() )->tag( $post_id ) );
		$this->assertSame( array(), $this->villages( $post_id ) );
		$this->assertSame( 'village_unresolved', $wpdb->get_var( $wpdb->prepare( 'SELECT type FROM ' . Schema::table( Schema::REVIEW_QUEUE ) . ' WHERE restaurant_id = %d', $post_id ) ) ); // phpcs:ignore WordPress.DB
	}
}
