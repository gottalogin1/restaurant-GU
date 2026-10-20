<?php
/**
 * Plain-language search tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Clock;
use GuamRestaurants\Core\Site\Directory_Query;
use GuamRestaurants\Core\Site\Directory_View;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\Query_Parser;
use WP_REST_Request;

/**
 * "cheap Korean open late in Tumon" becomes $, Korean, open late and Tamuning.
 *
 * @covers \GuamRestaurants\Core\Site\Query_Parser
 * @covers \GuamRestaurants\Core\Site\Filters
 */
class Query_Parser_Test extends Guam_Test_Case {

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

		$evening = array(
			'weekly' => array_map(
				static function ( $day ) {
					return array(
						'day'   => $day,
						'open'  => '17:00',
						'close' => '02:00',
					);
				},
				array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' )
			),
		);
		$lunch   = array(
			'weekly' => array(
				array(
					'day'   => 'sa',
					'open'  => '11:00',
					'close' => '14:00',
				),
			),
		);

		$this->place( 'Seoul Night', 'tamuning', array( 'korean' ), 'p1', $evening );
		$this->place( 'Tumon Kimbap Lunch', 'tamuning', array( 'korean' ), 'p1', $lunch );
		$this->place( 'Dededo Kimchi House', 'dededo', array( 'korean' ), 'p1', $evening );
		$this->place( 'Tumon Sushi Bar', 'tamuning', array( 'japanese' ), 'p3', $evening );
		$this->place( 'Galbi Palace', 'tamuning', array( 'korean' ), 'p3', $evening );
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
		$id = $this->restaurant( $name, array( Restaurant_Meta::HOURS => $hours ) );
		wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		wp_set_object_terms( $id, $cuisines, Content_Types::TAX_CUISINE );
		wp_set_object_terms( $id, $price, Content_Types::TAX_PRICE );
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
	 * The example from the brief.
	 */
	public function test_reads_the_example() {
		$parsed = Query_Parser::parse( 'cheap Korean open late in Tumon' );

		$this->assertSame( array( 'p1' ), $parsed['facets']['price'] );
		$this->assertSame( array( 'korean' ), $parsed['facets']['cuisine'] );
		$this->assertSame( array( 'tamuning' ), $parsed['facets']['village'] );
		$this->assertTrue( $parsed['late'] );
		$this->assertFalse( $parsed['open'], '"open late" is not "open" plus "late"' );
		$this->assertSame( '', $parsed['search'], '"in" is filler once something was read' );
		$this->assertContains(
			array(
				'type'   => 'village',
				'value'  => 'tamuning',
				'phrase' => 'Tumon',
			),
			$parsed['matches']
		);
	}

	/**
	 * Words it does not know stay, as typed, for the keyword search.
	 */
	public function test_unknown_words_stay_as_typed() {
		$parsed = Query_Parser::parse( "Grandma's breakfast burritos in Hagåtña" );
		$this->assertSame( array( 'mexican' ), $parsed['facets']['cuisine'] );
		$this->assertSame( array( 'hagatna' ), $parsed['facets']['village'] );
		$this->assertSame( "Grandma's breakfast", $parsed['search'] );

		$plain = Query_Parser::parse( 'The Kitchen at night' );
		$this->assertSame( array(), $plain['matches'] );
		$this->assertSame( 'The Kitchen at night', $plain['search'], 'Nothing read, so filler is kept too' );
	}

	/**
	 * Price words, dollar signs and "under $N".
	 */
	public function test_prices() {
		$this->assertSame( array( 'p2' ), Query_Parser::parse( '$$ pizza' )['facets']['price'] );
		$this->assertSame( array( 'p3', 'p4' ), Query_Parser::parse( 'fancy steak' )['facets']['price'] );
		$this->assertSame( array( 'p1', 'p2' ), Query_Parser::parse( 'dinner under $20' )['facets']['price'] );
		$this->assertSame( array( 'p1' ), Query_Parser::parse( 'less than 15 dollars' )['facets']['price'] );
		$this->assertSame( '', Query_Parser::parse( 'less than 15 dollars' )['search'] );
		$this->assertSame( array( 'p1' ), Query_Parser::parse( 'Affordable Filipino' )['facets']['price'] );
	}

