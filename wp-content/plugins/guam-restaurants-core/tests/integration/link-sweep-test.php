<?php
/**
 * Monthly link sweep: robots.txt, polite checks, review queue items and decisions.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Closures;
use GuamRestaurants\Core\Monitoring\Job_Controls;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Monitoring\Link_Checker;
use GuamRestaurants\Core\Monitoring\Link_Sweep;
use GuamRestaurants\Core\Monitoring\Robots_Txt;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Restaurant_Meta;

/**
 * A Tumon grill whose website has gone, and a café whose site has a bad week.
 *
 * @covers \GuamRestaurants\Core\Monitoring\Robots_Txt
 * @covers \GuamRestaurants\Core\Monitoring\Link_Checker
 * @covers \GuamRestaurants\Core\Monitoring\Link_Sweep
 * @covers \GuamRestaurants\Core\Monitoring\Closures::record_statement
 */
class Link_Sweep_Test extends Guam_Test_Case {

	/**
	 * URL => HTTP status, or a WP_Error message.
	 *
	 * @var array<string, int|string>
	 */
	private array $answers = array();

	/**
	 * Requests sent: "METHOD url".
	 *
	 * @var string[]
	 */
	private array $sent = array();

	/**
	 * Seconds the checker waited.
	 *
	 * @var int[]
	 */
	private array $slept = array();

	/**
	 * Temporary files.
	 *
	 * @var string[]
	 */
	private array $files = array();

	/**
	 * Removes temporary files.
	 */
	public function tear_down() {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		parent::tear_down();
	}

	/**
	 * A checker with a fake network: hosts resolve to a public address
	 * unless listed in $dns, and the clock stands still.
	 *
	 * @param array<string, string[]|false> $dns Host => addresses.
	 * @return Link_Checker
	 */
	private function checker( array $dns = array() ): Link_Checker {
		return new Link_Checker(
			function ( string $url, array $args ) {
				$this->sent[] = $args['method'] . ' ' . $url;
				$answer       = $this->answers[ $args['method'] . ' ' . $url ] ?? $this->answers[ $url ] ?? 404;
				if ( is_string( $answer ) ) {
					return new \WP_Error( 'http_request_failed', $answer );
				}
				return array(
					'response' => array( 'code' => $answer ),
					'body'     => (string) ( $this->answers[ 'body ' . $url ] ?? '' ),
					'headers'  => array(),
				);
			},
			function ( int $seconds ) {
				$this->slept[] = $seconds;
			},
			static function ( string $host ) use ( $dns ) {
				return array_key_exists( $host, $dns ) ? $dns[ $host ] : array( '93.184.216.34' );
			},
			static function (): float {
				return 1000.0;
			}
		);
	}

	/**
	 * A sweep with the fake network.
	 *
	 * @param string $fsq_file Foursquare extract.
	 * @return Link_Sweep
	 */
	private function sweep( string $fsq_file = '' ): Link_Sweep {
		return new Link_Sweep( $this->checker(), $fsq_file );
	}

