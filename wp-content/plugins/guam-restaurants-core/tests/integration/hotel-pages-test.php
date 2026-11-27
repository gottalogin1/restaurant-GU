<?php
/**
 * "Restaurants near your hotel" tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Hotels_Screen;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Cache;
use GuamRestaurants\Core\Site\Hotel_Pages;
use GuamRestaurants\Core\Site\Hotels;
use GuamRestaurants\Core\Site\Related_Links;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Site\Sitemaps;

/**
 * English audience idea E6 (comparison addition 21).
 *
 * @covers \GuamRestaurants\Core\Site\Hotels
 * @covers \GuamRestaurants\Core\Site\Hotel_Pages
 * @covers \GuamRestaurants\Core\Admin\Hotels_Screen
 */
class Hotel_Pages_Test extends Guam_Test_Case {

	/**
	 * A point in Tumon.
	 */
	private const LAT = 13.5160;
	private const LNG = 144.8050;

	/**
	 * Roughly one metre of latitude, in degrees.
	 */
	private const METRE = 1 / 111195;

	/**
	 * Clean list and caches.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Hotels::OPTION );
		Cache::flush();
		Hotels::reset();
	}

	/**
	 * A restaurant some metres north of the test point.
	 *
	 * @param string $name   Name.
	 * @param float  $metres Metres north.
	 * @param array  $meta   More meta.
	 * @return int
	 */
	private function place( string $name, float $metres, array $meta = array() ): int {
		return $this->restaurant(
			$name,
			$meta + array(
				Restaurant_Meta::LATITUDE  => self::LAT + $metres * self::METRE,
				Restaurant_Meta::LONGITUDE => self::LNG,
			)
		);
	}

	/**
	 * Saves hotels at the test point (and others), by slug.
	 *
	 * @param array<string, array<string, mixed>> $more More hotels.
	 * @return void
	 */
	private function hotels( array $more = array() ): void {
		Hotels::save(
			array(
				'tumon-bay-hotel' => array(
					'name'    => 'Tumon Bay Hotel',
					'osm'     => 'way/10',
					'lat'     => self::LAT,
					'lng'     => self::LNG,
					'village' => 'tamuning',
					'source'  => 'https://www.openstreetmap.org/way/10',
				),
			) + $more
		);
	}

	/**
	 * Overpass elements: nodes with a point, ways with a center, and the
	 * ones that are skipped.
	 */
	public function test_from_osm() {
		$hotels = Hotels::from_osm(
			array(
				array(
					'type' => 'node',
					'id'   => 1,
					'lat'  => 13.51,
					'lon'  => 144.80,
					'tags' => array(
						'tourism' => 'hotel',
						'name'    => 'Node Hotel',
					),
				),
				array(
					'type'   => 'way',
					'id'     => 2,
					'center' => array(
						'lat' => 13.52,
						'lon' => 144.81,
					),
					'tags'   => array(
						'tourism' => 'hotel',
						'name'    => 'ホテル',
						'name:en' => 'Way Hotel',
					),
				),
				array(
					'type' => 'node',
					'id'   => 3,
					'lat'  => 13.5,
					'lon'  => 144.8,
					'tags' => array( 'tourism' => 'hotel' ),
				),
				array(
					'type' => 'node',
					'id'   => 4,
					'lat'  => 13.5,
					'lon'  => 144.8,
					'tags' => array(
						'tourism' => 'guest_house',
						'name'    => 'Guest House',
					),
				),
				array(
					'type' => 'way',
					'id'   => 5,
					'tags' => array(
						'tourism' => 'hotel',
						'name'    => 'No Center',
					),
				),
			)
		);
		$this->assertSame( array( 'node/1', 'way/2' ), array_keys( $hotels ) );
		$this->assertSame( 'Way Hotel', $hotels['way/2']['name'], 'English name first' );
		$this->assertSame( 13.52, $hotels['way/2']['lat'] );

		$query = Overpass_Client::hotels_query();
		$this->assertStringContainsString( 'nwr["tourism"="hotel"]["name"](13.18,144.56,13.71,145.01);', $query );
		$this->assertStringContainsString( 'out center tags;', $query );
	}