	/**
	 * Village names, aliases, Chamorro spellings, several at once.
	 */
	public function test_villages() {
		$this->assertSame( array( 'dededo' ), Query_Parser::parse( 'pho in Dedidu' )['facets']['village'] );
		$this->assertSame( array( 'santa-rita' ), Query_Parser::parse( 'Sta. Rita bbq' )['facets']['village'] );
		$this->assertSame( array( 'agana-heights' ), Query_Parser::parse( 'coffee agana heights' )['facets']['village'], 'Longer names win' );
		$this->assertSame( array( 'tamuning' ), Query_Parser::parse( 'upper tumon' )['facets']['village'] );
		$this->assertSame( array( 'barrigada' ), Query_Parser::parse( 'barigada' )['facets']['village'] );
		$this->assertSame( array( 'tamuning', 'dededo' ), Query_Parser::parse( 'tumon or dededo' )['facets']['village'] );
		$this->assertSame( array(), Query_Parser::parse( 'tumonese' )['facets']['village'], 'No partial words' );
	}

	/**
	 * Cuisine names, dishes that name a cuisine, plurals, several at once.
	 */
	public function test_cuisines_and_flags() {
		$this->assertSame( array( 'japanese', 'korean' ), Query_Parser::parse( 'Japanese or Korean' )['facets']['cuisine'] );
		$this->assertSame( array( 'mexican' ), Query_Parser::parse( 'tacos' )['facets']['cuisine'] );
		$this->assertSame( array( 'american' ), Query_Parser::parse( 'Burgers' )['facets']['cuisine'] );
		$this->assertSame( array( 'korean' ), Query_Parser::parse( 'Korean BBQ' )['facets']['cuisine'], 'Korean BBQ is Korean, not also BBQ and grill' );
		$this->assertSame( array( 'bakery-and-dessert' ), Query_Parser::parse( 'ice cream' )['facets']['cuisine'] );

		$open = Query_Parser::parse( 'sushi open now' );
		$this->assertTrue( $open['open'] );
		$this->assertFalse( $open['late'] );
		$this->assertSame( array( 'japanese' ), $open['facets']['cuisine'] );

		$this->assertTrue( Query_Parser::parse( 'late-night ramen' )['late'] );
		$this->assertTrue( Query_Parser::parse( 'award winning chamorro' )['award'] );
	}

	/**
	 * Quoted searches and restaurant names are left alone.
	 */
	public function test_literal_searches() {
		$this->assertTrue( Query_Parser::is_literal( '"cheap korean"' ) );
		$this->assertTrue( Query_Parser::is_literal( 'sushi bar' ), 'Part of "Tumon Sushi Bar"' );
		$this->assertFalse( Query_Parser::is_literal( 'cheap korean' ) );
		$this->assertFalse( Query_Parser::is_literal( 'sushi' ), 'One word is always read' );

		$this->assertSame( array( 'Tumon Sushi Bar' ), $this->titles( array( 'q' => 'Tumon Sushi Bar' ) ) );
		$this->assertNull( Filters::from_params( array( 'q' => 'Tumon Sushi Bar' ) )['read'] );
	}

	/**
	 * A mall waiting for verification is not read, so its words stay.
	 */
	public function test_unverified_mall_is_not_read() {
		$parsed = Query_Parser::parse( 'pizza at Micronesia Mall' );
		$this->assertSame( array(), $parsed['facets']['mall'] );
		$this->assertSame( 'Micronesia Mall', $parsed['search'] );
	}

	/**
	 * The search becomes URL filters, joining the ones already chosen.
	 */
	public function test_filters_from_a_typed_search() {
		$filters = Filters::from_params(
			array(
				'q'       => 'cheap Korean open late in Tumon',
				'cuisine' => 'japanese',
			)
		);
		$this->assertSame( '', $filters['q'] );
		$this->assertSame( array( 'japanese', 'korean' ), $filters['facets']['cuisine'] );
		$this->assertTrue( $filters['late'] );
		$this->assertSame( 'cheap Korean open late in Tumon', $filters['read']['text'] );
		$this->assertSame(
			array(
				'village' => 'tamuning',
				'cuisine' => 'japanese,korean',
				'price'   => 'p1',
				'late'    => 'tonight',
			),
			Filters::to_params( $filters )
		);
		$this->assertTrue( Filters::is_timed( $filters ) );
		$this->assertSame(
			'',
			Filters::from_params(
				array(
					'q'    => 'cheap',
					'sort' => 'relevance',
				)
			)['sort'],
			'No words left, so no relevance sort'
		);
	}

