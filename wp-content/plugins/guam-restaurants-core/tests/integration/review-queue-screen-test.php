<?php
/**
 * Review queue screen tests: paging, saving a page of names and skips,
 * Google names shown for checking, and editing, deleting and bulk deciding
 * restaurants from the queue.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Seeder;

/**
 * The Restaurants → Review queue screen.
 *
 * @covers \GuamRestaurants\Core\Admin\Review_Queue_Screen
 * @covers \GuamRestaurants\Core\Discovery\Places_Client::details
 */
class Review_Queue_Screen_Test extends Guam_Test_Case {

	/**
	 * Seeds taxonomies.
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_all();
	}

	/**
	 * Clears the query vars a test set.
	 */
	public function tear_down() {
		unset( $_GET['paged'], $_GET['type'], $_GET['lookup'], $_GET['_wpnonce'] );
		putenv( 'GUAM_RESTAURANTS_GOOGLE_PLACES_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		parent::tear_down();
	}

	/**
	 * A discovered place.
	 *
	 * @param string $place_id Place ID.
	 * @return int Item ID.
	 */
	private function new_place( string $place_id ): int {
		return Review_Queue::enqueue(
			'new_place',
			array(
				'place_id'        => $place_id,
				'found_by'        => array(
					'kind'  => 'village',
					'slug'  => 'yigo',
					'query' => 'restaurants in Yigo, Guam',
				),
				'google_maps_url' => 'https://www.google.com/maps/place/?q=place_id:' . $place_id,
			),
			'google'
		);
	}

	/**
	 * The screen's HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		Review_Queue_Screen::render();
		return (string) ob_get_clean();
	}

	/**
	 * 100 items a page, with page links that reach the rest.
	 */
	public function test_pages_of_one_hundred() {
		$this->login_admin();
		$ids = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$ids[] = $this->new_place( 'P-' . $i );
		}

		$first = $this->render();
		$this->assertSame( 100, substr_count( $first, 'name="restaurant_name[' ) );
		$this->assertStringContainsString( 'Page 1 of 2', $first );
		$this->assertStringContainsString( '150 items', $first );
		$this->assertStringContainsString( 'restaurant_name[' . $ids[0] . ']', $first );
		$this->assertStringContainsString( esc_url( Review_Queue_Screen::url( '', 2 ) ), $first, 'Links to the next page.' );

		$_GET['paged'] = '2';
		$second        = $this->render();
		$this->assertSame( 50, substr_count( $second, 'name="restaurant_name[' ) );
		$this->assertStringContainsString( 'Page 2 of 2', $second );
		$this->assertStringContainsString( 'restaurant_name[' . $ids[149] . ']', $second );
		$this->assertStringNotContainsString( 'restaurant_name[' . $ids[0] . ']', $second );

		$_GET['paged'] = '9';
		$this->assertStringContainsString( 'Page 2 of 2', $this->render(), 'A page past the end shows the last page.' );
	}

	/**
	 * One save adds every named place and skips every ticked item.
	 */
	public function test_save_page_adds_named_and_skips_ticked() {
		$admin   = $this->login_admin();
		$named   = $this->new_place( 'P-A' );
		$both    = $this->new_place( 'P-B' );
		$skipped = $this->new_place( 'P-C' );
		$left    = $this->new_place( 'P-D' );
		$closure = Review_Queue::enqueue( 'closure_signal', array( 'checks' => 2 ), 'google', self::factory()->post->create( array( 'post_type' => 'gr_restaurant' ) ) );

		$result = Review_Queue_Screen::save_page(
			array(
				$named => 'Yigo Noodle House',
				$both  => 'Typed Name Wins',
				$left  => '   ',
			),
			array( $both, $skipped, $closure ),
			$admin
		);

		$this->assertSame(
			array(
				'added'     => 2,
				'skipped'   => 1,
				'merged'    => 0,
				'separated' => 0,
				'deleted'   => 0,
				'failed'    => 1,
			),
			$result
		);
		$this->assertSame( 'approved', Review_Queue::get( $named )['status'] );
		$this->assertSame( 'approved', Review_Queue::get( $both )['status'], 'A typed name wins over a ticked box.' );
		$this->assertSame( 'rejected', Review_Queue::get( $skipped )['status'] );
		$this->assertSame( 'pending', Review_Queue::get( $left )['status'], 'A blank name leaves the item for later.' );
		$this->assertSame( 'pending', Review_Queue::get( $closure )['status'], 'Closures are never skipped in bulk.' );

		$drafts = get_posts(
			array(
				'post_type'   => 'gr_restaurant',
				'post_status' => 'draft',
				'title'       => 'Yigo Noodle House',
			)
		);
		$this->assertCount( 1, $drafts );

		$again = Review_Queue_Screen::save_page( array( $named => 'Again' ), array( $skipped ), $admin );
		$this->assertSame( 0, $again['added'] );
		$this->assertSame( 2, $again['failed'], 'Decided items are not changed again.' );
	}

