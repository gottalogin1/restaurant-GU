<?php
/**
 * Public site: filters, directory query, open-now index, REST, history,
 * structured data, map rule, sponsored slot and listing links.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Awards\Repository as Awards;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Blocks;
use GuamRestaurants\Core\Site\Directory_Query;
use GuamRestaurants\Core\Site\Directory_View;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\History;
use GuamRestaurants\Core\Site\Maps;
use GuamRestaurants\Core\Site\Open_Hours;
use GuamRestaurants\Core\Site\Pages;
use GuamRestaurants\Core\Site\Sponsored;
use GuamRestaurants\Core\Structured_Data;
use GuamRestaurants\Core\Term_Meta;
use WP_REST_Request;

/**
 * Site tests. Restaurants use real Guam villages and malls; times are Guam time.
 */
class Site_Test extends Guam_Test_Case {

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

		$this->r['proa']   = $this->place( 'Proa', 'tamuning', array( 'chamorro' ), 'p2', $evening, array( 13.5130, 144.8070 ) );
		$this->r['sushi']  = $this->place( 'Tumon Sushi Bar', 'tamuning', array( 'japanese' ), 'p3', $lunch, array( 13.5140, 144.8060 ) );
		$this->r['kimchi'] = $this->place( 'Dededo Kimchi House', 'dededo', array( 'korean' ), 'p1', array(), array( 13.5180, 144.8390 ) );
		$this->r['meskla'] = $this->place( 'Meskla Chamoru Fusion', 'hagatna', array( 'chamorro', 'korean' ), 'p4', $evening, null );
		$this->r['closed'] = $this->place( 'Old Diner', 'dededo', array( 'korean' ), 'p1', $evening, null );
		update_post_meta( $this->r['closed'], Restaurant_Meta::STATUS, 'permanently_closed' );

		Awards::upsert(
			$this->r['meskla'],
			array(
				'program'     => 'Sample Awards',
				'year'        => 2025,
				'category'    => 'Best Fusion',
				'placement'   => 'winner',
				'source_url'  => 'https://example.com/results',
				'source_note' => '',
			)
		);
		Awards::upsert(
			$this->r['proa'],
			array(
				'program'     => 'Sample Awards',
				'year'        => 2025,
				'category'    => 'Best Fusion',
				'placement'   => 'finalist',
				'source_url'  => 'https://example.com/results',
				'source_note' => '',
			)
		);
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
	 * Creates a restaurant with terms, hours and coordinates.
	 *
	 * @param string       $name     Name.
	 * @param string       $village  Village slug.
	 * @param string[]     $cuisines Cuisine slugs.
	 * @param string       $price    Price slug.
	 * @param array        $hours    Hours object.
	 * @param float[]|null $point    Latitude, longitude.
	 * @return int
	 */
	private function place( string $name, string $village, array $cuisines, string $price, array $hours, ?array $point ): int {
		$meta = array();
		if ( $hours ) {
			$meta[ Restaurant_Meta::HOURS ] = $hours;
		}
		if ( $point ) {
			$meta[ Restaurant_Meta::LATITUDE ]  = $point[0];
			$meta[ Restaurant_Meta::LONGITUDE ] = $point[1];
		}
		$id = $this->restaurant( $name, $meta );
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
		$query = Directory_Query::query( Filters::from_params( $params ) );
		return wp_list_pluck( $query->posts, 'post_title' );
	}

	/*
	 * Open hours index.
	 */

	/**
	 * Intervals past midnight, past Sunday midnight, always open and overlaps.
	 */
	public function test_open_hours_intervals() {
		$this->assertSame(
			array( array( 660, 1260 ) ),
			Open_Hours::intervals(
				array(
					'weekly' => array(
						array(
							'day'   => 'mo',
							'open'  => '11:00',
							'close' => '21:00',
						),
					),
				)
			)
		);
		$this->assertSame(
			array( array( 1020, 1560 ) ),
			Open_Hours::intervals(
				array(
					'weekly' => array(
						array(
							'day'   => 'mo',
							'open'  => '17:00',
							'close' => '02:00',
						),
					),
				)
			),
			'Monday 17:00 to Tuesday 02:00'
		);
		$this->assertSame(
			array( array( 0, 120 ), array( 9660, 10080 ) ),
			Open_Hours::intervals(
				array(
					'weekly' => array(
						array(
							'day'   => 'su',
							'open'  => '17:00',
							'close' => '02:00',
						),
					),
				)
			),
			'Sunday night wraps to Monday'
		);
		$this->assertSame( array( array( 0, 10080 ) ), Open_Hours::intervals( array( 'always_open' => true ) ) );
		$this->assertSame(
			array( array( 600, 1320 ) ),
			Open_Hours::intervals(
				array(
					'weekly' => array(
						array(
							'day'   => 'mo',
							'open'  => '10:00',
							'close' => '15:00',
						),
						array(
							'day'   => 'mo',
							'open'  => '14:00',
							'close' => '22:00',
						),
					),
				)
			),
			'Overlaps merge'
		);
		$this->assertSame(
			array( array( 0, 1440 ) ),
			Open_Hours::intervals(
				array(
					'weekly' => array(
						array(
							'day'   => 'mo',
							'open'  => '00:00',
							'close' => '24:00',
						),
					),
				)
			)
		);
	}