	/**
	 * Results: the example finds the cheap Korean place in Tamuning that is open at 10 pm.
	 */
	public function test_results() {
		$this->assertSame( array( 'Seoul Night' ), $this->titles( array( 'q' => 'cheap Korean open late in Tumon' ) ) );
		$this->assertSame( array( 'Seoul Night', 'Tumon Kimbap Lunch' ), $this->titles( array( 'q' => 'cheap korean tumon' ) ) );
		$this->assertSame(
			array( 'Dededo Kimchi House', 'Galbi Palace', 'Seoul Night' ),
			$this->titles(
				array(
					'late'    => 'tonight',
					'cuisine' => 'korean',
				)
			)
		);
	}

	/**
	 * "Open late" is 10 pm tonight, or now once it is later than that.
	 */
	public function test_late_minute() {
		$saturday = 5 * Clock::MINUTES_PER_DAY;
		$this->assertSame( $saturday + 22 * 60, Clock::late_minute( self::SATURDAY_EVENING ) );
		$this->assertSame( $saturday + 23 * 60, Clock::late_minute( self::SATURDAY_EVENING + 210 * MINUTE_IN_SECONDS ), 'Saturday 23:00 is now' );
		$this->assertSame( 6 * Clock::MINUTES_PER_DAY + 60, Clock::late_minute( self::SATURDAY_EVENING + 330 * MINUTE_IN_SECONDS ), 'Sunday 01:00 is still Saturday night' );
		$this->assertSame( 22 * 60, Clock::late_minute( self::SATURDAY_EVENING + 38 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS ), 'Monday 10:00 looks at Monday 22:00' );
	}

	/**
	 * The results say how the search was read, and each chip removes one filter.
	 */
	public function test_read_line_and_remove_links() {
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		$filters = Filters::from_params( array( 'q' => 'cheap Korean in Tumon' ) );
		$html    = Directory_View::results( Directory_Query::query( $filters ), $filters, array() );

		$this->assertStringContainsString( 'We read “cheap Korean in Tumon” as:', $html );
		$this->assertStringContainsString( 'Tamuning <span class="gr-chip__note">(Tumon)</span>', $html );
		$this->assertStringContainsString( 'Under $15 per person', $html );
		$this->assertStringContainsString( 'Search the exact words instead', $html );
		$this->assertStringContainsString( '?q=%22cheap%20Korean%20in%20Tumon%22', $html );

		preg_match_all( '/<a class="gr-chip gr-chip--remove" href="([^"]+)"/', $html, $links );
		$this->assertCount( 3, $links[1] );
		$without_korean = html_entity_decode( $links[1][1] );
		$this->assertStringContainsString( 'village=tamuning', $without_korean );
		$this->assertStringContainsString( 'price=p1', $without_korean );
		$this->assertStringNotContainsString( 'cuisine', $without_korean );

		$plain = Filters::from_params( array( 'cuisine' => 'korean' ) );
		$this->assertStringContainsString( 'Filters:', Directory_View::active( $plain, array() ) );
		$this->assertSame( '', Directory_View::active( Filters::defaults(), array() ) );
	}

	/**
	 * The REST endpoint returns the read filters in its shareable URL.
	 */
	public function test_rest_url_has_the_read_filters() {
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/directory' );
		$request->set_query_params( array( 'q' => 'late night korean in tumon' ) );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 2, $data['total'] );
		$this->assertStringEndsWith( '/restaurants/?village=tamuning&cuisine=korean&late=tonight', $data['url'] );
		$this->assertSame( 'public, max-age=60', $response->get_headers()['Cache-Control'] );
	}
}
