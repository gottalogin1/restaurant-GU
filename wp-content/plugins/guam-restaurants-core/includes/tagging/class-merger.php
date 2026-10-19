<?php
/**
 * Merges two restaurant posts that are the same place.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one post and moves the other's data onto it, then trashes the other
 * (so a merge can be undone from the trash). Nothing on the kept post is
 * overwritten: empty fields are filled, tags are combined, and the other
 * name is added to Former names so award imports still find it.
 *
 * The kept post is the one with an OSM id (imports update it), then the
 * published one, then the older one.
 */
final class Merger {

	/**
	 * Post meta on the trashed post naming the post it was merged into.
	 */
	public const MERGED_INTO_META = '_gr_merged_into';

	/**
	 * Fields copied when the kept post has no value.
	 */
	private const FILL_FIELDS = array(
		Restaurant_Meta::ADDRESS,
		Restaurant_Meta::PHONE,
		Restaurant_Meta::WEBSITE,
		Restaurant_Meta::EMAIL,
		Restaurant_Meta::FACEBOOK,
		Restaurant_Meta::INSTAGRAM,
		Restaurant_Meta::HOURS,
		Restaurant_Meta::OPENED_ON,
		Restaurant_Meta::CLAIMED_BY_USER_ID,
		Restaurant_Meta::GOOGLE_PLACE_ID,
		Restaurant_Meta::GOOGLE_MATCH_CONFIDENCE,
		Restaurant_Meta::YELP_ID,
		Restaurant_Meta::YELP_MATCH_CONFIDENCE,
		Restaurant_Meta::FOURSQUARE_ID,
		Restaurant_Meta::FOURSQUARE_MATCH_CONFIDENCE,
	);

	/**
	 * Merges two restaurants.
	 *
	 * @param int                  $a      Restaurant.
	 * @param int                  $b      Restaurant.
	 * @param string               $source Change log source: `system` (automatic) or `editor`.
	 * @param int|null             $run_id Ingestion run.
	 * @param array<string, mixed> $score  Duplicate score, for the change log.
	 * @return array{kept_id: int, merged_id: int, filled: string[]}
	 * @throws \RuntimeException When either post is missing or not a restaurant.
	 */
	public function merge( int $a, int $b, string $source = 'editor', ?int $run_id = null, array $score = array() ): array {
		foreach ( array( $a, $b ) as $id ) {
			if ( Content_Types::POST_TYPE !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
				throw new \RuntimeException( esc_html( "Restaurant #{$id} is missing or in the trash." ) );
			}
		}
		if ( $a === $b ) {
			throw new \RuntimeException( 'A restaurant cannot be merged with itself.' );
		}

		list( $keep, $drop ) = self::choose( $a, $b );

		return Change_Tracker::suppress(
			function () use ( $keep, $drop, $source, $run_id, $score ) {
				$filled = $this->fill_fields( $keep, $drop );
				$this->merge_names( $keep, $drop );
				$this->merge_tags( $keep, $drop );
				self::move_rows( $keep, $drop );

				update_post_meta( $drop, self::MERGED_INTO_META, $keep );
				wp_trash_post( $drop );

				Change_Log::record(
					$keep,
					'merged',
					null,
					array_filter(
						array(
							'merged_id'  => $drop,
							'name'       => (string) get_post_field( 'post_title', $drop, 'raw' ),
							'confidence' => $score['confidence'] ?? null,
							'filled'     => $filled,
						),
						static function ( $value ) {
							return null !== $value && array() !== $value;
						}
					),
					$source,
					$run_id
				);

				return array(
					'kept_id'   => $keep,
					'merged_id' => $drop,
					'filled'    => $filled,
				);
			}
		);
	}

