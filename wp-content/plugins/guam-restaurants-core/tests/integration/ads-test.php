<?php
/**
 * Ad serving tests: scheduling, targeting, rotation, labeling, tracking,
 * reports, and the rule that ads never influence editorial content.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Ads\Admin;
use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Context;
use GuamRestaurants\Core\Ads\Guard;
use GuamRestaurants\Core\Ads\Report;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Ads\Slot;
use GuamRestaurants\Core\Ads\Stats;
use GuamRestaurants\Core\Ads\Tracking;
use GuamRestaurants\Core\Awards\Repository as Awards;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Site\Blocks;
use GuamRestaurants\Core\Site\Directory_Query;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Structured_Data;
use WP_REST_Request;

/**
 * Ads.
 *
 * @covers \GuamRestaurants\Core\Ads\Ads
 * @covers \GuamRestaurants\Core\Ads\Admin
 * @covers \GuamRestaurants\Core\Ads\Context
 * @covers \GuamRestaurants\Core\Ads\Guard
 * @covers \GuamRestaurants\Core\Ads\Report
 * @covers \GuamRestaurants\Core\Ads\Server
 * @covers \GuamRestaurants\Core\Ads\Slot
 * @covers \GuamRestaurants\Core\Ads\Stats
 * @covers \GuamRestaurants\Core\Ads\Tracking
 */
class Ads_Test extends Guam_Test_Case {

	/**
	 * Saturday 2026-10-10 19:30 in Guam (09:30 UTC).
	 */
	private const NOW = 1791624600;

	private const TODAY = '2026-10-10';

	private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

	/**
	 * Restaurants by key.
	 *
	 * @var array<string, int>
	 */
	private array $r = array();

	/**
	 * Fixed clock, a few restaurants, and logged out.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'guam_restaurants_now', array( $this, 'now' ) );
		Server::reset();

		$this->r['proa']   = $this->place( 'Proa', 'tamuning', array( 'chamorro' ) );
		$this->r['sushi']  = $this->place( 'Tumon Sushi Bar', 'tamuning', array( 'japanese' ) );
		$this->r['kimchi'] = $this->place( 'Dededo Kimchi House', 'dededo', array( 'korean' ) );
		Awards::upsert(
			$this->r['kimchi'],
			array(
				'program'     => 'Sample Awards',
				'year'        => 2025,
				'category'    => 'Best Korean',
				'placement'   => 'winner',
				'source_url'  => 'https://example.com/results',
				'source_note' => '',
			)
		);
		wp_set_current_user( 0 );
	}

	/**
	 * Clears per-request state.
	 */
	public function tear_down() {
		Server::reset();
		parent::tear_down();
	}

	/**
	 * Fixed clock.
	 *
	 * @return int
	 */
	public function now(): int {
		return self::NOW;
	}

	/**
	 * A restaurant with a village and cuisines.
	 *
	 * @param string   $name     Name.
	 * @param string   $village  Village slug.
	 * @param string[] $cuisines Cuisine slugs.
	 * @return int
	 */
	private function place( string $name, string $village, array $cuisines ): int {
		$id = $this->restaurant( $name );
		wp_set_object_terms( $id, $village, Content_Types::TAX_VILLAGE );
		wp_set_object_terms( $id, $cuisines, Content_Types::TAX_CUISINE );
		return $id;
	}

	/**
	 * Term ID by slug.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return int
	 */
	private function term( string $slug, string $taxonomy ): int {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		$this->assertInstanceOf( \WP_Term::class, $term, $slug );
		return (int) $term->term_id;
	}

	/**
	 * Creates an active ad.
	 *
	 * @param string               $title Title.
	 * @param array<string, mixed> $input Overrides.
	 * @return int
	 */
	private function ad( string $title, array $input = array() ): int {
		$id = Ads::create(
			$title,
			$input + array(
				'advertiser' => 'Harbor Grill LLC',
				'product'    => 'banner',
				'headline'   => $title,
				'link'       => 'https://example.com/' . sanitize_title( $title ),
				'start'      => '2026-10-01',
				'end'        => '2026-10-31',
				'status'     => Ads::STATUS_ACTIVE,
			)
		);
		$this->assertIsInt( $id, is_wp_error( $id ) ? implode( ' ', $id->get_error_messages() ) : '' );
		return $id;
	}

