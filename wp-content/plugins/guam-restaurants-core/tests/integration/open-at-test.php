<?php
/**
 * "Open at", Breakfast and "Hours checked" tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Blocks;
use GuamRestaurants\Core\Site\Directory_Query;
use GuamRestaurants\Core\Site\Directory_View;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\Pages;
use GuamRestaurants\Core\Site\Picker;
use GuamRestaurants\Core\Site\Query_Parser;
use GuamRestaurants\Core\Site\Restaurant_View;

/**
 * Open at a chosen day and time, the Breakfast chip, and when the hours
 * were last checked (comparison additions 13 and 14).
 *
 * @covers \GuamRestaurants\Core\Site\Filters
 * @covers \GuamRestaurants\Core\Site\Directory_Query
 * @covers \GuamRestaurants\Core\Site\Open_Hours
 * @covers \GuamRestaurants\Core\Site\Restaurant_View
 */
class Open_At_Test extends Guam_Test_Case {

	/**
	 * Saturday 2026-10-10 19:30 in Guam (09:30 UTC).
	 */
	private const SATURDAY_EVENING = 1791624600;

	/**
	 * Builds a small directory with a fixed clock.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'guam_restaurants_now', array( $this, 'saturday_evening' ) );

		$this->place(
			'Evening Grill',
			'tamuning',
			$this->every_day( '17:00', '02:00' )
		);
		$this->place(
			'Saturday Lunch',
			'tamuning',
			array(
				'weekly' => array(
					array(
						'day'   => 'sa',
						'open'  => '11:00',
						'close' => '14:00',
					),
				),
			)
		);
		$this->place(
			'Weekday Coffee Shop',
			'dededo',
			array(
				'weekly' => array_map(
					static function ( $day ) {
						return array(
							'day'   => $day,
							'open'  => '06:30',
							'close' => '11:00',
						);
					},
					array( 'mo', 'tu', 'we', 'th', 'fr' )
				),
			)
		);
		$this->place( 'All Night Diner', 'dededo', array( 'always_open' => true ) );
		$this->place( 'No Hours Cafe', 'dededo', array() );
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
	 * Creates a restaurant with hours.
	 *
	 * @param string $name    Name.
	 * @param string $village Village slug.
	 * @param array  $hours   Hours object.
	 * @return int
	 */
	private function place( string $name, string $village, array $hours ): int {
		$id = $this->restaurant( $name, $hours ? array( Restaurant_Meta::HOURS => $hours ) : array() );
		wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		return $id;
	}

	/**
	 * Titles from a directory query.
	 *
	 * @param array<string, mixed> $params URL parameters.
	 * @return string[]
	 */
	private function titles( array $params ): array {
		return wp_list_pluck( Directory_Query::query( Filters::from_params( $params ) )->posts, 'post_title' );
	}

	/**
	 * Valid values only: a day (or today, tomorrow) and a 24-hour time.
	 */
	public function test_at_values() {
		$this->assertSame(
			array(
				'day'    => 'sa',
				'minute' => 450,
			),
			Filters::at_parts( 'sa-0730' )
		);
		$this->assertSame( 'today', Filters::at_parts( 'today-2000' )['day'] );
		foreach ( array( '', 'sa', 'sa-730', 'sa-2400', 'sa-0760', 'xx-0730', 'sa-0730-x', 'SA-0730' ) as $bad ) {
			$this->assertNull( Filters::at_parts( $bad ), $bad );
		}

		$this->assertSame( 5 * 1440 + 450, Filters::at_minute( 'sa-0730' ) );
		$this->assertSame( 5 * 1440 + 20 * 60, Filters::at_minute( 'today-2000' ), 'Today is Saturday in Guam' );
		$this->assertSame( 6 * 1440 + 9 * 60, Filters::at_minute( 'tomorrow-0900' ) );
		$this->assertSame( 9 * 60, Filters::at_minute( 'tomorrow-0900', self::SATURDAY_EVENING + DAY_IN_SECONDS ), 'Tomorrow from Sunday is Monday' );
		$this->assertNull( Filters::at_minute( 'nope' ) );

		$this->assertSame( 'Saturday at ' . Restaurant_View::time_label( '07:30' ), Filters::at_label( 'sa-0730' ) );
		$this->assertSame( 'today at ' . Restaurant_View::time_label( '20:00' ), Filters::at_label( 'today-2000' ) );
	}

