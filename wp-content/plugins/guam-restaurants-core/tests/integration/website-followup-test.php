<?php
/**
 * Website follow-up list tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Website_Followup_Screen;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;

/**
 * Which restaurants need a website, and saving follow-ups.
 *
 * @covers \GuamRestaurants\Core\Admin\Website_Followup_Screen
 */
class Website_Followup_Test extends Test_Case {

	/**
	 * Creates a restaurant.
	 *
	 * @param string               $name   Name.
	 * @param array<string, mixed> $meta   Meta.
	 * @param string               $status Post status.
	 */
	private function restaurant( string $name, array $meta = array(), string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => $status,
				'post_title'  => $name,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * No website or only a social page is listed; real websites, closed and trashed places are not.
	 */
	public function test_lists_restaurants_without_their_own_website() {
		$none     = $this->restaurant( 'A Place', array( Restaurant_Meta::PHONE => '+16716461234' ) );
		$facebook = $this->restaurant( 'B Place', array( Restaurant_Meta::WEBSITE => 'https://www.facebook.com/bplace' ) );
		$draft    = $this->restaurant( 'C Place', array(), 'draft' );
		$this->restaurant( 'D Place', array( Restaurant_Meta::WEBSITE => 'https://dplace.example/' ) );
		$this->restaurant( 'E Place', array( Restaurant_Meta::STATUS => 'permanently_closed' ) );
		wp_trash_post( $this->restaurant( 'F Place' ) );

		$this->assertSame( array( $none, $facebook, $draft ), Website_Followup_Screen::restaurants( 'all' ) );
	}

	/**
	 * Follow-up status and note are saved; signed-up and declined restaurants leave the open list.
	 */
	public function test_save_and_filter() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$a     = $this->restaurant( 'A Place' );
		$b     = $this->restaurant( 'B Place' );
		$c     = $this->restaurant( 'C Place' );

		$this->assertTrue( Website_Followup_Screen::save( $a, 'proposal_sent', 'Sent quote 10/19', $admin ) );
		$this->assertTrue( Website_Followup_Screen::save( $b, 'not_interested', '', $admin ) );
		$this->assertSame( 'Sent quote 10/19', get_post_meta( $a, Website_Followup_Screen::NOTE_META, true ) );

		$this->assertSame( array( $a, $c ), Website_Followup_Screen::restaurants( 'open' ) );
		$this->assertSame( array( $a ), Website_Followup_Screen::restaurants( 'proposal_sent' ) );
		$this->assertSame( array( $c ), Website_Followup_Screen::restaurants( '' ) );

		// Unknown statuses and non-admins are refused.
		$this->assertFalse( Website_Followup_Screen::save( $a, 'bogus', '', $admin ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( Website_Followup_Screen::save( $a, 'contacted', '', $subscriber ) );

		// Back to "To contact" clears the status.
		$this->assertTrue( Website_Followup_Screen::save( $b, '', '', $admin ) );
		$this->assertSame( '', get_post_meta( $b, Website_Followup_Screen::STATUS_META, true ) );
	}

	/**
	 * The Google Maps link opens the place itself when its place ID is known.
	 */
	public function test_maps_url() {
		$id = $this->restaurant( 'A Place' );
		$this->assertStringContainsString( 'query=A%20Place%2C%20Guam', Website_Followup_Screen::maps_url( $id, 'A Place', '' ) );
		update_post_meta( $id, Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJabc' );
		$this->assertStringContainsString( 'query_place_id=ChIJabc', Website_Followup_Screen::maps_url( $id, 'A Place', 'Tamuning' ) );
	}

	/**
	 * The screen renders for an administrator.
	 */
	public function test_render() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->restaurant( 'Rendered Place', array( Restaurant_Meta::EMAIL => 'owner@rendered.example' ) );
		ob_start();
		Website_Followup_Screen::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Rendered Place', $html );
		$this->assertStringContainsString( 'mailto:owner@rendered.example', $html );
		$this->assertStringContainsString( 'name="followup_status"', $html );
	}
}