	/**
	 * IDs of the ads in slot markup, in order.
	 *
	 * @param string $html Markup.
	 * @return int[]
	 */
	private function shown( string $html ): array {
		preg_match_all( '/data-gr-ad="(\d+)"/', $html, $m );
		return array_map( 'intval', $m[1] );
	}

	/**
	 * Sends tracking events.
	 *
	 * @param array<string, mixed> $body       Body.
	 * @param string               $user_agent User-Agent.
	 * @return \WP_REST_Response
	 */
	private function track( array $body, string $user_agent = self::BROWSER ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/guam-restaurants/v1/ads/events' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'User-Agent', $user_agent );
		$request->set_body( (string) wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Counter rows for an ad.
	 *
	 * @param int $ad_id Ad.
	 * @return array<int, array<string, mixed>>
	 */
	private function stats( int $ad_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::AD_DAILY_STATS );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ad_id = %d ORDER BY stat_date, page_type", $ad_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/*
	 * Scheduling.
	 */

	/**
	 * Start and end dates are inclusive; no end date is open-ended; no start never runs.
	 */
	public function test_schedule_dates_are_inclusive() {
		$ad = array(
			'start' => '2026-10-05',
			'end'   => '2026-10-10',
		);
		$this->assertFalse( Server::is_running( $ad, '2026-10-04' ) );
		$this->assertTrue( Server::is_running( $ad, '2026-10-05' ) );
		$this->assertTrue( Server::is_running( $ad, '2026-10-10' ) );
		$this->assertFalse( Server::is_running( $ad, '2026-10-11' ) );
		$this->assertTrue(
			Server::is_running(
				array(
					'start' => '2026-10-05',
					'end'   => '',
				),
				'2030-01-01'
			)
		);
		$this->assertFalse(
			Server::is_running(
				array(
					'start' => '',
					'end'   => '',
				),
				'2026-10-10'
			)
		);
	}

	/**
	 * Only running, active, published ads are served, by the Guam date.
	 */
	public function test_only_running_active_published_ads_are_served() {
		$this->assertSame( self::TODAY, Server::today(), 'Guam date, not UTC' );

		$running  = $this->ad( 'Running' );
		$future   = $this->ad( 'Future', array( 'start' => '2026-10-11' ) );
		$ended    = $this->ad(
			'Ended',
			array(
				'start' => '2026-09-01',
				'end'   => '2026-10-09',
			)
		);
		$paused   = $this->ad( 'Paused', array( 'status' => Ads::STATUS_PAUSED ) );
		$draft    = $this->ad( 'Draft' );
		$tomorrow = $this->ad( 'Starts tomorrow UTC', array( 'start' => '2026-10-10' ) );
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);

		$html = Slot::render( 'banner', 6, Context::make( 'home' ) );
		$this->assertEqualsCanonicalizing( array( $running, $tomorrow ), $this->shown( $html ) );
		foreach ( array( $future, $ended, $paused, $draft ) as $id ) {
			$this->assertNotContains( $id, $this->shown( $html ) );
		}
	}

	/*
	 * Targeting.
	 */

	/**
	 * Page types, villages, malls and cuisines: any within a list, all lists together.
	 */
	public function test_targeting_rules() {
		$village             = Context::make( 'village' );
		$village['villages'] = array( 7 );

		$this->assertTrue( Server::matches( array(), $village ), 'No targets matches anywhere' );
		$this->assertTrue( Server::matches( array( 'page_types' => array( 'village', 'mall' ) ), $village ) );
		$this->assertFalse( Server::matches( array( 'page_types' => array( 'home' ) ), $village ) );
		$this->assertTrue( Server::matches( array( 'villages' => array( 3, 7 ) ), $village ) );
		$this->assertFalse( Server::matches( array( 'villages' => array( 3 ) ), $village ) );
		$this->assertFalse( Server::matches( array( 'villages' => array( 7 ) ), Context::make( 'home' ) ), 'A village ad never shows on the home page' );
		$this->assertFalse(
			Server::matches(
				array(
					'villages' => array( 7 ),
					'cuisines' => array( 9 ),
				),
				$village
			),
			'Every target kind must match'
		);
	}

	/**
	 * A village ad shows on its village page and on restaurant pages in that village only.
	 */
	public function test_village_ad_shows_only_in_its_village() {
		$tamuning = $this->term( 'tamuning', Content_Types::TAX_VILLAGE );
		$dededo   = $this->term( 'dededo', Content_Types::TAX_VILLAGE );
		$ad       = $this->ad( 'Tamuning lunch', array( 'villages' => array( $tamuning ) ) );

		$this->assertSame( array( $ad ), $this->shown( Slot::render( 'banner', 1, Context::for_term( get_term( $tamuning ) ) ) ) );
		Server::reset();
		$this->assertSame( '', Slot::render( 'banner', 1, Context::for_term( get_term( $dededo ) ) ) );
		$this->assertSame( array( $ad ), $this->shown( Slot::render( 'banner', 1, Context::for_restaurant( $this->r['proa'] ) ) ) );
		Server::reset();
		$this->assertSame( '', Slot::render( 'banner', 1, Context::for_restaurant( $this->r['kimchi'] ) ) );
		$this->assertSame( '', Slot::render( 'banner', 1, Context::make( 'home' ) ) );
	}

	/**
	 * The request context comes from the page being viewed.
	 */
	public function test_context_from_request() {
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'home', Context::current()['page_type'] );

		$this->go_to( get_term_link( $this->term( 'dededo', Content_Types::TAX_VILLAGE ) ) );
		$context = Context::current();
		$this->assertSame( 'village', $context['page_type'] );
		$this->assertSame( array( $this->term( 'dededo', Content_Types::TAX_VILLAGE ) ), $context['villages'] );

		$this->go_to( get_permalink( $this->r['sushi'] ) );
		$context = Context::current();
		$this->assertSame( 'restaurant', $context['page_type'] );
		$this->assertSame( array( $this->term( 'japanese', Content_Types::TAX_CUISINE ) ), $context['cuisines'] );
	}

