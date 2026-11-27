<?php
/**
 * Owner-posted specials and happy hours, and the Specials page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Owners\Specials;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Fresh_Pages;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Site\Specials_Page;

/**
 * English audience plan E1 (comparison addition 16).
 *
 * @covers \GuamRestaurants\Core\Owners\Specials
 * @covers \GuamRestaurants\Core\Site\Specials_Page
 * @covers \GuamRestaurants\Core\Owners\Owner_Content
 */
class Specials_Test extends Guam_Test_Case {

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
	 * Fixed clock and permalinks.
	 */
	public function set_up() {
		parent::set_up();
		$this->now = self::MONDAY_EVENING;
		add_filter( 'guam_restaurants_now', array( $this, 'clock' ) );
		$this->set_permalink_structure( '/%postname%/' );
		Fresh_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );
	}

	/**
	 * Clears the request.
	 */
	public function tear_down() {
		$_GET = array();
		wp_set_current_user( 0 );
		parent::tear_down();
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
	 * A published restaurant, claimed by a new owner unless $claimed is false.
	 *
	 * @param string $name    Title.
	 * @param string $village Village slug or ''.
	 * @param bool   $claimed Whether an owner is linked.
	 * @return array{0: int, 1: int} Restaurant and owner IDs.
	 */
	private function place( string $name, string $village = '', bool $claimed = true ): array {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => $name,
				'post_status' => 'publish',
			)
		);
		if ( '' !== $village ) {
			wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		}
		$owner = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		if ( $claimed ) {
			Roles::set_owned_restaurants( $owner, array( $id ) );
		}
		return array( $id, $owner );
	}

	/**
	 * A normalized special.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private function special( array $fields ): array {
		return (array) Specials::normalize( $fields + array( 'title' => 'Test special' ) );
	}

	/**
	 * Form rows are checked and cleaned.
	 */
	public function test_from_input() {
		$out = Specials::from_input(
			array(
				array(
					'kind'    => 'happy_hour',
					'title'   => '',
					'details' => '$3 drafts',
					'days'    => array( 'mo', 'tu', 'we', 'th', 'fr' ),
					'from'    => '16:00',
					'to'      => '19:00',
				),
				array(
					'title'  => 'Weekday lunch set',
					'days'   => array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ),
					'starts' => '2026-12-01',
					'ends'   => '2026-12-31',
				),
				array(
					'kind'  => 'bogus',
					'title' => 'Fiesta plate',
					'days'  => array( 'xx', 'sa' ),
					'from'  => '9:30',
					'to'    => '13:00',
				),
				array( 'title' => '' ),
				array(
					'kind' => 'special',
					'from' => '10:00',
					'to'   => '11:00',
				),
			),
			'2026-12-07'
		);
		$this->assertIsArray( $out );
		$this->assertCount( 3, $out, 'Rows without a title are dropped, except a happy hour with times' );
		$this->assertSame(
			array(
				'kind'    => 'happy_hour',
				'title'   => '',
				'details' => '$3 drafts',
				'days'    => array( 'mo', 'tu', 'we', 'th', 'fr' ),
				'from'    => '16:00',
				'to'      => '19:00',
				'starts'  => '',
				'ends'    => '',
			),
			$out[0]
		);
		$this->assertSame( array(), $out[1]['days'], 'All seven days are stored as every day' );
		$this->assertSame( 'special', $out[2]['kind'] );
		$this->assertSame( array( 'sa' ), $out[2]['days'] );
		$this->assertSame( '09:30', $out[2]['from'] );

		$bad = array(
			'happy hour without times' => array(
				'kind'  => 'happy_hour',
				'title' => 'Happy hour',
			),
			'only a start time'        => array(
				'title' => 'Lunch',
				'from'  => '11:00',
			),
			'same start and end'       => array(
				'title' => 'Lunch',
				'from'  => '11:00',
				'to'    => '11:00',
			),
			'not a time'               => array(
				'title' => 'Lunch',
				'from'  => '25:00',
				'to'    => '26:00',
			),
			'ends before it starts'    => array(
				'title'  => 'Lunch',
				'starts' => '2026-12-10',
				'ends'   => '2026-12-01',
			),
			'starts after a year'      => array(
				'title'  => 'Lunch',
				'starts' => '2028-01-01',
			),
			'not a date'               => array(
				'title' => 'Lunch',
				'ends'  => '2026-02-30',
			),
			'award claim'              => array( 'title' => 'Award-winning ribs night' ),
			'featured'                 => array(
				'title'   => 'Ribs',
				'details' => 'Our featured top pick',
			),
		);
		foreach ( $bad as $label => $row ) {
			$this->assertWPError( Specials::from_input( array( $row ), '2026-12-07' ), $label );
		}
		$this->assertWPError( Specials::from_input( array_fill( 0, Specials::MAX + 1, array( 'title' => 'Lunch' ) ) ), 'At most MAX' );
	}

	/**
	 * Specials saved before kinds, days and times read as all-day specials.
	 */
	public function test_old_specials_still_read() {
		[ $id ] = $this->place( 'Old Grill' );
		update_post_meta(
			$id,
			Owner_Content::SPECIALS,
			array(
				array(
					'title'   => 'Fiesta plate',
					'details' => '',
					'ends'    => '',
				),
				array(
					'title'   => 'Gone',
					'details' => '',
					'ends'    => '2026-12-01',
				),
			)
		);
		$current = Specials::current( $id );
		$this->assertCount( 1, $current );
		$this->assertSame( 'special', $current[0]['kind'] );
		$this->assertSame( array(), $current[0]['days'] );
		$this->assertSame( '', $current[0]['from'] );
		$this->assertTrue( Specials::on_date( $current[0], '2026-12-07' ) );
		$this->assertFalse( Specials::running_at( $current[0] ), 'All day is never "on now"' );
		$this->assertSame( 'Every day, all day', Specials::when_label( $current[0] ) );
	}

	/**
	 * Days, dates and times, Guam time, including past midnight.
	 */
	public function test_running_at() {
		$weekday = $this->special(
			array(
				'kind' => 'happy_hour',
				'days' => array( 'mo', 'tu', 'we', 'th', 'fr' ),
				'from' => '17:00',
				'to'   => '20:00',
			)
		);
		$this->assertTrue( Specials::running_at( $weekday ), 'Monday 19:30' );
		$this->assertFalse( Specials::running_at( $weekday, self::MONDAY_EVENING + 31 * MINUTE_IN_SECONDS ), 'Ends at 20:00' );
		$this->assertTrue( Specials::running_at( $weekday, self::MONDAY_EVENING - 5 * DAY_IN_SECONDS ), 'Wednesday' );
		$this->assertFalse( Specials::running_at( $weekday, self::MONDAY_EVENING - DAY_IN_SECONDS ), 'Not on Sunday' );

		$late         = $this->special(
			array(
				'days' => array( 'fr' ),
				'from' => '21:00',
				'to'   => '02:00',
			)
		);
		$saturday_1am = self::MONDAY_EVENING - 2 * DAY_IN_SECONDS - 18 * HOUR_IN_SECONDS - 30 * MINUTE_IN_SECONDS;
		$this->assertTrue( Specials::running_at( $late, $saturday_1am ), 'Friday night runs past midnight' );
		$this->assertFalse( Specials::running_at( $late, $saturday_1am - DAY_IN_SECONDS ), 'Not after a Thursday' );
		$this->assertTrue( Specials::running_at( $late, $saturday_1am - 4 * HOUR_IN_SECONDS ), 'Friday 21:30' );

		$dated = $this->special(
			array(
				'starts' => '2026-12-08',
				'ends'   => '2026-12-31',
				'from'   => '11:00',
				'to'     => '22:00',
			)
		);
		$this->assertFalse( Specials::running_at( $dated ), 'Starts tomorrow' );
		$this->assertTrue( Specials::running_at( $dated, self::MONDAY_EVENING + DAY_IN_SECONDS ) );
		$this->assertFalse( Specials::running_at( $dated, self::MONDAY_EVENING + 30 * DAY_IN_SECONDS ), 'Ended' );
		$this->assertTrue( Specials::on_any_day( $dated, '2026-12-07', '2026-12-13' ) );
	}

	/**
	 * Days and times in words.
	 */
	public function test_labels() {
		update_option( 'time_format', 'g:i a' );
		$this->assertSame( 'Monday to Friday', Specials::days_label( array( 'mo', 'tu', 'we', 'th', 'fr' ) ) );
		$this->assertSame( 'Saturday and Sunday', Specials::days_label( array( 'su', 'sa' ) ) );
		$this->assertSame( 'Monday, Wednesday, and Friday', Specials::days_label( array( 'mo', 'we', 'fr' ) ) );
		$this->assertSame( 'Monday to Wednesday and Saturday', Specials::days_label( array( 'mo', 'tu', 'we', 'sa' ) ) );
		$this->assertSame( 'Every day', Specials::days_label( array() ) );
		$special = $this->special(
			array(
				'kind'  => 'happy_hour',
				'title' => '',
				'days'  => array( 'mo', 'tu', 'we', 'th', 'fr' ),
				'from'  => '16:00',
				'to'    => '19:00',
				'ends'  => '2026-12-31',
			)
		);
		$this->assertSame( 'Monday to Friday, 4:00 pm–7:00 pm', Specials::when_label( $special ) );
		$this->assertSame( 'Happy hour', Specials::title( $special ) );
		$this->assertSame( 'Until December 31, 2026', Specials::dates_label( $special ) );
		$this->assertSame( 'From December 20, 2026', Specials::dates_label( $this->special( array( 'starts' => '2026-12-20' ) ) ) );
		$this->assertSame( '', Specials::dates_label( $this->special( array( 'starts' => '2026-12-01' ) ) ), 'A start date already past is not shown' );
	}

	/**
	 * Only claimed, published, open restaurants; on now first; filters.
	 */
	public function test_find() {
		[ $bar ]    = $this->place( 'Zebra Bar', 'tamuning' );
		[ $cafe ]   = $this->place( 'Alpha Cafe', 'hagatna' );
		[ $grill ]  = $this->place( 'Middle Grill', 'tamuning' );
		[ $nobody ] = $this->place( 'Unclaimed Diner', 'tamuning', false );
		[ $closed ] = $this->place( 'Closed Diner', 'tamuning' );
		update_post_meta( $closed, Restaurant_Meta::STATUS, 'permanently_closed' );

		$happy = array(
			'kind' => 'happy_hour',
			'days' => array( 'mo', 'tu', 'we', 'th', 'fr' ),
			'from' => '17:00',
			'to'   => '20:00',
		);
		update_post_meta( $bar, Owner_Content::SPECIALS, array( $happy ) );
		update_post_meta( $cafe, Owner_Content::SPECIALS, array( array( 'title' => 'Lunch set' ) ) );
		update_post_meta(
			$grill,
			Owner_Content::SPECIALS,
			array(
				array(
					'title' => 'Saturday ribs',
					'days'  => array( 'sa' ),
				),
			)
		);
		update_post_meta( $nobody, Owner_Content::SPECIALS, array( array( 'title' => 'Not shown' ) ) );
		update_post_meta( $closed, Owner_Content::SPECIALS, array( array( 'title' => 'Not shown' ) ) );

		$today = Specials::find( 'today' );
		$this->assertSame( array( $bar, $cafe ), wp_list_pluck( $today, 'restaurant_id' ), 'On now first, then by start time and name; Saturday-only and unclaimed or closed places left out' );
		$this->assertTrue( $today[0]['on_now'] );
		$this->assertTrue( $today[0]['specials'][0]['on_now'] );

		$this->assertSame( array( $bar ), wp_list_pluck( Specials::find( 'now' ), 'restaurant_id' ) );
		$this->assertSame( array( $bar ), wp_list_pluck( Specials::find( 'today', 'happy_hour' ), 'restaurant_id' ) );
		$this->assertSame( array( $cafe ), wp_list_pluck( Specials::find( 'today', 'special' ), 'restaurant_id' ) );
		$this->assertSame( array( $cafe ), wp_list_pluck( Specials::find( 'today', '', 'hagatna' ), 'restaurant_id' ) );
		$this->assertSame( array( $bar, $cafe, $grill ), wp_list_pluck( Specials::find( 'week' ), 'restaurant_id' ) );

		$this->assertSame( array( 'hagatna', 'tamuning' ), wp_list_pluck( Specials::villages(), 'slug' ) );
	}

	/**
	 * The owner posts through the one owner write path; the listing shows it.
	 */
	public function test_owner_posts_and_listing_shows() {
		[ $id, $owner ] = $this->place( 'Harbor Grill' );
		$result         = Owner_Content::apply(
			$owner,
			$id,
			array(
				'specials' => array(
					array(
						'kind'    => 'happy_hour',
						'details' => 'Half-price appetizers',
						'from'    => '17:00',
						'to'      => '20:00',
					),
				),
			)
		);
		$this->assertSame( array( Owner_Content::SPECIALS ), $result['changed'] );
		$other = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		$this->assertWPError( Owner_Content::apply( $other, $id, array( 'specials' => array() ) ), 'Only the verified owner' );

		$this->go_to( get_permalink( $id ) );
		$html = Owner_Content::render_block( array() );
		$this->assertStringContainsString( 'Specials and happy hours', $html );
		$this->assertStringContainsString( 'Half-price appetizers', $html );
		$this->assertStringContainsString( 'On now', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/specials/' ) . '"', $html );

		wp_set_current_user( $owner );
		$_GET['restaurant'] = (string) $id;
		ob_start();
		Dashboard::screen();
		$form = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="specials[0][kind]"', $form );
		$this->assertStringContainsString( 'name="specials[0][days][]" value="sa"', $form );
		$this->assertStringContainsString( 'name="specials[0][from]" value="17:00"', $form );
		$this->assertStringContainsString( 'name="specials[2][title]"', $form, 'Empty rows to add more' );
	}

	/**
	 * Route, title, filters, robots and the sitemap.
	 */
	public function test_page() {
		$ids = array();
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $name ) {
			[ $id ] = $this->place( 'Specials ' . $name, 'tamuning' );
			update_post_meta( $id, Owner_Content::SPECIALS, array( array( 'title' => 'Plate ' . $name ) ) );
			$ids[] = $id;
		}

		$this->go_to( Fresh_Pages::url( 'specials' ) );
		$this->assertSame( home_url( '/specials/' ), Fresh_Pages::url( 'specials' ) );
		$this->assertSame( 'specials', Fresh_Pages::current_page() );
		$this->assertFalse( is_home() );
		$this->assertSame( 'Specials and happy hours on Guam', Seo::plugin_page()['title'] );
		$this->assertTrue( Seo::plugin_page()['filtered'], 'Four places: not indexed yet' );
		$this->assertNotContains( home_url( '/specials/' ), wp_list_pluck( Fresh_Pages::sitemap_pages(), 'loc' ) );

		$html = Fresh_Pages::render_current();
		$this->assertStringContainsString( 'Plate One', $html );
		$this->assertStringContainsString( '4 restaurants', $html );
		$this->assertStringContainsString( 'aria-current="page">Today', $html );
		$this->assertStringContainsString( 'href="' . home_url( '/specials/?when=now' ) . '"', $html );

		[ $five ] = $this->place( 'Specials Five', 'hagatna' );
		update_post_meta(
			$five,
			Owner_Content::SPECIALS,
			array(
				array(
					'kind' => 'happy_hour',
					'from' => '17:00',
					'to'   => '20:00',
				),
			)
		);
		$this->go_to( Fresh_Pages::url( 'specials' ) );
		$this->assertFalse( Seo::plugin_page()['filtered'], 'Five places are enough to index' );
		$this->assertContains( home_url( '/specials/' ), wp_list_pluck( Fresh_Pages::sitemap_pages(), 'loc' ) );
		$this->assertStringContainsString( 'name="village"', Fresh_Pages::render_current(), 'Village picker once two villages have specials' );

		$this->go_to( add_query_arg( 'type', 'happy-hour', Fresh_Pages::url( 'specials' ) ) );
		$_GET = array( 'type' => 'happy-hour' );
		$this->assertTrue( Seo::plugin_page()['filtered'], 'Filtered views are not indexed' );
		$html = Fresh_Pages::render_current();
		$this->assertStringContainsString( 'Specials Five', $html );
		$this->assertStringNotContainsString( 'Plate One', $html );
		$this->assertSame( home_url( '/specials/' ), Fresh_Pages::canonical() );

		$_GET = array(
			'when'    => 'bogus',
			'type'    => 'nope',
			'village' => 'no-such-village',
		);
		$this->assertSame(
			array(
				'when'    => 'today',
				'type'    => '',
				'village' => '',
			),
			Specials_Page::current_filters()
		);
	}
}
