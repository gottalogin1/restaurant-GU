<?php
/**
 * View counts, newsletter draft, analytics tracker and SEO tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Analytics\Listing_Views;
use GuamRestaurants\Core\Analytics\Tracker;
use GuamRestaurants\Core\Awards\Repository as Awards;
use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Newsletter\Issue;
use GuamRestaurants\Core\Newsletter\Newsletter;
use GuamRestaurants\Core\Newsletter\Renderer;
use GuamRestaurants\Core\Newsletter\Sendfox_Client;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Pages;
use GuamRestaurants\Core\Site\Related_Links;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Structured_Data;
use GuamRestaurants\Core\Term_Meta;
use WP_REST_Request;

/**
 * View counts, newsletter, tracker, titles, ItemList and related links.
 *
 * @covers \GuamRestaurants\Core\Analytics\Listing_Views
 * @covers \GuamRestaurants\Core\Analytics\Tracker
 * @covers \GuamRestaurants\Core\Newsletter\Issue
 * @covers \GuamRestaurants\Core\Newsletter\Renderer
 * @covers \GuamRestaurants\Core\Newsletter\Newsletter
 * @covers \GuamRestaurants\Core\Newsletter\Sendfox_Client
 * @covers \GuamRestaurants\Core\Site\Seo
 * @covers \GuamRestaurants\Core\Site\Related_Links
 * @covers \GuamRestaurants\Core\Structured_Data
 */
class Newsletter_Seo_Test extends Guam_Test_Case {

	/**
	 * Restaurants by key.
	 *
	 * @var array<string, int>
	 */
	private array $r = array();

	/**
	 * A few restaurants in Tamuning and Dededo.
	 */
	public function set_up() {
		parent::set_up();
		$this->r['proa']   = $this->place( 'Proa', 'tamuning', array( 'chamorro' ) );
		$this->r['sushi']  = $this->place( 'Tumon Sushi Bar', 'tamuning', array( 'japanese' ) );
		$this->r['fiesta'] = $this->place( 'Fiesta Grill', 'tamuning', array( 'chamorro', 'american' ) );
		$this->r['kimchi'] = $this->place( 'Dededo Kimchi House', 'dededo', array( 'korean' ) );
		$this->r['diner']  = $this->place( 'Old Diner', 'dededo', array( 'american' ) );
		update_post_meta( $this->r['diner'], Restaurant_Meta::STATUS, 'permanently_closed' );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
	}