	/*
	 * Rotation and exclusivity.
	 */

	/**
	 * Priority first, then fewest impressions today, then lowest ID.
	 */
	public function test_rotation_order() {
		$ads   = array(
			array(
				'id'       => 30,
				'priority' => 0,
			),
			array(
				'id'       => 20,
				'priority' => 5,
			),
			array(
				'id'       => 10,
				'priority' => 0,
			),
			array(
				'id'       => 40,
				'priority' => 0,
			),
		);
		$order = wp_list_pluck(
			Server::order(
				$ads,
				array(
					10 => 8,
					30 => 2,
					20 => 99,
				)
			),
			'id'
		);
		$this->assertSame( array( 20, 40, 30, 10 ), $order );
	}

	/**
	 * Same-priority banners take turns as their impressions are counted.
	 */
	public function test_rotation_evens_out_impressions() {
		$a    = $this->ad( 'A' );
		$b    = $this->ad( 'B' );
		$c    = $this->ad( 'C' );
		$seen = array();
		for ( $i = 0; $i < 6; $i++ ) {
			Server::reset();
			$shown  = $this->shown( Slot::render( 'banner', 1, Context::make( 'home' ) ) );
			$seen[] = $shown[0];
			Stats::add( $shown[0], self::TODAY, 'home', 1, 0 );
		}
		$this->assertSame( array( $a, $b, $c, $a, $b, $c ), $seen );
	}

	/**
	 * Featured listings fill up to their count, and no ad repeats on one page.
	 */
	public function test_featured_listings_share_a_page_without_repeats() {
		$ids = array();
		foreach ( array( 'One', 'Two', 'Three', 'Four' ) as $name ) {
			$ids[] = $this->ad(
				$name,
				array(
					'product'       => 'featured_listing',
					'restaurant_id' => $this->r['proa'],
				)
			);
		}
		$first  = $this->shown( Slot::render( 'featured_listing', 3, Context::make( 'village' ) ) );
		$second = $this->shown( Slot::render( 'featured_listing', 3, Context::make( 'village' ) ) );
		$this->assertSame( array_slice( $ids, 0, 3 ), $first );
		$this->assertSame( array( $ids[3] ), $second );
	}