	/**
	 * The form's two selects become one `at`; bad values are dropped; it round-trips.
	 */
	public function test_at_params() {
		$filters = Filters::from_params(
			array(
				Filters::AT_DAY  => 'su',
				Filters::AT_TIME => '0100',
			)
		);
		$this->assertSame( 'su-0100', $filters['at'] );
		$this->assertSame( array( 'at' => 'su-0100' ), Filters::to_params( $filters ) );
		$this->assertFalse( Filters::is_timed( $filters ), 'A named day gives the same answer all week' );
		$this->assertTrue( Filters::is_timed( Filters::from_params( array( 'at' => 'today-2000' ) ) ) );

		$this->assertSame( '', Filters::from_params( array( Filters::AT_DAY => 'su' ) )['at'], 'A day alone is not enough' );
		$this->assertSame( '', Filters::from_params( array( 'at' => array( 'su-0100' ) ) )['at'] );
		$this->assertSame( '', Filters::from_params( array( 'at' => "su-0100' OR 1=1" ) )['at'] );

		$breakfast = Filters::from_params( array( 'meal' => 'breakfast' ) );
		$this->assertTrue( $breakfast['breakfast'] );
		$this->assertSame( array( 'meal' => 'breakfast' ), Filters::to_params( $breakfast ) );
		$this->assertFalse( Filters::from_params( array( 'meal' => 'brunch' ) )['breakfast'] );
	}

	/**
	 * Open at a chosen time, by listed hours: past midnight and past Sunday night too.
	 */
	public function test_open_at_results() {
		$this->assertSame( array( 'All Night Diner', 'Saturday Lunch' ), $this->titles( array( 'at' => 'sa-1200' ) ) );
		$this->assertSame( array( 'All Night Diner', 'Evening Grill' ), $this->titles( array( 'at' => 'su-0100' ) ), 'Saturday 17:00 to 02:00 runs into Sunday' );
		$this->assertSame( array( 'All Night Diner', 'Evening Grill' ), $this->titles( array( 'at' => 'mo-0130' ) ), 'Sunday night runs into Monday' );
		$this->assertSame( array( 'All Night Diner', 'Weekday Coffee Shop' ), $this->titles( array( 'at' => 'mo-0700' ) ) );
		$this->assertSame( array( 'All Night Diner', 'Evening Grill' ), $this->titles( array( 'at' => 'today-2000' ) ) );
		$this->assertSame(
			array( 'Saturday Lunch' ),
			$this->titles(
				array(
					'at'      => 'sa-1200',
					'village' => 'tamuning',
				)
			),
			'Joins the other filters'
		);
	}

	/**
	 * Breakfast: open at 8 am on at least one day.
	 */
	public function test_breakfast() {
		$this->assertSame( array( 'All Night Diner', 'Weekday Coffee Shop' ), $this->titles( array( 'meal' => 'breakfast' ) ) );

		$parsed = Query_Parser::parse( 'breakfast spots in Dededo' );
		$this->assertTrue( $parsed['breakfast'] );
		$this->assertSame( array( 'dededo' ), $parsed['facets']['village'] );
		$this->assertSame( array( 'All Night Diner', 'Weekday Coffee Shop' ), $this->titles( array( 'q' => 'breakfast spots in Dededo' ) ) );
		$this->assertTrue( Query_Parser::parse( 'somewhere for breakfast' )['breakfast'] );
		$this->assertFalse( Query_Parser::parse( 'breakfast burritos' )['breakfast'], 'A dish, not the filter' );
	}