	/**
	 * Pending broken-link items.
	 *
	 * @param int $post_id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( int $post_id ): array {
		return $this->rows( Schema::REVIEW_QUEUE, "type = 'broken_link' AND status = 'pending' AND restaurant_id = " . $post_id );
	}

	/**
	 * Makes every link of a restaurant look last checked a month ago.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	private function age_link_checks( int $post_id ): void {
		$state = Link_Sweep::state( $post_id );
		foreach ( $state as $key => $entry ) {
			$state[ $key ]['checked'] = time() - 30 * DAY_IN_SECONDS;
		}
		update_post_meta( $post_id, Link_Sweep::STATE_META, $state );
	}

	/**
	 * Robots rules: our own group wins over `*`, the longest rule wins, Allow wins a tie, wildcards and `$`.
	 */
	public function test_robots_rules() {
		$body = "# comment\nUser-agent: *\nDisallow: /private\nAllow: /private/menu\nDisallow: /*.pdf$\n\nUser-agent: OtherBot\nDisallow: /\n";
		$any  = new Robots_Txt( $body, 'guam-restaurants-core' );
		$this->assertTrue( $any->allowed( '/' ) );
		$this->assertFalse( $any->allowed( '/private/staff' ) );
		$this->assertTrue( $any->allowed( '/private/menu/dinner' ), 'Longer Allow wins.' );
		$this->assertFalse( $any->allowed( '/files/menu.pdf' ) );
		$this->assertTrue( $any->allowed( '/files/menu.pdf?v=2' ), '$ anchors the end.' );
		$this->assertTrue( $any->allowed( '/robots.txt' ) );

		$ours = new Robots_Txt( "User-agent: *\nDisallow: /\n\nUser-agent: guam-restaurants-core\nUser-agent: OtherBot\nDisallow: /admin\nDisallow:\n", 'Guam-Restaurants-Core' );
		$this->assertTrue( $ours->allowed( '/menu' ), 'Our own group replaces the * group.' );
		$this->assertFalse( $ours->allowed( '/admin/x' ) );

		$tie = new Robots_Txt( "User-agent: *\nDisallow: /page\nAllow: /page\n", 'x' );
		$this->assertTrue( $tie->allowed( '/page' ) );

		$this->assertTrue( Robots_Txt::allow_all()->allowed( '/anything' ) );
		$this->assertFalse( Robots_Txt::disallow_all()->allowed( '/anything' ) );
	}

	/**
	 * The checker reads robots.txt once per site, skips disallowed pages,
	 * falls back from HEAD to GET, and classifies answers.
	 */
	public function test_checker_verdicts_and_robots() {
		$this->answers = array(
			'https://grill.example/robots.txt'      => 200,
			'body https://grill.example/robots.txt' => "User-agent: *\nDisallow: /members\n",
			'HEAD https://grill.example/'           => 405,
			'GET https://grill.example/'            => 200,
			'https://grill.example/members/menu'    => 200,
			'https://grill.example/gone'            => 410,
			'https://grill.example/busy'            => 503,
			'https://grill.example/blocked'         => 403,
			'https://cafe.example/robots.txt'       => 404,
			'https://cafe.example/menu'             => 200,
			'https://down.example/robots.txt'       => 'cURL error 28: Operation timed out after 15001 milliseconds',
			'https://shaky.example/robots.txt'      => 503,
		);
		$checker       = $this->checker(
			array(
				'dead.example'     => false,
				'intranet.example' => array( '10.0.0.5' ),
			)
		);

		$this->assertSame( Link_Checker::OK, $checker->check( 'https://grill.example/' )['state'], 'HEAD 405, then GET 200.' );
		$robots = $checker->check( 'https://grill.example/members/menu' );
		$this->assertSame( Link_Checker::ROBOTS, $robots['state'] );
		$this->assertNotContains( 'HEAD https://grill.example/members/menu', $this->sent, 'A disallowed page is never requested.' );

		$gone = $checker->check( 'https://grill.example/gone' );
		$this->assertSame( array( Link_Checker::BROKEN, 410, true ), array( $gone['state'], $gone['code'], $gone['hard'] ) );
		$busy = $checker->check( 'https://grill.example/busy' );
		$this->assertSame( array( Link_Checker::BROKEN, false ), array( $busy['state'], $busy['hard'] ) );
		$this->assertSame( Link_Checker::UNKNOWN, $checker->check( 'https://grill.example/blocked' )['state'], 'A site that blocks bots is not called broken.' );
		$this->assertSame( 1, count( array_keys( $this->sent, 'GET https://grill.example/robots.txt', true ) ), 'robots.txt is read once per site.' );

		$this->assertSame( Link_Checker::OK, $checker->check( 'https://cafe.example/menu' )['state'], 'No robots.txt (404) allows everything.' );
		$down = $checker->check( 'https://down.example/' );
		$this->assertSame( array( Link_Checker::BROKEN, 'the site did not answer in time' ), array( $down['state'], $down['detail'] ) );
		$this->assertSame( Link_Checker::ROBOTS, $checker->check( 'https://shaky.example/' )['state'], 'robots.txt answering 5xx disallows everything.' );
		$dead = $checker->check( 'https://dead.example/' );
		$this->assertSame( array( Link_Checker::BROKEN, 'domain not found', false ), array( $dead['state'], $dead['detail'], $dead['hard'] ) );

		$before = count( $this->sent );
		$this->assertSame( Link_Checker::UNKNOWN, $checker->check( 'http://intranet.example/' )['state'] );
		$this->assertSame( Link_Checker::UNKNOWN, $checker->check( 'http://127.0.0.1/' )['state'] );
		$this->assertSame( Link_Checker::UNKNOWN, $checker->check( 'https://grill.example:2222/' )['state'] );
		$this->assertSame( $before, count( $this->sent ), 'Private addresses and odd ports are never requested.' );
		$this->assertSame( Link_Checker::BROKEN, $checker->check( 'not a link' )['state'] );

		$this->assertContains( Link_Checker::HOST_GAP, $this->slept, 'Requests to one site are spaced out.' );
		$this->assertSame( $checker->requests(), count( $this->sent ) );
	}

