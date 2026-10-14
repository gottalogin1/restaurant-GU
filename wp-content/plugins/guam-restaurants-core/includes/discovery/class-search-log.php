<?php
/**
 * The list of discovery searches and what each one last found.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Discovery;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Seed_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps every Text Search query discovery should run, when it last ran,
 * how many results it returned and how many of those were new. Discovery
 * runs queries that have never run first, then the ones whose re-check is
 * due (a week after their last run by default), so a run never repeats a
 * search that was just done.
 *
 * Queries come from five places:
 *
 * - `area`: "restaurants in <village or mall>, Guam" (the original queries).
 * - `type`: cafes, bakeries, bars, fast food and food trucks in each village.
 * - `cuisine`: "<cuisine> restaurants in Guam", one per cuisine term.
 * - `narrow`: "<cuisine> restaurants in <village>, Guam", added for a
 *   village whose area query filled all its result pages. Text Search
 *   returns at most 60 results per query, so a busy village (Tamuning,
 *   Dededo) hides places past the 60th until the search is split up.
 * - `custom`: added by hand with `wp guam-restaurants search-log add`.
 *
 * Only our own query text and counts are stored; nothing Google returns.
 */
final class Search_Log {

	public const SOURCE_GOOGLE = 'google';

	public const ORIGIN_AREA    = 'area';
	public const ORIGIN_TYPE    = 'type';
	public const ORIGIN_CUISINE = 'cuisine';
	public const ORIGIN_NARROW  = 'narrow';
	public const ORIGIN_CUSTOM  = 'custom';

	public const DEFAULT_RECHECK_DAYS = 7;

	/**
	 * Place types searched in every village; %s is the village name.
	 */
	public const TYPE_TEMPLATES = array(
		'cafes in %s, Guam',
		'bakeries in %s, Guam',
		'bars in %s, Guam',
		'fast food in %s, Guam',
		'food trucks in %s, Guam',
	);

	/**
	 * Island-wide cuisine query; %s is the cuisine name.
	 */
	public const CUISINE_TEMPLATE = '%s restaurants in Guam';

	/**
	 * Narrowed query; %1$s is the cuisine, %2$s the village.
	 */
	public const NARROW_TEMPLATE = '%1$s restaurants in %2$s, Guam';

	/**
	 * Adds every planned query that is not in the log yet. Safe to run
	 * every time: existing rows (and their history) are left alone.
	 *
	 * @return int Queries added.
	 */
	public static function sync(): int {
		$added = 0;

		foreach ( Discoverer::areas() as $area ) {
			$added += self::add( sprintf( Discoverer::QUERY_TEMPLATE, $area['name'] ), self::ORIGIN_AREA, $area['kind'], $area['slug'], $area['village'] );
		}

		foreach ( Seed_Data::villages() as $slug => $village ) {
			foreach ( self::TYPE_TEMPLATES as $template ) {
				$added += self::add( sprintf( $template, $village['name'] ), self::ORIGIN_TYPE, 'village', $slug, $slug );
			}
		}

		foreach ( self::cuisine_names() as $name ) {
			$added += self::add( sprintf( self::CUISINE_TEMPLATE, $name ), self::ORIGIN_CUISINE, 'island', '', '' );
		}

		return $added;
	}

	/**
	 * Adds the narrowed cuisine queries for a village whose search filled
	 * every result page.
	 *
	 * @param string $village_slug Village slug.
	 * @return int Queries added.
	 */
	public static function narrow( string $village_slug ): int {
		$villages = Seed_Data::villages();
		if ( ! isset( $villages[ $village_slug ] ) ) {
			return 0;
		}
		$added = 0;
		foreach ( self::cuisine_names() as $name ) {
			$added += self::add( sprintf( self::NARROW_TEMPLATE, $name, $villages[ $village_slug ]['name'] ), self::ORIGIN_NARROW, 'village', $village_slug, $village_slug );
		}
		return $added;
	}

