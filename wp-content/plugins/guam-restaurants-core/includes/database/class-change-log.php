<?php
/**
 * Change log writer.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Database;

use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Records changes to restaurant facts.
 */
final class Change_Log {

	/**
	 * Records one field change.
	 *
	 * @param int      $restaurant_id Restaurant ID.
	 * @param string   $field         Meta key, `title`, or `tax:{taxonomy}`.
	 * @param mixed    $old_value     Previous value; arrays are stored as JSON.
	 * @param mixed    $new_value     New value; arrays are stored as JSON.
	 * @param string   $source        One of Vocabulary::FACT_SOURCES.
	 * @param int|null $run_id        Ingestion run ID.
	 * @return int Row ID, or 0 on failure.
	 * @throws \InvalidArgumentException When the source is not allowed.
	 */
	public static function record( int $restaurant_id, string $field, $old_value, $new_value, string $source, ?int $run_id = null ): int {
		global $wpdb;

		if ( ! in_array( $source, Vocabulary::FACT_SOURCES, true ) ) {
			throw new \InvalidArgumentException( 'Source not allowed in the change log: ' . esc_html( $source ) );
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( Schema::CHANGE_LOG ),
			array(
				'restaurant_id' => $restaurant_id,
				'field'         => substr( $field, 0, 64 ),
				'old_value'     => self::encode( $old_value ),
				'new_value'     => self::encode( $new_value ),
				'source'        => $source,
				'detected_at'   => current_time( 'mysql', true ),
				'run_id'        => $run_id,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Encodes a value for storage.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	private static function encode( $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}
}