	/**
	 * A page gone (404) is queued on the first sweep, once, and nothing on the listing changes.
	 */
	public function test_gone_page_is_queued_once_without_changing_the_listing() {
		$grill         = $this->restaurant( 'Tumon Grill', array( Restaurant_Meta::WEBSITE => 'https://grill.example/' ) );
		$this->answers = array(
			'https://grill.example/robots.txt' => 404,
			'https://grill.example/'           => 404,
		);

		$result = $this->sweep()->run();
		$items  = $this->items( $grill );
		$this->assertCount( 1, $items );
		$this->assertSame( 'system', $items[0]['source'] );
		$payload = json_decode( $items[0]['payload'], true );
		$this->assertSame( array( 'website', 'https://grill.example/', 404 ), array( $payload['field'], $payload['link'], $payload['http_code'] ) );
		$this->assertSame( 'https://grill.example/', get_post_meta( $grill, Restaurant_Meta::WEBSITE, true ), 'The sweep never edits a listing.' );
		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertSame( 0, $result['counts']['failed'], 'Broken links are findings, not run failures.' );
		$run = $this->rows( Schema::INGESTION_RUNS, "source = 'link_sweep'" );
		$this->assertCount( 1, $run );
		$this->assertStringContainsString( '1 broken', $run[0]['notes'] );

		$this->login_admin();
		ob_start();
		Review_Queue_Screen::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Broken link', $html );
		$this->assertStringContainsString( 'page not found (HTTP 404)', $html );
		$this->assertStringContainsString( 'value="' . $items[0]['id'] . ':remove_link"', $html );
		$this->assertStringContainsString( 'value="' . $items[0]['id'] . ':keep_link"', $html );

		$again = $this->sweep()->run();
		$this->assertSame( 1, $again['links']['recent'], 'A second run the same day does not ask again.' );
		$this->age_link_checks( $grill );
		$this->sweep()->run();
		$this->assertCount( 1, $this->items( $grill ), 'Still one item while it waits for a decision.' );
	}

	/**
	 * Other failures need two sweeps in a row; a link that works again closes its item.
	 */
	public function test_soft_failures_need_two_sweeps_and_fixed_links_close_themselves() {
		$cafe          = $this->restaurant( 'Hagåtña Café', array( Restaurant_Meta::WEBSITE => 'https://cafe.example/' ) );
		$this->answers = array(
			'https://cafe.example/robots.txt' => 404,
			'https://cafe.example/'           => 503,
		);

		$this->sweep()->run();
		$this->assertSame( array(), $this->items( $cafe ), 'One bad day is not enough.' );
		$this->age_link_checks( $cafe );
		$this->sweep()->run();
		$items = $this->items( $cafe );
		$this->assertCount( 1, $items );
		$this->assertSame( 2, json_decode( $items[0]['payload'], true )['checks'] );

		$this->answers['https://cafe.example/'] = 200;
		$this->age_link_checks( $cafe );
		$result = $this->sweep()->run();
		$this->assertSame( array( (int) $items[0]['id'] ), $result['fixed'] );
		$this->assertSame( array(), $this->items( $cafe ) );
		$this->assertSame( 0, Link_Sweep::state( $cafe )[ Link_Sweep::key( 'website', 'https://cafe.example/' ) ]['fails'] );
	}

