<?php
/**
 * Retry logging, failure-rate alerts, rating refresh logging, raw OSM cuisine and the No cuisine view.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Admin\No_Cuisine_Filter;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Monitoring\Failure_Rates;
use GuamRestaurants\Core\Monitoring\Health_Check;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Ratings;
use GuamRestaurants\Core\Ratings\Refresher;
use GuamRestaurants\Core\Ratings\Yelp_Provider;
use GuamRestaurants\Core\Restaurant_Meta;

/**
 * Making failures visible in the ingestion run log.
 *
 * @covers \GuamRestaurants\Core\Http\Request_Failed
 * @covers \GuamRestaurants\Core\Database\Ingestion_Runs
 * @covers \GuamRestaurants\Core\Monitoring\Failure_Rates
 * @covers \GuamRestaurants\Core\Monitoring\Health_Check
 * @covers \GuamRestaurants\Core\Ratings\Refresher
 * @covers \GuamRestaurants\Core\Admin\No_Cuisine_Filter
 * @covers \GuamRestaurants\Core\Osm\Importer
 * @covers \GuamRestaurants\Core\Highlights\Roles
 */
class Ingestion_Health_Test extends Guam_Test_Case {

	/**
	 * Clears the list filter and alert state.
	 */
	public function tear_down() {
		unset( $_GET[ No_Cuisine_Filter::QUERY_VAR ] );
		delete_option( Failure_Rates::ALERTED_OPTION );
		remove_all_filters( Ratings::PROVIDERS_FILTER );
		putenv( 'GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.
		delete_option( Budget::MONTHLY_OPTION );
		parent::tear_down();
	}

	/**
	 * Overpass client whose transport answers with the given status codes in turn.
	 *
	 * @param int[] $codes Status codes; 200 returns the Guam fixtures.
	 * @return Overpass_Client
	 */
	private function overpass( array $codes ): Overpass_Client {
		$transport = static function () use ( &$codes ) {
			$code = array_shift( $codes ) ?? 504;
			return array(
				'response' => array( 'code' => $code ),
				'headers'  => array(),
				'body'     => 200 === $code ? (string) wp_json_encode( array( 'elements' => Guam_Fixtures::elements() ) ) : 'Gateway Timeout',
			);
		};
		return new Overpass_Client( new Retrying_Client( $transport, static function () {}, array( 429, 406 ), 0 ) );
	}

	/**
	 * A finished run row, as Ingestion_Runs::recent() returns it.
	 *
	 * @param array<string, mixed> $values Overrides.
	 * @return array<string, mixed>
	 */
	private static function run_row( array $values ): array {
		return $values + array(
			'id'          => 1,
			'finished_at' => '2026-10-10 00:00:00',
			'created'     => 0,
			'updated'     => 0,
			'unchanged'   => 100,
			'failed'      => 0,
			'retries'     => 0,
			'notes'       => 'import-osm',
			'errors'      => array(),
		);
	}

	/**
	 * Giving up carries every failed attempt.
	 */
	public function test_request_failed_carries_attempts() {
		$client = new Retrying_Client(
			static function () {
				return array(
					'response' => array( 'code' => 504 ),
					'body'     => '',
				);
			},
			static function () {},
			array( 429 ),
			0,
			2
		);
		try {
			$client->request( 'https://example.test/', array() );
			$this->fail( 'Expected Request_Failed.' );
		} catch ( Request_Failed $e ) {
			$this->assertSame( 'Gave up after 3 attempts (HTTP 504).', $e->getMessage() );
			$this->assertSame( array( 'attempt 1: HTTP 504', 'attempt 2: HTTP 504', 'attempt 3: HTTP 504' ), $e->attempt_log() );
		}
	}

	/**
	 * Retried Overpass requests are counted on the run and named in its notes.
	 */
	public function test_osm_job_records_retries() {
		$outcome = Jobs::run( Jobs::OSM, array( 'client' => $this->overpass( array( 504, 504, 504, 200 ) ) ) );

		$this->assertTrue( $outcome['ok'] );
		$run = Ingestion_Runs::get( (int) $outcome['run_id'] );
		$this->assertSame( 3, (int) $run['retries'] );
		$this->assertStringContainsString( '3 retried request(s): overpass-api.de attempt 1: HTTP 504, overpass-api.de attempt 2: HTTP 504, overpass-api.de attempt 3: HTTP 504', $run['notes'] );
	}

	/**
	 * An Overpass outage is logged with each attempt as an error.
	 */
	public function test_osm_job_failure_logs_attempts() {
		$outcome = Jobs::run( Jobs::OSM, array( 'client' => $this->overpass( array() ) ) );

		$this->assertFalse( $outcome['ok'] );
		$run = Ingestion_Runs::get( (int) $outcome['run_id'] );
		$this->assertTrue( Ingestion_Runs::failed_to_start( $run ) );
		$this->assertSame( 11, (int) $run['retries'], 'Six attempts at each of the two instances are eleven retries.' );
		$this->assertSame( 'Gave up after 6 attempts (HTTP 504).', $run['errors'][0] );
		$this->assertCount( 13, $run['errors'] );
		$this->assertStringStartsWith( 'overpass.kumi.systems attempt 6:', end( $run['errors'] ) );
	}

	/**
	 * Each rule, and a healthy source.
	 */
	public function test_failure_rate_rules() {
		$healthy = array( self::run_row( array( 'retries' => 3 ) ), self::run_row( array( 'retries' => 1 ) ), self::run_row( array( 'failed' => 5 ) ) );
		$this->assertSame( array(), Failure_Rates::problems_in( $healthy ), '5 of 305 failed is under 5%; only one recent run retried 3+ times.' );

		$this->assertSame( array(), Failure_Rates::problems_in( array( self::run_row( array( 'failed' => 10 ) ), self::run_row( array() ) ) ), '10 of 210 is under 5%.' );
		$this->assertSame( array( '12 of 212 records failed (5.7%) in the last 2 runs' ), Failure_Rates::problems_in( array( self::run_row( array( 'failed' => 12 ) ), self::run_row( array() ) ) ) );

		$outage = array(
			self::run_row(
				array(
					'unchanged' => 0,
					'failed'    => 1,
					'notes'     => 'Run failed before it started.',
					'errors'    => array( 'Gave up after 6 attempts (HTTP 504).' ),
				)
			),
			self::run_row( array() ),
			self::run_row(
				array(
					'unchanged' => 0,
					'failed'    => 1,
					'notes'     => 'Run failed before it started.',
				)
			),
		);
		$this->assertSame( array( '2 of the last 3 runs failed outright (latest: Gave up after 6 attempts (HTTP 504).)' ), Failure_Rates::problems_in( $outage ) );

		$struggling = array( self::run_row( array( 'retries' => 4 ) ), self::run_row( array( 'retries' => 3 ) ) );
		$this->assertSame( array( 'the last 2 runs needed 3 and 4 retried requests' ), Failure_Rates::problems_in( $struggling ) );
	}

	/**
	 * The health check emails once per new run that still shows the problem.
	 */
	public function test_health_check_alerts_on_rising_failures() {
		Ingestion_Runs::log_failure( 'osm', 'Gave up after 6 attempts (HTTP 504).' );
		$this->assertSame( array(), Failure_Rates::problems(), 'One outage is not a trend.' );

		Ingestion_Runs::log_failure( 'osm', 'Gave up after 6 attempts (HTTP 429).' );
		$summary = Health_Check::run();
		$this->assertStringContainsString( '1 source(s) with rising failures; alert sent.', $summary );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'failing more than usual', $this->mails[0]['subject'] );
		$this->assertStringContainsString( '- osm: 2 of the last 2 runs failed outright (latest: Gave up after 6 attempts (HTTP 429).)', $this->mails[0]['message'] );

		Health_Check::run();
		$this->assertCount( 1, $this->mails, 'Reported once per run.' );

		Ingestion_Runs::log_failure( 'osm', 'Gave up after 6 attempts (HTTP 504).' );
		Health_Check::run();
		$this->assertCount( 2, $this->mails, 'A new failing run is reported again.' );

		$this->import();
		$this->import();
		$this->import();
		$this->assertSame( array(), Failure_Rates::problems() );
	}

