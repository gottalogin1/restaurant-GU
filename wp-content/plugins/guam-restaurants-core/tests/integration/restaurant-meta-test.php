<?php
/**
 * Restaurant meta tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use WP_REST_Request;

/**
 * Meta registration, sanitizing and REST exposure.
 *
 * @covers \GuamRestaurants\Core\Restaurant_Meta
 */
class Restaurant_Meta_Test extends Test_Case {

	/**
	 * Every requested key is registered on the restaurant post type with REST support.
	 */
	public function test_all_keys_registered_with_rest_schema() {
		$registered = get_registered_meta_keys( 'post', Content_Types::POST_TYPE );

		foreach ( array_keys( Restaurant_Meta::fields() ) as $key ) {
			$this->assertArrayHasKey( $key, $registered, $key );
			$this->assertNotEmpty( $registered[ $key ]['show_in_rest'], $key );
		}
		$this->assertCount( 15, Restaurant_Meta::fields() );
	}

	/**
	 * Guam phone numbers normalize to E.164.
	 */
	public function test_phone_normalization() {
		$this->assertSame( '+16716461234', Restaurant_Meta::sanitize_phone( '646-1234' ) );
		$this->assertSame( '+16716461234', Restaurant_Meta::sanitize_phone( '(671) 646-1234' ) );
		$this->assertSame( '+16716461234', Restaurant_Meta::sanitize_phone( '+1 671 646 1234' ) );
		$this->assertSame( '', Restaurant_Meta::sanitize_phone( '808 555 1234' ), 'Not a Guam number' );
		$this->assertSame( '', Restaurant_Meta::sanitize_phone( 'call us' ) );
	}

	/**
	 * Partial dates keep their precision; impossible dates are dropped.
	 */
	public function test_partial_dates() {
		$this->assertSame( '2019', Restaurant_Meta::sanitize_partial_date( '2019' ) );
		$this->assertSame( '2019-06', Restaurant_Meta::sanitize_partial_date( '2019-06' ) );
		$this->assertSame( '2019-06-30', Restaurant_Meta::sanitize_partial_date( ' 2019-06-30 ' ) );
		$this->assertSame( '', Restaurant_Meta::sanitize_partial_date( '2019-02-30' ) );
		$this->assertSame( '', Restaurant_Meta::sanitize_partial_date( '2019-13' ) );
		$this->assertSame( '', Restaurant_Meta::sanitize_partial_date( 'June 2019' ) );
	}

	/**
	 * OSM ids carry their element type.
	 */
	public function test_osm_id() {
		$this->assertSame( 'node/123', Restaurant_Meta::sanitize_osm_id( 'Node/123' ) );
		$this->assertSame( '', Restaurant_Meta::sanitize_osm_id( '123' ) );
		$this->assertSame( '', Restaurant_Meta::sanitize_osm_id( 'area/123' ) );
	}

	/**
	 * Coordinates out of range become null.
	 */
	public function test_coordinates() {
		$this->assertSame( 13.518, Restaurant_Meta::sanitize_latitude( '13.518' ) );
		$this->assertNull( Restaurant_Meta::sanitize_latitude( '91' ) );
		$this->assertNull( Restaurant_Meta::sanitize_longitude( 'east' ) );
		$this->assertNull( Restaurant_Meta::sanitize_longitude( '' ) );
	}

	/**
	 * Hours keep valid intervals, including past-midnight closes, and drop the rest.
	 */
	public function test_hours() {
		$hours = Restaurant_Meta::sanitize_hours(
			array(
				'weekly'  => array(
					array(
						'day'   => 'fr',
						'open'  => '17:00',
						'close' => '02:00',
					),
					array(
						'day'   => 'xx',
						'open'  => '09:00',
						'close' => '17:00',
					),
					array(
						'day'   => 'mo',
						'open'  => '25:00',
						'close' => '17:00',
					),
				),
				'note'    => '<b>Holiday hours vary</b>',
				'source'  => 'google',
				'unknown' => 'dropped',
			)
		);

		$this->assertSame(
			array(
				'weekly' => array(
					array(
						'day'   => 'fr',
						'open'  => '17:00',
						'close' => '02:00',
					),
				),
				'note'   => 'Holiday hours vary',
			),
			$hours
		);
	}