	/**
	 * The index follows hours meta edits and deletions.
	 */
	public function test_index_follows_meta() {
		$this->assertStringContainsString( '8220-8760', Open_Hours::compact( $this->r['proa'] ), 'Saturday 17:00 to Sunday 02:00' );
		$this->assertContains( 'Proa', $this->titles( array( 'open' => 'now' ) ) );

		delete_post_meta( $this->r['proa'], Restaurant_Meta::HOURS );
		$this->assertNotContains( 'Proa', $this->titles( array( 'open' => 'now' ) ) );

		Open_Hours::rebuild_all();
		$this->assertNotContains( 'Proa', $this->titles( array( 'open' => 'now' ) ) );
	}

	/**
	 * Open now uses Guam time: Saturday 19:30 finds the evening places, not the lunch place.
	 */
	public function test_open_now() {
		$this->assertSame( array( 'Meskla Chamoru Fusion', 'Proa' ), $this->titles( array( 'open' => 'now' ) ) );

		// Sunday 01:00 Guam: Saturday's 17:00 to 02:00 is still running.
		remove_filter( 'guam_restaurants_now', array( $this, 'saturday_evening' ) );
		$sunday_late = static function () {
			return self::SATURDAY_EVENING + 5 * HOUR_IN_SECONDS + 30 * MINUTE_IN_SECONDS;
		};
		add_filter( 'guam_restaurants_now', $sunday_late );
		$this->assertSame( array( 'Meskla Chamoru Fusion', 'Proa' ), $this->titles( array( 'open' => 'now' ) ) );
		remove_filter( 'guam_restaurants_now', $sunday_late );
	}

	/*
	 * Filters and the directory query.
	 */

	/**
	 * URL parameters: commas or arrays, unknown slugs and unverified malls dropped, canonical output.
	 */
	public function test_filters_from_params() {
		$filters = Filters::from_params(
			array(
				'cuisine' => 'korean,japanese,not-a-cuisine',
				'village' => array( 'dededo' ),
				'mall'    => 'micronesia-mall',
				'award'   => 'winner',
				'open'    => 'now',
				'sort'    => 'drop table',
				'q'       => '  <b>kimchi</b> ',
			)
		);
		$this->assertSame( array( 'japanese', 'korean' ), $filters['facets']['cuisine'] );
		$this->assertSame( array( 'dededo' ), $filters['facets']['village'] );
		$this->assertSame( array(), $filters['facets']['mall'], 'Unverified mall is hidden' );
		$this->assertTrue( $filters['award'] );
		$this->assertTrue( $filters['open'] );
		$this->assertSame( '', $filters['sort'] );
		$this->assertSame( 'kimchi', $filters['q'] );
		$this->assertSame( 'relevance', Filters::effective_sort( $filters ) );
		$this->assertSame(
			array(
				'q'       => 'kimchi',
				'village' => 'dededo',
				'cuisine' => 'japanese,korean',
				'award'   => 'winner',
				'open'    => 'now',
			),
			Filters::to_params( $filters )
		);
		$this->assertArrayNotHasKey( 'village', Filters::to_params( $filters, array( 'village' => 'dededo' ) ), 'A page\'s own facet is not repeated' );
		$this->assertSame( '', Filters::from_params( array( 'sort' => 'relevance' ) )['sort'], 'Relevance needs a search' );
	}

