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
 * - prints a meta description on directory pages when no SEO plugin does;
 * - gives every village, mall, cuisine and price page its own title and
 *   description ("Restaurants in Dededo, Guam"), in the page title and in
 *   Yoast SEO's, unless an editor wrote one for that term in Yoast.
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
		add_filter( 'document_title_parts', array( self::class, 'title_parts' ) );
		add_filter( 'wpseo_title', array( self::class, 'yoast_title' ) );
		add_filter( 'wpseo_opengraph_title', array( self::class, 'yoast_title' ) );
		add_filter( 'wpseo_metadesc', array( self::class, 'yoast_description' ) );
		add_filter( 'wpseo_opengraph_desc', array( self::class, 'yoast_description' ) );
	}

	/**
	 * The directory term on this page, or null.
	 *
	 * @return \WP_Term|null
	 */
	private static function current_term(): ?\WP_Term {
		if ( ! is_tax( array_values( Filters::FACETS ) ) ) {
			return null;
		}
		$term = get_queried_object();
		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * Title for a village, mall, cuisine or price page, without the site name.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	public static function term_title( \WP_Term $term ): string {
		switch ( $term->taxonomy ) {
			case Content_Types::TAX_VILLAGE:
				/* translators: %s: village. */
				return sprintf( __( 'Restaurants in %s, Guam', 'guam-restaurants-core' ), $term->name );
			case Content_Types::TAX_MALL:
				$village = get_term( (int) get_term_meta( $term->term_id, Term_Meta::MALL_VILLAGE, true ), Content_Types::TAX_VILLAGE );
				return $village instanceof \WP_Term
					/* translators: 1: mall, 2: village. */
					? sprintf( __( 'Restaurants at %1$s, %2$s, Guam', 'guam-restaurants-core' ), $term->name, $village->name )
					/* translators: %s: mall. */
					: sprintf( __( 'Restaurants at %s, Guam', 'guam-restaurants-core' ), $term->name );
			case Content_Types::TAX_CUISINE:
				/* translators: %s: cuisine. */
				return sprintf( __( '%s Restaurants on Guam', 'guam-restaurants-core' ), $term->name );
			case Content_Types::TAX_PRICE:
				/* translators: %s: price level, for example $$. */
				return sprintf( __( '%s Restaurants on Guam by Price', 'guam-restaurants-core' ), $term->name );
		}
		return $term->name;
	}

	/**
	 * Description for a term page: the editor's intro, else the generated
	 * line, which names the count and the top related terms, so each page's
	 * description is its own.
	 *
	 * @param \WP_Term $term Term.
	 * @return string Plain text.
	 */
	public static function term_description( \WP_Term $term ): string {
		$intro = trim( wp_strip_all_tags( term_description( $term ) ) );
		if ( '' !== $intro ) {
			return $intro;
		}
		return Intro::default_text( $term ) . ' ' . __( 'See hours, maps, awards and reviews for each.', 'guam-restaurants-core' );
	}

	/**
	 * Core page title on term pages.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function title_parts( $parts ): array {
		$parts = (array) $parts;
		$term  = self::current_term();
		if ( null !== $term ) {
			$parts['title'] = self::term_title( $term );
		}
		return $parts;
	}

	/**
	 * Whether an editor set this Yoast field for the term.
	 *
	 * @param \WP_Term $term  Term.
	 * @param string   $field `title` or `desc`.
	 * @return bool
	 */
	private static function yoast_term_has( \WP_Term $term, string $field ): bool {
		if ( ! class_exists( '\WPSEO_Taxonomy_Meta' ) ) {
			return false;
		}
		return '' !== trim( (string) \WPSEO_Taxonomy_Meta::get_term_meta( $term, $term->taxonomy, $field ) );
	}

	/**
	 * Yoast title (and Open Graph title) on term pages.
	 *
	 * @param string $title Yoast's title.
	 * @return string
	 */
	public static function yoast_title( $title ): string {
		$term = self::current_term();
		if ( null === $term || self::yoast_term_has( $term, 'title' ) ) {
			return (string) $title;
		}
		$page = (int) get_query_var( 'paged' );
		/* translators: %d: page number. */
		$suffix = $page > 1 ? ' - ' . sprintf( __( 'Page %d', 'guam-restaurants-core' ), $page ) : '';
		return self::term_title( $term ) . $suffix . ' - ' . wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Yoast description (and Open Graph description) on term pages.
	 *
	 * @param string $description Yoast's description.
	 * @return string
	 */
	public static function yoast_description( $description ): string {
		$term = self::current_term();
		if ( null === $term || self::yoast_term_has( $term, 'desc' ) ) {
			return (string) $description;
		}
		return wp_trim_words( self::term_description( $term ), 30, '…' );
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
		$term = self::current_term();
		if ( null !== $term ) {
			return self::term_description( $term );
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
