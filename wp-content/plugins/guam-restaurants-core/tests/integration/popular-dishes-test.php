<?php
/**
 * Popular dishes tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Ads\Slot;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Dishes\Display;
use GuamRestaurants\Core\Dishes\Popular_Dishes;
use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Highlights\Policy;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;
use GuamRestaurants\Core\Site\Pages;

/**
 * Matching dish names, ranking, the owner's menu dishes, dishes named in
 * reviews, and the rotating sponsored spot before the list.
 *
 * @covers \GuamRestaurants\Core\Dishes\Popular_Dishes
 * @covers \GuamRestaurants\Core\Dishes\Display
 * @covers \GuamRestaurants\Core\Ads\Slot
 */
class Popular_Dishes_Test extends Guam_Test_Case {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Logs in nobody and makes an administrator for moderation.
	 */
	public function set_up() {
		parent::set_up();
		Server::reset();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Forgets printed ads.
	 */
	public function tear_down() {
		Server::reset();
		parent::tear_down();
	}

	/**
	 * A mention for rank().
	 *
	 * @param string $dish      Dish.
	 * @param string $person    Person.
	 * @param int    $rating    Rating.
	 * @param string $source    photo or review.
	 * @param bool   $connected Connected.
	 * @param string $date      Date.
	 * @return array<string, mixed>
	 */
	private function mention( string $dish, string $person, int $rating = 0, string $source = 'photo', bool $connected = false, string $date = '2026-10-01 12:00:00' ): array {
		return array(
			'dish'      => $dish,
			'source'    => $source,
			'person'    => $person,
			'rating'    => $rating,
			'connected' => $connected,
			'photo'     => 0,
			'date'      => $date,
		);
	}

	/**
	 * An approved photo submission (meta only; no file needed).
	 *
	 * @param int    $restaurant Restaurant.
	 * @param string $dish       Dish.
	 * @param int    $rating     Rating.
	 * @param string $name       Uploader.
	 * @param bool   $connected  Connected.
	 * @return int
	 */
	private function photo( int $restaurant, string $dish, int $rating, string $name, bool $connected = false ): int {
		$id = self::factory()->attachment->create(
			array(
				'post_parent'    => $restaurant,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $dish,
			)
		);
		update_post_meta( $id, Photo_Submissions::STATUS, Photo_Submissions::APPROVED );
		update_post_meta( $id, Photo_Submissions::DISH, $dish );
		update_post_meta( $id, Photo_Submissions::RATING, $connected ? 0 : $rating );
		update_post_meta( $id, Photo_Submissions::NAME, $name );
		update_post_meta( $id, Photo_Submissions::CONNECTED, $connected ? 1 : 0 );
		return $id;
	}

	/**
	 * An approved visitor review naming dishes.
	 *
	 * @param int         $restaurant Restaurant.
	 * @param string      $dishes     Dishes, one per line.
	 * @param string      $name       Reviewer.
	 * @param string|null $disclosure Connection.
	 * @return int
	 */
	private function review( int $restaurant, string $dishes, string $name, ?string $disclosure = null ): int {
		$id = Highlights::insert(
			array(
				'restaurant_id' => $restaurant,
				'type'          => Visitor_Reviews::TYPE,
				'rating'        => 4,
				'body'          => 'Good food and friendly staff, we will be back soon.',
				'author_name'   => $name,
				'disclosure'    => $disclosure,
				'dishes'        => $dishes,
			)
		);
		$this->assertIsInt( $id );
		$this->assertTrue( Highlights::moderate( $id, 'approved', '', $this->admin ) );
		return $id;
	}

	/**
	 * Spelling, case, accents, punctuation and plurals match.
	 */
	public function test_key_matches_spellings() {
		$this->assertSame( 'chicken kelaguen', Popular_Dishes::key( 'Chicken Kelaguen' ) );
		$this->assertSame( Popular_Dishes::key( 'Chicken Kelaguen' ), Popular_Dishes::key( '  chicken   kelaguen! ' ) );
		$this->assertSame( Popular_Dishes::key( 'Hot wings' ), Popular_Dishes::key( 'Hot Wing' ) );
		$this->assertSame( Popular_Dishes::key( 'Mac & cheese' ), Popular_Dishes::key( 'Mac and Cheese' ) );
		$this->assertSame( Popular_Dishes::key( 'Hagåtña special' ), Popular_Dishes::key( 'Hagatna Special' ) );
		$this->assertSame( 'bass', Popular_Dishes::key( 'Bass' ), 'A double s is not a plural' );
		$this->assertSame( '', Popular_Dishes::key( ' !! ' ) );
	}

	/**
	 * People count once, connected people never count, ties go to more
	 * ratings, then the higher average, then the name.
	 */
	public function test_rank_counts_people_not_posts() {
		$ranked = Popular_Dishes::rank(
			array(
				// One person with three photos of the same dish counts once.
				$this->mention( 'Spam musubi', 'name:ana', 5 ),
				$this->mention( 'Spam musubi', 'name:ana', 5 ),
				$this->mention( 'spam musubi', 'name:ana', 5 ),
				// Two people for kelaguen, one through a review.
				$this->mention( 'Chicken kelaguen', 'name:ben', 4 ),
				$this->mention( 'chicken kelaguen', 'user:7', 0, 'review' ),
				// The owner's cousin cannot push red rice up.
				$this->mention( 'Red rice', 'name:cousin', 5, 'photo', true ),
				$this->mention( 'Red rice', 'name:cousin2', 5, 'review', true ),
				$this->mention( 'Red rice', 'name:dee', 3 ),
				// Tie on people: more ratings first, then the higher average, then the name.
				$this->mention( 'Kadon pika', 'name:eve', 2 ),
				$this->mention( 'Apigigi', 'name:fay', 0, 'review' ),
				$this->mention( 'Titiyas', 'name:gus', 5 ),
			),
			array()
		);

		$this->assertSame( array( 'Chicken kelaguen', 'Spam musubi', 'Titiyas', 'Red rice', 'Kadon pika', 'Apigigi' ), wp_list_pluck( $ranked, 'name' ) );
		$this->assertSame( 2, $ranked[0]['visitors'] );
		$this->assertSame( 1, $ranked[0]['photos'] );
		$this->assertSame( 1, $ranked[0]['reviews'] );
		$this->assertSame( 1, $ranked[0]['ratings'] );

		$red_rice = $ranked[3];
		$this->assertSame( 1, $red_rice['visitors'], 'Connected people are not counted' );
		$this->assertSame( 3.0, $red_rice['average'], 'Connected ratings are not averaged' );

		$spam = $ranked[1];
		$this->assertSame( 1, $spam['visitors'] );
		$this->assertSame( 3, $spam['photos'] );
		$this->assertSame( 1, $spam['ratings'], 'One rating per person' );
		$this->assertSame( 'Spam musubi', $spam['name'], 'The spelling most visitors used' );
	}

	/**
	 * A person's newest rating is the one averaged.
	 */
	public function test_rank_uses_each_persons_newest_rating() {
		$ranked = Popular_Dishes::rank(
			array(
				$this->mention( 'Kelaguen', 'name:ana', 2, 'photo', false, '2026-09-01 10:00:00' ),
				$this->mention( 'Kelaguen', 'name:ana', 5, 'photo', false, '2026-10-01 10:00:00' ),
				$this->mention( 'Kelaguen', 'name:ben', 4, 'photo', false, '2026-08-01 10:00:00' ),
			),
			array()
		);
		$this->assertSame( 4.5, $ranked[0]['average'] );
		$this->assertSame( 2, $ranked[0]['ratings'] );
	}

	/**
	 * Owner-listed dishes take the owner's spelling and details, never
	 * move up the ranking, and the ones nobody mentioned follow in the
	 * owner's order.
	 */
	public function test_owner_dishes_follow_visitor_dishes() {
		$ranked = Popular_Dishes::rank(
			array(
				$this->mention( 'garlic shrimp', 'name:ana', 4 ),
				$this->mention( 'Fried rice', 'name:ben' ),
				$this->mention( 'Fried rice', 'name:cat' ),
			),
			array(
				array(
					'name'    => 'Pancit',
					'price'   => '$11',
					'details' => '',
				),
				array(
					'name'    => 'Garlic Shrimp',
					'price'   => '$18',
					'details' => 'With lemon butter',
				),
				array(
					'name'    => 'Lumpia',
					'price'   => '',
					'details' => '',
				),
			)
		);

		$this->assertSame( array( 'Fried rice', 'Garlic Shrimp', 'Pancit', 'Lumpia' ), wp_list_pluck( $ranked, 'name' ) );
		$this->assertFalse( $ranked[0]['owner'] );
		$this->assertTrue( $ranked[1]['owner'] );
		$this->assertSame( '$18', $ranked[1]['price'] );
		$this->assertSame( 1, $ranked[1]['visitors'] );
		$this->assertSame( 0, $ranked[2]['visitors'] );

		$this->assertCount( 2, Popular_Dishes::rank( array(), array( array( 'name' => 'A' ), array( 'name' => 'B' ), array( 'name' => 'C' ) ), 2 ) );
	}

	/**
	 * The owner's menu dishes: cleaned, de-duplicated, capped, and held
	 * to the ad wording rules.
	 */
	public function test_menu_dishes_from_input() {
		$dishes = Owner_Content::menu_dishes_from_input(
			array(
				array(
					'name'    => ' Chicken kelaguen ',
					'price'   => '$12',
					'details' => 'Lemon, coconut, peppers',
				),
				array( 'name' => 'chicken  KELAGUEN' ),
				array( 'name' => '' ),
				array( 'name' => 'Red rice' ),
			)
		);
		$this->assertSame(
			array(
				array(
					'name'    => 'Chicken kelaguen',
					'price'   => '$12',
					'details' => 'Lemon, coconut, peppers',
				),
				array(
					'name'    => 'Red rice',
					'price'   => '',
					'details' => '',
				),
			),
			$dishes
		);

		$this->assertWPError( Owner_Content::menu_dishes_from_input( array( array( 'name' => 'Award-winning ribs' ) ) ) );
		$too_many = array();
		for ( $i = 0; $i <= Owner_Content::MAX_MENU_DISHES; $i++ ) {
			$too_many[] = array( 'name' => 'Dish ' . $i );
		}
		$this->assertSame( 'gr_owner_dishes', Owner_Content::menu_dishes_from_input( $too_many )->get_error_code() );
	}

	/**
	 * Reviews may name up to three dishes; they are stored and returned.
	 */
	public function test_reviews_name_dishes() {
		$restaurant = $this->restaurant( 'Kelaguen House' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$input = array(
			'rating'      => 5,
			'body'        => 'Great kelaguen and the red rice was perfect.',
			'author_name' => 'Ana',
			'dishes'      => 'Chicken kelaguen, Red rice',
			'elapsed_ms'  => 10000,
		);

		$result = Visitor_Reviews::submit( $restaurant, $input, 'client-a' );
		$this->assertIsArray( $result );
		$this->assertSame( "Chicken kelaguen\nRed rice", Visitor_Reviews::get( $result['id'] )['dishes'] );
		Highlights::moderate( $result['id'], 'approved', '', $this->admin );
		$this->assertSame( array( 'Chicken kelaguen', 'Red rice' ), Visitor_Reviews::approved( $restaurant )[0]['dishes'] );

		$input['dishes'] = 'A, B, C, D';
		$this->assertSame( 'gr_review_dishes', Visitor_Reviews::submit( $restaurant, $input, 'client-b' )->get_error_code() );
		wp_set_current_user( 0 );
	}

	/**
	 * The restaurant's dishes come from approved photos, approved reviews
	 * and the owner; pending photos and other restaurants do not count.
	 */
	public function test_for_restaurant_reads_first_party_content() {
		$restaurant = $this->restaurant( 'Kelaguen House' );
		$other      = $this->restaurant( 'Other Place' );

		$photos = array(
			$this->photo( $restaurant, 'Chicken kelaguen', 5, 'Ana' ),
			$this->photo( $restaurant, 'Chicken Kelaguen', 4, 'Ben' ),
		);
		$this->photo( $restaurant, 'Red rice', 5, 'Owner', true );
		$this->photo( $other, 'Red rice', 5, 'Cat' );
		$pending = $this->photo( $restaurant, 'Red rice', 5, 'Dee' );
		update_post_meta( $pending, Photo_Submissions::STATUS, Photo_Submissions::PENDING );

		$this->review( $restaurant, "Red rice\nChicken kelaguen", 'Eve' );
		$this->review( $restaurant, 'Red rice', 'Staff', 'I work there' );

		update_post_meta(
			$restaurant,
			Owner_Content::MENU_DISHES,
			array(
				array(
					'name'    => 'Shrimp patties',
					'price'   => '$9',
					'details' => '',
				),
			)
		);
		$quote = Highlights::insert(
			array(
				'restaurant_id' => $restaurant,
				'type'          => Policy::OWNER_QUOTE,
				'body'          => 'Family recipes since 1985, cooked fresh every morning.',
				'author_name'   => 'Owner',
				'dishes'        => "Tinaktak\nRed Rice",
			)
		);
		Highlights::moderate( $quote, 'approved', '', $this->admin );

		$dishes = Popular_Dishes::for_restaurant( $restaurant );
		$this->assertSame( array( 'Chicken kelaguen', 'Red Rice', 'Shrimp patties', 'Tinaktak' ), wp_list_pluck( $dishes, 'name' ) );
		$this->assertSame( 3, $dishes[0]['visitors'] );
		$this->assertSame( 4.5, $dishes[0]['average'] );
		$this->assertContains( $dishes[0]['photo'], $photos, 'A visitor photo of the dish' );
		$this->assertSame( 1, $dishes[1]['visitors'], 'Only Eve: the owner photo, the staff review, the pending photo and the other restaurant do not count' );
		$this->assertTrue( $dishes[1]['owner'] );
		$this->assertNull( $dishes[1]['average'] );
	}

	/**
	 * The sponsored spot sits before the heading, labeled; its sponsors
	 * take turns (one shown, the rest waiting); the list is the same with
	 * or without it; with none running it invites advertisers, except on
	 * a premium restaurant.
	 */
	public function test_sponsor_spot_rotates_before_the_list() {
		Pages::ensure();
		$restaurant = $this->restaurant( 'Kelaguen House' );
		$tamuning   = (int) get_term_by( 'slug', 'tamuning', Content_Types::TAX_VILLAGE )->term_id;
		wp_set_object_terms( $restaurant, array( $tamuning ), Content_Types::TAX_VILLAGE );
		$this->photo( $restaurant, 'Chicken kelaguen', 5, 'Ana' );

		$without = Display::render( $restaurant );
		$this->assertStringContainsString( 'Advertise here', $without );
		$this->assertStringContainsString( 'href="' . Pages::url( 'advertise' ) . '"', $without );
		$this->assertLessThan( strpos( $without, 'Popular dishes</h2>' ), strpos( $without, 'Advertise here' ) );

		$ads = array();
		foreach ( array( 'Harbor Grill', 'Reef Cafe', 'Dededo Diner' ) as $name ) {
			$ads[ $name ] = Ads::create(
				$name . ' dishes',
				array(
					'advertiser'    => $name . ' LLC',
					'restaurant_id' => $this->restaurant( $name ),
					'product'       => Display::PRODUCT,
					'headline'      => $name . ': lunch specials',
					'link'          => 'https://example.com/' . sanitize_title( $name ),
					'start'         => '2026-01-01',
					'end'           => '2030-12-31',
					'status'        => Ads::STATUS_ACTIVE,
					'villages'      => 'Dededo Diner' === $name ? array( (int) get_term_by( 'slug', 'dededo', Content_Types::TAX_VILLAGE )->term_id ) : array( $tamuning ),
				)
			);
			$this->assertIsInt( $ads[ $name ], is_wp_error( $ads[ $name ] ) ? implode( ' ', $ads[ $name ]->get_error_messages() ) : '' );
		}

		Server::reset();
		$with = Display::render( $restaurant );
		$this->assertStringContainsString( 'Harbor Grill: lunch specials', $with );
		$this->assertStringContainsString( 'Reef Cafe: lunch specials', $with );
		$this->assertStringNotContainsString( 'Dededo Diner', $with, 'A Dededo sponsor stays off a Tamuning restaurant' );
		$this->assertStringNotContainsString( 'Advertise here', $with );
		$this->assertStringContainsString( 'data-gr-rotate="' . Slot::ROTATE_MS . '"', $with );
		$this->assertSame( 2, substr_count( $with, '<p class="gr-sponsored__label">Sponsored</p>' ) );
		$this->assertSame( 1, substr_count( $with, '<li class="gr-ad" hidden ' ), 'One sponsor shows, the other waits its turn' );
		$this->assertStringContainsString( 'rel="sponsored noopener"', $with );
		$this->assertStringContainsString( 'data-gr-ad-page="restaurant"', $with );
		$this->assertLessThan( strpos( $with, 'Popular dishes</h2>' ), strpos( $with, 'Harbor Grill' ), 'The spot comes before the list' );
		$this->assertTrue( wp_script_is( Slot::ROTATION, 'enqueued' ) );

		$list = static function ( string $html ): string {
			return substr( $html, (int) strpos( $html, '<h2' ) );
		};
		$this->assertSame( $list( $without ), $list( $with ), 'Sponsors never change the list' );

		// A premium restaurant shows no other restaurant's ads and no invitation.
		Premium::extend( $restaurant, 30, 'Test' );
		Server::reset();
		$premium = Display::render( $restaurant );
		$this->assertStringNotContainsString( 'gr-dishes__sponsor', $premium );
		$this->assertSame( $list( $without ), $list( $premium ) );
	}

	/**
	 * A single sponsor is printed without rotation, and other products
	 * keep their behavior.
	 */
	public function test_single_sponsor_does_not_rotate() {
		$restaurant = $this->restaurant( 'Kelaguen House' );
		$ad         = Ads::create(
			'Solo',
			array(
				'advertiser' => 'Solo LLC',
				'product'    => Display::PRODUCT,
				'headline'   => 'Solo: fresh fish',
				'link'       => 'https://example.com/solo',
				'start'      => '2026-01-01',
				'end'        => '2030-12-31',
				'status'     => Ads::STATUS_ACTIVE,
			)
		);
		$this->assertIsInt( $ad );
		$html = Display::render( $restaurant );
		$this->assertStringContainsString( 'Solo: fresh fish', $html );
		$this->assertStringNotContainsString( 'data-gr-rotate', $html );
		$this->assertStringNotContainsString( ' hidden ', $html );
		$this->assertStringContainsString( 'No dishes yet', $html );
		$this->assertTrue( Ads::is_rotating( Display::PRODUCT ) );
		$this->assertFalse( Ads::is_rotating( 'banner' ) );
	}
}
