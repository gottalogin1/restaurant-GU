<?php
/**
 * "Korean restaurants in Tumon": a village page narrowed to one cuisine.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * A village page with one cuisine chosen and nothing else
 * (/villages/tumon/?cuisine=korean) answers searches like "Korean
 * restaurants Tumon", so it is a page of its own for search engines when
 * at least MIN_PLACES open restaurants match (SEO plan gap G1; the
 * threshold is Sanjay's, 2026-10-26). Below it, the URL stays a noindex
 * filter like any other, so thin pages are never offered (Google's scaled
 * content policy).
 *
 * Such a page gets its own heading and title ("Korean Restaurants in
 * Tumon, Guam"), description, canonical link, ItemList and sitemap entry
 * (Seo, Structured_Data, Sitemaps), and village and cuisine pages link to
 * it (Related_Links). Order is the directory's own; nothing paid changes
 * which pages exist (CLAUDE.md rule 5).
 */
final class Cuisine_Villages {

	/**
	 * Open restaurants a village and cuisine need for a page of their own.
	 */
	public const MIN_PLACES = 5;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		// After Translation\Integration's, which wraps the village name.
		add_filter( 'get_the_archive_title', array( self::class, 'archive_title' ), 20 );
	}

	/**
	 * Every village and cuisine pair with a page, most places first.
	 *
	 * @return array<int, array{village: int, cuisine: int, count: int}> Term IDs.
	 */
	public static function all(): array {
		return Cache::remember(
			'cuisine-villages',
			array( self::MIN_PLACES ),
			static function () {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate over core tables; cached.
				$rows  = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT tv.term_id AS village, tc.term_id AS cuisine, COUNT(DISTINCT p.ID) AS places
						FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->term_relationships} rv ON rv.object_id = p.ID
						INNER JOIN {$wpdb->term_taxonomy} tv ON tv.term_taxonomy_id = rv.term_taxonomy_id AND tv.taxonomy = %s
						INNER JOIN {$wpdb->term_relationships} rc ON rc.object_id = p.ID
						INNER JOIN {$wpdb->term_taxonomy} tc ON tc.term_taxonomy_id = rc.term_taxonomy_id AND tc.taxonomy = %s
						WHERE p.post_type = %s AND p.post_status = 'publish'
						AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s)
						GROUP BY tv.term_id, tc.term_id
						HAVING places >= %d
						ORDER BY places DESC, tv.term_id ASC, tc.term_id ASC",
						Content_Types::TAX_VILLAGE,
						Content_Types::TAX_CUISINE,
						Content_Types::POST_TYPE,
						Restaurant_Meta::STATUS,
						'permanently_closed',
						self::MIN_PLACES
					),
					ARRAY_A
				);
				$pairs = array();
				foreach ( (array) $rows as $row ) {
					$pairs[] = array(
						'village' => (int) $row['village'],
						'cuisine' => (int) $row['cuisine'],
						'count'   => (int) $row['places'],
					);
				}
				return $pairs;
			},
			HOUR_IN_SECONDS
		);
	}

	/**
	 * The pair for a village and a cuisine, or null when it has no page.
	 *
	 * @param int $village Village term ID.
	 * @param int $cuisine Cuisine term ID.
	 * @return array{village: \WP_Term, cuisine: \WP_Term, count: int}|null
	 */
	public static function find( int $village, int $cuisine ): ?array {
		foreach ( self::all() as $pair ) {
			if ( $pair['village'] === $village && $pair['cuisine'] === $cuisine ) {
				$village_term = get_term( $village, Content_Types::TAX_VILLAGE );
				$cuisine_term = get_term( $cuisine, Content_Types::TAX_CUISINE );
				if ( $village_term instanceof \WP_Term && $cuisine_term instanceof \WP_Term ) {
					return array(
						'village' => $village_term,
						'cuisine' => $cuisine_term,
						'count'   => $pair['count'],
					);
				}
			}
		}
		return null;
	}

	/**
	 * The pair these filters show, or null: a village page with exactly one
	 * cuisine and no other filter or sort (the page number may vary).
	 *
	 * @param array<string, mixed>  $filters Filters::from_params().
	 * @param array<string, string> $locked  Facet => slug fixed by the page.
	 * @return array{village: \WP_Term, cuisine: \WP_Term, count: int}|null
	 */
	public static function for_filters( array $filters, array $locked ): ?array {
		if ( array( 'village' ) !== array_keys( $locked ) ) {
			return null;
		}
		$cuisines = (array) ( $filters['facets']['cuisine'] ?? array() );
		if ( 1 !== count( $cuisines ) ) {
			return null;
		}
		$expected                      = Filters::defaults();
		$expected['facets']['village'] = array( $locked['village'] );
		$expected['facets']['cuisine'] = $cuisines;
		if ( Filters::to_params( $filters ) !== Filters::to_params( $expected ) ) {
			return null;
		}
		$village = get_term_by( 'slug', $locked['village'], Content_Types::TAX_VILLAGE );
		$cuisine = get_term_by( 'slug', (string) reset( $cuisines ), Content_Types::TAX_CUISINE );
		return $village instanceof \WP_Term && $cuisine instanceof \WP_Term ? self::find( $village->term_id, $cuisine->term_id ) : null;
	}

	/**
	 * The pair the current page shows, or null.
	 *
	 * @return array{village: \WP_Term, cuisine: \WP_Term, count: int}|null
	 */
	public static function current(): ?array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || ! Directory_Query::is_directory( $wp_query ) ) {
			return null;
		}
		return self::for_filters( Directory_Query::current_filters( $wp_query ), Directory_Query::locked( $wp_query ) );
	}

	/**
	 * Page address, page 1 or a later page.
	 *
	 * @param \WP_Term $village Village.
	 * @param \WP_Term $cuisine Cuisine.
	 * @param int      $page    Page.
	 * @return string
	 */
	public static function url( \WP_Term $village, \WP_Term $cuisine, int $page = 1 ): string {
		$filters                      = Filters::defaults();
		$filters['facets']['village'] = array( $village->slug );
		$filters['facets']['cuisine'] = array( $cuisine->slug );
		return Directory_View::page_url( $filters, array( 'village' => $village->slug ), $page );
	}

	/**
	 * Heading and title, without the site name.
	 *
	 * @param \WP_Term $village Village.
	 * @param \WP_Term $cuisine Cuisine.
	 * @return string
	 */
	public static function title( \WP_Term $village, \WP_Term $cuisine ): string {
		/* translators: 1: cuisine, 2: village. */
		return sprintf( __( '%1$s Restaurants in %2$s, Guam', 'guam-restaurants-core' ), $cuisine->name, $village->name );
	}

	/**
	 * Description in plain text.
	 *
	 * @param array{village: \WP_Term, cuisine: \WP_Term, count: int} $pair Pair.
	 * @return string
	 */
	public static function description( array $pair ): string {
		return sprintf(
			/* translators: 1: number of restaurants, 2: cuisine, 3: village. */
			_n( '%1$s %2$s restaurant in %3$s, Guam.', '%1$s %2$s restaurants in %3$s, Guam.', $pair['count'], 'guam-restaurants-core' ),
			number_format_i18n( $pair['count'] ),
			$pair['cuisine']->name,
			$pair['village']->name
		) . ' ' . __( 'See hours, maps, awards and reviews for each.', 'guam-restaurants-core' );
	}

	/**
	 * Heading for directory results on a village page, for the script that
	 * reloads results in place: the pair's title, else the village's name.
	 *
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug fixed by the page.
	 * @return string '' when the page is not a village page.
	 */
	public static function heading( array $filters, array $locked ): string {
		$pair = self::for_filters( $filters, $locked );
		if ( null !== $pair ) {
			return self::title( $pair['village'], $pair['cuisine'] );
		}
		if ( array( 'village' ) !== array_keys( $locked ) ) {
			return '';
		}
		$village = get_term_by( 'slug', $locked['village'], Content_Types::TAX_VILLAGE );
		return $village instanceof \WP_Term ? $village->name : '';
	}

	/**
	 * The page heading (Query Title block) on a pair's page.
	 *
	 * @param string $title Archive title.
	 * @return string
	 */
	public static function archive_title( $title ): string {
		$pair = is_tax( Content_Types::TAX_VILLAGE ) ? self::current() : null;
		if ( null === $pair ) {
			return (string) $title;
		}
		/* translators: 1: cuisine, 2: village. */
		return sprintf( esc_html__( '%1$s Restaurants in %2$s, Guam', 'guam-restaurants-core' ), esc_html( $pair['cuisine']->name ), Languages::keep( esc_html( $pair['village']->name ) ) );
	}
}
