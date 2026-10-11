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
	 * Most errors kept per run; the rest are counted in the last entry.
	 */
	public const MAX_ERRORS = 100;

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
	 * @param string[]           $errors Error messages, shown on the Import runs screen.
	 * @return void
	 */
	public static function finish( int $run_id, array $counts, string $notes = '', array $errors = array() ): void {
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
				'errors'      => self::encode_errors( $errors ),
			),
			array( 'id' => $run_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Logs a run that failed before it could do any work (for example the
	 * API was unreachable), so the failure shows on the Import runs screen.
	 *
	 * @param string   $source      Source key.
	 * @param string   $message     Error.
	 * @param string[] $attempt_log Failed HTTP attempts, recorded as retries.
	 * @return int Run ID.
	 */
	public static function log_failure( string $source, string $message, array $attempt_log = array() ): int {
		$run_id = self::start( $source );
		if ( $run_id ) {
			self::finish( $run_id, array( 'failed' => 1 ), 'Run failed before it started.', array_merge( array( $message ), $attempt_log ) );
			self::set_retries( $run_id, max( 0, count( $attempt_log ) - 1 ) );
		}
		return $run_id;
	}

	/**
	 * Records the HTTP requests a run had to retry, so a source that is
	 * slowly failing shows up before it fails outright (Failure_Rates).
	 *
	 * @param int      $run_id      Run ID.
	 * @param string[] $attempt_log Failed attempts before the request succeeded.
	 * @return void
	 */
	public static function record_retries( int $run_id, array $attempt_log ): void {
		if ( ! $attempt_log ) {
			return;
		}
		self::set_retries( $run_id, count( $attempt_log ) );
		self::append_note( $run_id, sprintf( '%d retried request(s): %s', count( $attempt_log ), implode( ', ', $attempt_log ) ) );
	}

	/**
	 * Sets a run's retry count.
	 *
	 * @param int $run_id  Run ID.
	 * @param int $retries Retries.
	 * @return void
	 */
	private static function set_retries( int $run_id, int $retries ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( Schema::INGESTION_RUNS ),
			array( 'retries' => min( 65535, max( 0, $retries ) ) ),
			array( 'id' => $run_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Adds to a finished run's notes (for example the closure checks that
	 * follow an import).
	 *
	 * @param int    $run_id Run ID.
	 * @param string $note   Note.
	 * @return void
	 */
	public static function append_note( int $run_id, string $note ): void {
		global $wpdb;

		$row = self::get( $run_id );
		if ( ! $row ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( Schema::INGESTION_RUNS ),
			array( 'notes' => '' === (string) $row['notes'] ? $note : $row['notes'] . '; ' . $note ),
			array( 'id' => $run_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * One run.
	 *
	 * @param int $run_id Run ID.
	 * @return array<string, mixed>|null With `errors` decoded to a list.
	 */
	public static function get( int $run_id ): ?array {
		global $wpdb;

		$table = Schema::table( Schema::INGESTION_RUNS );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode_row( $row ) : null;
	}

	/**
	 * Runs, newest first.
	 *
	 * @param int    $limit  Rows.
	 * @param int    $offset Offset.
	 * @param string $source Only this source; '' for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 50, int $offset = 0, string $source = '' ): array {
		global $wpdb;

		$table = Schema::table( Schema::INGESTION_RUNS );
		if ( '' === $source ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s ORDER BY id DESC LIMIT %d OFFSET %d", $source, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map( array( self::class, 'decode_row' ), $rows ? $rows : array() );
	}

	/**
	 * Number of runs.
	 *
	 * @param string $source Only this source; '' for all.
	 * @return int
	 */
	public static function count( string $source = '' ): int {
		global $wpdb;

		$table = Schema::table( Schema::INGESTION_RUNS );
		if ( '' === $source ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", $source ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Every source that has a run.
	 *
	 * @return string[]
	 */
	public static function sources(): array {
		global $wpdb;

		$table = Schema::table( Schema::INGESTION_RUNS );
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT source FROM {$table} ORDER BY source" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The latest finished run of a source that had no run-level failure.
	 *
	 * @param string $source Source key.
	 * @return array<string, mixed>|null
	 */
	public static function last_success( string $source ): ?array {
		global $wpdb;

		$table = Schema::table( Schema::INGESTION_RUNS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s AND finished_at IS NOT NULL ORDER BY id DESC LIMIT 20", $source ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows ? $rows : array() as $row ) {
			$row = self::decode_row( $row );
			if ( ! self::failed_to_start( $row ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Whether any finished run of a source exists (the first run of a
	 * source is the baseline, so its restaurants are not "new openings").
	 *
	 * @param string   $source     Source key.
	 * @param int|null $before_run Only runs older than this one.
	 * @return bool
	 */
	public static function has_previous( string $source, ?int $before_run = null ): bool {
		global $wpdb;

		$table = Schema::table( Schema::INGESTION_RUNS );
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s AND finished_at IS NOT NULL AND id < %d", $source, $before_run ?? PHP_INT_MAX ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $count > 0;
	}

	/**
	 * Whether a run is a log_failure() row.
	 *
	 * @param array<string, mixed> $row Decoded row.
	 * @return bool
	 */
	public static function failed_to_start( array $row ): bool {
		return 'Run failed before it started.' === (string) $row['notes'];
	}

	/**
	 * Decodes the errors column.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function decode_row( array $row ): array {
		$errors        = json_decode( (string) ( $row['errors'] ?? '' ), true );
		$row['errors'] = is_array( $errors ) ? array_map( 'strval', $errors ) : array();
		return $row;
	}

	/**
	 * Encodes errors, keeping at most MAX_ERRORS.
	 *
	 * @param string[] $errors Errors.
	 * @return string|null
	 */
	private static function encode_errors( array $errors ): ?string {
		$errors = array_values( array_filter( array_map( 'strval', $errors ), 'strlen' ) );
		if ( ! $errors ) {
			return null;
		}
		if ( count( $errors ) > self::MAX_ERRORS ) {
			$more   = count( $errors ) - self::MAX_ERRORS;
			$errors = array_slice( $errors, 0, self::MAX_ERRORS );
			/* translators: %d: number of errors not shown. */
			$errors[] = sprintf( __( '…and %d more.', 'guam-restaurants-core' ), $more );
		}
		return (string) wp_json_encode( $errors );
	}
}