	/**
	 * Blocked or robots-disallowed links are never queued; social pages are not checked.
	 */
	public function test_blocked_and_social_links_are_left_alone() {
		$a = $this->restaurant( 'Blocked', array( Restaurant_Meta::WEBSITE => 'https://blocked.example/' ) );
		$b = $this->restaurant( 'Facebook only', array( Restaurant_Meta::WEBSITE => 'https://www.facebook.com/someplace' ) );
		$this->restaurant( 'Draft', array( Restaurant_Meta::WEBSITE => 'https://draft.example/' ), 'draft' );
		$this->restaurant(
			'Closed',
			array(
				Restaurant_Meta::WEBSITE => 'https://closed.example/',
				Restaurant_Meta::STATUS  => 'permanently_closed',
			)
		);
		$this->answers = array(
			'https://blocked.example/robots.txt' => 404,
			'https://blocked.example/'           => 403,
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->sweep()->run();
			$this->age_link_checks( $a );
		}
		$this->assertSame( array(), $this->items( $a ) );
		$this->assertSame( array(), Link_Sweep::links( $b ) );
		foreach ( $this->sent as $request ) {
			$this->assertStringContainsString( 'blocked.example', $request, 'Only the published, open restaurant with a real website is checked.' );
		}
	}

	/**
	 * Menu links and a running premium button are checked too; "Remove link"
	 * takes the link off and keeps imports from adding it back.
	 */
	public function test_menu_and_button_links_and_remove_decision() {
		$admin = $this->login_admin();
		$grill = $this->restaurant(
			'Tumon Grill',
			array(
				Restaurant_Meta::WEBSITE  => 'https://grill.example/',
				Owner_Content::MENU_LINKS => array(
					array(
						'label' => 'Dinner',
						'url'   => 'https://grill.example/dinner',
					),
					array(
						'label' => 'Lunch',
						'url'   => 'https://grill.example/lunch',
					),
				),
				Premium::CTA              => array(
					'action' => 'order',
					'url'    => 'https://order.example/grill',
				),
			)
		);
		$this->assertCount( 3, Link_Sweep::links( $grill ), 'The button counts only while a premium profile runs.' );
		Premium::extend( $grill, 30, 'Test order' );
		$links = Link_Sweep::links( $grill );
		$this->assertSame( array( 'website', 'menu', 'menu', 'cta' ), array_column( array_values( $links ), 'field' ) );

		$this->answers = array(
			'https://grill.example/robots.txt' => 404,
			'https://order.example/robots.txt' => 404,
			'https://grill.example/'           => 200,
			'https://grill.example/lunch'      => 200,
			'https://grill.example/dinner'     => 404,
			'https://order.example/grill'      => 410,
		);
		$this->sweep()->run();
		$items = $this->items( $grill );
		$this->assertCount( 2, $items );

		$by_field = array();
		foreach ( $items as $item ) {
			$by_field[ json_decode( $item['payload'], true )['field'] ] = (int) $item['id'];
		}
		$this->assertTrue( Review_Queue_Screen::decide( $by_field['menu'], 'remove_link', $admin ) );
		$this->assertSame( array( 'https://grill.example/lunch' ), array_column( get_post_meta( $grill, Owner_Content::MENU_LINKS, true ), 'url' ) );
		$this->assertTrue( Review_Queue_Screen::decide( $by_field['cta'], 'remove_link', $admin ) );
		$this->assertSame( '', get_post_meta( $grill, Premium::CTA, true ) );
		$this->assertCount( 2, $this->rows( Schema::CHANGE_LOG, "source = 'editor' AND field IN ('gr_menu_links', 'gr_premium_cta') AND restaurant_id = " . $grill ) );
		$this->assertFalse( Review_Queue_Screen::decide( $by_field['cta'], 'remove_link', $admin ), 'A decided item cannot be decided again.' );
	}

