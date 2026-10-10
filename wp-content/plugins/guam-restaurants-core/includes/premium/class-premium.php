<?php
/**
 * Premium profiles: how long one runs and what it adds.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Premium;

use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Owners\Owner_Content;

defined( 'ABSPATH' ) || exit;

/**
 * A premium profile is prepaid time on one restaurant's listing, stored as
 * the last day it runs (gr_premium_until, Y-m-d, Guam, inclusive). Time is
 * added by a paid WooCommerce order (Commerce\Woo_Checkout) or by an
 * administrator (Premium\Admin); there is no automatic renewal, so the
 * owner gets reminder emails 14 and 3 days before it ends
 * (Billing\Reminders::run_premium).
 *
 * While it runs, the restaurant's own page gets:
 *
 * - up to MAX_PHOTOS owner photos instead of Owner_Content::MAX_PHOTOS;
 * - a call-to-action button (one of CTA_ACTIONS, with the owner's link);
 * - no other restaurant's Sponsored placements (Ads\Server::matches).
 *
 * It never changes ratings, awards, reviews or where the restaurant
 * appears in any list, search or map: nothing here is read by the
 * directory query, ratings or awards code. The page says the restaurant
 * pays for a premium profile.
 */
final class Premium {

	public const UNTIL      = 'gr_premium_until';
	public const LOG        = 'gr_premium_log';
	public const CTA        = 'gr_premium_cta';
	public const MAX_PHOTOS = 20;
	public const LOG_MAX    = 50;

	/**
	 * Plans sold online: key => days and price (USD), from
	 * docs/monetization.md §2. Prices are set on the WooCommerce products;
	 * these are only the starting values.
	 */
	public const PLANS = array(
		'monthly' => array(
			'days'  => 30,
			'price' => '39',
		),
		'annual'  => array(
			'days'  => 365,
			'price' => '390',
		),
	);

