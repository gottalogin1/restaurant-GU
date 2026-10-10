<?php
/**
 * Activation and content model tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Activator;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Deactivator;
use WP_UnitTestCase;

/**
 * Activation, deactivation and content model.
 *
 * @covers \GuamRestaurants\Core\Activator
 * @covers \GuamRestaurants\Core\Deactivator
 * @covers \GuamRestaurants\Core\Content_Types
 */
class Activation_Test extends WP_UnitTestCase {

	/**
	 * Activation stores the version and registers the content model.
	 */
	public function test_activate_records_version_and_registers_content_types() {
		delete_option( 'guam_restaurants_core_version' );

		Activator::activate();

		$this->assertSame( GUAM_RESTAURANTS_CORE_VERSION, get_option( 'guam_restaurants_core_version' ) );
		$this->assertIsInt( get_option( 'guam_restaurants_core_activated_at' ) );
		$this->assertTrue( post_type_exists( Content_Types::POST_TYPE ) );
	}

	/**
	 * Activating twice keeps the first activation time.
	 */
	public function test_activate_is_idempotent() {
		Activator::activate();
		$first = get_option( 'guam_restaurants_core_activated_at' );

		Activator::activate();

		$this->assertSame( $first, get_option( 'guam_restaurants_core_activated_at' ) );
	}

	/**
	 * Restaurants can be tagged by village, mall and cuisine.
	 */
	public function test_taxonomies_attach_to_restaurants() {
		foreach ( array( Content_Types::TAX_VILLAGE, Content_Types::TAX_MALL, Content_Types::TAX_CUISINE ) as $taxonomy ) {
			$this->assertTrue( taxonomy_exists( $taxonomy ), $taxonomy );
			$this->assertContains( Content_Types::POST_TYPE, get_taxonomy( $taxonomy )->object_type, $taxonomy );
		}

		$post_id = self::factory()->post->create( array( 'post_type' => Content_Types::POST_TYPE ) );
		wp_set_object_terms( $post_id, 'Tumon', Content_Types::TAX_VILLAGE );

		$this->assertSame( array( 'Tumon' ), wp_get_object_terms( $post_id, Content_Types::TAX_VILLAGE, array( 'fields' => 'names' ) ) );
	}

	/**
	 * Deactivation keeps options (removal happens on uninstall).
	 */
	public function test_deactivate_keeps_data() {
		Activator::activate();
		Deactivator::deactivate();

		$this->assertSame( GUAM_RESTAURANTS_CORE_VERSION, get_option( 'guam_restaurants_core_version' ) );

		Content_Types::register();
	}
}
