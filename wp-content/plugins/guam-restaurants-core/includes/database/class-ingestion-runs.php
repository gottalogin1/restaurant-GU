<?php
/**
 * Ingestion run log.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Starts and finishes rows in the ingestion run table (CLAUDE.md rule 9).
 */
final class Ingestion_Runs {

	/**
	 * Starts a run.
	 *
	 * @param string $source Source key.
	 * @return int Run ID, or 0 on failure.
	 */
	public static function start( string $source ): int {
		global $wpdb;

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( Schema::INGESTION_RUNS ),
			array(
				'source'     => substr( $source, 0, 32 ),
				'started_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Finishes a run with its counts.
	 *
	 * @param int                $run_id Run ID.
	 * @param array<string, int> $counts created, updated, unchanged, failed.
	 * @param string             $notes  Notes.
	 * @return void
	 */
	public static function finish( int $run_id, array $counts, string $notes = '' ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( Schema::INGESTION_RUNS ),
			array(
				'finished_at' => current_time( 'mysql', true ),
				'created'     => (int) ( $counts['created'] ?? 0 ),
				'updated'     => (int) ( $counts['updated'] ?? 0 ),
				'unchanged'   => (int) ( $counts['unchanged'] ?? 0 ),
				'failed'      => (int) ( $counts['failed'] ?? 0 ),
				'notes'       => $notes,
			),
			array( 'id' => $run_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}
}