	/**
	 * Call-to-action labels. Fixed, so no owner text can say "Featured",
	 * "Top pick" or claim an award.
	 *
	 * @return array<string, string>
	 */
	public static function cta_actions(): array {
		return array(
			'order'    => __( 'Order online', 'guam-restaurants-core' ),
			'reserve'  => __( 'Reserve a table', 'guam-restaurants-core' ),
			'menu'     => __( 'See the full menu', 'guam-restaurants-core' ),
			'catering' => __( 'Ask about catering', 'guam-restaurants-core' ),
			'events'   => __( 'Book an event', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Last day of the premium profile, or '' when there never was one.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function until( int $restaurant_id ): string {
		$until = (string) get_post_meta( $restaurant_id, self::UNTIL, true );
		return self::is_date( $until ) ? $until : '';
	}

	/**
	 * Whether the premium profile runs on a day (Guam).
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d.
	 * @return bool
	 */
	public static function is_active( int $restaurant_id, ?string $today = null ): bool {
		$until = self::until( $restaurant_id );
		return '' !== $until && $until >= ( $today ?? Server::today() );
	}

	/**
	 * Adds days. Time already paid for is kept: the new days start the day
	 * after the current end date, or today when it has lapsed.
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param int         $days          Days to add (at least 1).
	 * @param string      $note          Why, for the log (e.g. "Order #12").
	 * @param string|null $today         Y-m-d.
	 * @return array{from: string, until: string}|null Null when nothing was added.
	 */
	public static function extend( int $restaurant_id, int $days, string $note, ?string $today = null ): ?array {
		if ( $days < 1 || Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) ) {
			return null;
		}
		$today = $today ?? Server::today();
		$until = self::until( $restaurant_id );
		$from  = '' !== $until && $until >= $today ? self::add_days( $until, 1 ) : $today;
		$end   = self::add_days( $from, $days - 1 );
		self::store( $restaurant_id, $end, sprintf( '+%d', $days ), $note );
		return array(
			'from'  => $from,
			'until' => $end,
		);
	}

	/**
	 * Takes days off the end date (a refund or cancelled order). The end
	 * date may land in the past, which ends the premium profile.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param int    $days          Days to remove.
	 * @param string $note          Why.
	 * @return string The new end date, or '' when there was none.
	 */
	public static function shorten( int $restaurant_id, int $days, string $note ): string {
		$until = self::until( $restaurant_id );
		if ( '' === $until || $days < 1 ) {
			return $until;
		}
		$end = self::add_days( $until, -$days );
		self::store( $restaurant_id, $end, sprintf( '-%d', $days ), $note );
		return $end;
	}

	/**
	 * Sets the end date directly (administrators; '' removes it).
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $until         Y-m-d or ''.
	 * @param string $note          Why.
	 * @return bool Whether it changed.
	 */
	public static function set_until( int $restaurant_id, string $until, string $note ): bool {
		if ( ( '' !== $until && ! self::is_date( $until ) ) || self::until( $restaurant_id ) === $until ) {
			return false;
		}
		self::store( $restaurant_id, $until, 'set', $note );
		return true;
	}

	/**
	 * The change history, newest first.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array{at: int, until: string, change: string, note: string, user: int}>
	 */
	public static function log( int $restaurant_id ): array {
		$log = get_post_meta( $restaurant_id, self::LOG, true );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * The owner's call-to-action, or null.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{action: string, url: string}|null
	 */
	public static function cta( int $restaurant_id ): ?array {
		$cta = get_post_meta( $restaurant_id, self::CTA, true );
		if ( ! is_array( $cta ) || ! isset( self::cta_actions()[ $cta['action'] ?? '' ] ) || '' === (string) ( $cta['url'] ?? '' ) ) {
			return null;
		}
		return array(
			'action' => (string) $cta['action'],
			'url'    => (string) $cta['url'],
		);
	}

	/**
	 * How many owner photos the listing shows.
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d.
	 * @return int
	 */
	public static function max_photos( int $restaurant_id, ?string $today = null ): int {
		return self::is_active( $restaurant_id, $today ) ? self::MAX_PHOTOS : Owner_Content::MAX_PHOTOS;
	}

	/**
	 * Restaurants whose premium profile ends within $days (inclusive).
	 *
	 * @param string $today Y-m-d.
	 * @param int    $days  Days ahead.
	 * @return array<int, array{restaurant_id: int, until: string, days_left: int}>
	 */
	public static function ending( string $today, int $days ): array {
		$last = self::add_days( $today, $days );
		$ids  = get_posts(
			array(
				'post_type'        => Content_Types::POST_TYPE,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ids only, once a day.
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::UNTIL,
						'value'   => array( $today, $last ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
			)
		);
		$out  = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$until = self::until( $id );
			$out[] = array(
				'restaurant_id' => $id,
				'until'         => $until,
				'days_left'     => (int) ( new \DateTimeImmutable( $today ) )->diff( new \DateTimeImmutable( $until ) )->format( '%r%a' ),
			);
		}
		return $out;
	}

	/**
	 * Y-m-d plus days.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days (may be negative).
	 * @return string
	 */
	public static function add_days( string $date, int $days ): string {
		return ( new \DateTimeImmutable( $date ) )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	/**
	 * Whether a string is a real Y-m-d date.
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( string $date ): bool {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Saves the end date and logs the change.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $until         Y-m-d or ''.
	 * @param string $change        "+30", "-30" or "set".
	 * @param string $note          Why.
	 * @return void
	 */
	private static function store( int $restaurant_id, string $until, string $change, string $note ): void {
		if ( '' === $until ) {
			delete_post_meta( $restaurant_id, self::UNTIL );
		} else {
			update_post_meta( $restaurant_id, self::UNTIL, $until );
		}
		$log = self::log( $restaurant_id );
		array_unshift(
			$log,
			array(
				'at'     => time(),
				'until'  => $until,
				'change' => $change,
				'note'   => mb_substr( sanitize_text_field( $note ), 0, 200 ),
				'user'   => get_current_user_id(),
			)
		);
		update_post_meta( $restaurant_id, self::LOG, array_slice( $log, 0, self::LOG_MAX ) );
	}
}
