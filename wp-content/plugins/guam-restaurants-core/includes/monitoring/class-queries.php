<?php
/**
 * Read queries over the change log.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Term_Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * Recent openings, recent closures, and one restaurant's history. Closures
 * are plain status facts.
 */
final class Queries {

	/**
	 * Restaurants first seen in the last N days, newest first.
	 *
	 * Restaurants found by a source's first run are the starting inventory,
	 * not openings, and are left out; so are trashed and permanently
	 * closed restaurants.
	 *
	 * @param int $days  Days back from now.
	 * @param int $limit Most rows to return; 0 for all.
	 * @return array<int, array{restaurant_id: int, name: string, first_seen: string, source: string, status: string, village: string[], mall: string[], url: string}>
	 */
	public static function recent_openings( int $days, int $limit = 0 ): array {
		global $wpdb;

		$table = Schema::table( Schema::CHANGE_LOG );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT restaurant_id, new_value, source, detected_at FROM {$table} WHERE field = %s AND detected_at >= %s ORDER BY detected_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Change_Tracker::FIRST_SEEN,
				self::since( $days )
			),
			ARRAY_A
		);

		return self::collect(
			$rows ? $rows : array(),
			$limit,
			static function ( int $id, array $row ): ?array {
				$value = json_decode( (string) $row['new_value'], true );
				if ( ! empty( $value['baseline'] ) || ! self::is_listed( $id ) || 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
					return null;
				}
				return self::summary( $id ) + array(
					'first_seen' => (string) $row['detected_at'],
					'source'     => (string) $row['source'],
				);
			}
		);
	}

	/**
	 * Restaurants marked permanently closed (by an administrator's approval
	 * or an edit) in the last N days and still closed, newest first.
	 *
	 * @param int $days  Days back from now.
	 * @param int $limit Most rows to return; 0 for all.
	 * @return array<int, array{restaurant_id: int, name: string, closed_at: string, closed_on: string, source: string, status: string, village: string[], mall: string[], url: string}>
	 */
	public static function recent_closures( int $days, int $limit = 0 ): array {
		global $wpdb;

		$table = Schema::table( Schema::CHANGE_LOG );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT restaurant_id, source, detected_at FROM {$table} WHERE field = %s AND new_value = %s AND detected_at >= %s ORDER BY detected_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Restaurant_Meta::STATUS,
				'permanently_closed',
				self::since( $days )
			),
			ARRAY_A
		);

		return self::collect(
			$rows ? $rows : array(),
			$limit,
			static function ( int $id, array $row ): ?array {
				if ( 'trash' === get_post_status( $id ) || 'permanently_closed' !== get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
					return null;
				}
				return self::summary( $id ) + array(
					'closed_at' => (string) $row['detected_at'],
					'closed_on' => (string) get_post_meta( $id, Restaurant_Meta::CLOSED_ON, true ),
					'source'    => (string) $row['source'],
				);
			}
		);
	}

	/**
	 * Restaurants whose status changed from closed (temporarily or
	 * permanently) back to open in the last N days and are still open,
	 * newest first.
	 *
	 * @param int $days  Days back from now.
	 * @param int $limit Most rows to return; 0 for all.
	 * @return array<int, array{restaurant_id: int, name: string, reopened_at: string, source: string, status: string, village: string[], mall: string[], url: string}>
	 */
	public static function recent_reopenings( int $days, int $limit = 0 ): array {
		global $wpdb;

		$table = Schema::table( Schema::CHANGE_LOG );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT restaurant_id, source, detected_at FROM {$table} WHERE field = %s AND new_value = %s AND old_value IN (%s, %s) AND detected_at >= %s ORDER BY detected_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Restaurant_Meta::STATUS,
				'open',
				'temporarily_closed',
				'permanently_closed',
				self::since( $days )
			),
			ARRAY_A
		);

		return self::collect(
			$rows ? $rows : array(),
			$limit,
			static function ( int $id, array $row ): ?array {
				if ( ! self::is_listed( $id ) || 'open' !== get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
					return null;
				}
				return self::summary( $id ) + array(
					'reopened_at' => (string) $row['detected_at'],
					'source'      => (string) $row['source'],
				);
			}
		);
	}

	/**
	 * Every recorded change to one restaurant, newest first. Values that
	 * were stored as JSON (hours, first_seen, merges) are decoded.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array{id: int, field: string, old_value: mixed, new_value: mixed, source: string, detected_at: string, run_id: int|null}>
	 */
	public static function changes_for( int $restaurant_id ): array {
		global $wpdb;

		$table = Schema::table( Schema::CHANGE_LOG );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE restaurant_id = %d ORDER BY detected_at DESC, id DESC", $restaurant_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[] = array(
				'id'          => (int) $row['id'],
				'field'       => (string) $row['field'],
				'old_value'   => self::decode( $row['old_value'] ),
				'new_value'   => self::decode( $row['new_value'] ),
				'source'      => (string) $row['source'],
				'detected_at' => (string) $row['detected_at'],
				'run_id'      => null === $row['run_id'] ? null : (int) $row['run_id'],
			);
		}
		return $out;
	}

	/**
	 * UTC datetime N days ago.
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private static function since( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );
	}

	/**
	 * Whether a restaurant exists and is not trashed.
	 *
	 * @param int $id Restaurant.
	 * @return bool
	 */
	private static function is_listed( int $id ): bool {
		$status = get_post_status( $id );
		return $status && 'trash' !== $status && Content_Types::POST_TYPE === get_post_type( $id );
	}

	/**
	 * Builds one entry per restaurant from change-log rows (newest first),
	 * stopping at $limit. Post, meta and term caches are primed a chunk at a
	 * time so a long log costs a few queries, not several per row.
	 *
	 * @param array<int, array<string, mixed>>   $rows     Change-log rows.
	 * @param int                                $limit    Most entries; 0 for all.
	 * @param callable(int, array): (array|null) $callback Entry for a restaurant, or null to skip it.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect( array $rows, int $limit, callable $callback ): array {
		$out   = array();
		$seen  = array();
		$chunk = max( 50, $limit );
		foreach ( array_chunk( $rows, $chunk ) as $batch ) {
			_prime_post_caches( array_unique( array_map( 'intval', wp_list_pluck( $batch, 'restaurant_id' ) ) ), true, true );
			foreach ( $batch as $row ) {
				$id = (int) $row['restaurant_id'];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$entry       = $callback( $id, $row );
				if ( null === $entry ) {
					continue;
				}
				$out[] = $entry;
				if ( $limit > 0 && count( $out ) >= $limit ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * Fields shared by openings and closures.
	 *
	 * @param int $id Restaurant.
	 * @return array{restaurant_id: int, name: string, status: string, village: string[], mall: string[], url: string}
	 */
	private static function summary( int $id ): array {
		return array(
			'restaurant_id' => $id,
			'name'          => html_entity_decode( (string) get_post_field( 'post_title', $id, 'raw' ), ENT_QUOTES ),
			'status'        => (string) get_post_meta( $id, Restaurant_Meta::STATUS, true ),
			'village'       => Term_Slugs::for_post( $id, Content_Types::TAX_VILLAGE ),
			'mall'          => Term_Slugs::for_post( $id, Content_Types::TAX_MALL ),
			'url'           => 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '',
		);
	}

	/**
	 * Decodes a JSON-encoded array value; other values are returned as stored.
	 *
	 * @param string|null $value Stored value.
	 * @return mixed
	 */
	private static function decode( ?string $value ) {
		if ( null === $value || '' === $value || ( '{' !== $value[0] && '[' !== $value[0] ) ) {
			return $value;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : $value;
	}
}