	/**
	 * A removed website is not put back by the OpenStreetMap import.
	 */
	public function test_removed_website_stays_removed_after_osm_import() {
		$admin    = $this->login_admin();
		$elements = array_map(
			static function ( array $element ): array {
				if ( 5002 === $element['id'] ) {
					$element['tags']['website'] = 'https://proa.example';
				}
				return $element;
			},
			Guam_Fixtures::elements()
		);
		$this->import( $elements );
		$proa    = $this->post_for( 5002 );
		$website = (string) get_post_meta( $proa, Restaurant_Meta::WEBSITE, true );
		$this->assertNotSame( '', $website, 'The import set a website.' );

		$this->answers = array(
			wp_parse_url( $website, PHP_URL_SCHEME ) . '://' . wp_parse_url( $website, PHP_URL_HOST ) . '/robots.txt' => 404,
			$website => 404,
		);
		$this->sweep()->run( array( $proa ) );
		$items = $this->items( $proa );
		$this->assertCount( 1, $items );
		$this->assertTrue( Review_Queue_Screen::decide( (int) $items[0]['id'], 'remove_link', $admin ) );
		$this->assertSame( '', get_post_meta( $proa, Restaurant_Meta::WEBSITE, true ) );
		$this->assertTrue( Link_Sweep::was_removed( $proa, 'http://www.proa.example/' ), 'Compared without scheme, www. or a trailing slash.' );

		$this->import( $elements );
		$this->assertSame( '', get_post_meta( $proa, Restaurant_Meta::WEBSITE, true ), 'The import leaves it out.' );
	}

	/**
	 * "Keep link" stops asking for KEEP_DAYS.
	 */
	public function test_keep_decision_stops_asking() {
		$admin         = $this->login_admin();
		$grill         = $this->restaurant( 'Tumon Grill', array( Restaurant_Meta::WEBSITE => 'https://grill.example/' ) );
		$this->answers = array(
			'https://grill.example/robots.txt' => 404,
			'https://grill.example/'           => 404,
		);
		$this->sweep()->run();
		$items = $this->items( $grill );
		$this->assertTrue( Review_Queue_Screen::decide( (int) $items[0]['id'], 'keep_link', $admin ) );
		$this->assertSame( 'https://grill.example/', get_post_meta( $grill, Restaurant_Meta::WEBSITE, true ) );

		$this->age_link_checks( $grill );
		$result = $this->sweep()->run();
		$this->assertSame( 1, $result['links']['kept'] );
		$this->assertSame( array(), $this->items( $grill ) );
	}

	/**
	 * Only MAX_LINKS links per run, longest-unchecked first.
	 */
	public function test_run_is_capped_and_rotates() {
		$ids = array();
		foreach ( array( 'a', 'b', 'c' ) as $letter ) {
			$ids[ $letter ] = $this->restaurant( strtoupper( $letter ), array( Restaurant_Meta::WEBSITE => 'https://' . $letter . '.example/' ) );
			$this->answers[ 'https://' . $letter . '.example/robots.txt' ] = 404;
			$this->answers[ 'https://' . $letter . '.example/' ]           = 200;
		}
		$first = ( new Link_Sweep( $this->checker(), '', 2 ) )->run();
		$this->assertSame( array( 2, 1 ), array( $first['links']['checked'], $first['links']['left'] ) );
		$this->assertSame( array(), Link_Sweep::state( $ids['c'] ) );

		$this->age_link_checks( $ids['a'] );
		$this->age_link_checks( $ids['b'] );
		$second = ( new Link_Sweep( $this->checker(), '', 2 ) )->run();
		$this->assertNotSame( array(), Link_Sweep::state( $ids['c'] ), 'The never-checked link goes first next time.' );
		$this->assertSame( 2, $second['links']['checked'] );
	}

