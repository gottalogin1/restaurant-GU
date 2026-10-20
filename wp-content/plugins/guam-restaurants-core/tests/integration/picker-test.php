<?php
/**
 * Random place picker: candidates, picks, rounds, REST and the block.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Pages;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Picker;
use WP_REST_Request;

/**
 * Picker tests. Times are Guam time.
 */
class Picker_Test extends Guam_Test_Case {

	/**
	 * Saturday 2026-10-10 19:30 in Guam (09:30 UTC).
	 */
	private const SATURDAY_EVENING = 1791624600;

	/**
	 * Restaurants by key.
	 *
	 * @var array<string, int>
	 */
	private array $r = array();

	/**
	 * Builds a small directory.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'guam_restaurants_now', array( $this, 'saturday_evening' ) );

		$evening = array(
			'weekly' => array_map(
				static function ( $day ) {
					return array(
						'day'   => $day,
						'open'  => '17:00',
						'close' => '23:00',
					);
				},
				array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' )
			),
		);

		$this->r['proa']    = $this->place( 'Proa', 'tamuning', array( 'chamorro' ), 'p2', $evening );
		$this->r['sushi']   = $this->place( 'Tumon Sushi Bar', 'tamuning', array( 'japanese' ), 'p3', array() );
		$this->r['kimchi']  = $this->place( 'Dededo Kimchi House', 'dededo', array( 'korean' ), 'p1', $evening );
		$this->r['bbq']     = $this->place( 'Dededo BBQ', 'dededo', array( 'korean' ), 'p2', array() );
		$this->r['closed']  = $this->place( 'Old Diner', 'dededo', array( 'korean' ), 'p1', $evening );
		$this->r['paused']  = $this->place( 'Paused Grill', 'dededo', array( 'korean' ), 'p1', $evening );
		$this->r['draft']   = $this->restaurant( 'Draft Noodles', array(), 'draft' );
		$this->r['premium'] = $this->place( 'Premium Bistro', 'hagatna', array( 'american' ), 'p3', array() );
		wp_set_object_terms( $this->r['draft'], 'dededo', Content_Types::TAX_VILLAGE );
		wp_set_object_terms( $this->r['draft'], 'korean', Content_Types::TAX_CUISINE );
		update_post_meta( $this->r['closed'], Restaurant_Meta::STATUS, 'permanently_closed' );
		update_post_meta( $this->r['paused'], Restaurant_Meta::STATUS, 'temporarily_closed' );
		update_post_meta( $this->r['kimchi'], Restaurant_Meta::STATUS, 'open' );
	}

	/**
	 * Fixed clock.
	 *
	 * @return int
	 */
	public function saturday_evening(): int {
		return self::SATURDAY_EVENING;
	}

	/**
	 * Creates a restaurant with terms and hours.
	 *
	 * @param string   $name     Name.
	 * @param string   $village  Village slug.
	 * @param string[] $cuisines Cuisine slugs.
	 * @param string   $price    Price slug.
	 * @param array    $hours    Hours object.
	 * @return int
	 */
	private function place( string $name, string $village, array $cuisines, string $price, array $hours ): int {
		$id = $this->restaurant( $name, $hours ? array( Restaurant_Meta::HOURS => $hours ) : array() );
		wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		wp_set_object_terms( $id, $cuisines, Content_Types::TAX_CUISINE );
		wp_set_object_terms( $id, $price, Content_Types::TAX_PRICE );
		return $id;
	}

	/**
	 * Candidates are published, not closed, and match every choice.
	 */
	public function test_candidates() {
		$dededo = Picker::candidates( Picker::filters( array( 'village' => 'dededo' ) ) );
		sort( $dededo );
		$expected = array( $this->r['kimchi'], $this->r['bbq'] );
		sort( $expected );
		$this->assertSame( $expected, $dededo, 'Closed (permanently or for now) and draft places are never picked' );

		$this->assertSame(
			array( $this->r['kimchi'] ),
			Picker::candidates(
				Picker::filters(
					array(
						'village' => 'dededo',
						'open'    => 'now',
					)
				)
			)
		);
		$this->assertSame(
			array( $this->r['sushi'] ),
			Picker::candidates(
				Picker::filters(
					array(
						'cuisine' => 'japanese,american',
						'price'   => 'p3',
						'village' => 'tamuning',
					)
				)
			)
		);
		$this->assertSame( array(), Picker::candidates( Picker::filters( array( 'village' => 'yigo' ) ) ) );

		$filters = Picker::filters(
			array(
				'q'       => 'sushi',
				'sort'    => 'newest',
				'village' => 'tamuning',
			)
		);
		$this->assertSame( '', $filters['q'], 'The picker has no keyword search' );
		$this->assertCount( 2, Picker::candidates( $filters ) );
	}

