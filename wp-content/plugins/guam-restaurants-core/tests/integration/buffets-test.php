<?php
/**
 * Buffet and all-you-can-eat tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Buffet_Box;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Migrations;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Buffets;
use GuamRestaurants\Core\Site\Directory_Query;
use GuamRestaurants\Core\Site\Directory_View;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\Fresh_Pages;
use GuamRestaurants\Core\Site\Query_Parser;
use GuamRestaurants\Core\Site\Seo;

/**
 * English audience idea E22.
 *
 * @covers \GuamRestaurants\Core\Site\Buffets
 * @covers \GuamRestaurants\Core\Admin\Buffet_Box
 */
class Buffets_Test extends Guam_Test_Case {

	/**
	 * A valid fact as a form sends it.
	 *
	 * @param string $price Dinner price.
	 * @return array<string, mixed>
	 */
	private function input( string $price = '45' ): array {
		return array(
			'kind'     => 'buffet',
			'note'     => 'Kids 5 to 11 half price.',
			'sessions' => array(
				array(
					'meal'  => 'dinner',
					'days'  => array( 'fr', 'sa' ),
					'from'  => '17:30',
					'to'    => '21:00',
					'price' => $price,
				),
				array(
					'meal'  => 'breakfast',
					'days'  => array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ),
					'from'  => '6:30',
					'to'    => '10:00',
					'price' => '$28.50',
				),
				array(
					'meal' => '',
					'days' => array(),
				),
			),
		);
	}

	/**
	 * A place with a buffet at a lowest price, in a village.
	 *
	 * @param string $name    Name.
	 * @param string $price   Lowest price ('' for none).
	 * @param string $village Village slug.
	 * @return int
	 */
	private function buffet_place( string $name, string $price, string $village = 'tamuning' ): int {
		$id = $this->restaurant( $name );
		wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		$value = Buffets::from_input(
			array(
				'kind'     => 'ayce',
				'sessions' => array(
					array(
						'meal'  => 'dinner',
						'days'  => array( 'mo' ),
						'price' => $price,
					),
				),
			),
			'editor',
			'2026-11-26'
		);
		Buffets::save( $id, $value, 'editor' );
		return $id;
	}

	/**
	 * Parsing: prices to cents, times padded, rows sorted by meal, empty rows dropped.
	 */
	public function test_from_input() {
		$fact = Buffets::from_input( $this->input(), 'owner', '2026-11-26' );
		$this->assertIsArray( $fact );
		$this->assertSame( 'buffet', $fact['kind'] );
		$this->assertSame( 'owner', $fact['by'] );
		$this->assertSame( '', $fact['source'], 'Owners do not give a source' );
		$this->assertSame( '2026-11-26', $fact['checked'] );
		$this->assertCount( 2, $fact['sessions'] );
		$this->assertSame( 'breakfast', $fact['sessions'][0]['meal'], 'Breakfast before dinner' );
		$this->assertSame( '06:30', $fact['sessions'][0]['from'] );
		$this->assertSame( 2850, $fact['sessions'][0]['price'] );
		$this->assertSame( 4500, $fact['sessions'][1]['price'] );
		$this->assertSame( 2850, Buffets::lowest_price( $fact ) );

		$this->assertSame( array(), Buffets::from_input( array( 'kind' => '' ), 'owner' ), 'No kind clears it' );
		$this->assertWPError( Buffets::from_input( array( 'kind' => 'brunch' ), 'owner' ) );
		$this->assertWPError( Buffets::from_input( $this->input( 'free' ), 'owner' ) );
		$this->assertWPError( Buffets::from_input( $this->input( '0' ), 'owner' ) );

		$no_days                        = $this->input();
		$no_days['sessions'][0]['days'] = array();
		$this->assertWPError( Buffets::from_input( $no_days, 'owner' ) );
		$half_time                      = $this->input();
		$half_time['sessions'][0]['to'] = '';
		$this->assertWPError( Buffets::from_input( $half_time, 'owner' ) );
		$no_price = $this->input( '' );
		$this->assertSame( 0, Buffets::from_input( $no_price, 'owner' )['sessions'][1]['price'], 'A price is optional' );

		$editor = Buffets::from_input( $this->input() + array( 'source' => 'https://example.com/buffet' ), 'editor' );
		$this->assertSame( 'https://example.com/buffet', $editor['source'] );
		$this->assertWPError( Buffets::from_input( array_merge( $this->input(), array( 'note' => 'Award-winning buffet' ) ), 'owner' ), 'Ad rules apply to the note' );
	}

	/**
	 * Day and price labels.
	 */
	public function test_labels() {
		$this->assertSame( 'Daily', Buffets::days_label( array( 'su', 'mo', 'tu', 'we', 'th', 'fr', 'sa' ) ) );
		$this->assertSame( 'Mon–Fri', Buffets::days_label( array( 'mo', 'tu', 'we', 'th', 'fr' ) ) );
		$this->assertSame( 'Fri, Sat', Buffets::days_label( array( 'sa', 'fr' ) ) );
		$this->assertSame( 'Mon, Tue, Thu–Sun', Buffets::days_label( array( 'mo', 'tu', 'th', 'fr', 'sa', 'su' ) ) );
		$this->assertSame( '$45', Buffets::price_label( 4500 ) );
		$this->assertSame( '$35.20', Buffets::price_label( 3520 ) );
	}

	/**
	 * The listing section says where the facts came from.
	 */
	public function test_render() {
		$place = $this->restaurant( 'Sunset Buffet' );
		Buffets::save( $place, Buffets::from_input( $this->input() + array( 'source' => 'https://www.example.com/dining' ), 'editor', '2026-11-26' ), 'editor' );
		$html = Buffets::render( $place );
		$this->assertStringContainsString( '<h4 class="gr-buffet__title">Buffet</h4>', $html );
		$this->assertStringContainsString( 'Fri, Sat', $html );
		$this->assertStringContainsString( '$45 per adult', $html );
		$this->assertStringContainsString( 'example.com', $html );
		$this->assertStringContainsString( 'November 26, 2026', $html );
		$this->assertStringContainsString( 'Kids 5 to 11 half price.', $html );
		$this->assertSame( '', Buffets::render( $this->restaurant( 'No Buffet' ) ) );
	}

	/**
	 * The chip filters the directory, and search reads "all you can eat".
	 */
	public function test_filter_and_search() {
		$buffet = $this->buffet_place( 'Grill Buffet', '30' );
		$plain  = $this->restaurant( 'Plain Grill' );
		$closed = $this->buffet_place( 'Closed Buffet', '20' );
		update_post_meta( $closed, Restaurant_Meta::STATUS, 'permanently_closed' );

		$filters = Filters::from_params( array( Filters::BUFFET => Filters::BUFFET_YES ) );
		$this->assertTrue( $filters['buffet'] );
		$this->assertSame( array( Filters::BUFFET => Filters::BUFFET_YES ), Filters::to_params( $filters ) );
		$ids = wp_list_pluck( Directory_Query::query( $filters )->posts, 'ID' );
		$this->assertSame( array( $buffet ), array_map( 'intval', $ids ) );
		$this->assertNotContains( $plain, $ids );
		$this->assertStringContainsString( 'name="buffet" value="yes" checked', Directory_View::form( $filters, array() ) );

		Query_Parser::reset();
		$parsed = Query_Parser::parse( 'all you can eat in Tamuning' );
		$this->assertTrue( $parsed['buffet'] );
		$this->assertSame( '', $parsed['search'] );
		$this->assertTrue( Filters::from_params( array( Filters::SEARCH => 'buffet' ) )['buffet'] );
	}

	/**
	 * Owners save through the one owner write path; a re-save only moves the date.
	 */
	public function test_owner_saves() {
		$place = $this->restaurant( 'Owner Buffet' );
		$owner = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		Roles::set_owned_restaurants( $owner, array( $place ) );
		$other = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );

		$this->assertWPError( Owner_Content::apply( $other, $place, array( 'buffet' => $this->input() ) ) );
		$result = Owner_Content::apply( $owner, $place, array( 'buffet' => $this->input() ) );
		$this->assertSame( array( Buffets::META ), $result['changed'] );
		$this->assertSame( 'owner', Buffets::get( $place )['by'] );
		$this->assertSame( array(), Owner_Content::apply( $owner, $place, array( 'buffet' => $this->input() ) )['changed'], 'Same facts: no change logged' );

		global $wpdb;
		$sources = $wpdb->get_col( $wpdb->prepare( 'SELECT source FROM %i WHERE restaurant_id = %d AND field = %s', Schema::table( Schema::CHANGE_LOG ), $place, Buffets::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'owner' ), $sources );

		$this->assertWPError( Owner_Content::apply( $owner, $place, array( 'buffet' => $this->input( 'lots' ) ) ) );
		$this->assertSame( 4500, Buffets::get( $place )['sessions'][1]['price'], 'A refused edit changes nothing' );

		Owner_Content::apply( $owner, $place, array( 'buffet' => array( 'kind' => '' ) ) );
		$this->assertNull( Buffets::get( $place ), 'No buffet removes it' );
	}

	/**
	 * Editors save from the restaurant screen, with a nonce.
	 */
	public function test_editor_box() {
		$place = $this->restaurant( 'Editor Buffet' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST = array( Buffet_Box::FIELD => $this->input() + array( 'source' => 'https://example.com/b' ) );
		Buffet_Box::save( $place );
		$this->assertNull( Buffets::get( $place ), 'No nonce, no save' );

		$_POST[ Buffet_Box::NONCE_FIELD ] = wp_create_nonce( Buffet_Box::NONCE_ACTION );
		Buffet_Box::save( $place );
		$this->assertSame( 'editor', Buffets::get( $place )['by'] );
		$this->assertSame( 'https://example.com/b', Buffets::get( $place )['source'] );
		$_POST = array();
	}

	/**
	 * The page: price order (unpriced last) or village order, indexed from five places.
	 */
	public function test_page() {
		$this->set_permalink_structure( '/%postname%/' );
		Fresh_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );
		$dear   = $this->buffet_place( 'Dear Buffet', '80', 'tamuning' );
		$cheap  = $this->buffet_place( 'Cheap Buffet', '25', 'tamuning' );
		$hidden = $this->buffet_place( 'Unpriced Buffet', '', 'agana-heights' );
		$middle = $this->buffet_place( 'Middle Buffet', '40', 'dededo' );

		$this->assertSame( array( $cheap, $middle, $dear, $hidden ), wp_list_pluck( Buffets::sorted( 'price' ), 'id' ) );
		$this->assertSame( array( $hidden, $middle, $cheap, $dear ), wp_list_pluck( Buffets::sorted( 'village' ), 'id' ) );
		$this->assertSame( 3, Buffets::priced_count() );

		$this->go_to( Fresh_Pages::url( 'buffets' ) );
		$this->assertSame( home_url( '/buffets/' ), Fresh_Pages::url( 'buffets' ) );
		$this->assertSame( 'buffets', Fresh_Pages::current_page() );
		$this->assertSame( 'Buffets and all-you-can-eat on Guam', Seo::plugin_page()['title'] );
		$this->assertTrue( Seo::plugin_page()['filtered'], 'Four places: noindex' );
		$this->assertNotContains( home_url( '/buffets/' ), wp_list_pluck( Fresh_Pages::sitemap_pages(), 'loc' ) );

		$this->buffet_place( 'Fifth Buffet', '35', 'dededo' );
		$this->go_to( Fresh_Pages::url( 'buffets' ) );
		$this->assertFalse( Seo::plugin_page()['filtered'], 'Five places: indexed' );
		$this->assertContains( home_url( '/buffets/' ), wp_list_pluck( Fresh_Pages::sitemap_pages(), 'loc' ) );
		$html = Fresh_Pages::render_current();
		$this->assertLessThan( strpos( $html, 'Dear Buffet' ), strpos( $html, 'Cheap Buffet' ), 'Cheapest first' );
		$this->assertStringContainsString( 'All you can eat from $25 per adult', $html );

		$this->go_to( add_query_arg( Filters::SORT, 'village', Fresh_Pages::url( 'buffets' ) ) );
		$this->assertSame( 'village', Buffets::current_sort() );
		$this->assertTrue( Seo::plugin_page()['filtered'], 'The village order is a copy: noindex' );
		$this->assertStringContainsString( '<h2 class="gr-section-title">', Fresh_Pages::render_current() );
	}

	/**
	 * The shipped list: every row parses, names a source, and matches by name.
	 */
	public function test_research() {
		$rows   = Buffets::research();
		$priced = 0;
		foreach ( $rows as $row ) {
			$this->assertStringStartsWith( 'https://', $row['source'], $row['name'] );
			$fact = Buffets::from_input( $row, 'editor', $row['checked'] );
			$this->assertIsArray( $fact, $row['name'] );
			$this->assertNotEmpty( $fact['sessions'], $row['name'] );
			$priced += Buffets::lowest_price( $fact ) > 0 ? 1 : 0;
		}
		$this->assertGreaterThanOrEqual( 10, $priced, 'Done when 10 or more places have prices' );

		$hilton = $this->restaurant( 'Islander Terrace' );
		wp_set_object_terms( $hilton, 'tamuning', Content_Types::TAX_VILLAGE );
		$palm = $this->restaurant( 'Palm Cafe' );
		wp_set_object_terms( $palm, 'tamuning', Content_Types::TAX_VILLAGE );
		$elsewhere = $this->restaurant( 'Niji' );
		wp_set_object_terms( $elsewhere, 'dededo', Content_Types::TAX_VILLAGE );
		$owned = $this->restaurant( 'Aqua' );
		wp_set_object_terms( $owned, 'tamuning', Content_Types::TAX_VILLAGE );
		Buffets::save( $owned, Buffets::from_input( $this->input(), 'owner' ), 'owner' );

		$counts = Buffets::apply_research();
		$this->assertSame( 2, $counts['created'] );
		$this->assertSame( 1, $counts['unchanged'], 'The owner\'s facts are kept' );
		$this->assertSame( 0, $counts['failed'] );
		$this->assertContains( 'Niji', $counts['missing'], 'Matched within the village only' );
		$this->assertSame( 3000, Buffets::lowest_price( Buffets::get( $hilton ) ) );
		$this->assertSame( 2800, Buffets::lowest_price( Buffets::get( $palm ) ), 'Matched by an alias' );
		$this->assertSame( 'owner', Buffets::get( $owned )['by'] );

		$again = Buffets::apply_research();
		$this->assertSame( 0, $again['created'] + $again['updated'], 'Running it again changes nothing' );

		$this->assertArrayHasKey( '2026-11-26-buffet-research', Migrations::definitions() );
	}
}