	/**
	 * Status defaults to unknown and rejects other values.
	 */
	public function test_status() {
		$post_id = self::factory()->post->create( array( 'post_type' => Content_Types::POST_TYPE ) );

		$this->assertSame( 'unknown', get_post_meta( $post_id, Restaurant_Meta::STATUS, true ) );

		update_post_meta( $post_id, Restaurant_Meta::STATUS, 'closed_forever' );
		$this->assertSame( 'unknown', get_post_meta( $post_id, Restaurant_Meta::STATUS, true ) );

		update_post_meta( $post_id, Restaurant_Meta::STATUS, 'temporarily_closed' );
		$this->assertSame( 'temporarily_closed', get_post_meta( $post_id, Restaurant_Meta::STATUS, true ) );
	}

	/**
	 * Private fields stay out of the public REST response and appear for editors.
	 */
	public function test_private_fields_only_in_edit_context() {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, Restaurant_Meta::PHONE, '646-1234' );
		update_post_meta( $post_id, Restaurant_Meta::YELP_ID, 'abc-123' );
		update_post_meta( $post_id, Restaurant_Meta::CLAIMED_BY_USER_ID, 7 );
		update_post_meta( $post_id, Restaurant_Meta::LATITUDE, 13.518 );

		$public = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/' . Content_Types::POST_TYPE . '/' . $post_id ) )->get_data();

		$this->assertSame( '+16716461234', $public['meta'][ Restaurant_Meta::PHONE ] );
		$this->assertEquals( 13.518, $public['meta'][ Restaurant_Meta::LATITUDE ] );
		$this->assertEquals( 0, $public['meta'][ Restaurant_Meta::LONGITUDE ], 'Unset coordinates read as 0 over REST' );
		foreach ( array( Restaurant_Meta::YELP_ID, Restaurant_Meta::GOOGLE_PLACE_ID, Restaurant_Meta::CLAIMED_BY_USER_ID, Restaurant_Meta::COORD_SOURCE ) as $key ) {
			$this->assertArrayNotHasKey( $key, $public['meta'], $key );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Content_Types::POST_TYPE . '/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$edit = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 'abc-123', $edit['meta'][ Restaurant_Meta::YELP_ID ] );
		$this->assertSame( 7, $edit['meta'][ Restaurant_Meta::CLAIMED_BY_USER_ID ] );
	}

	/**
	 * Editors can write meta through REST; schema validation rejects bad values.
	 */
	public function test_rest_write_validates() {
		$post_id = self::factory()->post->create( array( 'post_type' => Content_Types::POST_TYPE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Content_Types::POST_TYPE . '/' . $post_id );
		$request->set_body_params(
			array(
				'meta' => array(
					Restaurant_Meta::STATUS    => 'open',
					Restaurant_Meta::OPENED_ON => '2019-06',
					Restaurant_Meta::HOURS     => array(
						'weekly' => array(
							array(
								'day'   => 'mo',
								'open'  => '09:00',
								'close' => '17:00',
							),
						),
					),
				),
			)
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );
		$this->assertSame( '2019-06', get_post_meta( $post_id, Restaurant_Meta::OPENED_ON, true ) );

		$request->set_body_params( array( 'meta' => array( Restaurant_Meta::STATUS => 'gone' ) ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Subscribers cannot edit restaurant meta.
	 */
	public function test_subscribers_cannot_edit() {
		$post_id = self::factory()->post->create( array( 'post_type' => Content_Types::POST_TYPE ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( current_user_can( 'edit_post_meta', $post_id, Restaurant_Meta::PHONE ) );
	}
}