	/**
	 * Every match comes up once before any repeats; then a new round starts.
	 */
	public function test_pick_rounds() {
		$filters = Picker::filters( array() );
		$all     = Picker::candidates( $filters );
		$this->assertCount( 5, $all );

		$seen  = array();
		$shown = array();
		foreach ( range( 1, 5 ) as $n ) {
			$pick = Picker::pick( $filters, $seen );
			$this->assertContains( $pick['id'], $all );
			$this->assertNotContains( $pick['id'], $shown, 'No repeats within a round' );
			$this->assertSame( 5, $pick['total'] );
			$this->assertSame( 5 - $n, $pick['left'] );
			$shown[] = $pick['id'];
			$seen    = $pick['seen'];
		}

		$next = Picker::pick( $filters, $seen );
		$this->assertNotSame( end( $shown ), $next['id'], 'A new round does not start with the last place shown' );
		$this->assertSame( array( $next['id'] ), $next['seen'] );

		$none = Picker::pick( Picker::filters( array( 'village' => 'yigo' ) ) );
		$this->assertSame( 0, $none['id'] );
		$this->assertSame( 0, $none['total'] );

		$this->assertSame( array( 3, 7 ), Picker::seen( '3,x,7,3,0,-2' ) );
		$this->assertCount( Picker::MAX_NOT, Picker::seen( range( 1, 80 ) ) );
	}

	/**
	 * A premium profile gives no extra chance: picks are uniform.
	 */
	public function test_premium_is_not_favoured() {
		update_post_meta( $this->r['premium'], Restaurant_Meta::CLAIMED_BY_USER_ID, self::factory()->user->create() );
		Premium::extend( $this->r['premium'], 30, 'Test' );

		$filters = Picker::filters( array() );
		$counts  = array_fill_keys( Picker::candidates( $filters ), 0 );
		for ( $i = 0; $i < 500; $i++ ) {
			++$counts[ Picker::pick( $filters )['id'] ];
		}
		// Expected 100 each; 55 is more than six standard deviations below.
		foreach ( $counts as $count ) {
			$this->assertGreaterThan( 55, $count );
			$this->assertLessThan( 145, $count );
		}
	}

