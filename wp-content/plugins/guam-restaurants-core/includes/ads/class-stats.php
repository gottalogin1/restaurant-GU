<?php
/**
 * Daily ad counters.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `gr_ad_daily_stats`: one row per ad, Guam date and page
 * type with impression and click totals. No visitor data of any kind is
 * stored (design/content-model.md §5.7).
 */
final class Stats {

	/**
	 * Adds to an ad's counters for a day.
	 *
	 * @param int    $ad_id       Ad.
	 * @param string $date        Y-m-d (Guam).
	 * @param string $page_type   Page type.
	 * @param int    $impressions Impressions to add.
	 * @param int    $clicks      Clicks to add.
	 * @return void
	 */
	public static function add( int $ad_id, string $date, string $page_type, int $impressions, int $clicks ): void {
		global $wpdb;
		if ( $ad_id <= 0 || ( $impressions <= 0 && $clicks <= 0 ) ) {
			return;
		}
		$table = Schema::table( Schema::AD_DAILY_STATS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter upsert.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (ad_id, stat_date, page_type, impressions, clicks) VALUES (%d, %s, %s, %d, %d) ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions), clicks = clicks + VALUES(clicks)',
				$table,
				$ad_id,
				$date,
				$page_type,
				max( 0, $impressions ),
				max( 0, $clicks )
			)
		);
	}

	/**
	 * Impressions on one day for several ads, all page types together.
	 *
	 * @param int[]  $ad_ids Ads.
	 * @param string $date   Y-m-d.
	 * @return array<int, int> Ad ID => impressions.
	 */
	public static function impressions_on( array $ad_ids, string $date ): array {
		global $wpdb;
		$ad_ids = array_values( array_filter( array_map( 'intval', $ad_ids ) ) );
		if ( ! $ad_ids ) {
			return array();
		}
		$table = Schema::table( Schema::AD_DAILY_STATS );
		$ids   = implode( ',', array_fill( 0, count( $ad_ids ), '%d' ) );
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT ad_id, SUM(impressions) AS impressions FROM {$table} WHERE stat_date = %s AND ad_id IN ({$ids}) GROUP BY ad_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $date ), $ad_ids )
			),
			ARRAY_A
		);
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['ad_id'] ] = (int) $row['impressions'];
		}
		return $out;
	}

	/**
	 * Totals per ad and page type between two dates (inclusive).
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int, array{ad_id: int, page_type: string, impressions: int, clicks: int}>
	 */
	public static function totals( string $from, string $to ): array {
		global $wpdb;
		$table = Schema::table( Schema::AD_DAILY_STATS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- report on demand.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ad_id, page_type, SUM(impressions) AS impressions, SUM(clicks) AS clicks FROM %i WHERE stat_date BETWEEN %s AND %s GROUP BY ad_id, page_type ORDER BY ad_id, page_type',
				$table,
				$from,
				$to
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'ad_id'       => (int) $row['ad_id'],
				'page_type'   => (string) $row['page_type'],
				'impressions' => (int) $row['impressions'],
				'clicks'      => (int) $row['clicks'],
			);
		}
		return $out;
	}
}
