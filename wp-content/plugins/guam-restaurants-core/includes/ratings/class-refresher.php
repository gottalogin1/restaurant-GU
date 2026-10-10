<?php
/**
 * Background refresh of cacheable ratings.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps cacheable ratings (Yelp, 24 hours) fresh through WP-Cron, so page
 * views only read the cache. Sources that may not be cached (Google,
 * Foursquare) are never refreshed here.
 */
final class Refresher {

	public const HOOK        = 'guam_restaurants_refresh_ratings';
	public const SINGLE_HOOK = 'guam_restaurants_refresh_rating';

	/**
	 * Hooks the cron events and schedules the twice-daily refresh, so each
	 * cached rating is replaced well before its 24-hour limit.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run_scheduled' ) );
		add_action( self::SINGLE_HOOK, array( self::class, 'refresh_one' ), 10, 2 );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK );
		}
	}

	/**
	 * Removes the scheduled events (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_unschedule_hook( self::SINGLE_HOOK );
	}

	/**
	 * Schedules a refresh of one restaurant's rating, once.
	 *
	 * @param int    $restaurant_id Restaurant ID.
	 * @param string $source        Source key.
	 * @return void
	 */
	public static function schedule_one( int $restaurant_id, string $source ): void {
		$args = array( $restaurant_id, $source );
		if ( ! wp_next_scheduled( self::SINGLE_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::SINGLE_HOOK, $args );
		}
	}

	/**
	 * Cron callback for the twice-daily refresh.
	 *
	 * @return void
	 */
	public static function run_scheduled(): void {
		self::run( Ratings::providers(), Budget::for_run() );
	}

	/**
	 * Cron callback for one restaurant.
	 *
	 * @param int    $restaurant_id Restaurant ID.
	 * @param string $source        Source key.
	 * @return void
	 */
	public static function refresh_one( $restaurant_id, $source ): void {
		$providers = Ratings::providers();
		$source    = (string) $source;
		if ( ! isset( $providers[ $source ] ) || $providers[ $source ]->max_cache_seconds() <= 0 ) {
			return;
		}
		$provider = $providers[ $source ];
		if ( ! Budget::try_call_monthly( $source, $provider->fetch_sku( true ), $provider->fetch_calls( true ) ) ) {
			return;
		}
		try {
			Ratings::refresh( $provider, (int) $restaurant_id );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Refreshes every cacheable source for every restaurant that has its ID,
	 * stopping at the budget cap. Removes the cache of sources that are no
	 * longer configured (Yelp API Terms §4: delete content on termination).
	 *
	 * @param array<string, Provider> $providers Providers.
	 * @param Budget                  $budget    Run budget.
	 * @return array{refreshed: int, failed: int, stopped_at_cap: bool, spent: float, errors: string[]}
	 */
	public static function run( array $providers, Budget $budget ): array {
		$result = array(
			'refreshed'      => 0,
			'failed'         => 0,
			'stopped_at_cap' => false,
			'spent'          => 0.0,
			'errors'         => array(),
		);

		foreach ( Ratings::CACHED_SOURCES as $source ) {
			if ( ! isset( $providers[ $source ] ) ) {
				self::purge( $source );
			}
		}

		foreach ( $providers as $source => $provider ) {
			if ( $provider->max_cache_seconds() <= 0 ) {
				continue;
			}
			foreach ( self::restaurants_with_id( $provider->id_meta_key() ) as $restaurant_id ) {
				if ( ! $budget->try_call( $provider->fetch_sku( true ), $provider->fetch_calls( true ) ) ) {
					$result['stopped_at_cap'] = true;
					break 2;
				}
				try {
					Ratings::refresh( $provider, $restaurant_id );
					++$result['refreshed'];
				} catch ( \Throwable $e ) {
					++$result['failed'];
					$result['errors'][] = sprintf( '%s #%d: %s', $source, $restaurant_id, $e->getMessage() );
					// A failed refresh must not leave content past its limit.
					$cached = get_transient( Ratings::transient_key( $source, $restaurant_id ) );
					if ( is_array( $cached ) && (int) ( $cached['cached_until'] ?? 0 ) < time() ) {
						delete_transient( Ratings::transient_key( $source, $restaurant_id ) );
					}
				}
			}
		}

		$result['spent'] = $budget->spent();
		return $result;
	}

	/**
	 * Number of restaurants a full refresh would fetch, per cacheable source.
	 *
	 * @param array<string, Provider> $providers Providers.
	 * @return array<string, int>
	 */
	public static function planned( array $providers ): array {
		$planned = array();
		foreach ( $providers as $source => $provider ) {
			if ( $provider->max_cache_seconds() > 0 ) {
				$planned[ $source ] = count( self::restaurants_with_id( $provider->id_meta_key() ) );
			}
		}
		return $planned;
	}

	/**
	 * Deletes every cached rating for a source.
	 *
	 * @param string $source Source key.
	 * @return void
	 */
	public static function purge( string $source ): void {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			delete_transient( Ratings::transient_key( $source, (int) $id ) );
		}
	}

	/**
	 * Restaurant IDs with a value in the given ID meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return int[]
	 */
	private static function restaurants_with_id( string $meta_key ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Content_Types::POST_TYPE,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => $meta_key,
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			)
		);
	}
}
