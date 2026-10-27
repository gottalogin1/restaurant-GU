<?php
/**
 * Indexed weekly opening intervals, for the "open now" filter.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table; the name is built from a constant and values go through prepare().

/**
 * Keeps `{prefix}gr_open_hours` in step with each restaurant's `gr_hours`
 * meta: one row per opening interval, in minutes from Monday 00:00 Guam
 * time. "Open now" is then one indexed EXISTS lookup instead of decoding
 * every restaurant's hours in PHP.
 *
 * Restaurants with no hours have no rows and are never "open now".
 */
final class Open_Hours {

	/**
	 * Bump to rebuild the index on existing sites.
	 */
	public const INDEX_VERSION = '1';
	public const INDEX_OPTION  = 'guam_restaurants_open_hours_index';
	public const REBUILD_HOOK  = 'guam_restaurants_rebuild_open_hours';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'added_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'meta_deleted' ), 10, 3 );
		add_action( self::REBUILD_HOOK, array( self::class, 'rebuild_all' ) );
		add_action( 'init', array( self::class, 'maybe_schedule_rebuild' ), 30 );
	}

	/**
	 * Schedules a one-off rebuild when the index is older than this code.
	 *
	 * @return void
	 */
	public static function maybe_schedule_rebuild(): void {
		if ( get_option( self::INDEX_OPTION ) === self::INDEX_VERSION || wp_next_scheduled( self::REBUILD_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::REBUILD_HOOK );
	}

	/**
	 * Re-indexes a restaurant when its hours are added or changed.
	 *
	 * @param int|int[] $meta_id  Meta ID (unused).
	 * @param int       $post_id  Post.
	 * @param string    $meta_key Key.
	 * @return void
	 */
	public static function meta_changed( $meta_id, $post_id, $meta_key ): void {
		if ( Restaurant_Meta::HOURS === $meta_key && Content_Types::POST_TYPE === get_post_type( (int) $post_id ) ) {
			self::sync( (int) $post_id );
		}
	}

	/**
	 * Clears a restaurant's rows when its hours are deleted.
	 *
	 * @param int[]  $meta_ids Meta IDs (unused).
	 * @param int    $post_id  Post.
	 * @param string $meta_key Key.
	 * @return void
	 */
	public static function meta_deleted( $meta_ids, $post_id, $meta_key ): void {
		if ( Restaurant_Meta::HOURS === $meta_key ) {
			self::write( (int) $post_id, array() );
		}
	}

	/**
	 * Rebuilds every restaurant's rows.
	 *
	 * @return int Restaurants indexed.
	 */
	public static function rebuild_all(): int {
		global $wpdb;

		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		// DELETE, not TRUNCATE: TRUNCATE commits any open transaction.
		$wpdb->query( 'DELETE FROM ' . Schema::table( Schema::OPEN_HOURS ) );
		foreach ( array_chunk( array_map( 'intval', $ids ), 200 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
			foreach ( $chunk as $id ) {
				self::sync( $id );
			}
		}
		update_option( self::INDEX_OPTION, self::INDEX_VERSION, false );
		return count( $ids );
	}

	/**
	 * Re-indexes one restaurant from its stored hours.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return void
	 */
	public static function sync( int $restaurant_id ): void {
		$hours = get_post_meta( $restaurant_id, Restaurant_Meta::HOURS, true );
		self::write( $restaurant_id, self::intervals( is_array( $hours ) ? $hours : array() ) );
	}

	/**
	 * Weekly intervals [opens_at, closes_at) in minutes from Monday 00:00.
	 *
	 * A close time at or before the open time runs past midnight; an
	 * interval past Sunday midnight is split in two. Overlapping intervals
	 * are merged.
	 *
	 * @param array<string, mixed> $hours Hours object (Restaurant_Meta::hours_schema()).
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function intervals( array $hours ): array {
		if ( ! empty( $hours['always_open'] ) ) {
			return array( array( 0, Clock::MINUTES_PER_WEEK ) );
		}

		$raw = array();
		foreach ( (array) ( $hours['weekly'] ?? array() ) as $interval ) {
			$day = array_search( $interval['day'] ?? '', Vocabulary::DAYS, true );
			if ( false === $day ) {
				continue;
			}
			$open  = self::minutes( (string) ( $interval['open'] ?? '' ) );
			$close = self::minutes( (string) ( $interval['close'] ?? '' ) );
			if ( null === $open || null === $close ) {
				continue;
			}
			$start = $day * Clock::MINUTES_PER_DAY + $open;
			$end   = $day * Clock::MINUTES_PER_DAY + ( $close <= $open ? $close + Clock::MINUTES_PER_DAY : $close );
			if ( $end > Clock::MINUTES_PER_WEEK ) {
				$raw[] = array( $start, Clock::MINUTES_PER_WEEK );
				$raw[] = array( 0, $end - Clock::MINUTES_PER_WEEK );
			} else {
				$raw[] = array( $start, $end );
			}
		}

		sort( $raw );
		$merged = array();
		foreach ( $raw as $interval ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $interval[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $interval[1] );
			} else {
				$merged[] = $interval;
			}
		}
		return $merged;
	}

	/**
	 * Whether a minute of the week falls inside one of the intervals.
	 *
	 * @param array<int, array{0: int, 1: int}> $intervals Intervals.
	 * @param int                               $minute    Minute of the week.
	 * @return bool
	 */
	public static function is_open_at( array $intervals, int $minute ): bool {
		foreach ( $intervals as $interval ) {
			if ( $interval[0] <= $minute && $minute < $interval[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Intervals as "start-end,start-end", for the browser's open-now label.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string Empty when no hours are known.
	 */
	public static function compact( int $restaurant_id ): string {
		$hours = get_post_meta( $restaurant_id, Restaurant_Meta::HOURS, true );
		$parts = array();
		foreach ( self::intervals( is_array( $hours ) ? $hours : array() ) as $interval ) {
			$parts[] = $interval[0] . '-' . $interval[1];
		}
		return implode( ',', $parts );
	}

	/**
	 * SQL condition: the post (alias `$posts_alias`) is open at `$minute`.
	 *
	 * @param int    $minute      Minute of the week.
	 * @param string $posts_alias Posts table name or alias in the outer query.
	 * @return string
	 */
	public static function open_sql( int $minute, string $posts_alias ): string {
		global $wpdb;
		return $wpdb->prepare(
			'EXISTS (SELECT 1 FROM ' . Schema::table( Schema::OPEN_HOURS ) . " gr_oh WHERE gr_oh.restaurant_id = {$posts_alias}.ID AND gr_oh.opens_at <= %d AND gr_oh.closes_at > %d)",
			$minute,
			$minute
		);
	}

	/**
	 * SQL condition: the post is open at `$minute_of_day` on at least one
	 * day of the week.
	 *
	 * @param int    $minute_of_day Minutes after midnight, 0 to 1439.
	 * @param string $posts_alias   Posts table name or alias in the outer query.
	 * @return string
	 */
	public static function open_any_day_sql( int $minute_of_day, string $posts_alias ): string {
		global $wpdb;
		$days = array();
		for ( $day = 0; $day < 7; $day++ ) {
			$minute = $day * Clock::MINUTES_PER_DAY + $minute_of_day;
			$days[] = $wpdb->prepare( '(gr_oh.opens_at <= %d AND gr_oh.closes_at > %d)', $minute, $minute );
		}
		return 'EXISTS (SELECT 1 FROM ' . Schema::table( Schema::OPEN_HOURS ) . " gr_oh WHERE gr_oh.restaurant_id = {$posts_alias}.ID AND (" . implode( ' OR ', $days ) . '))';
	}

	/**
	 * Replaces a restaurant's rows.
	 *
	 * @param int                               $restaurant_id Restaurant.
	 * @param array<int, array{0: int, 1: int}> $intervals     Intervals.
	 * @return void
	 */
	private static function write( int $restaurant_id, array $intervals ): void {
		global $wpdb;
		$table = Schema::table( Schema::OPEN_HOURS );
		$wpdb->delete( $table, array( 'restaurant_id' => $restaurant_id ), array( '%d' ) );
		foreach ( $intervals as $interval ) {
			$wpdb->insert(
				$table,
				array(
					'restaurant_id' => $restaurant_id,
					'opens_at'      => $interval[0],
					'closes_at'     => $interval[1],
				),
				array( '%d', '%d', '%d' )
			);
		}
	}

	/**
	 * "HH:MM" as minutes after midnight; "24:00" is 1440.
	 *
	 * @param string $time Time.
	 * @return int|null
	 */
	public static function minutes( string $time ): ?int {
		if ( 1 !== preg_match( '/^(\d{2}):(\d{2})$/', $time, $m ) ) {
			return null;
		}
		$minutes = (int) $m[1] * 60 + (int) $m[2];
		return $minutes <= Clock::MINUTES_PER_DAY ? $minutes : null;
	}
}