	/**
	 * The Yelp refresh is logged as a run, failures and all; a failed single refresh is logged too.
	 */
	public function test_rating_refresh_is_logged() {
		$api    = new Fake_Api(
			array(
				'#/businesses/broken#' => 500,
				'#/reviews#'           => Ratings_Fixtures::yelp_reviews(),
				'#/businesses/#'       => Ratings_Fixtures::yelp_business(),
			)
		);
		$client = $api->client();
		add_filter(
			Ratings::PROVIDERS_FILTER,
			static function () use ( $client ) {
				return array( 'yelp' => new Yelp_Provider( 'y-key', $client, 8, 'https://cdn.example.com/yelp' ) );
			}
		);
		$good   = $this->restaurant( 'Good Grill' );
		$broken = $this->restaurant( 'Broken Grill' );
		update_post_meta( $good, Restaurant_Meta::YELP_ID, 'good-grill' );
		update_post_meta( $broken, Restaurant_Meta::YELP_ID, 'broken' );

		$result = Refresher::run( Ratings::providers(), new Budget( 10 ) );
		$this->assertSame( 1, $result['refreshed'] );
		$this->assertSame( 1, $result['failed'] );

		$runs = Ingestion_Runs::recent( 5, 0, 'yelp_refresh' );
		$this->assertCount( 1, $runs );
		$this->assertSame( array( 1, 1 ), array( (int) $runs[0]['updated'], (int) $runs[0]['failed'] ) );
		$this->assertSame( 'refresh-ratings: 2 of 2 restaurants', $runs[0]['notes'] );
		$this->assertSame( 'yelp #' . $broken . ': Gave up after 1 attempts (HTTP 500).', $runs[0]['errors'][0] );

		putenv( 'GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD=5' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Yelp has no free tier.
		Refresher::refresh_one( $broken, 'yelp' );
		$runs = Ingestion_Runs::recent( 5, 0, 'yelp_refresh' );
		$this->assertCount( 2, $runs );
		$this->assertSame( 'refresh of restaurant #' . $broken, $runs[0]['notes'] );
		$this->assertSame( 1, (int) $runs[0]['failed'] );

		Refresher::refresh_one( $good, 'yelp' );
		$this->assertCount( 2, Ingestion_Runs::recent( 5, 0, 'yelp_refresh' ), 'A successful single refresh is not logged.' );
	}

	/**
	 * No run is logged when no restaurant has an ID for a cacheable source.
	 */
	public function test_refresh_with_nothing_to_do_logs_nothing() {
		Refresher::run( array(), new Budget( 10 ) );
		$this->assertSame( 0, Ingestion_Runs::count() );
	}

	/**
	 * OSM's raw cuisine tag is kept, counted when absent, and cleared when OSM drops it,
	 * without counting as an update.
	 */
	public function test_raw_osm_cuisine_is_stored() {
		$result = $this->import();
		$this->assertSame( 2, $result['no_cuisine_tag'], 'Meskla and Tumon Bay Coffee have no cuisine tag.' );
		$this->assertStringContainsString( '2 with no OSM cuisine tag', Ingestion_Runs::get( (int) $result['run_id'] )['notes'] );
		$this->assertSame( 'american;filipino', get_post_meta( $this->post_for( 5001 ), Restaurant_Meta::OSM_CUISINE, true ) );
		$this->assertSame( '', get_post_meta( $this->post_for( 5003 ), Restaurant_Meta::OSM_CUISINE, true ) );

		$shirley = $this->post_for( 5001 );
		delete_post_meta( $shirley, Restaurant_Meta::OSM_CUISINE );
		$again = $this->import();
		$this->assertSame( 'american;filipino', get_post_meta( $shirley, Restaurant_Meta::OSM_CUISINE, true ), 'Back-filled on existing restaurants.' );
		$this->assertSame( 0, $again['counts']['updated'] );

		$elements = Guam_Fixtures::elements();
		foreach ( $elements as $i => $element ) {
			if ( 5002 === $element['id'] ) {
				$elements[ $i ]['tags']['cuisine'] = 'chamorro;ramen';
			}
		}
		$this->import( $elements );
		$this->assertSame( 'chamorro;ramen', get_post_meta( $this->post_for( 5002 ), Restaurant_Meta::OSM_CUISINE, true ) );
	}

	/**
	 * The No cuisine view lists only restaurants without a cuisine term, with OSM's raw tag.
	 */
	public function test_no_cuisine_view() {
		$this->import();
		$bare = $this->restaurant( 'Untagged Snack Bar' );
		update_post_meta( $bare, Restaurant_Meta::OSM_CUISINE, 'snack' );
		$expected = array_values(
			array_filter(
				get_posts(
					array(
						'post_type'      => Content_Types::POST_TYPE,
						'post_status'    => 'publish',
						'fields'         => 'ids',
						'posts_per_page' => -1,
					)
				),
				function ( $id ) {
					return array() === $this->terms( (int) $id, Content_Types::TAX_CUISINE );
				}
			)
		);
		$this->assertContains( $bare, $expected );
		$this->assertSame( count( $expected ), No_Cuisine_Filter::count() );

		$_GET[ No_Cuisine_Filter::QUERY_VAR ] = '1';
		set_current_screen( 'edit-' . Content_Types::POST_TYPE );
		$query                   = new \WP_Query();
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- make it the main query.
		$ids                     = $query->query(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertEqualsCanonicalizing( $expected, $ids );

		$views = No_Cuisine_Filter::views( array( 'all' => '<a href="edit.php" class="current" aria-current="page">All</a>' ) );
		$this->assertStringContainsString( 'No cuisine', $views[ No_Cuisine_Filter::QUERY_VAR ] );
		$this->assertStringContainsString( 'class="current"', $views[ No_Cuisine_Filter::QUERY_VAR ] );
		$this->assertStringNotContainsString( 'current', $views['all'] );
		$this->assertArrayHasKey( No_Cuisine_Filter::COLUMN, No_Cuisine_Filter::columns( array( 'title' => 'Title' ) ) );

		ob_start();
		No_Cuisine_Filter::column( No_Cuisine_Filter::COLUMN, $bare );
		$this->assertSame( '<code>snack</code>', ob_get_clean() );

		unset( $_GET[ No_Cuisine_Filter::QUERY_VAR ] );
		$this->assertArrayNotHasKey( No_Cuisine_Filter::COLUMN, No_Cuisine_Filter::columns( array( 'title' => 'Title' ) ) );
	}

	/**
	 * Installing the owner role translates nothing, since it runs before init.
	 */
	public function test_owner_role_install_loads_no_translations() {
		remove_role( Roles::OWNER_ROLE );
		$calls = 0;
		$count = static function ( $translation, $text, $domain ) use ( &$calls ) {
			if ( 'guam-restaurants-core' === $domain ) {
				++$calls;
			}
			return $translation;
		};
		add_filter( 'gettext', $count, 10, 3 );
		Roles::install();
		remove_filter( 'gettext', $count, 10 );

		$this->assertSame( 0, $calls );
		$this->assertSame( 'Restaurant owner', wp_roles()->role_names[ Roles::OWNER_ROLE ] );
	}
}