	/**
	 * One category sponsor per page, whatever the slot asks for; overlaps are reported.
	 */
	public function test_category_sponsor_is_exclusive() {
		$this->login_admin();
		$tamuning = $this->term( 'tamuning', Content_Types::TAX_VILLAGE );
		$input    = array(
			'product'  => 'category_sponsor',
			'villages' => array( $tamuning ),
		);
		$low      = $this->ad( 'Low', $input );
		$high     = $this->ad( 'High', $input + array( 'priority' => 3 ) );
		$context  = Context::for_term( get_term( $tamuning ) );

		$this->assertSame( array( $high ), $this->shown( Slot::render( 'category_sponsor', 3, $context ) ) );
		$this->assertSame( '', Slot::render( 'category_sponsor', 1, $context ), 'A second slot on the same page stays empty' );

		$this->assertSame( array( $high ), Guard::conflicts( Ads::get( $low ) ) );
		$messages = Admin::save_ad(
			$low,
			$input + array(
				'advertiser' => 'Harbor Grill LLC',
				'headline'   => 'Low',
				'link'       => 'https://example.com/',
				'start'      => '2026-10-01',
				'status'     => Ads::STATUS_ACTIVE,
			)
		);
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( 'High', $messages[0] );

		// Different villages or months do not conflict.
		$this->assertFalse( Guard::targets_overlap( array( 'villages' => array( 1 ) ), array( 'villages' => array( 2 ) ) ) );
		$this->assertFalse(
			Guard::dates_overlap(
				array(
					'start' => '2026-10-01',
					'end'   => '2026-10-31',
				),
				array(
					'start' => '2026-11-01',
					'end'   => '',
				)
			)
		);
	}

	/*
	 * Labeling.
	 */

