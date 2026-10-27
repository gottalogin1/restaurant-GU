<?php
/**
 * Holiday hours, truck stops, markets, fiestas, This week and New on Guam tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Local_Events_Screen;
use GuamRestaurants\Core\Admin\Special_Hours_Box;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Newsletter\Renderer;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Blocks;
use GuamRestaurants\Core\Site\Directory_Query;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\Fresh_Pages;
use GuamRestaurants\Core\Site\Intro;
use GuamRestaurants\Core\Site\Local_Events;
use GuamRestaurants\Core\Site\New_Places;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Site\Special_Hours;
use GuamRestaurants\Core\Site\This_Week;
use GuamRestaurants\Core\Site\Truck_Stops;

/**
 * Comparison additions 15 (New on Guam), 22 (food trucks and night
 * markets), 23 (holiday hours) and 26 (fiesta dates).
 *
 * @covers \GuamRestaurants\Core\Site\Special_Hours
 * @covers \GuamRestaurants\Core\Site\Truck_Stops
 * @covers \GuamRestaurants\Core\Site\Local_Events
 * @covers \GuamRestaurants\Core\Site\This_Week
 * @covers \GuamRestaurants\Core\Site\New_Places
 * @covers \GuamRestaurants\Core\Site\Fresh_Pages
 * @covers \GuamRestaurants\Core\Admin\Local_Events_Screen
 * @covers \GuamRestaurants\Core\Admin\Special_Hours_Box
 */
class Fresh_Content_Test extends Guam_Test_Case {

	/**
	 * Monday 2026-12-07 19:30 in Guam.
	 */
	private const MONDAY_EVENING = 1796635800;

	/**
	 * The clock the code sees.
	 *
	 * @var int
	 */
	private int $now = self::MONDAY_EVENING;

	/**
	 * Fixed clock, shipped markets and fiestas.
	 */
	public function set_up() {
		parent::set_up();
		$this->now = self::MONDAY_EVENING;
		add_filter( 'guam_restaurants_now', array( $this, 'clock' ) );
		delete_option( Local_Events::MARKETS_OPTION );
		delete_option( Local_Events::FIESTAS_OPTION );
		delete_option( Special_Hours::INDEX_OPTION );
	}

	/**
	 * Fixed clock.
	 *
	 * @return int
	 */
	public function clock(): int {
		return $this->now;
	}

	/**
	 * Hours open every day.
	 *
	 * @param string $open  Open.
	 * @param string $close Close.
	 * @return array<string, mixed>
	 */
	private function every_day( string $open, string $close ): array {
		return array(
			'weekly' => array_map(
				static function ( $day ) use ( $open, $close ) {
					return array(
						'day'   => $day,
						'open'  => $open,
						'close' => $close,
					);
				},
				array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' )
			),
		);
	}

	/**
	 * Titles from a directory query.
	 *
	 * @param array<string, mixed> $params URL parameters.
	 * @return string[]
	 */
	private function titles( array $params ): array {
		$titles = wp_list_pluck( Directory_Query::query( Filters::from_params( $params ) )->posts, 'post_title' );
		sort( $titles );
		return $titles;
	}

	/**
	 * Three places: one closed for the day, one with other hours that day, one unchanged.
	 *
	 * @return array{late: int, lunch: int, plain: int}
	 */
	private function holiday_places(): array {
		$late  = $this->restaurant( 'Late Grill', array( Restaurant_Meta::HOURS => $this->every_day( '17:00', '02:00' ) ) );
		$lunch = $this->restaurant(
			'Lunch Counter',
			array(
				Restaurant_Meta::HOURS => array(
					'weekly' => array(
						array(
							'day'   => 'mo',
							'open'  => '11:00',
							'close' => '14:00',
						),
					),
				),
			)
		);
		$plain = $this->restaurant( 'Plain Diner', array( Restaurant_Meta::HOURS => $this->every_day( '17:00', '22:00' ) ) );
		update_post_meta(
			$late,
			Special_Hours::META,
			array(
				array(
					'date'   => '2026-12-07',
					'closed' => true,
					'ranges' => array(),
					'note'   => 'Staff party',
				),
			)
		);
		update_post_meta(
			$lunch,
			Special_Hours::META,
			array(
				array(
					'date'   => '2026-12-07',
					'closed' => false,
					'ranges' => array(
						array(
							'open'  => '17:00',
							'close' => '23:00',
						),
					),
					'note'   => '',
				),
			)
		);
		return compact( 'late', 'lunch', 'plain' );
	}

