<?php
/**
 * Picks which ads a slot shows.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Site\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Selection in four steps, each a pure function so it can be tested alone:
 *
 * 1. Scheduling (is_running): today, Guam time, is on or after the start
 *    date and on or before the end date. Both dates are inclusive; no end
 *    date means open-ended.
 * 2. Targeting (matches): an ad with page types set shows only on those;
 *    an ad with villages, malls or cuisines set shows only where the page
 *    has at least one of them (a term page, or a restaurant page through
 *    the restaurant's terms). Different target kinds must all match. An
 *    ad with no targets shows anywhere its slot is. On the page of a
 *    restaurant with a premium profile, ads linked to a different
 *    restaurant never match (Premium\Premium).
 * 3. Rotation (order): highest priority first; within a priority, the ad
 *    with the fewest impressions today first; ties by lowest ad ID. The
 *    order is deterministic, and because the beacon counts every shown
 *    impression, ads of the same priority even out over the day.
 * 4. Limits (select): exclusive products (category sponsor, leaderboard)
 *    show one ad per page, and no ad shows twice on one page.
 *
 * Selection reads only ads and their counters. It never reads or changes
 * a restaurant's ratings, awards or place in any list (Guard).
 */
final class Server {

	/**
	 * Ad IDs already printed in this request.
	 *
	 * @var array<int, true>
	 */
	private static array $shown = array();

	/**
	 * Exclusive products already printed in this request.
	 *
	 * @var array<string, true>
	 */
	private static array $exclusive_used = array();

	/**
	 * Today's date in Guam, Y-m-d.
	 *
	 * @return string
	 */
	public static function today(): string {
		return ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( Clock::zone() )->format( 'Y-m-d' );
	}

	/**
	 * Whether an ad runs on a date.
	 *
	 * @param array<string, mixed> $ad    Ad from Ads::get().
	 * @param string               $today Y-m-d.
	 * @return bool
	 */
	public static function is_running( array $ad, string $today ): bool {
		$start = (string) ( $ad['start'] ?? '' );
		$end   = (string) ( $ad['end'] ?? '' );
		if ( '' === $start || $today < $start ) {
			return false;
		}
		return '' === $end || $today <= $end;
	}

	/**
	 * Whether an ad may be served at all today: published, active, running
	 * and with an acceptable creative.
	 *
	 * @param array<string, mixed> $ad    Ad.
	 * @param string               $today Y-m-d.
	 * @return bool
	 */
	public static function is_servable( array $ad, string $today ): bool {
		return 'publish' === ( $ad['post_status'] ?? '' )
			&& Ads::STATUS_ACTIVE === ( $ad['status'] ?? '' )
			&& self::is_running( $ad, $today )
			&& ! Guard::creative_problems( $ad );
	}

	/**
	 * Whether an ad targets a page.
	 *
	 * @param array<string, mixed> $ad      Ad.
	 * @param array<string, mixed> $context From Context::current().
	 * @return bool
	 */
	public static function matches( array $ad, array $context ): bool {
		$host = (int) ( $context['premium_restaurant'] ?? 0 );
		if ( $host > 0 && (int) ( $ad['restaurant_id'] ?? 0 ) > 0 && (int) $ad['restaurant_id'] !== $host ) {
			return false;
		}
		$page_types = (array) ( $ad['page_types'] ?? array() );
		if ( $page_types && ! in_array( (string) ( $context['page_type'] ?? '' ), $page_types, true ) ) {
			return false;
		}
		foreach ( array_keys( Ads::TARGET_TAXONOMIES ) as $key ) {
			$wanted = array_map( 'intval', (array) ( $ad[ $key ] ?? array() ) );
			if ( $wanted && ! array_intersect( $wanted, array_map( 'intval', (array) ( $context[ $key ] ?? array() ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Rotation order.
	 *
	 * @param array<int, array<string, mixed>> $ads         Candidates.
	 * @param array<int, int>                  $impressions Today's impressions by ad ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function order( array $ads, array $impressions ): array {
		usort(
			$ads,
			static function ( array $a, array $b ) use ( $impressions ): int {
				return array( - (int) $a['priority'], (int) ( $impressions[ $a['id'] ] ?? 0 ), (int) $a['id'] )
					<=> array( - (int) $b['priority'], (int) ( $impressions[ $b['id'] ] ?? 0 ), (int) $b['id'] );
			}
		);
		return array_values( $ads );
	}

	/**
	 * Ads for one slot, in display order, and marks them shown.
	 *
	 * @param string               $product Product key.
	 * @param int                  $count   Most ads to show.
	 * @param array<string, mixed> $context Page context.
	 * @return array<int, array<string, mixed>>
	 */
	public static function select( string $product, int $count, array $context ): array {
		if ( ! isset( Ads::products()[ $product ] ) || $count < 1 ) {
			return array();
		}
		$exclusive = Ads::is_exclusive( $product );
		if ( $exclusive ) {
			if ( isset( self::$exclusive_used[ $product ] ) ) {
				return array();
			}
			$count = 1;
		}

		$today      = self::today();
		$candidates = array();
		foreach ( Ads::active( $product ) as $ad ) {
			if ( ! isset( self::$shown[ $ad['id'] ] ) && self::is_servable( $ad, $today ) && self::matches( $ad, $context ) ) {
				$candidates[] = $ad;
			}
		}
		if ( ! $candidates ) {
			return array();
		}

		$picked = array_slice( self::order( $candidates, Stats::impressions_on( wp_list_pluck( $candidates, 'id' ), $today ) ), 0, $count );
		foreach ( $picked as $ad ) {
			self::$shown[ $ad['id'] ] = true;
		}
		if ( $exclusive ) {
			self::$exclusive_used[ $product ] = true;
		}
		return $picked;
	}

	/**
	 * Forgets what this request printed (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$shown          = array();
		self::$exclusive_used = array();
	}
}
