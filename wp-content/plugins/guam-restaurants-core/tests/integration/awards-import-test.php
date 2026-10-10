<?php
/**
 * Award CSV reading, restaurant matching and import tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Awards\Csv_Reader;
use GuamRestaurants\Core\Awards\Importer;
use GuamRestaurants\Core\Awards\Report;
use GuamRestaurants\Core\Awards\Repository;
use GuamRestaurants\Core\Awards\Restaurant_Matcher;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;

/**
 * Importing award results.
 *
 * @covers \GuamRestaurants\Core\Awards\Csv_Reader
 * @covers \GuamRestaurants\Core\Awards\Restaurant_Matcher
 * @covers \GuamRestaurants\Core\Awards\Importer
 * @covers \GuamRestaurants\Core\Awards\Repository
 * @covers \GuamRestaurants\Core\Awards\Report
 */
class Awards_Import_Test extends Test_Case {

	private const SAMPLE = __DIR__ . '/../fixtures/awards/sample.csv';

	/**
	 * Restaurant IDs by label.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Creates the restaurants the sample file refers to.
	 */
	public function set_up() {
		parent::set_up();
		$this->ids = array(
			'egg'    => $this->restaurant( 'The Kracked Egg' ),
			'harbor' => $this->restaurant( 'Harbor Grill', array( Restaurant_Meta::FORMER_NAMES => 'Old Harbor Grill' ) ),
			'kings'  => $this->restaurant( 'Kings Restaurant' ),
			'sushi'  => $this->restaurant( 'Sunset Sushi' ),
		);
	}