	/**
	 * Which post to keep: [keep, drop].
	 *
	 * @param int $a Restaurant.
	 * @param int $b Restaurant.
	 * @return array{0: int, 1: int}
	 */
	public static function choose( int $a, int $b ): array {
		$rank = static function ( int $id ): array {
			return array(
				'' !== (string) get_post_meta( $id, Restaurant_Meta::OSM_ID, true ) ? 0 : 1,
				'publish' === get_post_status( $id ) ? 0 : 1,
				$id,
			);
		};
		return $rank( $a ) <= $rank( $b ) ? array( $a, $b ) : array( $b, $a );
	}

	/**
	 * Copies fields the kept post lacks. The OSM id and coordinates move
	 * only when the kept post has none, so imports keep updating one post.
	 *
	 * @param int $keep Kept post.
	 * @param int $drop Post being merged in.
	 * @return string[] Fields filled.
	 */
	private function fill_fields( int $keep, int $drop ): array {
		$filled = array();
		foreach ( self::FILL_FIELDS as $key ) {
			$current = get_post_meta( $keep, $key, true );
			$theirs  = get_post_meta( $drop, $key, true );
			if ( self::is_empty( $current ) && ! self::is_empty( $theirs ) ) {
				update_post_meta( $keep, $key, $theirs );
				$filled[] = $key;
			}
		}

		$osm = (string) get_post_meta( $drop, Restaurant_Meta::OSM_ID, true );
		if ( '' !== $osm && '' === (string) get_post_meta( $keep, Restaurant_Meta::OSM_ID, true ) ) {
			delete_post_meta( $drop, Restaurant_Meta::OSM_ID );
			update_post_meta( $keep, Restaurant_Meta::OSM_ID, $osm );
			$filled[] = Restaurant_Meta::OSM_ID;
		}

		$keep_lat = (float) get_post_meta( $keep, Restaurant_Meta::LATITUDE, true );
		$drop_lat = (float) get_post_meta( $drop, Restaurant_Meta::LATITUDE, true );
		if ( 0.0 === $keep_lat && 0.0 !== $drop_lat ) {
			foreach ( array( Restaurant_Meta::LATITUDE, Restaurant_Meta::LONGITUDE, Restaurant_Meta::COORD_SOURCE ) as $key ) {
				update_post_meta( $keep, $key, get_post_meta( $drop, $key, true ) );
			}
			$filled[] = Restaurant_Meta::LATITUDE;
		}

		$status = (string) get_post_meta( $keep, Restaurant_Meta::STATUS, true );
		$theirs = (string) get_post_meta( $drop, Restaurant_Meta::STATUS, true );
		if ( in_array( $status, array( '', 'unknown' ), true ) && ! in_array( $theirs, array( '', 'unknown' ), true ) ) {
			update_post_meta( $keep, Restaurant_Meta::STATUS, $theirs );
			$filled[] = Restaurant_Meta::STATUS;
		}

		return $filled;
	}

	/**
	 * Adds the other post's name and former names to Former names.
	 *
	 * @param int $keep Kept post.
	 * @param int $drop Post being merged in.
	 * @return void
	 */
	private function merge_names( int $keep, int $drop ): void {
		$title = (string) get_post_field( 'post_title', $keep, 'raw' );
		$names = Restaurant_Meta::former_names( $keep );
		foreach ( array_merge( array( (string) get_post_field( 'post_title', $drop, 'raw' ) ), Restaurant_Meta::former_names( $drop ) ) as $name ) {
			if ( '' !== $name && Names::key( $name ) !== Names::key( $title ) ) {
				$names[] = $name;
			}
		}
		update_post_meta( $keep, Restaurant_Meta::FORMER_NAMES, implode( "\n", $names ) );
	}