	/**
	 * Any of several cuisines, all of the facets; closed restaurants never listed.
	 */
	public function test_facets_combine() {
		$this->assertSame( array( 'Dededo Kimchi House', 'Meskla Chamoru Fusion', 'Tumon Sushi Bar' ), $this->titles( array( 'cuisine' => 'korean,japanese' ) ) );
		$this->assertSame(
			array( 'Dededo Kimchi House' ),
			$this->titles(
				array(
					'cuisine' => 'korean,japanese',
					'village' => 'dededo',
				)
			)
		);
		$this->assertNotContains( 'Old Diner', $this->titles( array() ) );
		$this->assertSame( array( 'Meskla Chamoru Fusion' ), $this->titles( array( 'award' => 'winner' ) ), 'Finalists are not winners' );
	}

	/**
	 * Price sorts by the price level's rank, and a village alias in the search becomes a filter.
	 */
	public function test_sort_and_alias_search() {
		$this->assertSame( array( 'Dededo Kimchi House', 'Proa', 'Tumon Sushi Bar', 'Meskla Chamoru Fusion' ), $this->titles( array( 'sort' => 'price_asc' ) ) );
		$this->assertSame( array( 'Meskla Chamoru Fusion', 'Tumon Sushi Bar', 'Proa', 'Dededo Kimchi House' ), $this->titles( array( 'sort' => 'price_desc' ) ) );
		$this->assertSame( array( 'Proa', 'Tumon Sushi Bar' ), $this->titles( array( 'q' => 'tumon' ) ), 'Tumon is Tamuning' );
		$this->assertSame( array( 'Tumon Sushi Bar' ), $this->titles( array( 'q' => 'sushi tumon' ) ) );
	}

	/**
	 * Village pages lock their village; pagination URLs keep the filters.
	 */
	public function test_locked_facet_and_urls() {
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		$filters = Filters::from_params(
			array(
				'village' => 'tamuning',
				'cuisine' => 'korean',
			),
			array( 'village' => 'dededo' )
		);
		$this->assertSame( array( 'dededo' ), $filters['facets']['village'] );
		$url = Directory_View::page_url( $filters, array( 'village' => 'dededo' ), 3 );
		$this->assertStringContainsString( '/village/dededo/', $url );
		$this->assertStringContainsString( 'cuisine=korean', $url );
		$this->assertStringNotContainsString( 'village=', $url );

		$this->assertSame( array( 'village' => 'dededo' ), Directory_View::parse_context( 'village:dededo' ) );
		$this->assertSame( array(), Directory_View::parse_context( 'village:atlantis' ) );
		$this->assertSame( array(), Directory_View::parse_context( 'post_tag:x' ) );
	}

	/*
	 * REST.
	 */