	/**
	 * Every ad has its own Sponsored label and every link is rel="sponsored noopener".
	 */
	public function test_every_ad_is_labeled_and_links_are_sponsored() {
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => 'banner.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => 0,
			)
		);
		$this->ad(
			'Image ad',
			array(
				'creative_type' => Ads::CREATIVE_IMAGE,
				'image_id'      => $image,
				'image_alt'     => 'Plate of kelaguen',
			)
		);
		$this->ad(
			'Listing ad',
			array(
				'restaurant_id' => $this->r['proa'],
				'headline'      => '',
				'link'          => '',
				'text'          => 'Fiesta plates every Friday.',
			)
		);
		$this->ad( 'Text ad', array( 'text' => 'Happy hour 4 to 6.' ) );

		$html = Slot::render( 'banner', 3, Context::make( 'home' ) );
		$this->assertCount( 3, $this->shown( $html ) );
		$this->assertSame( 3, substr_count( $html, '<p class="gr-sponsored__label">Sponsored</p>' ), 'One visible label per ad' );
		$this->assertStringContainsString( 'aria-label="Sponsored"', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'featured', $html );

		preg_match_all( '/<a\s[^>]*>/', $html, $links );
		$this->assertNotEmpty( $links[0] );
		foreach ( $links[0] as $link ) {
			$this->assertStringContainsString( 'rel="sponsored noopener"', $link );
		}
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $this->r['proa'] ) ) . '"', $html, 'A listing ad links to the restaurant' );
		$this->assertStringContainsString( '>Proa</a>', $html, 'and is headed with its name' );
		$this->assertStringContainsString( 'alt="Plate of kelaguen"', $html );

		// No structured data or rating markup in a slot.
		foreach ( array( 'itemprop', 'itemscope', 'application/ld+json', 'rating', 'award' ) as $needle ) {
			$this->assertStringNotContainsStringIgnoringCase( $needle, $html );
		}
		$this->assertTrue( wp_script_is( Tracking::SCRIPT, 'enqueued' ) );
	}

	/**
	 * Nothing is printed when no ad runs.
	 */
	public function test_empty_slot_prints_nothing() {
		$this->assertSame( '', Slot::render( 'banner', 1, Context::make( 'home' ) ) );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', Blocks::sponsored_slot( array( 'product' => 'banner' ) ) );
	}

	/**
	 * The block renders the slot for the page being viewed.
	 */
	public function test_block_renders_on_home_page() {
		$ad = $this->ad( 'Home banner', array( 'page_types' => array( 'home' ) ) );
		$this->go_to( home_url( '/' ) );
		$html = do_blocks( '<!-- wp:guam-restaurants/sponsored-slot {"product":"banner","count":1} /-->' );
		$this->assertSame( array( $ad ), $this->shown( $html ) );
		$this->assertStringContainsString( 'data-gr-ad-page="home"', $html );
	}

	/**
	 * Editorial words and awards cannot be activated, and are never served.
	 */
	public function test_banned_words_block_activation() {
		$fields = Ads::sanitize(
			array(
				'advertiser' => 'Harbor Grill LLC',
				'product'    => 'banner',
				'headline'   => 'Featured: our top pick',
				'link'       => 'https://example.com/',
				'start'      => '2026-10-01',
				'status'     => Ads::STATUS_ACTIVE,
			)
		);
		$valid  = Ads::validate( $fields );
		$this->assertWPError( $valid );
		$this->assertCount( 2, $valid->get_error_messages() );
		$this->assertNotEmpty( Guard::creative_problems( array( 'text' => 'Award-winning ribs' ) ) );
		$this->assertNotEmpty( Guard::creative_problems( array( 'text' => "Editor's choice" ) ) );
		$this->assertSame( array(), Guard::creative_problems( array( 'text' => 'Fresh poke daily' ) ) );

		// Saving from the edit screen keeps the ad, paused.
		$this->login_admin();
		$id       = $this->ad( 'Clean' );
		$messages = Admin::save_ad(
			$id,
			array(
				'advertiser' => 'Harbor Grill LLC',
				'product'    => 'banner',
				'headline'   => 'Recommended by locals',
				'link'       => 'https://example.com/',
				'start'      => '2026-10-01',
				'status'     => Ads::STATUS_ACTIVE,
			)
		);
		$this->assertNotEmpty( $messages );
		$this->assertSame( Ads::STATUS_PAUSED, Ads::get( $id )['status'] );

		// Even if meta is changed directly, it is not served.
		update_post_meta( $id, Ads::META_STATUS, Ads::STATUS_ACTIVE );
		$this->assertSame( '', Slot::render( 'banner', 1, Context::make( 'home' ) ) );
	}

	/**
	 * Required fields.
	 */
	public function test_validation() {
		$valid = Ads::validate( Ads::sanitize( array( 'creative_type' => Ads::CREATIVE_IMAGE ) ) );
		$this->assertWPError( $valid );
		$this->assertEqualsCanonicalizing(
			array( 'gr_ad_advertiser', 'gr_ad_product', 'gr_ad_image', 'gr_ad_link', 'gr_ad_start' ),
			$valid->get_error_codes()
		);
		$valid = Ads::validate(
			Ads::sanitize(
				array(
					'advertiser' => 'A',
					'product'    => 'banner',
					'headline'   => 'H',
					'link'       => 'javascript:alert(1)',
					'start'      => '2026-10-10',
					'end'        => '2026-10-01',
				)
			)
		);
		$this->assertEqualsCanonicalizing( array( 'gr_ad_link', 'gr_ad_dates' ), $valid->get_error_codes() );
	}

	/**
	 * Only administrators manage ads.
	 */
	public function test_capability() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Ads::CAP ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Ads::CAP ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( user_can( $editor, 'edit_post', $this->ad( 'X' ) ) );
	}

	/*
	 * Tracking.
	 */

	/**
	 * Impressions and clicks become daily counters, once per ad and type per request.
	 */
	public function test_tracking_counts_events() {
		$ad       = $this->ad( 'Counted' );
		$response = $this->track(
			array(
				'page'   => 'village',
				'events' => array(
					array(
						'ad'   => $ad,
						'type' => 'impression',
					),
					array(
						'ad'   => $ad,
						'type' => 'impression',
					),
					array(
						'ad'   => $ad,
						'type' => 'click',
					),
				),
			)
		);
		$this->assertSame( 204, $response->get_status() );
		$this->track(
			array(
				'page'   => 'village',
				'events' => array(
					array(
						'ad'   => $ad,
						'type' => 'impression',
					),
				),
			)
		);
		$rows = $this->stats( $ad );
		$this->assertCount( 1, $rows );
		$this->assertSame( self::TODAY, $rows[0]['stat_date'] );
		$this->assertSame( 'village', $rows[0]['page_type'] );
		$this->assertSame( 2, (int) $rows[0]['impressions'] );
		$this->assertSame( 1, (int) $rows[0]['clicks'] );

		// Only counters are stored: no IP, user agent, cookie or user.
		$this->assertSame( array( 'ad_id', 'stat_date', 'page_type', 'impressions', 'clicks' ), array_keys( $rows[0] ) );
	}

	/**
	 * Bots, unknown or stopped ads and bad input are not counted.
	 */
	public function test_tracking_filters_bots_and_bad_events() {
		$ad     = $this->ad( 'Counted' );
		$paused = $this->ad( 'Paused', array( 'status' => Ads::STATUS_PAUSED ) );
		$event  = static function ( int $id ): array {
			return array(
				'page'   => 'home',
				'events' => array(
					array(
						'ad'   => $id,
						'type' => 'impression',
					),
				),
			);
		};

		foreach ( array( 'Googlebot/2.1 (+http://www.google.com/bot.html)', 'Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/120.0', 'curl/8.4.0', '' ) as $agent ) {
			$this->assertSame( 204, $this->track( $event( $ad ), $agent )->get_status() );
		}
		$this->track( $event( $paused ) );
		$this->track( $event( $this->r['proa'] ) );
		$this->track( $event( 999999 ) );
		$this->assertSame( array(), $this->stats( $ad ) );
		$this->assertSame( array(), $this->stats( $paused ) );
		$this->assertSame( array(), $this->stats( $this->r['proa'] ) );

		$this->assertSame( 400, $this->track( array( 'page' => 'nowhere' ) + $event( $ad ) )->get_status() );
		$too_many = array(
			'page'   => 'home',
			'events' => array_fill(
				0,
				Tracking::MAX_EVENTS + 1,
				array(
					'ad'   => $ad,
					'type' => 'impression',
				)
			),
		);
		$this->assertSame( 400, $this->track( $too_many )->get_status() );

		$this->assertFalse( Tracking::is_bot( self::BROWSER ) );
		$this->assertTrue( Tracking::is_bot( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ) );
		$this->assertTrue( Tracking::is_bot( 'facebookexternalhit/1.1' ) );
	}

	/*
	 * Reports.
	 */

	/**
	 * Monthly totals per ad and page type, with CTR and a total, for one advertiser or all.
	 */
	public function test_monthly_report() {
		$mine   = $this->ad( 'Mine' );
		$theirs = $this->ad( 'Theirs', array( 'advertiser' => '=Other Co' ) );
		Stats::add( $mine, '2026-09-30', 'home', 50, 5 );
		Stats::add( $mine, '2026-10-01', 'home', 100, 3 );
		Stats::add( $mine, '2026-10-31', 'home', 100, 1 );
		Stats::add( $mine, '2026-10-02', 'village', 50, 0 );
		Stats::add( $theirs, '2026-10-03', 'home', 10, 1 );
		Stats::add( $mine, '2026-11-01', 'home', 70, 7 );

		$rows = Report::rows( '2026-10', 'Harbor Grill LLC' );
		$this->assertCount( 2, $rows );
		$this->assertSame( array( 200, 4 ), array( $rows[0]['impressions'], $rows[0]['clicks'] ) );
		$this->assertSame( 'Home page', $rows[0]['page_type'] );

		$csv = Report::csv_rows( '2026-10', 'Harbor Grill LLC' );
		$this->assertSame( array( 'Month', 'Advertiser', 'Ad ID', 'Ad', 'Product', 'Page type', 'Impressions', 'Clicks', 'Click-through rate' ), $csv[0] );
		$this->assertSame( '2.00%', $csv[1][8] );
		$this->assertSame( array( '2026-10', 'Harbor Grill LLC', '', 'Total', '', '', '250', '4', '1.60%' ), end( $csv ) );

		$all = Report::csv_rows( '2026-10' );
		$this->assertCount( 5, $all, 'Header, three rows and the total' );
		$this->assertContains( "'=Other Co", array_column( $all, 1 ), 'Formulas are neutralized' );
		$this->assertSame( '260', end( $all )[6] );

		$this->assertSame( '', Report::ctr( 0, 0 ) );
		$this->assertNull( Report::month_range( '2026-13' ) );
		$this->assertSame( array( '2026-02-01', '2026-02-28' ), Report::month_range( '2026-02' ) );
		$this->assertSame( array( '2026-09', '' ), Report::params( array( 'month' => 'bad' ) ), 'Defaults to last month' );
	}

	/*
	 * No influence on editorial content.
	 */

	/**
	 * Running ads (with counts) change no listing order, rating, award or structured data.
	 */
	public function test_ads_never_change_order_ratings_awards_or_structured_data() {
		$snapshot = function (): array {
			$out = array();
			foreach ( array( array(), array( 'sort' => 'name' ), array( 'village' => 'tamuning' ), array( 'award' => 'winner' ) ) as $params ) {
				$out[] = wp_list_pluck( Directory_Query::query( Filters::from_params( $params ) )->posts, 'ID' );
			}
			foreach ( $this->r as $id ) {
				$out[] = Structured_Data::node( $id );
				$out[] = get_post_meta( $id );
				$out[] = wp_get_object_terms( $id, array( Content_Types::TAX_VILLAGE, Content_Types::TAX_CUISINE ), array( 'fields' => 'ids' ) );
				$out[] = Awards::for_restaurant( $id );
			}
			return $out;
		};
		$before   = $snapshot();

		$tamuning = $this->term( 'tamuning', Content_Types::TAX_VILLAGE );
		foreach ( array( 'featured_listing', 'category_sponsor', 'banner' ) as $product ) {
			$ad = $this->ad(
				'Sushi ' . $product,
				array(
					'product'       => $product,
					'restaurant_id' => $this->r['sushi'],
					'priority'      => 10,
					'villages'      => array( $tamuning ),
				)
			);
			Stats::add( $ad, self::TODAY, 'village', 500, 50 );
			Slot::render( $product, 3, Context::for_term( get_term( $tamuning ) ) );
		}

		$this->assertEquals( $before, $snapshot() );
	}

	/**
	 * Ads stay out of sitemaps, searches, feeds and the recently opened list.
	 */
	public function test_ads_stay_out_of_sitemaps_search_and_feeds() {
		$ad = $this->ad( 'Proa special', array( 'restaurant_id' => $this->r['proa'] ) );

		$this->assertFalse( is_post_type_viewable( Ads::POST_TYPE ) );
		$this->assertArrayNotHasKey( Ads::POST_TYPE, wp_sitemaps_get_server()->registry->get_provider( 'posts' )->get_object_subtypes() );
		$this->assertTrue( Guard::yoast_exclude( false, Ads::POST_TYPE ) );
		$this->assertFalse( Guard::yoast_exclude( false, Content_Types::POST_TYPE ) );

		$search = new \WP_Query(
			array(
				's'         => 'Proa',
				'post_type' => 'any',
			)
		);
		$this->assertNotContains( $ad, wp_list_pluck( $search->posts, 'ID' ) );

		$this->go_to( get_feed_link() );
		$this->assertNotContains( $ad, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );

		$openings = Queries::recent_openings( 90 );
		$this->assertNotContains( $ad, wp_list_pluck( $openings, 'restaurant_id' ) );
		$feed = Blocks::recent_changes(
			array(
				'kind'  => 'openings',
				'limit' => 10,
			)
		);
		$this->assertStringNotContainsString( 'data-gr-ad', $feed );
		$this->assertStringNotContainsString( 'Proa special', $feed );
	}
}