	/**
	 * Chips and notes; the random picker ignores a chosen time.
	 */
	public function test_view_and_picker() {
		$filters = Filters::from_params(
			array(
				'at'   => 'sa-1200',
				'meal' => 'breakfast',
			)
		);
		$html    = Directory_View::form( $filters, array() );
		$this->assertStringContainsString( 'name="at_day"', $html );
		$this->assertStringContainsString( '<option value="sa" selected=\'selected\'>', $html );
		$this->assertStringContainsString( '<option value="1200" selected=\'selected\'>', $html );
		$this->assertStringContainsString( 'name="meal" value="breakfast"', $html );

		$results = Directory_View::results( Directory_Query::query( $filters ), $filters, array() );
		$this->assertStringContainsString( 'Holiday hours may differ', $results );
		$this->assertStringContainsString( 'going by their listed hours', $results );
		$this->assertStringContainsString( 'All Night Diner', $results );

		$this->assertSame( '', Picker::filters( array( 'at' => 'sa-1200' ) )['at'] );
	}

	/**
	 * When and by whom the hours were last checked.
	 */
	public function test_hours_checked() {
		$id    = $this->restaurant( 'Checked Place' );
		$hours = $this->every_day( '10:00', '20:00' );

		$this->assertSame( '', Restaurant_View::hours_checked( $id ), 'No hours, nothing to check' );

		update_post_meta( $id, Restaurant_Meta::HOURS, $hours + array( 'source' => 'osm' ) );
		$this->assertSame( 'Hours from OpenStreetMap, not yet confirmed by the owner.', Restaurant_View::hours_checked( $id ) );

		update_post_meta(
			$id,
			Restaurant_Meta::HOURS,
			$hours + array(
				'source'      => 'owner',
				'verified_on' => '2026-10-02',
			)
		);
		$this->assertSame( 'Hours confirmed by the owner on ' . wp_date( (string) get_option( 'date_format' ), (int) strtotime( '2026-10-02 12:00:00 UTC' ) ) . '.', Restaurant_View::hours_checked( $id ) );

		update_post_meta(
			$id,
			Restaurant_Meta::HOURS,
			$hours + array(
				'source'      => 'manual',
				'verified_on' => 'yesterday',
			)
		);
		$this->assertSame( 'Hours added by our editors.', Restaurant_View::hours_checked( $id ), 'A bad date is not shown' );
	}

	/**
	 * The hours table asks "Are these hours right?"; "No" opens the
	 * correction form on this restaurant's hours.
	 */
	public function test_hours_ask() {
		Pages::ensure();
		$id = $this->restaurant( 'Asked Place', array( Restaurant_Meta::HOURS => $this->every_day( '10:00', '20:00' ) + array( 'source' => 'osm' ) ) );
		$this->go_to( get_permalink( $id ) );

		$html = Blocks::restaurant_details( array() );
		$this->assertStringContainsString( 'Hours from OpenStreetMap, not yet confirmed by the owner.', $html );
		$this->assertStringContainsString( 'Are these hours right?', $html );
		$this->assertStringContainsString( 'data-gr-hours-yes hidden', $html, 'Yes appears only with JavaScript' );
		$this->assertStringContainsString(
			'href="' . esc_url(
				add_query_arg(
					array(
						'restaurant' => $id,
						'topic'      => 'hours',
					),
					Pages::url( 'correction' )
				)
			) . '"',
			$html
		);

		$none = $this->restaurant( 'Unknown Hours' );
		$this->go_to( get_permalink( $none ) );
		$html = Blocks::restaurant_details( array() );
		$this->assertStringContainsString( 'Know the hours? Tell us', $html );
		$this->assertStringNotContainsString( 'Are these hours right?', $html );
	}
}