	/**
	 * A closing date in the Foursquare extract queues a closure once; a rejected one is not asked again.
	 */
	public function test_foursquare_closed_places_go_to_the_queue() {
		$admin = $this->login_admin();
		$open  = $this->restaurant( 'Still open', array( Restaurant_Meta::FOURSQUARE_ID => '4b0a000000000000000000a1' ) );
		$gone  = $this->restaurant( 'Closed in March', array( Restaurant_Meta::FOURSQUARE_ID => '4b0a000000000000000000b2' ) );
		$file  = $this->fsq_file(
			array(
				array(
					'fsq_place_id' => '4b0a000000000000000000a1',
					'name'         => 'Still open',
				),
				array(
					'fsq_place_id' => '4b0a000000000000000000b2',
					'name'         => 'Closed in March',
					'date_closed'  => '2026-03-01',
				),
			)
		);

		$result = $this->sweep( $file )->run();
		$this->assertSame( 2, $result['closures']['checked'] );
		$this->assertSame( array( $gone ), $result['closures']['queued'] );
		$items = $this->rows( Schema::REVIEW_QUEUE, "type = 'closure_signal' AND status = 'pending' AND restaurant_id = " . $gone );
		$this->assertCount( 1, $items );
		$this->assertSame( 'fsq_os', $items[0]['source'] );
		$this->assertSame( 'date_closed 2026-03-01', json_decode( $items[0]['payload'], true )['statement'] );
		$this->assertNotSame( 'permanently_closed', get_post_meta( $gone, Restaurant_Meta::STATUS, true ), 'Nothing is closed without approval.' );
		$this->assertSame( array(), $this->rows( Schema::REVIEW_QUEUE, "type = 'closure_signal' AND restaurant_id = " . $open ) );

		$this->assertTrue( Review_Queue_Screen::decide( (int) $items[0]['id'], 'reject_closure', $admin ) );
		$this->sweep( $file )->run();
		$this->assertCount( 0, $this->rows( Schema::REVIEW_QUEUE, "type = 'closure_signal' AND status = 'pending' AND restaurant_id = " . $gone ), 'A rejected statement is not asked again.' );

		$this->assertTrue( Closures::record_statement( $gone, 'fsq_os', 'date_closed 2026-09-01' ), 'A new closing date is.' );
	}

	/**
	 * No extract: the sweep says so and carries on.
	 */
	public function test_missing_extract_is_noted() {
		$result = $this->sweep( '/nonexistent/fsq.jsonl' )->run();
		$this->assertStringContainsString( 'no Foursquare extract', Link_Sweep::notes( $result ) );
	}

	/**
	 * The job is on the Scheduled jobs screen and runs through Jobs::run().
	 */
	public function test_job_is_registered_and_controllable() {
		$this->assertArrayHasKey( Jobs::LINK_SWEEP, Jobs::definitions() );
		$this->assertSame( Jobs::MONTHLY, Jobs::definitions()[ Jobs::LINK_SWEEP ]['recurrence'] );
		$this->assertArrayHasKey( Jobs::LINK_SWEEP, Job_Controls::tasks() );
		$this->assertTrue( Jobs::enabled( Jobs::LINK_SWEEP ), 'Needs no key.' );

		Jobs::schedule();
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::LINK_SWEEP ) ) );
		Job_Controls::set_paused( Jobs::LINK_SWEEP, true );
		$this->assertFalse( wp_next_scheduled( Jobs::HOOK, array( Jobs::LINK_SWEEP ) ), 'Turning it off removes its schedule.' );
		Job_Controls::set_paused( Jobs::LINK_SWEEP, false );

		$grill         = $this->restaurant( 'Tumon Grill', array( Restaurant_Meta::WEBSITE => 'https://grill.example/' ) );
		$this->answers = array(
			'https://grill.example/robots.txt' => 404,
			'https://grill.example/'           => 404,
		);
		$outcome       = Jobs::run( Jobs::LINK_SWEEP, array( 'sweep' => $this->sweep() ) );
		$this->assertTrue( $outcome['ok'] );
		$this->assertStringContainsString( '1 queued', $outcome['summary'] );
		$this->assertCount( 1, $this->items( $grill ) );
		$this->assertTrue( (bool) Jobs::state( Jobs::LINK_SWEEP )['last_ok'] );
	}

	/**
	 * Writes a Foursquare extract.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return string Path.
	 */
	private function fsq_file( array $rows ): string {
		$file = wp_tempnam( 'fsq' ) . '.jsonl';
		$base = array(
			'latitude'            => 13.5,
			'longitude'           => 144.8,
			'fsq_category_labels' => array( 'Dining and Drinking > Restaurant' ),
		);
		file_put_contents( $file, implode( "\n", array_map( static fn( $row ) => wp_json_encode( $row + $base ), $rows ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test file.
		$this->files[] = $file;
		return $file;
	}
}