	/**
	 * Importing twice changes nothing; names, hiding, hand-added hotels and
	 * removals behave.
	 */
	public function test_merge() {
		$village  = static function (): string {
			return 'tamuning';
		};
		$imported = array(
			'way/1'  => array(
				'name' => 'Seaside Hotel',
				'lat'  => 13.51,
				'lng'  => 144.80,
			),
			'node/2' => array(
				'name' => 'Seaside Hotel',
				'lat'  => 13.52,
				'lng'  => 144.81,
			),
			'node/3' => array(
				'name' => 'Old Inn',
				'lat'  => 13.50,
				'lng'  => 144.79,
			),
			'node/9' => array(
				'name' => 'Far Away',
				'lat'  => 21.3,
				'lng'  => -157.8,
			),
		);
		$first    = Hotels::merge( array(), $imported, $village );
		$this->assertSame(
			array(
				'created'   => 3,
				'updated'   => 0,
				'unchanged' => 0,
				'removed'   => 0,
			),
			$first['counts'],
			'The hotel off Guam is skipped'
		);
		$slugs = array_keys( $first['hotels'] );
		sort( $slugs );
		$this->assertSame( array( 'old-inn', 'seaside-hotel', 'seaside-hotel-tamuning' ), $slugs, 'A second hotel with the same name gets the village' );
		$this->assertSame( 'https://www.openstreetmap.org/node/3', $first['hotels']['old-inn']['source'] );

		$again = Hotels::merge( $first['hotels'], $imported, $village );
		$this->assertSame( 3, $again['counts']['unchanged'], 'Idempotent' );
		$this->assertSame( $first['hotels'], $again['hotels'] );

		$saved                      = $again['hotels'];
		$saved['old-inn']['hidden'] = true;
		$saved['my-place']          = Hotels::clean(
			array(
				'name'   => 'My Place',
				'lat'    => 13.48,
				'lng'    => 144.75,
				'source' => 'https://example.com/my-place',
			)
		);
		$imported['node/3']['lat']  = 13.501;
		$imported['way/1']['name']  = 'Seaside Resort';
		unset( $imported['node/9'] );
		$imported['node/2']['lat'] = 13.52;
		$third                     = Hotels::merge( $saved, $imported, $village );
		$this->assertSame( 2, $third['counts']['updated'], 'Moved and renamed' );
		$this->assertSame( 1, $third['counts']['unchanged'] );
		$this->assertTrue( $third['hotels']['old-inn']['hidden'], 'Stays hidden' );
		$this->assertArrayHasKey( 'my-place', $third['hotels'], 'Hand-added hotels are kept' );
		$this->assertArrayHasKey( 'seaside-resort', $third['hotels'], 'Renamed hotels get a new slug' );
		$this->assertSame( array_search( 'node/2', wp_list_pluck( $first['hotels'], 'osm' ), true ), array_search( 'node/2', wp_list_pluck( $third['hotels'], 'osm' ), true ), 'The unchanged hotel keeps its slug' );

		unset( $imported['node/3'] );
		$fourth = Hotels::merge( $third['hotels'], $imported, $village );
		$this->assertSame( 1, $fourth['counts']['removed'] );
		$this->assertArrayNotHasKey( 'old-inn', $fourth['hotels'] );
	}

	/**
	 * Nearest first, split at a kilometre, nothing past three.
	 */
	public function test_nearby() {
		$points = array(
			7 => array(
				'lat' => self::LAT + 900 * self::METRE,
				'lng' => self::LNG,
			),
			3 => array(
				'lat' => self::LAT + 100 * self::METRE,
				'lng' => self::LNG,
			),
			5 => array(
				'lat' => self::LAT + 2500 * self::METRE,
				'lng' => self::LNG,
			),
			9 => array(
				'lat' => self::LAT + 3200 * self::METRE,
				'lng' => self::LNG,
			),
		);
		$near   = Hotels::nearby( self::LAT, self::LNG, $points );
		$this->assertSame( array( 3, 7 ), array_keys( $near['walk'] ) );
		$this->assertSame( array( 5 ), array_keys( $near['drive'] ) );
		$this->assertEqualsWithDelta( 100, $near['walk'][3], 1 );

		$this->assertSame( 2, Hotels::walk_minutes( 100 ) );
		$this->assertSame( 17, Hotels::walk_minutes( 1000 ) );
		$this->assertSame( '100 m', Hotels::distance_label( 101 ) );
		$this->assertSame( '2.5 km', Hotels::distance_label( 2480 ) );
	}