	/**
	 * Creates a restaurant.
	 *
	 * @param string               $title  Title.
	 * @param array<string, mixed> $meta   Meta.
	 * @param string               $status Post status.
	 * @return int
	 */
	private function restaurant( string $title, array $meta = array(), string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
				'meta_input'  => $meta,
			)
		);
	}

	/**
	 * Number of rows in a custom table.
	 *
	 * @param string $table Table constant.
	 * @return int
	 */
	private function count_rows( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Restaurant posts of any status except trash.
	 *
	 * @return int
	 */
	private function count_restaurants(): int {
		return count(
			get_posts(
				array(
					'post_type'      => Content_Types::POST_TYPE,
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			)
		);
	}

	/**
	 * A row without a source URL is rejected; so is one whose URL is not http(s).
	 */
	public function test_rows_without_source_url_are_rejected() {
		$header = Csv_Reader::COLUMNS;
		$good   = array( 'Test Awards', '2023', 'Best Burger', 'winner', 'The Kracked Egg', 'https://example.com/r', '' );
		$parsed = Csv_Reader::parse(
			array(
				$header,
				$good,
				array( 'Test Awards', '2023', 'Best Soup', 'winner', 'No Source Diner', '', '' ),
				array( 'Test Awards', '2023', 'Best Soup', 'winner', 'Blank Space Diner', '   ', '' ),
				array( 'Test Awards', '2023', 'Best Soup', 'winner', 'Not A Link', 'the PDN insert', '' ),
				array( 'Test Awards', '2023', 'Best Soup', 'winner', 'Script Link', 'javascript:alert(1)', '' ),
				array( 'Test Awards', '2023', 'Best Soup', 'winner', 'Ftp Link', 'ftp://example.com/r.pdf', '' ),
			)
		);

		$this->assertCount( 1, $parsed['rows'] );
		$this->assertSame( 'The Kracked Egg', $parsed['rows'][0]['restaurant_name'] );
		$this->assertCount( 5, $parsed['rejected'] );
		$this->assertSame( 'missing source_url', $parsed['rejected'][0]['reason'] );
		$this->assertSame( 3, $parsed['rejected'][0]['line'] );
		$this->assertSame( 'missing source_url', $parsed['rejected'][1]['reason'] );
		foreach ( array_slice( $parsed['rejected'], 2 ) as $rejected ) {
			$this->assertSame( 'source_url is not an http(s) URL', $rejected['reason'] );
		}
	}

	/**
	 * Importing a file with a row that has no source URL stores nothing for that row and counts it as failed.
	 */
	public function test_import_rejects_row_without_source_url() {
		$result = ( new Importer() )->import_file( self::SAMPLE );

		$this->assertCount( 1, $result['rejected'] );
		$this->assertSame( 'No Source Diner', $result['rejected'][0]['restaurant_name'] );
		$this->assertSame( 1, $result['counts']['failed'] );
		$this->assertEmpty(
			get_posts(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_status' => 'any',
					'title'       => 'No Source Diner',
				)
			)
		);
		$this->assertSame( array( 'Best Burger', 'Best Pizza' ), Repository::categories(), 'Best Breakfast is only on the queued row.' );
	}

	/**
	 * Other validation: required columns, year range, placement words, apostrophes, BOM.
	 */
	public function test_validation_and_normalization() {
		$this->assertSame( 'finalist', Csv_Reader::placement( ' 2nd ' ) );
		$this->assertSame( 'finalist', Csv_Reader::placement( 'Runner-up' ) );
		$this->assertSame( 'other', Csv_Reader::placement( 'Honorable  mention' ) );
		$this->assertNull( Csv_Reader::placement( 'gold star' ) );
		$this->assertSame( "Pika's Best of Guam", Csv_Reader::clean_label( ' Pika’s   Best of Guam ' ) );

		$row = Csv_Reader::validate(
			array(
				'program'         => 'Test Awards',
				'year'            => '1890',
				'category'        => 'Best Burger',
				'placement'       => 'winner',
				'restaurant_name' => 'X',
				'source_url'      => 'https://example.com',
			)
		);
		$this->assertIsString( $row );
		$this->assertStringStartsWith( 'year must be', $row );

		$bom = Csv_Reader::read_file( __DIR__ . '/../fixtures/awards/bom-header.csv' );
		$this->assertCount( 1, $bom['rows'] );
		$this->assertSame( 2021, $bom['rows'][0]['year'] );

		$this->expectException( \RuntimeException::class );
		Csv_Reader::parse( array( array( 'program', 'year', 'category', 'placement', 'restaurant_name' ) ) );
	}

	/**
	 * Matching normalizes names, uses former names, and finds closed or draft restaurants.
	 */
	public function test_matching() {
		$closed = $this->restaurant( 'Shuttered Steakhouse', array( Restaurant_Meta::STATUS => 'permanently_closed' ), 'draft' );
		$twin_a = $this->restaurant( 'Twin Noodle Bar' );
		$twin_b = $this->restaurant( 'Twin Noodle Bar' );

		$matcher = Restaurant_Matcher::from_database();

		$exact = $matcher->match( 'kracked egg' );
		$this->assertSame( Restaurant_Matcher::MATCHED, $exact['status'] );
		$this->assertSame( $this->ids['egg'], $exact['restaurant_id'] );
		$this->assertSame( 1.0, $exact['confidence'] );

		$renamed = $matcher->match( 'Old Harbor Grill' );
		$this->assertSame( Restaurant_Matcher::MATCHED, $renamed['status'] );
		$this->assertSame( $this->ids['harbor'], $renamed['restaurant_id'] );
		$this->assertSame( 'former_name', $renamed['via'] );

		$gone = $matcher->match( 'The Shuttered Steakhouse' );
		$this->assertSame( Restaurant_Matcher::MATCHED, $gone['status'] );
		$this->assertSame( $closed, $gone['restaurant_id'] );

		$partial = $matcher->match( 'Kings' );
		$this->assertSame( Restaurant_Matcher::REVIEW, $partial['status'] );
		$this->assertSame( $this->ids['kings'], $partial['restaurant_id'] );
		$this->assertLessThan( Restaurant_Matcher::AUTO_ACCEPT, $partial['confidence'] );

		$twins = $matcher->match( 'Twin Noodle Bar' );
		$this->assertSame( Restaurant_Matcher::REVIEW, $twins['status'], 'Two restaurants with the same name need a person.' );
		$this->assertContains( $twins['restaurant_id'], array( $twin_a, $twin_b ) );
		$this->assertContains( $twins['runner_up_id'], array( $twin_a, $twin_b ) );

		$none = $matcher->match( 'Long Gone Pizza Co' );
		$this->assertSame( Restaurant_Matcher::NONE, $none['status'] );
		$this->assertNull( $none['restaurant_id'] );
	}

	/**
	 * A full import: matched rows stored, unmatched names become drafts, weak matches queued.
	 */
	public function test_import_creates_drafts_and_queues_weak_matches() {
		$result = ( new Importer( 'permanently_closed' ) )->import_file( self::SAMPLE );

		$this->assertSame( 4, $result['counts']['created'] );
		$this->assertSame( 0, $result['counts']['updated'] );
		$this->assertSame( 1, $result['queued'] );
		$this->assertCount( 1, $result['restaurants_created'] );
		$this->assertArrayHasKey( $this->ids['harbor'], $result['former_name_matches'] );

		$draft = $result['restaurants_created'][0]['id'];
		$this->assertSame( 'Long Gone Pizza Co', get_the_title( $draft ) );
		$this->assertSame( 'draft', get_post_status( $draft ) );
		$this->assertSame( 'permanently_closed', get_post_meta( $draft, Restaurant_Meta::STATUS, true ) );

		$this->assertCount( 2, Repository::for_restaurant( $this->ids['egg'] ) );
		$award = Repository::find( $this->ids['harbor'], 'Test Awards', 2022, 'Best Burger' );
		$this->assertSame( 'finalist', $award['placement'] );
		$this->assertSame( '0', (string) $award['verified'] );
		$this->assertEmpty( Repository::for_restaurant( $this->ids['kings'] ), 'A weak match is not stored.' );

		global $wpdb;
		$item = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( Schema::REVIEW_QUEUE ) . ' WHERE restaurant_id = %d', $this->ids['kings'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'match_conflict', $item['type'] );
		$this->assertSame( 'editor', $item['source'] );
		$payload = json_decode( $item['payload'], true );
		$this->assertSame( 'award_match', $payload['kind'] );
		$this->assertSame( 'Kings', $payload['award']['restaurant_name'] );
		$this->assertSame( 'https://example.com/2023-results', $payload['award']['source_url'] );

		$this->assertSame(
			array(
				'Test Awards' => array(
					2022 => array( 'Best Burger' ),
					2023 => array( 'Best Burger', 'Best Pizza' ),
				),
			),
			$result['imported']
		);
	}

	/**
	 * Running the same file twice writes nothing new: no awards, posts or queue items.
	 */
	public function test_import_is_idempotent() {
		( new Importer() )->import_file( self::SAMPLE );
		$awards      = $this->count_rows( Schema::AWARDS );
		$queue       = $this->count_rows( Schema::REVIEW_QUEUE );
		$restaurants = $this->count_restaurants();

		$again = ( new Importer() )->import_file( self::SAMPLE );

		$this->assertSame( 0, $again['counts']['created'] );
		$this->assertSame( 0, $again['counts']['updated'] );
		$this->assertSame( 4, $again['counts']['unchanged'] );
		$this->assertSame( array(), $again['restaurants_created'], 'The draft from the first run is matched, not duplicated.' );
		$this->assertSame( $awards, $this->count_rows( Schema::AWARDS ) );
		$this->assertSame( $queue, $this->count_rows( Schema::REVIEW_QUEUE ) );
		$this->assertSame( $restaurants, $this->count_restaurants() );
	}

	/**
	 * The key is restaurant, program, year and category: a changed placement updates the same row.
	 */
	public function test_changed_placement_updates_existing_row() {
		$importer = new Importer();
		$rows     = Csv_Reader::parse(
			array(
				Csv_Reader::COLUMNS,
				array( 'Test Awards', '2024', 'Best Brunch', 'finalist', 'Sunset Sushi', 'https://example.com/2024', '' ),
			)
		)['rows'];
		$importer->run( $rows );

		$rows[0]['placement'] = 'winner';
		$result               = $importer->run( $rows );

		$this->assertSame( 1, $result['counts']['updated'] );
		$this->assertCount( 1, Repository::for_restaurant( $this->ids['sushi'] ) );
		$this->assertSame( 'winner', Repository::find( $this->ids['sushi'], 'Test Awards', 2024, 'Best Brunch' )['placement'] );
	}

	/**
	 * After a reviewer adds the name as a former name, the next run stores the award and closes the queue item.
	 */
	public function test_queued_match_is_merged_after_former_name_added() {
		( new Importer() )->import_file( self::SAMPLE );
		update_post_meta( $this->ids['kings'], Restaurant_Meta::FORMER_NAMES, 'Kings' );

		$result = ( new Importer() )->import_file( self::SAMPLE );

		$this->assertSame( 0, $result['queued'] );
		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertNotNull( Repository::find( $this->ids['kings'], 'Test Awards', 2023, 'Best Breakfast' ) );

		global $wpdb;
		$status = $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::table( Schema::REVIEW_QUEUE ) . ' WHERE restaurant_id = %d', $this->ids['kings'] ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'merged', $status );
	}

	/**
	 * A dry run reports what would happen and writes nothing.
	 */
	public function test_dry_run_writes_nothing() {
		$restaurants = $this->count_restaurants();
		$result      = ( new Importer() )->import_file( self::SAMPLE, true );

		$this->assertSame( 4, $result['counts']['created'] );
		$this->assertCount( 1, $result['restaurants_created'] );
		$this->assertSame( 0, $this->count_rows( Schema::AWARDS ) );
		$this->assertSame( 0, $this->count_rows( Schema::REVIEW_QUEUE ) );
		$this->assertSame( $restaurants, $this->count_restaurants() );
		$this->assertNull( $result['run_id'] );
	}

	/**
	 * Only unknown and permanently_closed are allowed for created restaurants.
	 */
	public function test_unmatched_status_is_limited() {
		$this->expectException( \InvalidArgumentException::class );
		new Importer( 'open' );
	}

	/**
	 * The report lists recorded years and categories and the gaps, from our table only.
	 */
	public function test_report_gaps() {
		( new Importer() )->import_file( self::SAMPLE );

		$report = Report::build( 'Test Awards', 2020, 2024 );
		$data   = $report['Test Awards'];

		$this->assertSame( 4, $data['results'] );
		$this->assertSame( array( 2022, 2023 ), array_keys( $data['years'] ) );
		$this->assertSame( array( 2020, 2021, 2024 ), $data['missing_years'] );
		$this->assertSame( array( 2022 => array( 'Best Pizza' ) ), $data['missing_categories'] );
		$this->assertSame( array( 'Best Burger' ), array_keys( $data['years'][2022] ) );
		$this->assertSame( 1, $data['years'][2022]['Best Burger']['finalist'] );
		$this->assertSame( array(), $data['no_winner'] );

		$markdown = Report::markdown( $report );
		$this->assertStringContainsString( 'Missing years: 2020–2021, 2024', $markdown );
		$this->assertSame( '2014–2016, 2018', Report::ranges( array( 2014, 2015, 2016, 2018 ) ) );

		$empty = Report::build();
		$this->assertArrayHasKey( "Pika's Best of Guam", $empty, 'A program with a known start year is reported even with no results.' );
		$this->assertSame( 2014, $empty["Pika's Best of Guam"]['missing_years'][0] );
	}
}
