<?php
/**
 * Search engine details the SEO plugin does not know about.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Sitemaps come from the SEO plugin (Yoast SEO, docs/plugins.md), or from
 * WordPress core when none is active. This class only:
 *
 * - keeps unverified malls out of both sitemaps;
 * - marks filtered and sorted listing URLs noindex,follow, so the endless
 *   filter combinations do not compete with the clean village, mall and
 *   cuisine pages;
 * - prints a meta description on directory pages when no SEO plugin does.
 */
final class Seo {

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( self::class, 'core_sitemap_terms' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_term_ids', array( self::class, 'yoast_excluded_terms' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
		add_filter( 'wpseo_robots_array', array( self::class, 'yoast_robots' ) );
		add_action( 'wp_head', array( self::class, 'meta_description' ), 1 );
	}

	/**
	 * Core sitemap: verified malls only.
	 *
	 * @param array<string, mixed> $args     get_terms() arguments.
	 * @param string               $taxonomy Taxonomy.
	 * @return array<string, mixed>
	 */
	public static function core_sitemap_terms( $args, $taxonomy ): array {
		$args = (array) $args;
		if ( Content_Types::TAX_MALL === $taxonomy ) {
			$args['exclude'] = array_merge( (array) ( $args['exclude'] ?? array() ), self::unverified_mall_ids() );
		}
		return $args;
	}

	/**
	 * Yoast sitemap: excluded term IDs.
	 *
	 * @param int[] $ids Term IDs.
	 * @return int[]
	 */
	public static function yoast_excluded_terms( $ids ): array {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), self::unverified_mall_ids() ) ) );
	}

	/**
	 * IDs of unverified malls.
	 *
	 * @return int[]
	 */
	public static function unverified_mall_ids(): array {
		$ids = \GuamRestaurants\Core\Mall_Verification::with_unverified(
			static function () {
				return get_terms(
					array(
						'taxonomy'   => Content_Types::TAX_MALL,
						'hide_empty' => false,
						'fields'     => 'ids',
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a handful of malls.
							'relation' => 'OR',
							array(
								'key'     => Term_Meta::VERIFIED,
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => Term_Meta::VERIFIED,
								'value'   => '1',
								'compare' => '!=',
							),
						),
					)
				);
			}
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Whether the current page is a filtered or sorted listing.
	 *
	 * @return bool
	 */
	public static function is_filtered_listing(): bool {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || ! Directory_Query::is_directory( $wp_query ) ) {
			return false;
		}
		return Filters::is_filtered( Directory_Query::current_filters(), Directory_Query::locked() );
	}

	/**
	 * Core robots meta.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		if ( self::is_filtered_listing() ) {
			unset( $robots['index'], $robots['max-image-preview'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * Yoast robots meta.
	 *
	 * @param array<string, string> $robots Directives.
	 * @return array<string, string>
	 */
	public static function yoast_robots( $robots ): array {
		$robots = (array) $robots;
		if ( self::is_filtered_listing() ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}
		return $robots;
	}

	/**
	 * Whether an SEO plugin prints descriptions.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'SQ_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' );
	}

	/**
	 * A plain description for directory pages without an SEO plugin.
	 *
	 * @return string
	 */
	public static function description(): string {
		if ( is_singular( Content_Types::POST_TYPE ) ) {
			$id      = (int) get_queried_object_id();
			$parts   = array_filter(
				array(
					Restaurant_View::term_names( $id, Content_Types::TAX_CUISINE ),
					implode( ', ', array_filter( array( Restaurant_View::term_names( $id, Content_Types::TAX_MALL ), Restaurant_View::term_names( $id, Content_Types::TAX_VILLAGE ) ) ) ),
				)
			);
			$excerpt = has_excerpt( $id ) ? wp_strip_all_tags( get_the_excerpt( $id ) ) : '';
			/* translators: 1: restaurant name, 2: cuisine and place. */
			$text = $parts ? sprintf( __( '%1$s: %2$s, Guam. Hours, map, awards and reviews.', 'guam-restaurants-core' ), get_the_title( $id ), implode( ' in ', $parts ) ) : sprintf( __( '%1$s, Guam. Hours, map, awards and reviews.', 'guam-restaurants-core' ), get_the_title( $id ) );
			return '' !== $excerpt ? $excerpt : $text;
		}
		if ( is_tax( array_values( Filters::FACETS ) ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$intro = wp_strip_all_tags( term_description( $term ) );
				return '' !== $intro ? $intro : Intro::default_text( $term );
			}
		}
		if ( is_post_type_archive( Content_Types::POST_TYPE ) ) {
			return __( 'Find restaurants across Guam by village, mall, cuisine and price, with hours, awards and reviews.', 'guam-restaurants-core' );
		}
		if ( '' !== \GuamRestaurants\Core\Awards\Pages::current_page() ) {
			return __( 'Restaurant award results recorded on this site, each with a link to where it was published.', 'guam-restaurants-core' );
		}
		if ( is_front_page() ) {
			$tagline = (string) get_bloginfo( 'description' );
			return '' !== $tagline ? $tagline : __( 'Find a place to eat on Guam: search restaurants by name or dish, or browse by village, mall and cuisine.', 'guam-restaurants-core' );
		}
		if ( is_page() ) {
			$id = (int) get_queried_object_id();
			return has_excerpt( $id ) ? (string) get_the_excerpt( $id ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( (string) get_post_field( 'post_content', $id ) ) ) ), 30, '' );
		}
		return '';
	}

	/**
	 * Prints the description.
	 *
	 * @return void
	 */
	public static function meta_description(): void {
		if ( self::seo_plugin_active() ) {
			return;
		}
		$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( self::description(), ENT_QUOTES, 'UTF-8' ) ) );
		if ( '' !== $text ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( wp_trim_words( $text, 30, '…' ) ) );
		}
	}
}
