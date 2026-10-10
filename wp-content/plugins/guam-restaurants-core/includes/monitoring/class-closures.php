<?php
/**
 * Permanent-closure signals and their approval.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * A restaurant is never marked permanently closed by a job. Each check
 * (the weekly OSM import, the monthly Google status check) reports whether
 * the restaurant looked closed. When two consecutive checks from the same
 * source agree, a `closure_signal` item goes to the review queue, and only
 * an administrator's approval sets the status. A check that finds the
 * restaurant open resets that source's count.
 *
 * Closure is recorded as a plain status fact, never as a negative mark.
 *
 * The count per source is kept in hidden post meta and holds only our own
 * check results (a count and dates), never a source's data.
 */
final class Closures {

	public const STREAK_META = '_gr_closure_checks';

	/**
	 * Consecutive closed checks needed before an administrator is asked.
	 */
	public const CHECKS_NEEDED = 2;

	/**
	 * A closed check this soon after the previous one from the same source
	 * (for example a manual re-run the same day) does not count again.
	 */
	public const MIN_HOURS_BETWEEN = 20;

	public const CAPABILITY = 'manage_options';

	/**
	 * Records one check result.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param string               $source        Source of the check (osm, google, yelp).
	 * @param bool                 $closed        Whether the check says the restaurant is closed.
	 * @param int|null             $run_id        Ingestion run.
	 * @param array<string, mixed> $evidence      Extra payload for the reviewer (our own data or the source's ID only).
	 * @return array{count: int, queued: bool} Consecutive closed checks after this one; whether an item was queued.
	 */
	public static function record_check( int $restaurant_id, string $source, bool $closed, ?int $run_id = null, array $evidence = array() ): array {
		$streaks = self::streaks( $restaurant_id );

		if ( ! $closed ) {
			if ( isset( $streaks[ $source ] ) ) {
				unset( $streaks[ $source ] );
				self::save_streaks( $restaurant_id, $streaks );
			}
			return array(
				'count'  => 0,
				'queued' => false,
			);
		}

		if ( 'permanently_closed' === get_post_meta( $restaurant_id, Restaurant_Meta::STATUS, true ) ) {
			return array(
				'count'  => 0,
				'queued' => false,
			);
		}

		$now    = current_time( 'mysql', true );
		$streak = $streaks[ $source ] ?? array(
			'count' => 0,
			'first' => $now,
		);
		if ( isset( $streak['last'] ) && strtotime( $streak['last'] . ' UTC' ) > time() - self::MIN_HOURS_BETWEEN * HOUR_IN_SECONDS ) {
			return array(
				'count'  => (int) $streak['count'],
				'queued' => false,
			);
		}

		$streak['count']    = (int) $streak['count'] + 1;
		$streak['last']     = $now;
		$streak['last_run'] = $run_id;
		$streaks[ $source ] = $streak;
		self::save_streaks( $restaurant_id, $streaks );

		$queued = false;
		if ( $streak['count'] >= self::CHECKS_NEEDED && ! self::pending_item( $restaurant_id ) ) {
			$queued = Review_Queue::enqueue(
				'closure_signal',
				array(
					'proposed_status' => 'permanently_closed',
					'checks'          => $streak['count'],
					'first_signal_at' => $streak['first'],
					'last_signal_at'  => $now,
				) + $evidence,
				$source,
				$restaurant_id,
				null,
				$run_id
			) > 0;
		}

		return array(
			'count'  => $streak['count'],
			'queued' => $queued,
		);
	}

	/**
	 * Approves a closure item: sets the status to permanently closed, with
	 * the closing month, and logs it as an administrator's edit.
	 *
	 * @param int $item_id Review queue item.
	 * @param int $user_id Administrator.
	 * @return bool
	 * @throws \RuntimeException When the item is not a pending closure or the user may not approve it.
	 */
	public static function approve( int $item_id, int $user_id ): bool {
		$item = self::item( $item_id );
		if ( ! user_can( $user_id, self::CAPABILITY ) ) {
			throw new \RuntimeException( 'Only an administrator can approve a closure.' );
		}

		$restaurant_id = (int) $item['restaurant_id'];
		$old_status    = (string) get_post_meta( $restaurant_id, Restaurant_Meta::STATUS, true );

		Change_Tracker::suppress(
			static function () use ( $restaurant_id ) {
				update_post_meta( $restaurant_id, Restaurant_Meta::STATUS, 'permanently_closed' );
				if ( '' === (string) get_post_meta( $restaurant_id, Restaurant_Meta::CLOSED_ON, true ) ) {
					update_post_meta( $restaurant_id, Restaurant_Meta::CLOSED_ON, gmdate( 'Y-m' ) );
				}
				delete_post_meta( $restaurant_id, self::STREAK_META );
			}
		);
		Change_Log::record( $restaurant_id, Restaurant_Meta::STATUS, $old_status, 'permanently_closed', 'editor', isset( $item['run_id'] ) ? (int) $item['run_id'] : null );

		return Review_Queue::resolve( $item_id, 'approved', $user_id );
	}

	/**
	 * Rejects a closure item and resets the check counts, so the next two
	 * checks must agree again before an administrator is asked.
	 *
	 * @param int $item_id Review queue item.
	 * @param int $user_id User.
	 * @return bool
	 * @throws \RuntimeException When the item is not a pending closure.
	 */
	public static function reject( int $item_id, int $user_id ): bool {
		$item = self::item( $item_id );
		delete_post_meta( (int) $item['restaurant_id'], self::STREAK_META );
		return Review_Queue::resolve( $item_id, 'rejected', $user_id );
	}

	/**
	 * Closed-check counts per source.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<string, array{count: int, first: string, last?: string, last_run?: int|null}>
	 */
	public static function streaks( int $restaurant_id ): array {
		$streaks = get_post_meta( $restaurant_id, self::STREAK_META, true );
		return is_array( $streaks ) ? $streaks : array();
	}

	/**
	 * Saves closed-check counts.
	 *
	 * @param int                                 $restaurant_id Restaurant.
	 * @param array<string, array<string, mixed>> $streaks       Counts.
	 * @return void
	 */
	private static function save_streaks( int $restaurant_id, array $streaks ): void {
		Change_Tracker::suppress(
			static function () use ( $restaurant_id, $streaks ) {
				if ( $streaks ) {
					update_post_meta( $restaurant_id, self::STREAK_META, $streaks );
				} else {
					delete_post_meta( $restaurant_id, self::STREAK_META );
				}
			}
		);
	}

	/**
	 * Whether a closure item is already pending for the restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return bool
	 */
	public static function pending_item( int $restaurant_id ): bool {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s LIMIT 1", $restaurant_id, 'closure_signal', 'pending' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * A pending closure item.
	 *
	 * @param int $item_id Item.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When missing, resolved, or not a closure.
	 */
	private static function item( int $item_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $item_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item || 'closure_signal' !== $item['type'] || 'pending' !== $item['status'] || ! $item['restaurant_id'] ) {
			throw new \RuntimeException( 'Not a pending closure item.' );
		}
		return $item;
	}
}