	/**
	 * Google's name, address and status are fetched per place for display,
	 * counted against the budget, and never written to the queue.
	 */
	public function test_google_details_for_new_places_only() {
		$first  = $this->new_place( 'P-1' );
		$closed = $this->new_place( 'P-2' );
		Review_Queue::enqueue( 'village_unresolved', array( 'osm_id' => 'node/1' ), 'osm' );
		$api = new Fake_Api(
			array(
				'#/places/P-1$#' => array(
					'displayName'      => array( 'text' => 'Yigo Noodle House' ),
					'formattedAddress' => 'Route 1, Yigo, Guam',
					'businessStatus'   => 'OPERATIONAL',
				),
				'#/places/P-2$#' => array(
					'displayName'    => array( 'text' => 'Old Diner' ),
					'businessStatus' => 'CLOSED_PERMANENTLY',
				),
			)
		);

		$result = Review_Queue_Screen::google_details( Review_Queue::pending(), new Places_Client( 'test-key', $api->client() ) );

		$this->assertSame( '', $result['error'] );
		$this->assertSame(
			array(
				'name'            => 'Yigo Noodle House',
				'address'         => 'Route 1, Yigo, Guam',
				'business_status' => 'OPERATIONAL',
			),
			$result['places'][ $first ]
		);
		$this->assertSame( 'CLOSED_PERMANENTLY', $result['places'][ $closed ]['business_status'] );
		$this->assertCount( 2, $api->requests, 'Only discovered places are looked up.' );
		$this->assertSame( Places_Client::DETAILS_FIELD_MASK, $api->requests[0][1]['headers']['X-Goog-FieldMask'] );
		$this->assertSame( 2, Budget::usage()['calls'][ Pricing::GOOGLE_DETAILS_PRO ] );
		$this->assertStringNotContainsString( 'Noodle', (string) wp_json_encode( Review_Queue::get( $first ) ), 'Google content is not stored.' );
	}