	/**
	 * Routes, titles, robots, structured data, the sitemap and the 404s.
	 */
	public function test_routes() {
		$this->set_permalink_structure( '/%postname%/' );
		Hotel_Pages::add_rewrite_rules();
		flush_rewrite_rules( false );
		foreach ( array( 120, 300, 450, 800 ) as $metres ) {
			$this->place( 'Walk ' . $metres, $metres );
		}
		$this->place( 'Drive 2000', 2000 );
		$this->place( 'Closed 50', 50, array( Restaurant_Meta::STATUS => 'permanently_closed' ) );
		$this->place( 'Far 5000', 5000 );
		$this->hotels(
			array(
				'lonely-lodge' => array(
					'name'    => 'Lonely Lodge',
					'lat'     => 13.30,
					'lng'     => 144.70,
					'village' => 'yona',
				),
				'hidden-hotel' => array(
					'name'   => 'Hidden Hotel',
					'lat'    => self::LAT + 200 * self::METRE,
					'lng'    => self::LNG,
					'hidden' => true,
				),
				'next-door'    => array(
					'name'    => 'Next Door Resort',
					'lat'     => self::LAT + 500 * self::METRE,
					'lng'     => self::LNG,
					'village' => 'tamuning',
				),
			)
		);

		$this->go_to( Hotel_Pages::url( 'tumon-bay-hotel' ) );
		$this->assertSame( home_url( '/restaurants-near/tumon-bay-hotel/' ), Hotel_Pages::url( 'tumon-bay-hotel' ) );
		$this->assertFalse( is_404() );
		$this->assertFalse( is_home() );
		$this->assertSame( 'Restaurants near Tumon Bay Hotel', Seo::plugin_page()['title'] );
		$this->assertFalse( Seo::plugin_page()['filtered'] );
		$this->assertSame( '4 places to eat within walking distance of Tumon Bay Hotel and 1 more a short drive away, nearest first, with a map.', Seo::description() );
		$html = Hotel_Pages::render_current();
		$this->assertLessThan( strpos( $html, 'Walk 300' ), strpos( $html, 'Walk 120' ), 'Nearest first' );
		$this->assertLessThan( strpos( $html, 'A short drive away' ), strpos( $html, 'Walk 800' ) );
		$this->assertStringNotContainsString( 'Closed 50', $html, 'Closed places are left out' );
		$this->assertStringNotContainsString( 'Far 5000', $html );
		$this->assertStringContainsString( '120 m away · about 2 minute walk', $html );
		$this->assertStringContainsString( '2.0 km away', $html );
		$this->assertStringContainsString( 'data-gr-origin="' . self::LNG . ',' . self::LAT . '"', $html );
		$this->assertStringContainsString( 'Next Door Resort', $html, 'Other hotels nearby' );
		$this->assertStringNotContainsString( 'Hidden Hotel', $html );
		$this->assertStringContainsString( 'translate="no">Tumon Bay Hotel</span>', $html, 'Hotel names stay as written' );
		$this->assertCount( 5, Hotel_Pages::current_ids() );

		$this->go_to( Hotel_Pages::url() );
		$this->assertSame( 'index', Hotel_Pages::current_page() );
		$html = Hotel_Pages::render_current();
		$this->assertStringContainsString( '<h2 class="gr-section-title">Tamuning</h2>', $html );
		$this->assertStringContainsString( 'Tumon Bay Hotel</span></a> <span class="gr-dish-index__count">4 places within walking distance</span>', $html );
		$this->assertStringNotContainsString( 'Lonely Lodge', $html );

		$this->go_to( home_url( '/restaurants-near/lonely-lodge/' ) );
		$this->assertTrue( is_404(), 'Too few places' );
		$this->go_to( home_url( '/restaurants-near/hidden-hotel/' ) );
		$this->assertTrue( is_404(), 'Hidden' );
		$this->go_to( home_url( '/restaurants-near/no-such-hotel/' ) );
		$this->assertTrue( is_404() );

		$this->assertSame(
			array( home_url( '/restaurants-near/' ), home_url( '/restaurants-near/next-door/' ), home_url( '/restaurants-near/tumon-bay-hotel/' ) ),
			wp_list_pluck( Hotel_Pages::sitemap_pages(), 'loc' )
		);
		$this->assertContains( home_url( '/restaurants-near/tumon-bay-hotel/' ), wp_list_pluck( Sitemaps::plugin_pages(), 'loc' ) );
	}