	/**
	 * The endpoint returns a card, the round state and the choices' URL, never cached.
	 */
	public function test_rest_pick() {
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'what-to-eat',
				'post_status' => 'publish',
			)
		);
		$url  = get_permalink( $page );

		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/pick' );
		$request->set_query_params(
			array(
				'village'  => 'dededo',
				'page_url' => $url . '?village=tamuning',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertContains( $data['id'], array( $this->r['kimchi'], $this->r['bbq'] ) );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 1, $data['left'] );
		$this->assertSame( (string) $data['id'], $data['not'] );
		$this->assertSame( $url . '?village=dededo', $data['url'] );
		$this->assertStringContainsString( 'Picked at random from 2 places that match', $data['html'] );
		$this->assertStringContainsString( 'Pick again', $data['html'] );
		$this->assertStringContainsString( 'See all 2 matches', $data['html'] );

		$request->set_query_params(
			array(
				'village'  => 'dededo',
				'not'      => $data['not'],
				'page_url' => 'https://evil.example/what-to-eat/',
			)
		);
		$again = rest_get_server()->dispatch( $request )->get_data();
		$this->assertNotSame( $data['id'], $again['id'], 'Pick again shows the other place' );
		$this->assertSame( 0, $again['left'] );
		$this->assertStringContainsString( 'starts over', $again['html'] );
		$this->assertStringNotContainsString( 'evil.example', $again['html'], 'Links stay on this site' );

		$request->set_query_params( array( 'cuisine' => 'japanese' ) );
		$one = rest_get_server()->dispatch( $request )->get_data();
		$this->assertStringContainsString( 'Only one place matches', $one['html'] );
		$this->assertStringNotContainsString( 'Pick again', $one['html'] );
	}

	/**
	 * The block is a working form without JavaScript and picks when asked.
	 */
	public function test_block() {
		$_GET = array();
		$html = do_blocks( '<!-- wp:guam-restaurants/random-picker /-->' );
		$this->assertStringContainsString( 'data-gr-picker', $html );
		$this->assertStringContainsString( '<option value="dededo" class="notranslate" translate="no" data-no-translation>Dededo (Liguan, Finegayan)</option>', $html );
		$this->assertStringContainsString( '<option value="tamuning" class="notranslate" translate="no" data-no-translation>Tamuning (Tumon, Upper Tumon, Harmon)</option>', $html, 'Villages show their aliases' );
		$this->assertStringContainsString( 'name="cuisine[]" value="korean"', $html );
		$this->assertStringContainsString( 'name="price[]" value="p1"', $html );
		$this->assertStringContainsString( 'name="open" value="now"', $html );
		$this->assertStringContainsString( 'name="pick" value="1"', $html );
		$this->assertStringNotContainsString( 'gr-picker__card', $html, 'No pick until asked' );

		$_GET = array(
			'pick'    => '1',
			'village' => 'dededo',
			'cuisine' => array( 'korean' ),
			'open'    => 'now',
		);
		$html = do_blocks( '<!-- wp:guam-restaurants/random-picker /-->' );
		$this->assertStringContainsString( 'Dededo Kimchi House', $html );
		$this->assertStringContainsString( '<option value="dededo" selected=\'selected\' class="notranslate" translate="no" data-no-translation>Dededo (Liguan, Finegayan)</option>', $html );
		$this->assertStringContainsString( 'Open now uses Guam time', $html );

		$_GET = array(
			'pick'    => '1',
			'village' => 'yigo',
		);
		$this->assertStringContainsString( 'Nothing matches all of these choices', do_blocks( '<!-- wp:guam-restaurants/random-picker /-->' ) );
		$_GET = array();
	}

	/**
	 * One sponsored ad sits above the pick, labeled, targeted by the
	 * visitor's choices; with none running, an invitation to advertise.
	 * The pick itself is unchanged.
	 */
	public function test_sponsor_slot() {
		Server::reset();
		Pages::ensure();
		$tamuning = (int) get_term_by( 'slug', 'tamuning', Content_Types::TAX_VILLAGE )->term_id;
		$ad       = Ads::create(
			'Harbor Grill picker',
			array(
				'advertiser' => 'Harbor Grill LLC',
				'product'    => 'picker_sponsor',
				'headline'   => 'Harbor Grill: sunset dinners',
				'link'       => 'https://example.com/harbor-grill',
				'start'      => '2026-10-01',
				'end'        => '2026-10-31',
				'status'     => Ads::STATUS_ACTIVE,
				'villages'   => array( $tamuning ),
			)
		);
		$this->assertIsInt( $ad, is_wp_error( $ad ) ? implode( ' ', $ad->get_error_messages() ) : '' );

		$tamuning_pick = Picker::result( Picker::pick( Picker::filters( array( 'village' => 'tamuning' ) ) ), Picker::filters( array( 'village' => 'tamuning' ) ), home_url( '/what-to-eat/' ) );
		$this->assertStringContainsString( 'Harbor Grill: sunset dinners', $tamuning_pick );
		$this->assertStringContainsString( '<p class="gr-sponsored__label">Sponsored</p>', $tamuning_pick );
		$this->assertStringContainsString( 'rel="sponsored noopener"', $tamuning_pick );
		$this->assertStringContainsString( 'data-gr-ad-page="picker"', $tamuning_pick );
		$this->assertLessThan( strpos( $tamuning_pick, 'gr-picker__card' ), strpos( $tamuning_pick, 'Harbor Grill' ), 'The ad sits above the pick' );
		$this->assertStringNotContainsString( 'Advertise here', $tamuning_pick );

		Server::reset();
		$dededo_pick = Picker::result( Picker::pick( Picker::filters( array( 'village' => 'dededo' ) ) ), Picker::filters( array( 'village' => 'dededo' ) ), home_url( '/what-to-eat/' ) );
		$this->assertStringNotContainsString( 'Harbor Grill', $dededo_pick, 'A Tamuning ad stays out of Dededo picks' );
		$this->assertStringContainsString( 'Advertise here', $dededo_pick );
		$this->assertStringContainsString( 'href="' . Pages::url( 'advertise' ) . '"', $dededo_pick );

		Server::reset();
		$none = Picker::result( Picker::pick( Picker::filters( array( 'village' => 'yigo' ) ) ), Picker::filters( array( 'village' => 'yigo' ) ), home_url( '/what-to-eat/' ) );
		$this->assertStringNotContainsString( 'gr-picker__sponsor', $none, 'No slot without a pick' );
		Server::reset();
	}
}