	/**
	 * The directory endpoint returns the page's own results markup, a shareable URL and cache headers.
	 */
	public function test_rest_directory() {
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/directory' );
		$request->set_query_params(
			array(
				'cuisine' => 'chamorro',
				'context' => 'village:tamuning',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['total'] );
		$this->assertStringContainsString( 'Proa', $data['html'] );
		$this->assertStringNotContainsString( 'Meskla', $data['html'] );
		$this->assertStringContainsString( '/village/tamuning/?cuisine=chamorro', $data['url'] );
		$this->assertSame( 'public, max-age=300', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * The map endpoint returns GeoJSON points for restaurants with coordinates only.
	 */
	public function test_rest_map() {
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/guam-restaurants/v1/map' ) );
		$data     = $response->get_data();
		$names    = wp_list_pluck( wp_list_pluck( $data['features'], 'properties' ), 'name' );
		sort( $names );

		$this->assertSame( 'FeatureCollection', $data['type'] );
		$this->assertSame( array( 'Dededo Kimchi House', 'Proa', 'Tumon Sushi Bar' ), $names, 'No coordinates, no point; closed never shown' );
		$this->assertSame( array( 144.807, 13.513 ), $data['features'][ array_search( 'Proa', wp_list_pluck( wp_list_pluck( $data['features'], 'properties' ), 'name' ), true ) ]['geometry']['coordinates'] );

		$request = new WP_REST_Request( 'GET', '/guam-restaurants/v1/map' );
		$request->set_query_params( array( 'open' => 'now' ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertCount( 1, $response->get_data()['features'] );
		$this->assertSame( 'public, max-age=60', $response->get_headers()['Cache-Control'] );
	}

	/*
	 * Restaurant page.
	 */

	/**
	 * History lists opened, first listed and reopened dates, newest first; closures are not listed.
	 */
	public function test_history_and_reopenings() {
		$id = $this->r['kimchi'];
		update_post_meta( $id, Restaurant_Meta::OPENED_ON, '2019-03' );
		Change_Tracker::record_first_seen( $id, 'osm', null, true );
		Change_Log::record( $id, Restaurant_Meta::STATUS, 'open', 'temporarily_closed', 'editor' );
		Change_Log::record( $id, Restaurant_Meta::STATUS, 'temporarily_closed', 'open', 'editor' );
		update_post_meta( $id, Restaurant_Meta::STATUS, 'open' );

		$events = History::events( $id );
		$this->assertSame( array( 'reopened', 'listed', 'opened' ), array_values( array_unique( wp_list_pluck( $events, 'type' ) ) ) );
		$this->assertSame( 'March 2019', $events[ count( $events ) - 1 ]['date'] );

		$reopened = Queries::recent_reopenings( 7 );
		$this->assertSame( array( $id ), wp_list_pluck( $reopened, 'restaurant_id' ) );

		$html = do_blocks( '<!-- wp:guam-restaurants/change-history {} /-->' );
		$this->assertSame( '', $html, 'Needs a restaurant in context' );
		$this->go_to( get_permalink( $id ) );
		$this->assertStringContainsString( 'Reopened', do_blocks( '<!-- wp:guam-restaurants/change-history /-->' ) );
		$this->assertStringNotContainsString( 'Temporarily closed', do_blocks( '<!-- wp:guam-restaurants/change-history /-->' ) );
	}

	/**
	 * The Restaurant node carries our facts: address, geo, cuisine, price, hours.
	 */
	public function test_structured_data_facts() {
		update_post_meta( $this->r['proa'], Restaurant_Meta::ADDRESS, '1 Pale San Vitores Road' );
		update_post_meta( $this->r['proa'], Restaurant_Meta::PHONE, '+16716467762' );
		$node = Structured_Data::node( $this->r['proa'] );

		$this->assertSame( 'Restaurant', $node['@type'] );
		$this->assertSame( '1 Pale San Vitores Road', $node['address']['streetAddress'] );
		$this->assertSame( 'Tamuning', $node['address']['addressLocality'] );
		$this->assertSame( 'GU', $node['address']['addressCountry'] );
		$this->assertSame( 13.513, $node['geo']['latitude'] );
		$this->assertSame( '+16716467762', $node['telephone'] );
		$this->assertSame( array( 'Chamorro' ), $node['servesCuisine'] );
		$this->assertSame( '$$', $node['priceRange'] );
		$this->assertCount( 7, $node['openingHoursSpecification'] );
		$this->assertSame( array( 'Sample Awards 2025, Best Fusion: Finalist' ), $node['award'] );
		$this->assertArrayNotHasKey( 'aggregateRating', $node, 'No third-party ratings' );
	}

	/**
	 * Pages that can show Google ratings never get a non-Google map.
	 */
	public function test_google_map_rule() {
		$this->assertSame( 'maplibre', Maps::restaurant_map_kind( $this->r['proa'] ) );
		$this->assertSame( 'none', Maps::restaurant_map_kind( $this->r['meskla'] ), 'No coordinates' );

		update_post_meta( $this->r['proa'], Restaurant_Meta::GOOGLE_PLACE_ID, 'ChIJproa' );
		$this->assertSame( 'maplibre', Maps::restaurant_map_kind( $this->r['proa'] ), 'Place ID but no Google key: no Google content' );

		$google = static function ( $providers ) {
			$providers['google'] = new \GuamRestaurants\Core\Ratings\Google_Provider( 'test-key' );
			return $providers;
		};
		add_filter( 'guam_restaurants_rating_providers', $google );
		$this->assertSame( 'none', Maps::restaurant_map_kind( $this->r['proa'] ), 'Google content, no embed key: no map' );
		putenv( 'GUAM_RESTAURANTS_GOOGLE_MAPS_EMBED_KEY=embed-key' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test setting.
		$this->assertSame( 'google', Maps::restaurant_map_kind( $this->r['proa'] ) );
		$this->assertStringContainsString( 'place_id%3AChIJproa', Maps::google_embed_url( $this->r['proa'] ) );
		putenv( 'GUAM_RESTAURANTS_GOOGLE_MAPS_EMBED_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test setting.
		remove_filter( 'guam_restaurants_rating_providers', $google );
	}

	/*
	 * Home page and links.
	 */

	/**
	 * The sponsored slot prints nothing without an ad and is always labeled with one.
	 */
	public function test_sponsored_slot() {
		$this->assertSame( '', Sponsored::render( 'home' ) );
		$ad = static function () {
			return '<a href="https://example.com/">Lunch special</a>';
		};
		add_filter( 'guam_restaurants_sponsored_slot', $ad );
		$html = Sponsored::render( 'home' );
		$this->assertStringContainsString( '<p class="gr-sponsored__label">Sponsored</p>', $html );
		$this->assertStringContainsString( 'Lunch special', $html );

		// Sponsorship never changes listing order.
		$this->assertSame( array( 'Dededo Kimchi House', 'Meskla Chamoru Fusion', 'Proa', 'Tumon Sushi Bar' ), $this->titles( array() ) );
		remove_filter( 'guam_restaurants_sponsored_slot', $ad );
	}

	/**
	 * Listing links appear only for published pages; contact only for claimed listings.
	 */
	public function test_listing_actions() {
		$pages = Pages::ensure();
		$this->go_to( get_permalink( $this->r['proa'] ) );
		$this->assertSame( '', Blocks::listing_actions( array() ), 'Draft pages are not linked' );

		wp_publish_post( $pages['claim'] );
		wp_publish_post( $pages['correction'] );
		wp_publish_post( $pages['contact'] );
		$html = Blocks::listing_actions( array() );
		$this->assertStringContainsString( 'Claim this listing', $html );
		$this->assertStringContainsString( 'restaurant=' . $this->r['proa'], $html );
		$this->assertStringNotContainsString( 'Contact the owner', $html );

		update_post_meta( $this->r['proa'], Restaurant_Meta::CLAIMED_BY_USER_ID, 1 );
		$html = Blocks::listing_actions( array() );
		$this->assertStringNotContainsString( 'Claim this listing', $html );
		$this->assertStringContainsString( 'Contact the owner', $html );
	}

	/**
	 * Awards feature lists recorded winners with their citation, or says none are recorded.
	 */
	public function test_awards_feature() {
		$html = Blocks::awards_feature( array( 'limit' => 6 ) );
		$this->assertStringContainsString( 'Meskla Chamoru Fusion', $html );
		$this->assertStringContainsString( 'Sample Awards 2025, Best Fusion: Winner', $html );
		$this->assertStringNotContainsString( 'Proa', $html, 'Finalists are left out' );
		$this->assertStringContainsString( 'not affiliated', $html );
	}

	/**
	 * Mall chips list verified malls only.
	 */
	public function test_term_links_hide_unverified_malls() {
		$mall = get_term_by( 'slug', 'micronesia-mall', Content_Types::TAX_MALL );
		$this->assertFalse( $mall, 'Unverified malls are hidden from visitors' );
		$mall   = \GuamRestaurants\Core\Mall_Verification::with_unverified(
			static function () {
				return get_term_by( 'slug', 'micronesia-mall', Content_Types::TAX_MALL );
			}
		);
		$kimchi = $this->r['kimchi'];
		\GuamRestaurants\Core\Mall_Verification::with_unverified(
			static function () use ( $kimchi, $mall ) {
				wp_set_object_terms( $kimchi, array( (int) $mall->term_id ), Content_Types::TAX_MALL );
			}
		);
		$this->assertStringNotContainsString( 'Micronesia Mall', Blocks::term_links( array( 'taxonomy' => Content_Types::TAX_MALL ) ) );
		$this->assertSame(
			'',
			Blocks::term_links(
				array(
					'taxonomy' => Content_Types::TAX_MALL,
					'heading'  => 'Malls',
				)
			),
			'No verified malls: no heading either'
		);

		update_term_meta( $mall->term_id, Term_Meta::VERIFIED, 1 );
		$html = Blocks::term_links(
			array(
				'taxonomy' => Content_Types::TAX_MALL,
				'heading'  => 'Malls',
			)
		);
		$this->assertStringContainsString( 'Micronesia Mall', $html );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Malls</h2>', $html );
	}

	/**
	 * Filtered listings are noindex; the plain village page is not.
	 */
	public function test_filtered_listings_are_noindex() {
		$this->go_to( get_term_link( 'dededo', Content_Types::TAX_VILLAGE ) );
		$this->assertArrayNotHasKey( 'noindex', \GuamRestaurants\Core\Site\Seo::robots( array() ) );

		$_GET['cuisine'] = 'korean';
		$this->go_to( add_query_arg( 'cuisine', 'korean', get_term_link( 'dededo', Content_Types::TAX_VILLAGE ) ) );
		$this->assertTrue( \GuamRestaurants\Core\Site\Seo::robots( array() )['noindex'] );
		unset( $_GET['cuisine'] );
	}
}
