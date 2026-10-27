<?php
/**
 * Directory listings: the restaurant archive and the village, mall,
 * cuisine and price pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Village_Aliases;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb and Schema constants; values go through prepare().

/**
 * Turns Filters into one WP_Query, used both by the main query of the
 * archive pages (so WordPress pages and 404s them as usual) and by the REST
 * endpoint the filter form calls.
 *
 * Everything is a database filter on indexed columns: taxonomy clauses use
 * the term relationship keys, "open now", "open late", "open at" and
 * "breakfast" the gr_open_hours index, "award
 * winner" the awards table's restaurant key, and the closed check the
 * postmeta (post_id, meta_key) key. Ranking is plain (name, date, price or
 * search relevance); nothing paid takes part in it (CLAUDE.md rule 5).
 * Permanently closed restaurants are left out of listings; they keep their
 * pages and appear in the recent closures feed.
 */
final class Directory_Query {

	public const PER_PAGE = 24;

	/**
	 * Query var carrying the extra conditions to posts_clauses().
	 */
	public const QUERY_VAR = 'gr_directory';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pre_get_posts', array( self::class, 'filter_main_query' ) );
		add_filter( 'posts_clauses', array( self::class, 'posts_clauses' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'no_page_cache_for_open_now' ) );
	}

	/**
	 * Whether a query is a front-end directory listing.
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	public static function is_directory( \WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() || $query->is_feed() ) {
			return false;
		}
		return $query->is_post_type_archive( Content_Types::POST_TYPE ) || $query->is_tax( array_values( Filters::FACETS ) );
	}

	/**
	 * The facet fixed by a taxonomy page, for example array( 'village' => 'dededo' ).
	 *
	 * @param \WP_Query|null $query Query; defaults to the main query.
	 * @return array<string, string>
	 */
	public static function locked( ?\WP_Query $query = null ): array {
		global $wp_query;
		$query = $query ?? $wp_query;
		if ( ! $query instanceof \WP_Query ) {
			return array();
		}
		foreach ( Filters::FACETS as $param => $taxonomy ) {
			if ( $query->is_tax( $taxonomy ) ) {
				$slug = (string) $query->get( $taxonomy );
				if ( '' === $slug ) {
					$term = $query->get_queried_object();
					$slug = $term instanceof \WP_Term ? $term->slug : '';
				}
				return '' === $slug ? array() : array( $param => sanitize_title( $slug ) );
			}
		}
		return array();
	}

	/**
	 * Filters for the current request.
	 *
	 * @param \WP_Query|null $query Query; defaults to the main query.
	 * @return array<string, mixed>
	 */
	public static function current_filters( ?\WP_Query $query = null ): array {
		// Read-only listing filters on a public page; no state changes, so no nonce.
		$params  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Filters::from_params() sanitizes each value.
		$filters = Filters::from_params( is_array( $params ) ? $params : array(), self::locked( $query ) );
		global $wp_query;
		$query = $query ?? $wp_query;
		if ( $query instanceof \WP_Query ) {
			$filters['page'] = max( 1, (int) $query->get( 'paged' ) );
		}
		return $filters;
	}

	/**
	 * Applies the request's filters to the main query of directory pages.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function filter_main_query( \WP_Query $query ): void {
		if ( ! self::is_directory( $query ) ) {
			return;
		}
		$filters = self::current_filters( $query );
		foreach ( self::args( $filters ) as $key => $value ) {
			if ( 'paged' === $key ) {
				continue;
			}
			$query->set( $key, $value );
		}
	}

	/**
	 * WP_Query arguments for a set of filters.
	 *
	 * @param array<string, mixed> $filters  Filters::from_params().
	 * @param int                  $per_page Results per page; -1 for all.
	 * @return array<string, mixed>
	 */
	public static function args( array $filters, int $per_page = self::PER_PAGE ): array {
		$tax_query = array();
		$search    = (string) $filters['q'];
		if ( '' !== $search && ! Query_Parser::is_literal( $search ) ) {
			$match = Village_Aliases::extract( $search );
			if ( $match['village_id'] ) {
				$search      = $match['search'];
				$tax_query[] = array(
					'taxonomy' => Content_Types::TAX_VILLAGE,
					'field'    => 'term_id',
					'terms'    => array( (int) $match['village_id'] ),
				);
			}
		}
		foreach ( Filters::FACETS as $param => $taxonomy ) {
			if ( ! empty( $filters['facets'][ $param ] ) ) {
				$tax_query[] = array(
					'taxonomy'         => $taxonomy,
					'field'            => 'slug',
					'terms'            => $filters['facets'][ $param ],
					'include_children' => false,
				);
			}
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}

		$sort = Filters::effective_sort( $filters );
		if ( 'relevance' === $sort && '' === $search ) {
			$sort = 'name';
		}
		$extra = array(
			'award'      => ! empty( $filters['award'] ),
			'open_at'    => ! empty( $filters['open'] ) ? Clock::minute_of_week() : null,
			'late_at'    => ! empty( $filters['late'] ) ? Clock::late_minute() : null,
			'chosen_at'  => Filters::at_minute( (string) ( $filters['at'] ?? '' ) ),
			'breakfast'  => ! empty( $filters['breakfast'] ),
			'price_sort' => in_array( $sort, array( 'price_asc', 'price_desc' ), true ) ? $sort : '',
		);

		$args = array(
			'post_type'           => Content_Types::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => max( 1, (int) $filters['page'] ),
			'ignore_sticky_posts' => true,
			'tax_query'           => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- indexed term relationships.
			self::QUERY_VAR       => $extra,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		switch ( $sort ) {
			case 'newest':
				$args['orderby'] = array(
					'date' => 'DESC',
					'ID'   => 'DESC',
				);
				break;
			case 'relevance':
				$args['orderby'] = 'relevance';
				break;
			case 'price_asc':
			case 'price_desc':
				// Ordered in posts_clauses().
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
				break;
			default:
				$args['orderby'] = 'title';
				$args['order']   = 'ASC';
		}
		return $args;
	}

	/**
	 * Runs a directory query outside the main loop (REST, blocks).
	 *
	 * @param array<string, mixed> $filters  Filters.
	 * @param int                  $per_page Results per page; -1 for all.
	 * @param array<string, mixed> $override Extra WP_Query arguments.
	 * @return \WP_Query
	 */
	public static function query( array $filters, int $per_page = self::PER_PAGE, array $override = array() ): \WP_Query {
		return new \WP_Query( array_merge( self::args( $filters, $per_page ), $override ) );
	}

	/**
	 * Adds the award, opening-hours, closed and price conditions.
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param \WP_Query             $query   Query.
	 * @return array<string, string>
	 */
	public static function posts_clauses( $clauses, $query ): array {
		$clauses = (array) $clauses;
		$extra   = $query instanceof \WP_Query ? $query->get( self::QUERY_VAR ) : null;
		if ( ! is_array( $extra ) ) {
			return $clauses;
		}
		global $wpdb;
		$posts = $wpdb->posts;

		$clauses['where'] .= $wpdb->prepare(
			" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} gr_status WHERE gr_status.post_id = {$posts}.ID AND gr_status.meta_key = %s AND gr_status.meta_value = %s)",
			Restaurant_Meta::STATUS,
			'permanently_closed'
		);
		if ( ! empty( $extra['award'] ) ) {
			$clauses['where'] .= $wpdb->prepare(
				' AND EXISTS (SELECT 1 FROM ' . Schema::table( Schema::AWARDS ) . " gr_aw WHERE gr_aw.restaurant_id = {$posts}.ID AND gr_aw.placement = %s)",
				'winner'
			);
		}
		if ( isset( $extra['open_at'] ) && null !== $extra['open_at'] ) {
			$clauses['where'] .= ' AND ' . Special_Hours::open_sql( Clock::now(), $posts, Open_Hours::open_sql( (int) $extra['open_at'], $posts ) );
		}
		if ( isset( $extra['late_at'] ) && null !== $extra['late_at'] ) {
			$clauses['where'] .= ' AND ' . Special_Hours::open_sql( Clock::instant( (int) $extra['late_at'] ), $posts, Open_Hours::open_sql( (int) $extra['late_at'], $posts ) );
		}
		if ( isset( $extra['chosen_at'] ) && null !== $extra['chosen_at'] ) {
			$clauses['where'] .= ' AND ' . Special_Hours::open_sql( Clock::instant( (int) $extra['chosen_at'] ), $posts, Open_Hours::open_sql( (int) $extra['chosen_at'], $posts ) );
		}
		if ( ! empty( $extra['breakfast'] ) ) {
			$clauses['where'] .= ' AND ' . Open_Hours::open_any_day_sql( Filters::BREAKFAST_MINUTE, $posts );
		}
		if ( ! empty( $extra['price_sort'] ) ) {
			$rank               = $wpdb->prepare(
				"(SELECT MIN(CAST(gr_tm.meta_value AS UNSIGNED)) FROM {$wpdb->term_relationships} gr_tr INNER JOIN {$wpdb->term_taxonomy} gr_tt ON gr_tt.term_taxonomy_id = gr_tr.term_taxonomy_id AND gr_tt.taxonomy = %s INNER JOIN {$wpdb->termmeta} gr_tm ON gr_tm.term_id = gr_tt.term_id AND gr_tm.meta_key = %s WHERE gr_tr.object_id = {$posts}.ID)",
				Content_Types::TAX_PRICE,
				Term_Meta::RANK
			);
			$direction          = 'price_desc' === $extra['price_sort'] ? 'DESC' : 'ASC';
			$clauses['orderby'] = "{$rank} IS NULL ASC, {$rank} {$direction}, {$posts}.post_title ASC";
		}
		return $clauses;
	}

	/**
	 * "Open now", "open late" and "open today at" results change with the clock, so keep them out of page caches.
	 *
	 * @return void
	 */
	public static function no_page_cache_for_open_now(): void {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query && self::is_directory( $wp_query ) && Filters::is_timed( self::current_filters() ) && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constant read by page cache plugins.
		}
	}
}
