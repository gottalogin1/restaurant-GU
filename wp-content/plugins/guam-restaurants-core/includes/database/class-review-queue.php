<?php
/**
 * Review queue writer.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Database;

use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Adds items that need a person's decision.
 */
final class Review_Queue {

	/**
	 * Adds a pending item.
	 *
	 * @param string               $type          One of Vocabulary::REVIEW_QUEUE_TYPES.
	 * @param array<string, mixed> $payload       Data for the reviewer. Never Google or Yelp content; from Google, the place ID only.
	 * @param string               $source        One of Vocabulary::REVIEW_QUEUE_SOURCES.
	 * @param int|null             $restaurant_id Restaurant ID, or null for a new place.
	 * @param float|null           $confidence    0 to 1.
	 * @param int|null             $run_id        Ingestion run ID.
	 * @return int Row ID, or 0 on failure.
	 * @throws \InvalidArgumentException When the type or source is not allowed.
	 */
	public static function enqueue( string $type, array $payload, string $source, ?int $restaurant_id = null, ?float $confidence = null, ?int $run_id = null ): int {
		global $wpdb;

		if ( ! in_array( $type, Vocabulary::REVIEW_QUEUE_TYPES, true ) || ! in_array( $source, Vocabulary::REVIEW_QUEUE_SOURCES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown review queue type or source.' );
		}

		$row     = array(
			'type'       => $type,
			'payload'    => (string) wp_json_encode( $payload ),
			'status'     => 'pending',
			'created_at' => current_time( 'mysql', true ),
			'source'     => $source,
		);
		$formats = array( '%s', '%s', '%s', '%s', '%s' );

		// Optional columns are left out rather than written as 0, so they stay NULL.
		foreach ( array(
			'restaurant_id' => array( $restaurant_id, '%d' ),
			'confidence'    => array( null === $confidence ? null : max( 0.0, min( 1.0, $confidence ) ), '%f' ),
			'run_id'        => array( $run_id, '%d' ),
		) as $column => list( $value, $format ) ) {
			if ( null !== $value ) {
				$row[ $column ] = $value;
				$formats[]      = $format;
			}
		}

		$inserted = $wpdb->insert( Schema::table( Schema::REVIEW_QUEUE ), $row, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One item, with its payload decoded.
	 *
	 * @param int $item_id Item.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $item_id ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Pending items, oldest first.
	 *
	 * @param string $type   Only this type; '' for all.
	 * @param int    $limit  Rows.
	 * @param int    $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pending( string $type = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		if ( '' === $type ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'pending' AND type = %s ORDER BY id ASC LIMIT %d OFFSET %d", $type, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map( array( self::class, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Pending item counts by type.
	 *
	 * @return array<string, int>
	 */
	public static function pending_counts(): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( "SELECT type, COUNT(*) AS n FROM {$table} WHERE status = 'pending' GROUP BY type", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[ (string) $row['type'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Marks an item resolved.
	 *
	 * @param int    $item_id Item.
	 * @param string $status  One of Vocabulary::REVIEW_QUEUE_STATUSES other than pending.
	 * @param int    $user_id User who decided; 0 for an automatic decision.
	 * @return bool
	 */
	public static function resolve( int $item_id, string $status, int $user_id ): bool {
		global $wpdb;
		if ( 'pending' === $status || ! in_array( $status, Vocabulary::REVIEW_QUEUE_STATUSES, true ) ) {
			return false;
		}
		$row     = array(
			'status'      => $status,
			'resolved_at' => current_time( 'mysql', true ),
		);
		$formats = array( '%s', '%s' );
		if ( $user_id > 0 ) {
			$row['resolved_by'] = $user_id;
			$formats[]          = '%d';
		}
		return (bool) $wpdb->update( Schema::table( Schema::REVIEW_QUEUE ), $row, array( 'id' => $item_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Merges keys into an item's payload, for example a reviewer's note.
	 *
	 * @param int                  $item_id Item.
	 * @param array<string, mixed> $values  Keys to add or replace.
	 * @return bool
	 */
	public static function annotate( int $item_id, array $values ): bool {
		global $wpdb;
		$item = self::get( $item_id );
		if ( ! $item ) {
			return false;
		}
		$payload = array_merge( (array) $item['payload'], $values );
		return false !== $wpdb->update( Schema::table( Schema::REVIEW_QUEUE ), array( 'payload' => (string) wp_json_encode( $payload ) ), array( 'id' => $item_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Decodes a row's payload.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function decode( array $row ): array {
		$payload        = json_decode( (string) $row['payload'], true );
		$row['payload'] = is_array( $payload ) ? $payload : array();
		return $row;
	}
}
