<?php
/**
 * Reads and writes the awards table.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; table names are built from constants and every value goes through prepare().

/**
 * Queries on `{prefix}gr_awards`. Rows whose restaurant is in the trash are
 * left out of every read.
 */
final class Repository {

	public const CREATED   = 'created';
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';

	/**
	 * Inserts or updates the award for (restaurant, program, year, category),
	 * the table's unique key, so the same result never appears twice.
	 *
	 * @param int                                                                                                             $restaurant_id Restaurant.
	 * @param array{program: string, year: int, category: string, placement: string, source_url: string, source_note: string} $award Validated award.
	 * @return string One of the count constants.
	 */
	public static function upsert( int $restaurant_id, array $award ): string {
		global $wpdb;

		$table    = Schema::table( Schema::AWARDS );
		$now      = current_time( 'mysql', true );
		$existing = self::find( $restaurant_id, $award['program'], (int) $award['year'], $award['category'] );
		$note     = '' === $award['source_note'] ? null : $award['source_note'];

		if ( null === $existing ) {
			$ok = $wpdb->insert(
				$table,
				array(
					'restaurant_id' => $restaurant_id,
					'program'       => $award['program'],
					'year'          => (int) $award['year'],
					'category'      => $award['category'],
					'placement'     => $award['placement'],
					'source_url'    => $award['source_url'],
					'source_note'   => $note,
					'verified'      => 0,
					'created_at'    => $now,
					'updated_at'    => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			return $ok ? self::CREATED : self::FAILED;
		}

		if ( $existing['placement'] === $award['placement'] && $existing['source_url'] === $award['source_url'] && (string) $existing['source_note'] === $award['source_note'] ) {
			return self::UNCHANGED;
		}

		$ok = $wpdb->update(
			$table,
			array(
				'placement'   => $award['placement'],
				'source_url'  => $award['source_url'],
				'source_note' => $note,
				'updated_at'  => $now,
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false === $ok ? self::FAILED : self::UPDATED;
	}

	/**
	 * One award by its unique key.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $program       Program.
	 * @param int    $year          Year.
	 * @param string $category      Category.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $restaurant_id, string $program, int $year, string $category ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::AWARDS );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE restaurant_id = %d AND program = %s AND year = %d AND category = %s", $restaurant_id, $program, $year, $category ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A restaurant's awards, newest first.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_restaurant( int $restaurant_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::AWARDS );
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE restaurant_id = %d ORDER BY year DESC, program ASC, category ASC", $restaurant_id ),
			ARRAY_A
		);
	}

	/**
	 * Awards matching the filters, newest year first, joined with the restaurant's title and status.
	 *
	 * @param array{program?: string, year?: int, category?: string} $filters Exact values; empty means any.
	 * @return array<int, array<string, mixed>>
	 */
	public static function query( array $filters = array() ): array {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['program'] ) ) {
			$where[] = 'a.program = %s';
			$args[]  = (string) $filters['program'];
		}
		if ( ! empty( $filters['year'] ) ) {
			$where[] = 'a.year = %d';
			$args[]  = (int) $filters['year'];
		}
		if ( isset( $filters['category'] ) && '' !== $filters['category'] ) {
			$where[] = 'a.category = %s';
			$args[]  = (string) $filters['category'];
		}

		$sql = 'SELECT a.*, p.post_title AS restaurant_name, p.post_status AS restaurant_status FROM ' . Schema::table( Schema::AWARDS ) . ' a' . self::join() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY a.year DESC, a.program ASC, a.category ASC, FIELD(a.placement, %s, %s, %s), p.post_title ASC';

		$args = array_merge( $args, array( 'winner', 'finalist', 'other' ) );
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; values are passed to prepare().
	}

	/**
	 * Distinct years, newest first.
	 *
	 * @param string $program Program, or '' for all.
	 * @return int[]
	 */
	public static function years( string $program = '' ): array {
		return array_map( 'intval', self::distinct( 'year', $program, 'a.year DESC' ) );
	}

	/**
	 * Distinct programs.
	 *
	 * @return string[]
	 */
	public static function programs(): array {
		return self::distinct( 'program', '', 'a.program ASC' );
	}

	/**
	 * Distinct category names.
	 *
	 * @param string $program Program, or '' for all.
	 * @return string[]
	 */
	public static function categories( string $program = '' ): array {
		return array_values( array_filter( self::distinct( 'category', $program, 'a.category ASC' ), 'strlen' ) );
	}

	/**
	 * The category name whose slug is $slug, or null.
	 *
	 * @param string $slug Slug from sanitize_title().
	 * @return string|null
	 */
	public static function category_by_slug( string $slug ): ?string {
		foreach ( self::categories() as $category ) {
			if ( sanitize_title( $category ) === $slug ) {
				return $category;
			}
		}
		return null;
	}

	/**
	 * Most-awarded restaurants, computed from our table only: ordered by
	 * wins, then finalist placings, then all results. Ties share a rank.
	 *
	 * @param string $category Category name, or '' for all categories.
	 * @param int    $limit    Maximum rows.
	 * @return array<int, array{rank: int, restaurant_id: int, restaurant_name: string, restaurant_status: string, wins: int, finalist: int, other: int, total: int}>
	 */
	public static function ranking( string $category = '', int $limit = 25 ): array {
		global $wpdb;

		$where = '';
		$args  = array( 'winner', 'finalist', 'other' );
		if ( '' !== $category ) {
			$where  = ' WHERE a.category = %s';
			$args[] = $category;
		}
		$args[] = max( 1, $limit );

		$sql  = 'SELECT a.restaurant_id, p.post_title AS restaurant_name, p.post_status AS restaurant_status,'
			. ' SUM(a.placement = %s) AS wins, SUM(a.placement = %s) AS finalist, SUM(a.placement = %s) AS other, COUNT(*) AS total'
			. ' FROM ' . Schema::table( Schema::AWARDS ) . ' a' . self::join() . $where
			. ' GROUP BY a.restaurant_id, p.post_title, p.post_status ORDER BY wins DESC, finalist DESC, total DESC, p.post_title ASC LIMIT %d';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders only.

		$ranked   = array();
		$previous = null;
		$rank     = 0;
		foreach ( $rows as $i => $row ) {
			$key = array( (int) $row['wins'], (int) $row['finalist'], (int) $row['total'] );
			if ( $key !== $previous ) {
				$rank     = $i + 1;
				$previous = $key;
			}
			$ranked[] = array(
				'rank'              => $rank,
				'restaurant_id'     => (int) $row['restaurant_id'],
				'restaurant_name'   => (string) $row['restaurant_name'],
				'restaurant_status' => (string) $row['restaurant_status'],
				'wins'              => (int) $row['wins'],
				'finalist'          => (int) $row['finalist'],
				'other'             => (int) $row['other'],
				'total'             => (int) $row['total'],
			);
		}
		return $ranked;
	}

	/**
	 * Per program and year: the categories recorded and how many results of each placement.
	 *
	 * @return array<string, array<int, array<string, array{winner: int, finalist: int, other: int}>>> program => year => category => counts.
	 */
	public static function coverage(): array {
		global $wpdb;
		$sql  = 'SELECT a.program, a.year, a.category, a.placement, COUNT(*) AS n FROM ' . Schema::table( Schema::AWARDS ) . ' a' . self::join() . ' GROUP BY a.program, a.year, a.category, a.placement ORDER BY a.program, a.year, a.category';
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no values.

		$coverage = array();
		foreach ( $rows as $row ) {
			$program  = (string) $row['program'];
			$year     = (int) $row['year'];
			$category = (string) $row['category'];
			if ( ! isset( $coverage[ $program ][ $year ][ $category ] ) ) {
				$coverage[ $program ][ $year ][ $category ] = array(
					'winner'   => 0,
					'finalist' => 0,
					'other'    => 0,
				);
			}
			$placement = in_array( $row['placement'], array( 'winner', 'finalist' ), true ) ? $row['placement'] : 'other';

			$coverage[ $program ][ $year ][ $category ][ $placement ] += (int) $row['n'];
		}
		return $coverage;
	}

	/**
	 * Distinct values of one column.
	 *
	 * @param string $column  year, program or category.
	 * @param string $program Program filter, or ''.
	 * @param string $order   ORDER BY clause (a constant from this class).
	 * @return string[]
	 */
	private static function distinct( string $column, string $program, string $order ): array {
		global $wpdb;
		$column = in_array( $column, array( 'year', 'program', 'category' ), true ) ? $column : 'year';
		$sql    = "SELECT DISTINCT a.{$column} FROM " . Schema::table( Schema::AWARDS ) . ' a' . self::join();
		if ( '' !== $program ) {
			$sql = $wpdb->prepare( $sql . ' WHERE a.program = %s', $program ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholder only.
		}
		return array_map( 'strval', (array) $wpdb->get_col( $sql . " ORDER BY {$order}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; order is a constant.
	}

	/**
	 * Join that drops awards of trashed or deleted restaurants.
	 *
	 * @return string
	 */
	private static function join(): string {
		global $wpdb;
		return " INNER JOIN {$wpdb->posts} p ON p.ID = a.restaurant_id AND p.post_type = '" . esc_sql( Content_Types::POST_TYPE ) . "' AND p.post_status NOT IN ('trash', 'auto-draft')";
	}
}