	/**
	 * Form rows: past dates dropped, bad dates and times refused, a repeated date keeps the last row.
	 */
	public function test_special_hours_input() {
		$this->assertSame(
			array(
				array(
					'date'   => '2026-12-24',
					'closed' => false,
					'ranges' => array(
						array(
							'open'  => '10:00',
							'close' => '15:00',
						),
					),
					'note'   => 'Christmas Eve',
				),
				array(
					'date'   => '2026-12-25',
					'closed' => true,
					'ranges' => array(),
					'note'   => 'Christmas Day',
				),
			),
			Special_Hours::from_input(
				array(
					array(
						'date'   => '2026-12-25',
						'closed' => '1',
						'hours'  => '11:00-14:00',
						'note'   => 'Christmas Day',
					),
					array(
						'date'  => '2026-12-24',
						'hours' => '9:00-12:00',
					),
					array(
						'date'  => '2026-12-24',
						'hours' => '10:00-15:00',
						'note'  => 'Christmas Eve',
					),
					array(
						'date'   => '2026-12-01',
						'closed' => '1',
					),
					array( 'date' => '' ),
				),
				'2026-12-07'
			)
		);
		$this->assertWPError( Special_Hours::from_input( array( array( 'date' => '2026-02-30' ) ), '2026-12-07' ) );
		$this->assertWPError( Special_Hours::from_input( array( array( 'date' => '2026-12-25' ) ), '2026-12-07' ), 'Closed or hours' );
		$this->assertWPError( Special_Hours::from_input( array( array( 'date' => '2028-01-01', 'closed' => '1' ) ), '2026-12-07' ), 'Too far ahead' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertWPError(
			Special_Hours::from_input(
				array(
					array(
						'date'   => '2026-12-25',
						'closed' => '1',
						'note'   => 'Award-winning holiday menu',
					),
				),
				'2026-12-07'
			),
			'Owner wording rules apply to the note'
		);
	}

	/**
	 * A date's hours replace that day's weekly hours; the night before still runs past midnight.
	 */
	public function test_special_hours_open_or_closed() {
		$ids = $this->holiday_places();
		$at  = static function ( string $local ): int {
			return ( new \DateTimeImmutable( $local, new \DateTimeZone( 'Pacific/Guam' ) ) )->getTimestamp();
		};

		$this->assertTrue( Special_Hours::is_open( $ids['late'], $at( '2026-12-07 01:00' ) ), 'Sunday night runs into Monday' );
		$this->assertFalse( Special_Hours::is_open( $ids['late'], $at( '2026-12-07 19:30' ) ), 'Closed Monday' );
		$this->assertFalse( Special_Hours::is_open( $ids['late'], $at( '2026-12-08 01:00' ) ), 'No Monday night' );
		$this->assertTrue( Special_Hours::is_open( $ids['late'], $at( '2026-12-08 19:30' ) ), 'Weekly hours again' );
		$this->assertFalse( Special_Hours::is_open( $ids['lunch'], $at( '2026-12-07 12:00' ) ), 'The weekly lunch is replaced' );
		$this->assertTrue( Special_Hours::is_open( $ids['lunch'], $at( '2026-12-07 19:30' ) ) );

		$this->assertSame( array( $ids['late'], $ids['lunch'] ), Special_Hours::restaurants_on( '2026-12-07' ), 'Saving the meta updates the date index' );
		$this->assertSame( '2026-12-07:0-120;2026-12-08:1020-1560', Special_Hours::browser_days( $ids['late'], '2026-12-07' ) );
		$this->assertSame( '', Special_Hours::browser_days( $ids['plain'], '2026-12-07' ) );
		$this->assertSame( '', Special_Hours::browser_days( $ids['late'], '2026-12-10' ), 'Past dates are not sent' );
	}

	/**
	 * Open now, open late and open at use the special hours; other places keep the weekly SQL.
	 */
	public function test_directory_filters_use_special_hours() {
		$this->holiday_places();

		$this->assertSame( array( 'Lunch Counter', 'Plain Diner' ), $this->titles( array( 'open' => '1' ) ) );
		$this->assertSame( array( 'Late Grill' ), $this->titles( array( 'at' => 'mo-0100' ) ), 'Sunday night into Monday' );
		$this->assertSame( array(), $this->titles( array( 'at' => 'tu-0100' ) ), 'Closed Monday, so no Monday night' );
		$this->assertSame( array( 'Lunch Counter', 'Plain Diner' ), $this->titles( array( 'at' => 'mo-2030' ) ) );
		$this->assertSame( array( 'Late Grill', 'Plain Diner' ), $this->titles( array( 'at' => 'tu-2030' ) ) );
		$this->assertSame( array( 'Lunch Counter' ), $this->titles( array( 'late' => '1' ) ), 'Open at 22:00 tonight' );

		$this->now = self::MONDAY_EVENING + 7 * DAY_IN_SECONDS;
		$this->assertSame( array( 'Late Grill', 'Plain Diner' ), $this->titles( array( 'open' => '1' ) ), 'A week later the weekly hours apply' );
	}

	/**
	 * The hours table lists the coming dates and gives the browser the days.
	 */
	public function test_hours_table_lists_special_hours() {
		$ids = $this->holiday_places();
		$this->go_to( get_permalink( $ids['late'] ) );
		$html = Blocks::restaurant_details( array() );
		$this->assertStringContainsString( 'Holiday and special hours', $html );
		$this->assertStringContainsString( '(Staff party): Closed', $html );
		$this->assertStringContainsString( 'data-gr-special="2026-12-07:0-120;2026-12-08:1020-1560"', $html );
		$this->go_to( get_permalink( $ids['plain'] ) );
		$this->assertStringNotContainsString( 'Holiday and special hours', Blocks::restaurant_details( array() ) );
	}

	/**
	 * Stops repeat weekly within the limit; unknown villages are dropped; a place is required.
	 */
	public function test_truck_stops() {
		$stops = Truck_Stops::from_input(
			array(
				array(
					'date'    => '2026-12-09',
					'from'    => '5:00',
					'to'      => '21:00',
					'place'   => 'Chamorro Village parking lot',
					'village' => 'hagatna',
					'repeat'  => '3',
				),
				array(
					'date'    => '2026-12-08',
					'from'    => '11:00',
					'to'      => '14:00',
					'place'   => 'Route 16 turnout',
					'village' => 'nowhere',
				),
			),
			'2026-12-07'
		);
		$this->assertSame( array( '2026-12-08', '2026-12-09', '2026-12-16', '2026-12-23' ), array_column( $stops, 'date' ) );
		$this->assertSame( '05:00', $stops[1]['from'] );
		$this->assertSame( '', $stops[0]['village'] );
		$this->assertWPError( Truck_Stops::from_input( array( array( 'date' => '2026-12-09', 'from' => '17:00', 'to' => '21:00' ) ), '2026-12-07' ), 'A place is required' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertWPError( Truck_Stops::from_input( array( array( 'date' => '2026-12-09', 'from' => '25:00', 'to' => '21:00', 'place' => 'Lot' ) ), '2026-12-07' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$truck  = $this->restaurant( 'Kelaguen Truck', array( Truck_Stops::META => $stops ) );
		$closed = $this->restaurant(
			'Closed Truck',
			array(
				Truck_Stops::META       => $stops,
				Restaurant_Meta::STATUS => 'permanently_closed',
			)
		);
		$draft  = $this->restaurant( 'Draft Truck', array( Truck_Stops::META => $stops ), 'draft' );
		$listed = Truck_Stops::between( '2026-12-07', '2026-12-13' );
		$this->assertSame( array( $truck ), array_values( array_unique( array_column( $listed, 'restaurant_id' ) ) ), 'Published, not closed' );
		$this->assertSame( array( '2026-12-08', '2026-12-09' ), array_column( $listed, 'date' ) );
		$this->assertSame( 'Chamorro Village parking lot, Hagåtña', Truck_Stops::place_label( $listed[1] ) );
		$this->assertNotContains( $closed, array_column( $listed, 'restaurant_id' ) );
		$this->assertNotContains( $draft, array_column( $listed, 'restaurant_id' ) );
		$this->assertStringContainsString( 'Where to find us', Truck_Stops::render( $truck ) );
		$this->assertStringContainsString( 'December 16, 2026', Truck_Stops::render( $truck ) );
		$this->assertStringNotContainsString( 'December 23, 2026', Truck_Stops::render( $truck ), 'Two weeks ahead only' );
	}

	/**
	 * The verified owner sets holiday hours and stops through the one owner write path.
	 */
	public function test_owner_sets_holiday_hours_and_stops() {
		$place = $this->restaurant( 'Owner Grill', array( Restaurant_Meta::HOURS => $this->every_day( '11:00', '21:00' ) ) );
		$owner = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		Roles::set_owned_restaurants( $owner, array( $place ) );
		$other = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );

		$input = array(
			'special_hours' => array(
				array(
					'date'   => '2026-12-25',
					'closed' => '1',
				),
			),
			'truck_stops'   => array(
				array(
					'date'  => '2026-12-12',
					'from'  => '17:00',
					'to'    => '21:00',
					'place' => 'Skinner Plaza',
				),
			),
		);
		$this->assertWPError( Owner_Content::apply( $other, $place, $input ) );
		$result = Owner_Content::apply( $owner, $place, $input );
		$this->assertIsArray( $result );
		$this->assertEqualSets( array( Special_Hours::META, Truck_Stops::META ), $result['changed'] );
		$this->assertSame( array( $place ), Special_Hours::restaurants_on( '2026-12-25' ) );
		$this->assertCount( 1, Truck_Stops::upcoming( $place ) );

		global $wpdb;
		$sources = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT source FROM %i WHERE restaurant_id = %d AND field = %s', Schema::table( Schema::CHANGE_LOG ), $place, Special_Hours::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'owner' ), $sources );

		$this->assertWPError( Owner_Content::apply( $owner, $place, array( 'special_hours' => array( array( 'date' => '2026-12-26' ) ) ) ) );
		$this->assertSame( array( $place ), Special_Hours::restaurants_on( '2026-12-25' ), 'A refused edit changes nothing' );
	}

	/**
	 * Editors set both lists from the restaurant screen, with a nonce.
	 */
	public function test_editor_box_saves() {
		$place = $this->restaurant( 'Editor Grill' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_POST = array(
			'gr_special_hours' => array(
				array(
					'date'  => '2026-12-31',
					'hours' => '11:00-18:00',
					'note'  => 'New Year\'s Eve',
				),
			),
			'gr_truck_stops'   => array(),
		);
		Special_Hours_Box::save( $place );
		$this->assertSame( '', get_post_meta( $place, Special_Hours::META, true ), 'No nonce, no save' );

		$_POST[ Special_Hours_Box::NONCE_FIELD ] = wp_create_nonce( Special_Hours_Box::NONCE_ACTION );
		Special_Hours_Box::save( $place );
		$this->assertSame( '2026-12-31', Special_Hours::stored( $place )[0]['date'] );
		$this->assertSame( array( $place ), Special_Hours::restaurants_on( '2026-12-31' ) );

		$_POST['gr_special_hours'] = array();
		Special_Hours_Box::save( $place );
		$this->assertSame( '', get_post_meta( $place, Special_Hours::META, true ), 'Clearing the rows deletes the meta' );
		$this->assertSame( array(), Special_Hours::restaurants_on( '2026-12-31' ) );
		$_POST = array();
	}

	/**
	 * Every shipped market and fiesta names a seeded village and its source.
	 */
	public function test_shipped_lists() {
		$markets = Local_Events::markets();
		$fiestas = Local_Events::fiestas();
		$this->assertCount( 4, $markets );
		$this->assertCount( 40, $fiestas );
		foreach ( array_merge( $markets, $fiestas ) as $row ) {
			$this->assertNotEmpty( term_exists( $row['village'], Content_Types::TAX_VILLAGE ), $row['name'] . ' has a seeded village' );
			$this->assertStringStartsWith( 'https://', $row['source'], $row['name'] . ' has a source' );
		}
		$this->assertSame( array( 'Chamorro Village Wednesday Night Market' ), array_column( Local_Events::markets_on( '2026-12-09' ), 'name' ) );
		$this->assertSame( array( 'Dededo Flea Market' ), array_column( Local_Events::markets_on( '2026-12-12' ), 'name' ) );
		$this->assertSame( '5:30 pm–9:30 pm', Local_Events::market_hours( $markets[0] ) );
		$this->assertSame( 'hours not published', Local_Events::market_hours( $markets[1] ) );
		$this->assertSame( array( '2026-12-08', '2026-12-13' ), array_column( Local_Events::fiestas_between( '2026-12-07', '2026-12-13' ), 'date' ) );
	}

	/**
	 * A village page shows the next fiesta first, the others, and the source.
	 */
	public function test_village_line() {
		$agat = get_term_by( 'slug', 'agat', Content_Types::TAX_VILLAGE );
		$this->assertInstanceOf( \WP_Term::class, $agat );
		$html = Local_Events::village_line( $agat, '2026-07-20' );
		$this->assertStringContainsString( 'Next village fiesta:</strong> Santa Ana, July 25', $html );
		$this->assertStringContainsString( 'Other 2026 fiestas: Our Lady of Mount Carmel, July 11; Santa Rosa, August 29', $html );
		$this->assertStringContainsString( 'href="https://www.guampedia.com/guam-fiesta-calendar"', $html );

		$html = Local_Events::village_line( $agat, '2026-12-01' );
		$this->assertStringNotContainsString( 'Next village fiesta', $html );
		$this->assertStringContainsString( '2026 fiestas: Our Lady of Mount Carmel, July 11', $html );

		$this->assertStringContainsString( 'gr-fiestas', Intro::render( $agat ) );
		$cuisine = get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_CUISINE,
				'hide_empty' => false,
				'number'     => 1,
			)
		)[0];
		$this->assertStringNotContainsString( 'gr-fiestas', Intro::render( $cuisine ) );
	}

	/**
	 * The editing screen refuses rows without a village or source and saves all or nothing; reset goes back to the shipped lists.
	 */
	public function test_local_events_screen() {
		$market = array(
			'name'    => 'Test Night Market',
			'village' => 'hagatna',
			'days'    => array( 'fr' ),
			'opens'   => '18:00',
			'closes'  => '22:00',
			'source'  => 'https://example.org/market',
		);
		$fiesta = array(
			'date'    => '2027-01-03',
			'village' => 'barrigada',
			'name'    => 'Santo Niño',
			'source'  => 'https://www.guampedia.com/guam-fiesta-calendar',
		);

		$problems = Local_Events_Screen::apply(
			array(
				'markets' => array( $market, array( 'source' => '' ) + $market ),
				'fiestas' => array( $fiesta ),
			)
		);
		$this->assertCount( 1, $problems );
		$this->assertCount( 4, Local_Events::markets(), 'Nothing saved' );

		$this->assertCount( 1, Local_Events_Screen::apply( array( 'markets' => array( array( 'village' => 'nowhere' ) + $market ) ) ), 'An unknown village is refused' );
		$this->assertSame(
			array(),
			Local_Events_Screen::apply(
				array(
					'markets' => array( $market ),
					'fiestas' => array( $fiesta ),
				)
			)
		);
		$this->assertSame( array( 'Test Night Market' ), array_column( Local_Events::markets(), 'name' ) );
		$this->assertSame( array( '2027-01-03' ), array_column( Local_Events::fiestas(), 'date' ) );

		$this->assertSame( array(), Local_Events_Screen::apply( array( 'reset' => true ) ) );
		$this->assertCount( 4, Local_Events::markets() );
	}

	/**
	 * This week: seven days from today with fiestas, markets, stops and holiday hours in that order.
	 */
	public function test_this_week() {
		$this->holiday_places();
		$this->restaurant(
			'Kelaguen Truck',
			array(
				Truck_Stops::META => array(
					array(
						'date'    => '2026-12-09',
						'from'    => '17:00',
						'to'      => '21:00',
						'place'   => 'Chamorro Village parking lot',
						'village' => 'hagatna',
					),
				),
			)
		);

		$days = This_Week::days();
		$this->assertSame( array( '2026-12-07', '2026-12-08', '2026-12-09', '2026-12-10', '2026-12-11', '2026-12-12', '2026-12-13' ), array_keys( $days ) );
		$this->assertSame( array( 'Late Grill', 'Lunch Counter' ), array_map( 'get_the_title', array_column( $days['2026-12-07']['hours'], 'restaurant_id' ) ) );
		$this->assertSame( 'Immaculate Conception (Santa Marian Kamalen)', $days['2026-12-08']['fiestas'][0]['name'] );
		$this->assertCount( 1, $days['2026-12-09']['stops'] );

		$html = This_Week::render();
		$this->assertStringContainsString( 'Today, Monday, December 7', $html );
		$this->assertStringContainsString( 'Tomorrow, Tuesday, December 8', $html );
		$this->assertStringContainsString( 'Hågat Tuesday Night Market', $html );
		$this->assertStringContainsString( '5:00 pm–9:00 pm</span> <a href="', $html );
		$this->assertStringContainsString( 'Nothing listed for this day yet.', $html, 'Friday has nothing' );
		$wednesday = substr( $html, (int) strpos( $html, 'gr-week-2026-12-09' ) );
		$this->assertLessThan( strpos( $wednesday, 'Food trucks and pop-ups' ), strpos( $wednesday, 'Markets' ), 'Markets before trucks' );
	}

	/**
	 * New on Guam: an opening date wins, then the first-listed date; the starting
	 * inventory, earlier openings, drafts and closures are left out.
	 */
	public function test_new_places_months() {
		$this->import();
		$this->assertSame( array(), New_Places::build(), 'The first import is the starting inventory' );

		$opened = $this->restaurant( 'Opened In November', array( Restaurant_Meta::OPENED_ON => '2026-11-14' ) );
		$found  = $this->restaurant( 'Found Today' );
		$this->restaurant( 'Opened Long Ago', array( Restaurant_Meta::OPENED_ON => '2019' ) );
		$this->restaurant( 'Draft Place', array(), 'draft' );
		$this->restaurant(
			'Closed Place',
			array( Restaurant_Meta::STATUS => 'permanently_closed' )
		);

		global $wpdb;
		$wpdb->update( Schema::table( Schema::CHANGE_LOG ), array( 'detected_at' => '2026-12-01 00:00:00' ), array( 'restaurant_id' => $found ) ); // phpcs:ignore WordPress.DB

		$months = New_Places::build();
		$this->assertSame( array( '2026-12', '2026-11' ), array_keys( $months ), 'Newest month first; earlier openings, drafts and closures left out' );
		$this->assertSame( array( $opened ), array_column( $months['2026-11'], 'id' ) );
		$this->assertTrue( $months['2026-11'][0]['opened'] );
		$this->assertSame( array( $found ), array_column( $months['2026-12'], 'id' ) );
		$this->assertFalse( $months['2026-12'][0]['opened'] );
		$this->assertSame( '2026-12-01', $months['2026-12'][0]['date'], 'First listed, Guam date' );
	}

	/**
	 * Routes, titles, robots, the sitemap and the empty-month 404.
	 */
	public function test_fresh_routes() {
		$this->set_permalink_structure( '/%postname%/' );
		Fresh_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );
		$ids = array();
		foreach ( array( 'One', 'Two', 'Three', 'Four', 'Five' ) as $name ) {
			$ids[] = $this->restaurant( 'November ' . $name, array( Restaurant_Meta::OPENED_ON => '2026-11-0' . ( count( $ids ) + 1 ) ) );
		}
		$this->restaurant( 'October One', array( Restaurant_Meta::OPENED_ON => '2026-10' ) );

		$this->go_to( Fresh_Pages::url( 'week' ) );
		$this->assertSame( home_url( '/this-week/' ), Fresh_Pages::url( 'week' ) );
		$this->assertSame( 'week', Fresh_Pages::current_page() );
		$this->assertFalse( is_home() );
		$this->assertSame( 'This week on Guam: night markets, food trucks and fiestas', Seo::plugin_page()['title'] );
		$this->assertFalse( Seo::plugin_page()['filtered'] );

		$this->go_to( Fresh_Pages::url( 'new' ) );
		$this->assertSame( 'new', Fresh_Pages::current_page() );
		$html = Fresh_Pages::render_current();
		$this->assertStringContainsString( '<h2 class="gr-section-title">November 2026</h2>', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/new/2026-10/' ) . '">October 2026</a> <span class="gr-dish-index__count">1 place</span>', $html );

		$this->go_to( Fresh_Pages::url( 'new', '2026-11' ) );
		$this->assertSame( '2026-11', Fresh_Pages::current_month() );
		$this->assertSame( 'New restaurants on Guam, November 2026', Seo::plugin_page()['title'] );
		$this->assertFalse( Seo::plugin_page()['filtered'], 'Five places are enough to index' );
		$html = Fresh_Pages::render_current();
		$this->assertLessThan( strpos( $html, 'November One' ), strpos( $html, 'November Five' ), 'Newest first' );
		$this->assertStringContainsString( 'rel="prev">Earlier: October 2026</a>', $html );

		$this->go_to( Fresh_Pages::url( 'new', '2026-10' ) );
		$this->assertTrue( Seo::plugin_page()['filtered'], 'One place: noindex' );

		$this->go_to( home_url( '/new/2026-09/' ) );
		$this->assertTrue( is_404() );
		$this->go_to( home_url( '/new/2026-13/' ) );
		$this->assertTrue( is_404() );

		$this->assertSame(
			array( home_url( '/this-week/' ), home_url( '/new/' ), home_url( '/new/2026-11/' ) ),
			wp_list_pluck( Fresh_Pages::sitemap_pages(), 'loc' )
		);
	}

	/**
	 * The newsletter links both pages.
	 */
	public function test_newsletter_links() {
		$this->set_permalink_structure( '/%postname%/' );
		$html = Renderer::html(
			array(
				'start'    => '2026-11-30',
				'end'      => '2026-12-06',
				'openings' => array(),
				'top'      => array(),
				'awards'   => array(),
				'closures' => array(),
				'sponsor'  => null,
			)
		);
		$this->assertStringContainsString( 'href="' . home_url( '/this-week/' ) . '"', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/new/' ) . '"', $html );
	}
}