	/**
	 * Combines cuisines and price level; takes the other mall only when the kept post has none.
	 *
	 * @param int $keep Kept post.
	 * @param int $drop Post being merged in.
	 * @return void
	 */
	private function merge_tags( int $keep, int $drop ): void {
		$keep_origins = Tag_Store::origins( $keep );
		$drop_origins = Tag_Store::origins( $drop );

		Tag_Store::applying(
			static function () use ( $keep, $drop, &$keep_origins, $drop_origins ) {
				$cuisines = array_values( array_unique( array_merge( Term_Slugs::for_post( $keep, Content_Types::TAX_CUISINE ), Term_Slugs::for_post( $drop, Content_Types::TAX_CUISINE ) ) ) );
				Term_Slugs::set( $keep, $cuisines, Content_Types::TAX_CUISINE );
				$keep_origins[ Content_Types::TAX_CUISINE ] += $drop_origins[ Content_Types::TAX_CUISINE ];

				if ( ! Term_Slugs::for_post( $keep, Content_Types::TAX_PRICE ) ) {
					Term_Slugs::set( $keep, Term_Slugs::for_post( $drop, Content_Types::TAX_PRICE ), Content_Types::TAX_PRICE );
				}

				$drop_mall = Term_Slugs::for_post( $drop, Content_Types::TAX_MALL );
				if ( ! Term_Slugs::for_post( $keep, Content_Types::TAX_MALL ) && $drop_mall ) {
					Term_Slugs::set( $keep, $drop_mall, Content_Types::TAX_MALL );
					$keep_origins[ Content_Types::TAX_MALL ] = $drop_origins[ Content_Types::TAX_MALL ];
					if ( '' !== Tag_Store::mall_override( $drop ) ) {
						update_post_meta( $keep, Tag_Store::MALL_OVERRIDE_META, Tag_Store::mall_override( $drop ) );
					}
				}
			}
		);

		// Cuisine sources: the kept post's own sources win; the other fills sources it lacks.
		$sources = Tag_Store::cuisine_sources( $keep ) + Tag_Store::cuisine_sources( $drop );
		if ( $sources ) {
			update_post_meta( $keep, Tag_Store::CUISINE_SOURCES_META, $sources );
		}
		$manual      = Tag_Store::cuisine_manual( $keep );
		$drop_manual = Tag_Store::cuisine_manual( $drop );
		if ( $drop_manual['add'] || $drop_manual['remove'] || ! Tag_Store::is_legacy_cuisine( $keep ) ) {
			$manual['add']    = array_values( array_unique( array_merge( $manual['add'], $drop_manual['add'] ) ) );
			$manual['remove'] = array_values( array_diff( array_unique( array_merge( $manual['remove'], $drop_manual['remove'] ) ), $manual['add'] ) );
			Tag_Store::save_cuisine_manual( $keep, $manual );
		}
		Tag_Store::save_origins( $keep, $keep_origins );
	}

	/**
	 * Moves the other post's awards, highlights, review items and change
	 * history to the kept post, and marks review items for this pair merged.
	 *
	 * @param int $keep Kept post.
	 * @param int $drop Post being merged in.
	 * @return void
	 */
	private static function move_rows( int $keep, int $drop ): void {
		global $wpdb;

		// An award the kept post already has stays once.
		$awards = Schema::table( Schema::AWARDS );
		$wpdb->query( $wpdb->prepare( "UPDATE IGNORE {$awards} SET restaurant_id = %d WHERE restaurant_id = %d", $keep, $drop ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$awards} WHERE restaurant_id = %d", $drop ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( array( Schema::HIGHLIGHTS, Schema::CHANGE_LOG ) as $table ) {
			$wpdb->update( Schema::table( $table ), array( 'restaurant_id' => $keep ), array( 'restaurant_id' => $drop ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$queue = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, restaurant_id, payload FROM {$queue} WHERE type = %s AND status = %s AND restaurant_id IN (%d, %d)", 'duplicate', 'pending', $keep, $drop ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			$other   = (int) ( $payload['duplicate_of'] ?? 0 );
			if ( in_array( $other, array( $keep, $drop ), true ) ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$queue,
					array(
						'status'      => 'merged',
						'resolved_at' => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $row['id'] ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET restaurant_id = %d WHERE restaurant_id = %d AND status = %s", $keep, $drop, 'pending' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a meta value counts as empty.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty( $value ): bool {
		return '' === $value || null === $value || array() === $value || 0 === $value || '0' === $value || ( is_numeric( $value ) && 0.0 === (float) $value );
	}
}
