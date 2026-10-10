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
}