	/**
	 * Village and restaurant pages link the hotel pages.
	 */
	public function test_related_links() {
		$ids = array();
		foreach ( array( 100, 200, 300, 400, 500 ) as $metres ) {
			$ids[] = $this->place( 'Place ' . $metres, $metres );
		}
		$this->hotels();
		$village = get_term_by( 'slug', 'tamuning', Content_Types::TAX_VILLAGE );
		$groups  = Related_Links::for_term( $village );
		$titles  = wp_list_pluck( $groups, 'title' );
		$this->assertContains( 'Restaurants near hotels in Tamuning', $titles );
		$group = $groups[ array_search( 'Restaurants near hotels in Tamuning', $titles, true ) ];
		$this->assertSame( 'Near Tumon Bay Hotel', $group['links'][0]['label'] );

		$groups = Related_Links::for_restaurant( $ids[0] );
		$last   = end( $groups );
		$this->assertSame( 'Hotels within walking distance', $last['title'] );
		$this->assertSame( 'Tumon Bay Hotel (100 m)', $last['links'][0]['label'] );
		$this->assertSame( Hotel_Pages::url( 'tumon-bay-hotel' ), $last['links'][0]['url'] );
	}

	/**
	 * The admin screen hides hotels, adds one with a source link, and
	 * removes only hand-added ones.
	 */
	public function test_admin_screen() {
		$this->login_admin();
		$this->hotels();

		$problems = Hotels_Screen::apply(
			array(
				'add' => array(
					'name' => 'New Hotel',
					'lat'  => '13.49',
					'lng'  => '144.78',
				),
			)
		);
		$this->assertCount( 1, $problems, 'A source link is required' );
		$this->assertArrayNotHasKey( 'new-hotel', Hotels::all() );

		$this->assertSame(
			array(),
			Hotels_Screen::apply(
				array(
					'hide' => array( 'tumon-bay-hotel' => '1' ),
					'add'  => array(
						'name'   => 'New Hotel',
						'lat'    => '13.49',
						'lng'    => '144.78',
						'source' => 'https://example.com/new-hotel',
					),
				)
			)
		);
		$all = Hotels::all();
		$this->assertTrue( $all['tumon-bay-hotel']['hidden'] );
		$this->assertSame( '', $all['new-hotel']['osm'] );
		$this->assertNull( Hotels::get( 'tumon-bay-hotel' ) );

		Hotels_Screen::apply(
			array(
				'remove' => array(
					'tumon-bay-hotel' => '1',
					'new-hotel'       => '1',
				),
			)
		);
		$all = Hotels::all();
		$this->assertArrayHasKey( 'tumon-bay-hotel', $all, 'OSM hotels can only be hidden' );
		$this->assertFalse( $all['tumon-bay-hotel']['hidden'], 'Unticked means shown' );
		$this->assertArrayNotHasKey( 'new-hotel', $all );
	}

	/**
	 * The import saves, logs the run and is idempotent.
	 */
	public function test_import() {
		$elements = array(
			array(
				'type' => 'node',
				'id'   => 11,
				'lat'  => 13.513,
				'lon'  => 144.809,
				'tags' => array(
					'tourism' => 'hotel',
					'name'    => 'Imported Hotel',
				),
			),
		);
		$dry      = Hotels::import( $elements, true );
		$this->assertSame( 1, $dry['created'] );
		$this->assertSame( array(), Hotels::all(), 'A dry run saves nothing' );

		$first = Hotels::import( $elements );
		$this->assertSame( 1, $first['created'] );
		$this->assertGreaterThan( 0, $first['run_id'] );
		$this->assertSame( 'tamuning', Hotels::all()['imported-hotel']['village'], 'Village from the Census boundaries' );
		$second = Hotels::import( $elements );
		$this->assertSame( 1, $second['unchanged'] );
		$this->assertSame( 0, $second['created'] );
	}
}