	/**
	 * Lookups stop when the free allowance is used up or Google keeps failing.
	 */
	public function test_google_details_stop() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->new_place( 'P-' . $i );
		}

		$failing = new Fake_Api( array( '#/places/#' => 500 ) );
		$result  = Review_Queue_Screen::google_details( Review_Queue::pending(), new Places_Client( 'test-key', $failing->client() ) );
		$this->assertSame( array(), $result['places'] );
		$this->assertCount( Review_Queue_Screen::MAX_LOOKUP_FAILURES, $failing->requests );
		$this->assertStringContainsString( 'did not answer', $result['error'] );

		update_option(
			Budget::MONTHLY_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'calls' => array( Pricing::GOOGLE_DETAILS_PRO => Pricing::free_per_month( Pricing::GOOGLE_DETAILS_PRO ) ),
				'spend' => array(),
			)
		);
		$api    = new Fake_Api( array( '#/places/#' => array( 'displayName' => array( 'text' => 'X' ) ) ) );
		$result = Review_Queue_Screen::google_details( Review_Queue::pending(), new Places_Client( 'test-key', $api->client() ) );
		$this->assertSame( array(), $api->requests, 'No paid calls under the default $0 cap.' );
		$this->assertStringContainsString( 'used up', $result['error'] );
	}

	/**
	 * Without a key the page says so instead of looking anything up.
	 */
	public function test_google_details_without_key() {
		$this->new_place( 'P-1' );
		$result = Review_Queue_Screen::google_details( Review_Queue::pending() );
		$this->assertSame( array(), $result['places'] );
		$this->assertStringContainsString( 'No Google Places key', $result['error'] );
	}

	/**
	 * Google names are shown only with a valid nonce, and closed places are pre-ticked to skip.
	 */
	public function test_lookup_needs_nonce() {
		$this->login_admin();
		$this->new_place( 'P-1' );

		$_GET['lookup']   = '1';
		$_GET['_wpnonce'] = 'bad';
		$html             = $this->render();
		$this->assertStringContainsString( 'Fill in Google names on this page', $html );
		$this->assertStringNotContainsString( 'No Google Places key', $html, 'An invalid nonce does not look anything up.' );

		$_GET['_wpnonce'] = wp_create_nonce( Review_Queue_Screen::LOOKUP_NONCE );
		$this->assertStringContainsString( 'No Google Places key', $this->render() );
	}

	/**
	 * With Google names on, each name box is filled with Google's name, so the
	 * page can be saved as it is; a permanently closed place is left blank and
	 * ticked to skip.
	 */
	public function test_lookup_fills_name_boxes() {
		$this->login_admin();
		$open   = $this->new_place( 'P-OPEN' );
		$closed = $this->new_place( 'P-CLOSED' );
		putenv( 'GUAM_RESTAURANTS_GOOGLE_PLACES_KEY=test-key' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$answer = static function ( $pre, $args, $url ) {
			if ( ! preg_match( '#/places/(P-\w+)$#', $url, $m ) ) {
				return $pre;
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => (string) wp_json_encode(
					'P-OPEN' === $m[1]
						? array(
							'displayName'    => array( 'text' => 'Ben & Jerry\'s "Tumon"' ),
							'businessStatus' => 'OPERATIONAL',
						)
						: array(
							'displayName'    => array( 'text' => 'Old Diner' ),
							'businessStatus' => 'CLOSED_PERMANENTLY',
						)
				),
			);
		};
		add_filter( 'pre_http_request', $answer, 10, 3 );

		$_GET['lookup']   = '1';
		$_GET['_wpnonce'] = wp_create_nonce( Review_Queue_Screen::LOOKUP_NONCE );
		$html             = $this->render();
		remove_filter( 'pre_http_request', $answer, 10 );

		$this->assertStringContainsString( 'name="restaurant_name[' . $open . ']" value="' . esc_attr( 'Ben & Jerry\'s "Tumon"' ) . '"', $html );
		$this->assertStringContainsString( 'name="restaurant_name[' . $closed . ']" value=""', $html );
		$this->assertMatchesRegularExpression( '#name="selected\[\]" value="' . $closed . '" checked#', $html );
		$this->assertDoesNotMatchRegularExpression( '#name="selected\[\]" value="' . $open . '" checked#', $html );

		$saved = Review_Queue_Screen::save_page( array( $open => 'Ben & Jerry\'s "Tumon"' ), array( $closed ), get_current_user_id() );
		$this->assertSame( 1, $saved['added'] );
		$this->assertSame( 1, $saved['skipped'] );
	}

	/**
	 * Term ID by slug.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private function term( string $slug, string $taxonomy = Content_Types::TAX_VILLAGE ): int {
		return (int) Mall_Verification::with_unverified(
			static function () use ( $slug, $taxonomy ) {
				return get_term_by( 'slug', $slug, $taxonomy )->term_id;
			}
		);
	}

	/**
	 * A restaurant's term slugs.
	 *
	 * @param int    $id       Restaurant.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private function slugs( int $id, string $taxonomy = Content_Types::TAX_VILLAGE ): array {
		return Mall_Verification::with_unverified(
			static function () use ( $id, $taxonomy ) {
				return wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'slugs' ) );
			}
		);
	}

	/**
	 * A possible duplicate pair.
	 *
	 * @param int $a Restaurant.
	 * @param int $b Restaurant.
	 * @return int Item ID.
	 */
	private function duplicate( int $a, int $b ): int {
		return Review_Queue::enqueue( 'duplicate', array( 'duplicate_of' => $b ), 'system', $a, 0.7 );
	}

	/**
	 * Renaming and moving a restaurant; an unchanged value writes nothing, and
	 * setting a village closes its "Village unknown" item.
	 */
	public function test_edit_restaurant() {
		$admin   = $this->login_admin();
		$id      = $this->restaurant( 'Jamaican Gril' );
		$unknown = Review_Queue::enqueue( 'village_unresolved', array( 'osm_id' => 'node/1' ), 'osm', $id );
		$mall    = Review_Queue::enqueue( 'mall_unresolved', array( 'osm_id' => 'node/1' ), 'osm', $id );

		$this->assertFalse(
			Review_Queue_Screen::edit_restaurant(
				$id,
				array(
					'name'    => 'Jamaican Gril',
					'village' => 0,
					'mall'    => 0,
				),
				$admin
			),
			'Nothing changed.'
		);
		$this->assertSame( 'pending', Review_Queue::get( $unknown )['status'] );

		$this->assertTrue(
			Review_Queue_Screen::edit_restaurant(
				$id,
				array(
					'name'    => 'Jamaican Grill',
					'village' => $this->term( 'yigo' ),
				),
				$admin
			)
		);
		$this->assertSame( 'Jamaican Grill', get_post_field( 'post_title', $id, 'raw' ) );
		$this->assertSame( array( 'yigo' ), $this->slugs( $id ) );
		$this->assertSame( 'approved', Review_Queue::get( $unknown )['status'], 'Village unknown is closed.' );
		$this->assertSame( 'pending', Review_Queue::get( $mall )['status'], 'Mall unknown stays until a mall is set.' );

		Review_Queue_Screen::edit_restaurant( $id, array( 'mall' => $this->term( 'micronesia-mall', Content_Types::TAX_MALL ) ), $admin );
		$this->assertSame( array( 'micronesia-mall' ), $this->slugs( $id, Content_Types::TAX_MALL ) );
		$this->assertSame( array( 'dededo' ), $this->slugs( $id ), 'A mall brings its village.' );
		$this->assertSame( 'approved', Review_Queue::get( $mall )['status'] );

		Review_Queue_Screen::edit_restaurant(
			$id,
			array(
				'village' => $this->term( 'yona' ),
				'mall'    => 0,
			),
			$admin
		);
		$this->assertSame( array(), $this->slugs( $id, Content_Types::TAX_MALL ), '0 clears the mall.' );
		$this->assertSame( array( 'yona' ), $this->slugs( $id ), 'A village picked with it wins.' );

		$this->assertFalse( Review_Queue_Screen::edit_restaurant( $id, array( 'village' => 999999 ), $admin ), 'Unknown terms are ignored.' );
		$this->assertFalse( Review_Queue_Screen::edit_restaurant( $id, array( 'name' => 'X' ), self::factory()->user->create() ), 'Administrators only.' );
	}

	/**
	 * Delete moves the restaurant to the Trash and closes every item about it.
	 */
	public function test_delete_restaurant() {
		$admin   = $this->login_admin();
		$bad     = $this->restaurant( 'Restaurant 123' );
		$other   = $this->restaurant( 'Real Place' );
		$unknown = Review_Queue::enqueue( 'village_unresolved', array( 'osm_id' => 'node/2' ), 'osm', $bad );
		$pair    = $this->duplicate( $other, $bad );
		$kept    = Review_Queue::enqueue( 'village_unresolved', array( 'osm_id' => 'node/3' ), 'osm', $other );

		$this->assertTrue( Review_Queue_Screen::delete_restaurant( $bad, $admin ) );
		$this->assertSame( 'trash', get_post_status( $bad ) );
		$this->assertSame( 'rejected', Review_Queue::get( $unknown )['status'] );
		$this->assertSame( 'rejected', Review_Queue::get( $pair )['status'], 'A pair naming it as the other listing closes too.' );
		$this->assertSame( 'pending', Review_Queue::get( $kept )['status'] );
		$this->assertFalse( Review_Queue_Screen::delete_restaurant( $bad, $admin ), 'Already in the Trash.' );
		$this->assertFalse( Review_Queue_Screen::delete_restaurant( $other, self::factory()->user->create() ), 'Administrators only.' );
	}

	/**
	 * Ticked duplicate pairs are merged or marked not the same in one save;
	 * ticked rows can be deleted; a discovered place gets the village picked.
	 */
	public function test_bulk_actions() {
		$admin = $this->login_admin();
		$a1    = $this->restaurant( 'Pika\'s Cafe' );
		$a2    = $this->restaurant( 'Pikas Cafe' );
		$b1    = $this->restaurant( 'Kings' );
		$b2    = $this->restaurant( 'Kings Restaurant' );
		$c1    = $this->restaurant( 'Taco Shop' );
		$c2    = $this->restaurant( 'Taco Shack' );
		$merge = array( $this->duplicate( $a1, $a2 ), $this->duplicate( $b1, $b2 ) );
		$apart = $this->duplicate( $c1, $c2 );

		$result = Review_Queue_Screen::save_page( array(), $merge, $admin, 'merge' );
		$this->assertSame( 2, $result['merged'] );
		$this->assertSame( 'merged', Review_Queue::get( $merge[0] )['status'] );
		$this->assertSame( 'trash', get_post_status( $a2 ) );

		$result = Review_Queue_Screen::save_page( array(), array( $apart ), $admin, 'not_duplicate' );
		$this->assertSame( 1, $result['separated'] );
		$this->assertSame( 'rejected', Review_Queue::get( $apart )['status'] );
		$this->assertContains( (string) $c2, get_post_meta( $c1, Duplicate_Detector::NOT_DUPLICATE_META, false ) );

		$place   = $this->new_place( 'P-DEL' );
		$unknown = Review_Queue::enqueue( 'village_unresolved', array( 'osm_id' => 'node/4' ), 'osm', $c1 );
		$result  = Review_Queue_Screen::save_page( array(), array( $place, $unknown ), $admin, 'delete' );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertSame( 'trash', get_post_status( $c1 ) );
		$this->assertSame( 'rejected', Review_Queue::get( $place )['status'], 'A place with no restaurant just leaves the queue.' );

		$named  = $this->new_place( 'P-NAMED' );
		$result = Review_Queue_Screen::save_page( array( $named => 'Mall Noodles' ), array( $named ), $admin, 'delete', array( $named => array( 'mall' => $this->term( 'micronesia-mall', Content_Types::TAX_MALL ) ) ) );
		$this->assertSame( 1, $result['added'] );
		$this->assertSame( 0, $result['deleted'], 'A typed name wins over a ticked box.' );
		$post = get_posts(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
				'title'       => 'Mall Noodles',
				'fields'      => 'ids',
			)
		)[0];
		$this->assertSame( array( 'micronesia-mall' ), $this->slugs( $post, Content_Types::TAX_MALL ) );
		$this->assertSame( array( 'dededo' ), $this->slugs( $post ), 'The picked mall replaces the Yigo search.' );
	}

	/**
	 * Each restaurant gets name, village and mall boxes and a Delete button
	 * once per page; a discovered place gets village and mall boxes set from
	 * its search; duplicates say which listing a merge keeps.
	 */
	public function test_render_edit_boxes() {
		$this->login_admin();
		$a = $this->restaurant( 'Kings', array( 'osm_id' => 'node/9' ) );
		$b = $this->restaurant( 'Kings Restaurant' );
		$c = $this->restaurant( 'Kings Too' );
		wp_set_object_terms( $a, 'tamuning', Content_Types::TAX_VILLAGE );
		$this->duplicate( $b, $a );
		$this->duplicate( $a, $c );
		$this->new_place( 'P-R' );

		$html = $this->render();
		$this->assertSame( 1, substr_count( $html, 'name="edit[' . $a . '][name]"' ), 'Editable once per page.' );
		$this->assertStringContainsString( 'name="edit[' . $b . '][village]"', $html );
		$this->assertStringContainsString( 'name="delete_restaurant" value="' . $c . '"', $html );
		$this->assertMatchesRegularExpression( '/name="edit\[' . $a . '\]\[village\]"[^>]*>(?:(?!<\/select>).)*?<option value="' . $this->term( 'tamuning' ) . '" selected=/s', $html );
		$this->assertMatchesRegularExpression( '/name="place\[\d+\]\[village\]"[^>]*>(?:(?!<\/select>).)*?<option value="' . $this->term( 'yigo' ) . '" selected=/s', $html );
		$this->assertSame( 2, substr_count( $html, 'kept if merged' ) );
		$this->assertStringContainsString( 'name="bulk_action_top"', $html );
		$this->assertStringContainsString( "<option value=\"skip\" selected='selected'", $html, 'Skip is the default on a page with new places.' );
	}
}