	/**
	 * Adds one query if it is new.
	 *
	 * @param string $query     Query text.
	 * @param string $origin    One of the ORIGIN_ constants.
	 * @param string $area_kind village, mall or island.
	 * @param string $area_slug Village or mall slug, '' for island.
	 * @param string $village   Village slug used to match listed restaurants, ''.
	 * @param string $source    Source.
	 * @return int 1 when added, 0 when it was already there.
	 */
	public static function add( string $query, string $origin, string $area_kind, string $area_slug = '', string $village = '', string $source = self::SOURCE_GOOGLE ): int {
		global $wpdb;

		$query = trim( preg_replace( '/\s+/', ' ', $query ) );
		if ( '' === $query ) {
			return 0;
		}

		$table = Schema::table( Schema::SEARCH_LOG );
		// INSERT IGNORE: the unique (source, query_hash) key keeps an existing row and its history.
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (source, query, query_hash, origin, area_kind, area_slug, village, added_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$source,
				mb_substr( $query, 0, 255 ),
				self::hash( $query ),
				$origin,
				$area_kind,
				$area_slug,
				$village,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Queries to run now: never-run ones first (oldest added first), then
	 * those whose re-check is due (longest overdue first).
	 *
	 * @param string   $scope  all, villages, malls or island.
	 * @param int|null $limit  Maximum rows; null for all.
	 * @param string   $source Source.
	 * @return array<int, array<string, mixed>>
	 */
	public static function due( string $scope = 'all', ?int $limit = null, string $source = self::SOURCE_GOOGLE ): array {
		global $wpdb;

		$table = Schema::table( Schema::SEARCH_LOG );
		$kinds = array(
			'villages' => 'village',
			'malls'    => 'mall',
			'island'   => 'island',
		);
		$where = isset( $kinds[ $scope ] ) ? $wpdb->prepare( ' AND area_kind = %s', $kinds[ $scope ] ) : '';
		$limit = null === $limit ? '' : $wpdb->prepare( ' LIMIT %d', max( 1, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where and $limit are prepared above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source = %s AND active = 1 AND ( last_run_at IS NULL OR next_due_at <= %s ){$where}
				ORDER BY ( last_run_at IS NULL ) DESC, next_due_at ASC, id ASC{$limit}",
				$source,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	/**
	 * Records one finished search.
	 *
	 * @param int      $id           Row.
	 * @param int      $results      Results returned.
	 * @param int      $new_count    Results newly queued.
	 * @param bool     $saturated    Every result page was full and more remained.
	 * @param int|null $run_id       Ingestion run.
	 * @param int      $recheck_days Days until it is due again.
	 * @return void
	 */
	public static function record( int $id, int $results, int $new_count, bool $saturated, ?int $run_id, int $recheck_days = self::DEFAULT_RECHECK_DAYS ): void {
		global $wpdb;

		$table = Schema::table( Schema::SEARCH_LOG );
		$now   = time();
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET last_run_at = %s, next_due_at = %s, runs = runs + 1, last_results = %d, last_new = %d, total_new = total_new + %d, saturated = %d, last_run_id = NULLIF( %d, 0 ), last_error = NULL WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + max( 1, $recheck_days ) * DAY_IN_SECONDS ),
				min( 65535, $results ),
				min( 65535, $new_count ),
				$new_count,
				$saturated ? 1 : 0,
				(int) $run_id,
				$id
			)
		);
	}

	/**
	 * Records a failed search. It stays due, so the next run tries again.
	 *
	 * @param int    $id    Row.
	 * @param string $error Error text.
	 * @return void
	 */
	public static function record_error( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( Schema::SEARCH_LOG ),
			array( 'last_error' => mb_substr( $error, 0, 255 ) ),
			array( 'id' => $id )
		);
	}

	/**
	 * Turns a query on or off.
	 *
	 * @param int  $id     Row.
	 * @param bool $active Active.
	 * @return bool
	 */
	public static function set_active( int $id, bool $active ): bool {
		global $wpdb;
		return false !== $wpdb->update( Schema::table( Schema::SEARCH_LOG ), array( 'active' => $active ? 1 : 0 ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Every row, for listing.
	 *
	 * @param string $source Source.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( string $source = self::SOURCE_GOOGLE ): array {
		global $wpdb;
		$table = Schema::table( Schema::SEARCH_LOG );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s ORDER BY id", $source ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	/**
	 * Totals for the status command.
	 *
	 * @param string $source Source.
	 * @return array{queries: int, active: int, never_run: int, due: int, searched_7d: int, new_7d: int, total_new: int, saturated: int, errors: int}
	 */
	public static function summary( string $source = self::SOURCE_GOOGLE ): array {
		$rows    = self::all( $source );
		$now     = current_time( 'mysql', true );
		$week    = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
		$summary = array_fill_keys( array( 'queries', 'active', 'never_run', 'due', 'searched_7d', 'new_7d', 'total_new', 'saturated', 'errors' ), 0 );

		foreach ( $rows as $row ) {
			++$summary['queries'];
			$summary['total_new'] += $row['total_new'];
			if ( ! $row['active'] ) {
				continue;
			}
			++$summary['active'];
			if ( null === $row['last_run_at'] ) {
				++$summary['never_run'];
			} elseif ( $row['next_due_at'] <= $now ) {
				++$summary['due'];
			}
			if ( null !== $row['last_run_at'] && $row['last_run_at'] >= $week ) {
				++$summary['searched_7d'];
				$summary['new_7d'] += $row['last_new'];
			}
			$summary['saturated'] += $row['saturated'] ? 1 : 0;
			$summary['errors']    += null === $row['last_error'] ? 0 : 1;
		}
		return $summary;
	}

	/**
	 * Cuisine term names (seeded terms plus any added by hand).
	 *
	 * @return string[]
	 */
	private static function cuisine_names(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_CUISINE,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			$terms = array_column( Seed_Data::cuisines(), 'name' );
		}
		return array_values( array_unique( array_map( 'strval', $terms ) ) );
	}

	/**
	 * Case- and space-insensitive key for a query.
	 *
	 * @param string $query Query.
	 * @return string
	 */
	private static function hash( string $query ): string {
		return md5( mb_strtolower( trim( preg_replace( '/\s+/', ' ', $query ) ) ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function cast( array $row ): array {
		foreach ( array( 'id', 'runs', 'last_results', 'last_new', 'total_new' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		$row['active']      = (bool) (int) $row['active'];
		$row['saturated']   = (bool) (int) $row['saturated'];
		$row['last_run_id'] = null === $row['last_run_id'] ? null : (int) $row['last_run_id'];
		return $row;
	}
}
