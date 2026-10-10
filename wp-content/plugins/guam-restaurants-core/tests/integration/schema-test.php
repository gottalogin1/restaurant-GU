<?php
/**
 * Custom table tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;

/**
 * Tables, writers and cleanup.
 *
 * @covers \GuamRestaurants\Core\Database\Schema
 * @covers \GuamRestaurants\Core\Database\Change_Log
 * @covers \GuamRestaurants\Core\Database\Review_Queue
 * @covers \GuamRestaurants\Core\Database\Ingestion_Runs
 */
class Schema_Test extends Test_Case {

	/**
	 * Columns of a table.
	 *
	 * @return string[]
	 */
	private function columns( string $table ): array {
		global $wpdb;
		return $wpdb->get_col( 'DESCRIBE ' . Schema::table( $table ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Every table exists with the designed columns, and there is no rating table.
	 */
	public function test_tables_exist_with_columns() {
		global $wpdb;

		$this->assertSame( array( 'id', 'restaurant_id', 'program', 'year', 'category', 'placement', 'source_url', 'source_note', 'verified', 'created_at', 'updated_at' ), $this->columns( Schema::AWARDS ) );
		$this->assertSame( array( 'id', 'restaurant_id', 'field', 'old_value', 'new_value', 'source', 'detected_at', 'run_id' ), $this->columns( Schema::CHANGE_LOG ) );
		$this->assertSame( array( 'id', 'source', 'started_at', 'finished_at', 'created', 'updated', 'unchanged', 'failed', 'notes' ), $this->columns( Schema::INGESTION_RUNS ) );
		$this->assertSame( array( 'id', 'type', 'payload', 'confidence', 'status', 'created_at', 'restaurant_id', 'source', 'run_id', 'resolved_by', 'resolved_at' ), $this->columns( Schema::REVIEW_QUEUE ) );
		$this->assertSame( array( 'id', 'restaurant_id', 'type', 'body', 'author_name', 'status', 'created_at', 'updated_at', 'parent_id', 'author_user_id', 'disclosure', 'moderation_reason', 'moderated_by', 'moderated_at', 'rating', 'dishes', 'email_verified' ), $this->columns( Schema::HIGHLIGHTS ) );
		$this->assertSame( array( 'ad_id', 'stat_date', 'page_type', 'impressions', 'clicks' ), $this->columns( Schema::AD_DAILY_STATS ) );

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'gr_rating_snapshots' ) ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * Installing twice changes nothing.
	 */
	public function test_install_is_idempotent() {
		$before = $this->columns( Schema::HIGHLIGHTS );
		Schema::install();
		$this->assertSame( $before, $this->columns( Schema::HIGHLIGHTS ) );
	}

	/**
	 * The award key stops duplicate imports of the same fact.
	 */
	public function test_award_unique_key() {
		global $wpdb;

		$row = array(
			'restaurant_id' => 1,
			'program'       => "Pika's Best of Guam",
			'year'          => 2023,
			'category'      => 'Best Chamorro Food',
			'placement'     => 'winner',
			'source_url'    => 'https://example.com/mention',
			'created_at'    => '2026-10-10 00:00:00',
			'updated_at'    => '2026-10-10 00:00:00',
		);

		$wpdb->suppress_errors( true );
		$this->assertSame( 1, $wpdb->insert( Schema::table( Schema::AWARDS ), $row ) ); // phpcs:ignore WordPress.DB
		$this->assertFalse( $wpdb->insert( Schema::table( Schema::AWARDS ), $row ) ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( false );
	}

	/**
	 * Daily ad counters add up in one row; no per-visitor data is stored.
	 */
	public function test_ad_counters_aggregate() {
		global $wpdb;
		$table = Schema::table( Schema::AD_DAILY_STATS );

		for ( $i = 0; $i < 3; $i++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO $table (ad_id, stat_date, page_type, impressions) VALUES (%d, %s, %s, 1) ON DUPLICATE KEY UPDATE impressions = impressions + 1", 5, '2026-10-10', 'village' ) ); // phpcs:ignore WordPress.DB
		}

		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( '3', $wpdb->get_var( "SELECT impressions FROM $table" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Change log and review queue refuse Google and Yelp as sources.
	 */
	public function test_writers_refuse_google_and_yelp() {
		$this->assertGreaterThan( 0, Change_Log::record( 1, 'gr_phone', '', '+16716461234', 'osm' ) );

		$this->expectException( \InvalidArgumentException::class );
		Change_Log::record( 1, 'gr_phone', '', '+16716461234', 'google' );
	}

	/**
	 * Review queue rows keep optional columns NULL.
	 */
	public function test_review_queue_enqueue() {
		global $wpdb;

		$id  = Review_Queue::enqueue( 'new_place', array( 'name' => 'Test' ), 'osm' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( Schema::REVIEW_QUEUE ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$this->assertSame( 'pending', $row['status'] );
		$this->assertNull( $row['restaurant_id'] );
		$this->assertNull( $row['confidence'] );
		$this->assertSame( array( 'name' => 'Test' ), json_decode( $row['payload'], true ) );
	}

	/**
	 * Runs record their counts.
	 */
	public function test_ingestion_run_counts() {
		global $wpdb;

		$run = Ingestion_Runs::start( 'osm' );
		Ingestion_Runs::finish(
			$run,
			array(
				'created'   => 2,
				'updated'   => 1,
				'unchanged' => 5,
				'failed'    => 0,
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( Schema::INGESTION_RUNS ) . ' WHERE id = %d', $run ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$this->assertNotNull( $row['finished_at'] );
		$this->assertSame( array( '2', '1', '5', '0' ), array( $row['created'], $row['updated'], $row['unchanged'], $row['failed'] ) );
	}

	/**
	 * Deleting a restaurant deletes its rows.
	 */
	public function test_deleting_restaurant_removes_rows() {
		global $wpdb;

		$post_id = self::factory()->post->create( array( 'post_type' => Content_Types::POST_TYPE ) );
		Change_Log::record( $post_id, 'gr_status', 'unknown', 'open', 'editor' );

		wp_delete_post( $post_id, true );

		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( Schema::CHANGE_LOG ) . ' WHERE restaurant_id = %d', $post_id ) ) ); // phpcs:ignore WordPress.DB
	}
}