	/**
	 * Clears config and globals.
	 */
	public function tear_down() {
		foreach ( array( Config::ANALYTICS, Config::ANALYTICS_SCRIPT_URL, Config::ANALYTICS_ENDPOINT, Config::ANALYTICS_SITE_ID, Config::SENDFOX_TOKEN ) as $name ) {
			putenv( $name ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		}
		parent::tear_down();
	}

	/**
	 * Restaurant with a village and cuisines.
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
	 * A term by slug.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return \WP_Term
	 */
	private function term( string $slug, string $taxonomy ): \WP_Term {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		$this->assertInstanceOf( \WP_Term::class, $term );
		return $term;
	}

	/**
	 * Sends a view through REST.
	 *
	 * @param int $id Restaurant.
	 * @return int Status.
	 */
	private function view( int $id ): int {
		$request = new WP_REST_Request( 'POST', '/guam-restaurants/v1/views' );
		$request->set_header( 'User-Agent', $_SERVER['HTTP_USER_AGENT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$request->set_body_params( array( 'restaurant' => $id ) );
		return rest_get_server()->dispatch( $request )->get_status();
	}

	/*
	 * View counts.
	 */

	/**
	 * Views are counted per day; bots and unpublished restaurants are not; top() ranks published ones.
	 */
	public function test_listing_views() {
		$this->assertSame( 204, $this->view( $this->r['proa'] ) );
		$this->view( $this->r['proa'] );
		$this->view( $this->r['sushi'] );

		$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
		$this->view( $this->r['sushi'] );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';

		$draft = $this->restaurant( 'Hidden', array(), 'draft' );
		$this->view( $draft );

		Listing_Views::add( $this->r['kimchi'], gmdate( 'Y-m-d', strtotime( '-30 days' ) ), 50 );

		$this->assertSame(
			array(
				array(
					'restaurant_id' => $this->r['proa'],
					'views'         => 2,
				),
				array(
					'restaurant_id' => $this->r['sushi'],
					'views'         => 1,
				),
			),
			Listing_Views::top( 7, 5 ),
			'Bots, drafts and old days are left out.'
		);
		$this->assertSame( $this->r['kimchi'], Listing_Views::top( 60, 1 )[0]['restaurant_id'] );

		Listing_Views::add( $this->r['kimchi'], gmdate( 'Y-m-d', strtotime( '-500 days' ) ), 5 );
		$this->assertSame( 1, Listing_Views::prune() );

		global $wpdb;
		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . \GuamRestaurants\Core\Database\Schema::table( \GuamRestaurants\Core\Database\Schema::LISTING_VIEWS ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'restaurant_id', 'stat_date', 'views' ), $columns, 'Counts only: nothing about the visitor.' );
	}

	/*
	 * Newsletter.
	 */

	/**
	 * Builds an issue with openings, a closure, a new award and top views.
	 *
	 * @return array<string, mixed>
	 */
	private function issue(): array {
		update_post_meta( $this->r['diner'], Restaurant_Meta::STATUS, 'open' );
		update_post_meta( $this->r['diner'], Restaurant_Meta::STATUS, 'permanently_closed' );
		Awards::upsert(
			$this->r['proa'],
			array(
				'program'     => 'Sample Awards',
				'year'        => 2026,
				'category'    => 'Best Fusion',
				'placement'   => 'winner',
				'source_url'  => 'https://example.com/results',
				'source_note' => '',
			)
		);
		Listing_Views::add( $this->r['sushi'], Listing_Views::today(), 9 );
		return Issue::build();
	}

	/**
	 * The issue holds the week's facts and the HTML keeps the sponsor apart and labeled.
	 */
	public function test_issue_and_html() {
		Newsletter::save_sponsor(
			array(
				'name' => 'Island Coffee Co.',
				'text' => 'Fresh roasts every morning in Hagåtña.',
				'url'  => 'https://coffee.example',
			)
		);
		$issue = $this->issue();

		$this->assertContains( 'Proa', wp_list_pluck( $issue['openings'], 'name' ) );
		$this->assertNotContains( 'Old Diner', wp_list_pluck( $issue['openings'], 'name' ), 'Closed restaurants are not openings.' );
		$this->assertSame( array( 'Old Diner' ), wp_list_pluck( $issue['closures'], 'name' ) );
		$this->assertSame( 'Sample Awards 2026, Best Fusion: Winner', $issue['awards'][0]['note'] );
		$this->assertSame( 'Tumon Sushi Bar', $issue['top'][0]['name'] );
		$this->assertSame( 'Island Coffee Co.', $issue['sponsor']['name'] );
		$this->assertTrue( Issue::has_content( $issue ) );

		$html = Renderer::html( $issue );
		$this->assertStringContainsString( 'New and reopened', $html );
		$this->assertStringContainsString( 'Most viewed this week', $html );
		$this->assertStringContainsString( 'New award results', $html );
		$this->assertStringContainsString( 'example.com/results', $html, 'Award results link to their source.' );
		$this->assertStringContainsString( '>Sponsored<', $html );
		$this->assertStringContainsString( 'rel="sponsored"', $html );
		$this->assertGreaterThan( strpos( $html, 'Closed' ), strpos( $html, '>Sponsored<' ), 'The sponsor comes after the issue\'s own content.' );
		$this->assertStringNotContainsString( 'Sample Awards', substr( $html, (int) strpos( $html, '>Sponsored<' ) ), 'No award mention in the sponsor box.' );
		$this->assertStringContainsString( (string) get_bloginfo( 'name' ), Renderer::subject( $issue ) );

		Newsletter::save_sponsor( array() );
		$this->assertStringNotContainsString( 'Sponsored', Renderer::html( Issue::build() ), 'No sponsor, no label.' );
	}

	/**
	 * Sponsor validation.
	 */
	public function test_sponsor_validation() {
		$this->assertWPError( Newsletter::save_sponsor( array( 'name' => 'Only a name' ) ) );
		$this->assertWPError(
			Newsletter::save_sponsor(
				array(
					'name' => 'X',
					'text' => str_repeat( 'a', Newsletter::MAX_SPONSOR_TEXT + 1 ),
				)
			)
		);
		$this->assertTrue(
			Newsletter::save_sponsor(
				array(
					'name' => 'X',
					'text' => 'Hello',
					'url'  => 'javascript:alert(1)',
				)
			)
		);
		$this->assertSame( '', Newsletter::sponsor()['url'] );
		$this->assertTrue( Newsletter::save_sponsor( array() ) );
		$this->assertNull( Newsletter::sponsor() );
	}

	/**
	 * With SendFox, a draft is created and never scheduled; the sponsor is used once.
	 */
	public function test_assemble_creates_sendfox_draft() {
		$this->issue();
		Newsletter::save_sponsor(
			array(
				'name' => 'Island Coffee Co.',
				'text' => 'Fresh roasts.',
			)
		);
		$requests = array();
		$client   = new Sendfox_Client(
			'token-123',
			static function ( string $url, array $args ) use ( &$requests ) {
				$requests[] = array( $url, $args );
				return array(
					'response' => array( 'code' => 201 ),
					'body'     => wp_json_encode( array( 'id' => 4242 ) ),
				);
			}
		);

		$draft = Newsletter::assemble( array( 'client' => $client ) );
		$this->assertSame( 4242, $draft['sendfox_id'] );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://api.sendfox.com/campaigns', $requests[0][0] );
		$this->assertSame( 'POST', $requests[0][1]['method'] );
		$this->assertSame( 'Bearer token-123', $requests[0][1]['headers']['Authorization'] );
		$body = json_decode( $requests[0][1]['body'], true );
		$this->assertArrayNotHasKey( 'scheduled_at', $body, 'Drafts are never scheduled.' );
		$this->assertSame( $draft['subject'], $body['subject'] );
		$this->assertStringContainsString( 'Island Coffee Co.', $body['html'] );

		$this->assertNull( Newsletter::sponsor(), 'The sponsor is cleared after one issue.' );
		$this->assertSame( 4242, Newsletter::latest()['sendfox_id'] );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'has not been sent', $this->mails[0]['message'] );
	}

	/**
	 * Without a token, or on SendFox's free plan, the HTML is kept for copying.
	 */
	public function test_assemble_without_sendfox_keeps_html() {
		$draft = Newsletter::assemble( array( 'notify' => false ) );
		$this->assertSame( 0, $draft['sendfox_id'] );
		$this->assertStringContainsString( 'No SendFox token', $draft['sendfox_error'] );
		$this->assertStringContainsString( '<!DOCTYPE html>', Newsletter::latest()['html'] );
		$this->assertCount( 0, $this->mails );

		$client = new Sendfox_Client(
			'token',
			static function () {
				return array(
					'response' => array( 'code' => 402 ),
					'body'     => wp_json_encode( array( 'message' => 'API access requires a paid plan.' ) ),
				);
			}
		);
		$draft  = Newsletter::assemble(
			array(
				'client' => $client,
				'notify' => false,
			)
		);
		$this->assertSame( 0, $draft['sendfox_id'] );
		$this->assertStringContainsString( 'paid SendFox plan', $draft['sendfox_error'] );
	}

	/*
	 * Analytics tracker.
	 */

	/**
	 * Off by default; Matomo runs without cookies; Umami honours Do Not Track; staff are not tracked.
	 */
	public function test_tracker() {
		$this->assertSame( '', Tracker::markup() );

		putenv( Config::ANALYTICS . '=matomo' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( Config::ANALYTICS_SCRIPT_URL . '=https://example.org/wp-content/uploads/matomo/matomo.js' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( Config::ANALYTICS_SITE_ID . '=1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$this->assertSame( '', Tracker::markup(), 'Matomo needs its endpoint.' );
		putenv( Config::ANALYTICS_ENDPOINT . '=https://example.org/wp-json/matomo/v1/hit/' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$html = Tracker::markup();
		$this->assertStringContainsString( '["disableCookies"]', $html );
		$this->assertStringContainsString( '["setDoNotTrack",true]', $html );
		$this->assertLessThan( strpos( $html, 'trackPageView' ), strpos( $html, 'disableCookies' ) );
		$this->assertStringContainsString( 'matomo.js', $html );

		putenv( Config::ANALYTICS . '=umami' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( Config::ANALYTICS_SCRIPT_URL . '=https://stats.example.org/script.js' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		putenv( Config::ANALYTICS_SITE_ID . '=abc-123' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$html = Tracker::markup();
		$this->assertStringContainsString( 'data-website-id="abc-123"', $html );
		$this->assertStringContainsString( 'data-do-not-track="true"', $html );
		$this->assertStringContainsString( 'data-domains="example.org"', $html );

		ob_start();
		Tracker::print();
		$this->assertStringContainsString( 'script.js', (string) ob_get_clean() );
		$this->login_admin();
		ob_start();
		Tracker::print();
		$this->assertSame( '', (string) ob_get_clean(), 'Staff visits are not tracked.' );
	}

	/*
	 * SEO.
	 */

	/**
	 * Village, mall and cuisine pages get their own titles and descriptions.
	 */
	public function test_term_titles_and_descriptions() {
		$tamuning = $this->term( 'tamuning', Content_Types::TAX_VILLAGE );
		$dededo   = $this->term( 'dededo', Content_Types::TAX_VILLAGE );
		$chamorro = $this->term( 'chamorro', Content_Types::TAX_CUISINE );
		$this->assertSame( 'Restaurants in Tamuning, Guam', Seo::term_title( $tamuning ) );
		$this->assertSame( 'Chamorro Restaurants on Guam', Seo::term_title( $chamorro ) );

		$mall = \GuamRestaurants\Core\Mall_Verification::with_unverified(
			static function () {
				return get_terms(
					array(
						'taxonomy'   => Content_Types::TAX_MALL,
						'hide_empty' => false,
						'number'     => 1,
					)
				);
			}
		)[0];
		update_term_meta( $mall->term_id, Term_Meta::MALL_VILLAGE, $tamuning->term_id );
		$this->assertSame( sprintf( 'Restaurants at %s, Tamuning, Guam', $mall->name ), Seo::term_title( $mall ) );

		$this->assertNotSame( Seo::term_description( $tamuning ), Seo::term_description( $dededo ) );
		$this->assertStringContainsString( 'Tamuning', Seo::term_description( $tamuning ) );
		wp_update_term( $dededo->term_id, Content_Types::TAX_VILLAGE, array( 'description' => 'Our own intro to Dededo.' ) );
		$this->assertSame( 'Our own intro to Dededo.', Seo::term_description( get_term( $dededo->term_id ) ) );

		$this->go_to( get_term_link( $tamuning ) );
		$this->assertSame( 'Restaurants in Tamuning, Guam', Seo::title_parts( array( 'title' => 'Tamuning' ) )['title'] );
		$this->assertStringStartsWith( 'Restaurants in Tamuning, Guam - ', Seo::yoast_title( 'Tamuning Archives' ) );
		$this->assertStringContainsString( 'Tamuning', Seo::yoast_description( '' ) );

		$this->go_to( get_permalink( $this->r['proa'] ) );
		$this->assertSame( 'Kept', Seo::yoast_title( 'Kept' ), 'Other pages keep the SEO plugin\'s title.' );
	}

	/**
	 * Listing pages print an ItemList of the restaurants shown; filtered pages do not.
	 */
	public function test_item_list() {
		$this->go_to( get_term_link( $this->term( 'tamuning', Content_Types::TAX_VILLAGE ) ) );
		ob_start();
		Structured_Data::print_item_list();
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#<script type="application/ld\+json">(.+)</script>#s', $html );
		preg_match( '#<script type="application/ld\+json">(.+)</script>#s', $html, $match );
		$data = json_decode( $match[1], true );
		$this->assertSame( 'ItemList', $data['@type'] );
		$this->assertSame( 'Restaurants in Tamuning, Guam', $data['name'] );
		$this->assertSame( 3, $data['numberOfItems'] );
		$this->assertSame( array( 1, 2, 3 ), wp_list_pluck( $data['itemListElement'], 'position' ) );
		$this->assertContains( get_permalink( $this->r['proa'] ), wp_list_pluck( $data['itemListElement'], 'url' ) );

		$this->go_to( add_query_arg( 'cuisine', 'japanese', get_term_link( $this->term( 'tamuning', Content_Types::TAX_VILLAGE ) ) ) );
		ob_start();
		Structured_Data::print_item_list();
		$this->assertSame( '', (string) ob_get_clean(), 'Filtered listings are noindex and get no list.' );

		$this->go_to( get_permalink( $this->r['proa'] ) );
		ob_start();
		Structured_Data::print_item_list();
		$this->assertSame( '', (string) ob_get_clean() );

		putenv( Config::BRAND_PROFILES . '=https://g.page/example, https://facebook.com/example' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$org = Structured_Data::organization();
		putenv( Config::BRAND_PROFILES ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$this->assertSame( 'Organization', $org['@graph'][0]['@type'] );
		$this->assertSame( array( 'https://g.page/example', 'https://facebook.com/example' ), $org['@graph'][0]['sameAs'] );
		$this->assertSame( 'WebSite', $org['@graph'][1]['@type'] );
	}

	/**
	 * Related links connect villages, cuisines and restaurants by what is listed.
	 */
	public function test_related_links() {
		$tamuning = Related_Links::for_term( $this->term( 'tamuning', Content_Types::TAX_VILLAGE ) );
		$cuisines = $tamuning[0]['links'];
		$this->assertSame( 'Popular cuisines in Tamuning', $tamuning[0]['title'], 'No verified malls, so no mall group.' );
		$this->assertSame( 'Chamorro', $cuisines[0]['label'], 'Most common first.' );
		$this->assertSame( get_term_link( 'chamorro', Content_Types::TAX_CUISINE ), $cuisines[0]['url'] );

		$chamorro = Related_Links::for_term( $this->term( 'chamorro', Content_Types::TAX_CUISINE ) );
		$this->assertSame( array( 'Tamuning' ), wp_list_pluck( $chamorro[0]['links'], 'label' ) );
		$this->assertSame( array( 'American' ), wp_list_pluck( $chamorro[1]['links'], 'label' ), 'Cuisines the same restaurants serve.' );

		$proa = Related_Links::for_restaurant( $this->r['proa'] );
		$this->assertSame( 'More Chamorro restaurants in Tamuning', $proa[0]['title'] );
		$this->assertSame( array( 'Fiesta Grill' ), wp_list_pluck( $proa[0]['links'], 'label' ) );
		$this->assertSame( 'Browse', $proa[1]['title'] );
		$this->assertSame( array( 'Tamuning', 'Chamorro' ), wp_list_pluck( $proa[1]['links'], 'label' ) );

		$this->go_to( get_permalink( $this->r['proa'] ) );
		$html = Related_Links::render();
		$this->assertStringContainsString( 'aria-label="Related pages"', $html );
		$this->assertSame( get_term_link( 'tamuning', Content_Types::TAX_VILLAGE ), wp_list_pluck( $proa[1]['links'], 'url' )[0], 'Links go to term pages, never filtered listings.' );
	}

	/**
	 * The correction link points at a published page.
	 */
	public function test_correction_page_is_linked() {
		$pages = Pages::ensure();
		$this->assertSame( get_permalink( $pages['correction'] ), Pages::url( 'correction' ) );
	}
}
