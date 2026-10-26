<?php
/**
 * Links between related village, mall, cuisine and restaurant pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The `guam-restaurants/related-links` block. It links each page to the
 * clean (indexable) pages most related to it, worked out from the listings
 * themselves:
 *
 * - village: its verified malls, and the cuisines most common there;
 * - mall: its village, and the cuisines most common there;
 * - cuisine: the villages with the most of it, and cuisines that the same
 *   restaurants also serve;
 * - restaurant: up to five other restaurants with the same cuisine in the
 *   same village (else the same village), plus its village, mall and
 *   cuisine pages.
 *
 * A cuisine on a village page, and a village on a cuisine page, link to
 * that cuisine in that village when it has a page of its own
 * (Cuisine_Villages); links never point at other filtered listing URLs,
 * which are noindex (Seo).
 * Order is by number of listings, never by advertising.
 */
final class Related_Links {

	public const LIMIT = 6;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Registers the block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . 'blocks/related-links',
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Block output for the current page.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Content.
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function render( $attributes = array(), $content = '', $block = null ): string {
		unset( $attributes, $content );
		$id = $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
		if ( ! $id && is_singular( Content_Types::POST_TYPE ) ) {
			$id = (int) get_queried_object_id();
		}
		if ( $id && Content_Types::POST_TYPE === get_post_type( $id ) ) {
			$groups = self::for_restaurant( $id );
		} else {
			$term   = get_queried_object();
			$groups = $term instanceof \WP_Term ? self::for_term( $term ) : array();
		}
		if ( ! $groups ) {
			return '';
		}

		Assets::enqueue();
		$html = '';
		foreach ( $groups as $group ) {
			$items = '';
			foreach ( $group['links'] as $link ) {
				$items .= sprintf( '<li><a class="gr-chip" href="%1$s">%2$s</a></li>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
			}
			$html .= sprintf( '<div class="gr-related__group"><h2 class="gr-related__title">%1$s</h2><ul class="gr-chips">%2$s</ul></div>', esc_html( $group['title'] ), $items );
		}
		return sprintf(
			'<nav %1$s>%2$s</nav>',
			get_block_wrapper_attributes(
				array(
					'class'      => 'gr-related',
					'aria-label' => esc_attr__( 'Related pages', 'guam-restaurants-core' ),
				)
			),
			$html
		);
	}

	/**
	 * Link groups for a village, mall or cuisine page.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<int, array{title: string, links: array<int, array{url: string, label: string}>}>
	 */
	public static function for_term( \WP_Term $term ): array {
		$groups = array();
		switch ( $term->taxonomy ) {
			case Content_Types::TAX_VILLAGE:
				$malls = get_terms(
					array(
						'taxonomy'   => Content_Types::TAX_MALL,
						'hide_empty' => true,
						'meta_key'   => Term_Meta::MALL_VILLAGE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a handful of malls.
						'meta_value' => (string) $term->term_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
						'orderby'    => 'name',
					)
				);
				/* translators: %s: village. */
				$groups[] = self::group( sprintf( __( 'Malls in %s', 'guam-restaurants-core' ), $term->name ), is_array( $malls ) ? $malls : array() );
				$groups[] = self::group(
					/* translators: %s: village. */
					sprintf( __( 'Popular cuisines in %s', 'guam-restaurants-core' ), $term->name ),
					self::co_terms( $term, Content_Types::TAX_CUISINE ),
					static function ( \WP_Term $cuisine ) use ( $term ): string {
						return self::pair_url( $term, $cuisine );
					}
				);
				break;
			case Content_Types::TAX_MALL:
				$village  = get_term( (int) get_term_meta( $term->term_id, Term_Meta::MALL_VILLAGE, true ), Content_Types::TAX_VILLAGE );
				$groups[] = self::group( __( 'Village', 'guam-restaurants-core' ), $village instanceof \WP_Term ? array( $village ) : array() );
				/* translators: %s: mall. */
				$groups[] = self::group( sprintf( __( 'Cuisines at %s', 'guam-restaurants-core' ), $term->name ), self::co_terms( $term, Content_Types::TAX_CUISINE ) );
				break;
			case Content_Types::TAX_CUISINE:
				$groups[] = self::group(
					/* translators: %s: cuisine. */
					sprintf( __( 'Where to find %s food', 'guam-restaurants-core' ), $term->name ),
					self::co_terms( $term, Content_Types::TAX_VILLAGE ),
					static function ( \WP_Term $village ) use ( $term ): string {
						return self::pair_url( $village, $term );
					}
				);
				$groups[] = self::group( __( 'Related cuisines', 'guam-restaurants-core' ), self::co_terms( $term, Content_Types::TAX_CUISINE ) );
				break;
		}
		return array_values( array_filter( $groups ) );
	}

	/**
	 * Link groups for a restaurant page.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array{title: string, links: array<int, array{url: string, label: string}>}>
	 */
	public static function for_restaurant( int $restaurant_id ): array {
		$village = self::first_term( $restaurant_id, Content_Types::TAX_VILLAGE );
		$cuisine = self::first_term( $restaurant_id, Content_Types::TAX_CUISINE );
		$nearby  = self::similar( $restaurant_id, $village, $cuisine );
		$browse  = array_filter(
			array_merge(
				self::post_terms( $restaurant_id, Content_Types::TAX_VILLAGE ),
				self::post_terms( $restaurant_id, Content_Types::TAX_MALL ),
				self::post_terms( $restaurant_id, Content_Types::TAX_CUISINE )
			)
		);
		$title   = $village && $cuisine
			/* translators: 1: cuisine, 2: village. */
			? sprintf( __( 'More %1$s restaurants in %2$s', 'guam-restaurants-core' ), $cuisine->name, $village->name )
			/* translators: %s: village. */
			: ( $village ? sprintf( __( 'More restaurants in %s', 'guam-restaurants-core' ), $village->name ) : '' );
		$links = array();
		foreach ( $nearby as $id ) {
			$links[] = array(
				'url'   => (string) get_permalink( $id ),
				'label' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		$groups = array();
		if ( $links && '' !== $title ) {
			$groups[] = array(
				'title' => $title,
				'links' => $links,
			);
		}
		$groups[] = self::group( __( 'Browse', 'guam-restaurants-core' ), $browse );
		return array_values( array_filter( $groups ) );
	}

	/**
	 * Up to LIMIT - 1 other published restaurants in the same village with
	 * the same cuisine; if none, in the same village. Alphabetical, so no
	 * restaurant gets a better spot for any reason but its name.
	 *
	 * @param int           $restaurant_id Restaurant.
	 * @param \WP_Term|null $village       Village.
	 * @param \WP_Term|null $cuisine       Cuisine.
	 * @return int[]
	 */
	private static function similar( int $restaurant_id, ?\WP_Term $village, ?\WP_Term $cuisine ): array {
		if ( ! $village ) {
			return array();
		}
		return Cache::remember(
			'related-restaurants',
			array( $restaurant_id, $village->term_id, $cuisine ? $cuisine->term_id : 0 ),
			static function () use ( $restaurant_id, $village, $cuisine ) {
				$query      = static function ( array $tax_query ) use ( $restaurant_id ): array {
					return array_map(
						'intval',
						get_posts(
							array(
								'post_type'        => Content_Types::POST_TYPE,
								'post_status'      => 'publish',
								'posts_per_page'   => self::LIMIT - 1,
								'post__not_in'     => array( $restaurant_id ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one ID.
								'tax_query'        => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
								'orderby'          => 'title',
								'order'            => 'ASC',
								'fields'           => 'ids',
								'no_found_rows'    => true,
								'suppress_filters' => true,
							)
						)
					);
				};
				$in_village = array(
					'taxonomy' => Content_Types::TAX_VILLAGE,
					'terms'    => array( $village->term_id ),
				);
				$ids        = $cuisine ? $query(
					array(
						$in_village,
						array(
							'taxonomy' => Content_Types::TAX_CUISINE,
							'terms'    => array( $cuisine->term_id ),
						),
					)
				) : array();
				return $ids ? $ids : $query( array( $in_village ) );
			}
		);
	}

	/**
	 * The most common terms of another taxonomy among a term's published
	 * restaurants (the term itself excluded; unverified malls left out).
	 *
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Other taxonomy.
	 * @return \WP_Term[]
	 */
	public static function co_terms( \WP_Term $term, string $taxonomy ): array {
		$ids   = Cache::remember(
			'related-terms',
			array( $term->term_taxonomy_id, $taxonomy ),
			static function () use ( $term, $taxonomy ) {
				global $wpdb;
				return array_map(
					'intval',
					(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate over core tables; cached.
						$wpdb->prepare(
							"SELECT tt.term_id FROM {$wpdb->term_relationships} a
							INNER JOIN {$wpdb->posts} p ON p.ID = a.object_id AND p.post_status = 'publish' AND p.post_type = %s
							INNER JOIN {$wpdb->term_relationships} b ON b.object_id = a.object_id
							INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = b.term_taxonomy_id AND tt.taxonomy = %s AND tt.term_taxonomy_id <> %d
							INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
							WHERE a.term_taxonomy_id = %d
							GROUP BY tt.term_id, t.name ORDER BY COUNT(*) DESC, t.name ASC LIMIT %d",
							Content_Types::POST_TYPE,
							$taxonomy,
							(int) $term->term_taxonomy_id,
							(int) $term->term_taxonomy_id,
							self::LIMIT + 4
						)
					)
				);
			},
			HOUR_IN_SECONDS
		);
		$terms = array();
		foreach ( $ids as $id ) {
			if ( Content_Types::TAX_MALL === $taxonomy && ! Mall_Verification::is_verified( $id ) ) {
				continue;
			}
			$found = get_term( $id, $taxonomy );
			if ( $found instanceof \WP_Term ) {
				$terms[] = $found;
			}
			if ( count( $terms ) >= self::LIMIT ) {
				break;
			}
		}
		return $terms;
	}

	/**
	 * The page of a cuisine in a village, or '' when it has none.
	 *
	 * @param \WP_Term $village Village.
	 * @param \WP_Term $cuisine Cuisine.
	 * @return string
	 */
	private static function pair_url( \WP_Term $village, \WP_Term $cuisine ): string {
		return null === Cuisine_Villages::find( $village->term_id, $cuisine->term_id ) ? '' : Cuisine_Villages::url( $village, $cuisine );
	}

	/**
	 * A titled group of term links, or null when there are none.
	 *
	 * @param string        $title Title.
	 * @param \WP_Term[]    $terms Terms.
	 * @param callable|null $link  Address for a term, '' for its own page.
	 * @return array{title: string, links: array<int, array{url: string, label: string}>}|null
	 */
	private static function group( string $title, array $terms, ?callable $link = null ): ?array {
		$links = array();
		foreach ( $terms as $term ) {
			$url = null === $link ? '' : (string) $link( $term );
			$url = '' === $url ? get_term_link( $term ) : $url;
			if ( ! is_wp_error( $url ) ) {
				$links[] = array(
					'url'   => (string) $url,
					'label' => $term->name,
				);
			}
		}
		return $links ? array(
			'title' => $title,
			'links' => $links,
		) : null;
	}

	/**
	 * A restaurant's terms in a taxonomy (unverified malls are already hidden).
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $taxonomy      Taxonomy.
	 * @return \WP_Term[]
	 */
	private static function post_terms( int $restaurant_id, string $taxonomy ): array {
		$terms = get_the_terms( $restaurant_id, $taxonomy );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * A restaurant's first term in a taxonomy.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $taxonomy      Taxonomy.
	 * @return \WP_Term|null
	 */
	private static function first_term( int $restaurant_id, string $taxonomy ): ?\WP_Term {
		$terms = self::post_terms( $restaurant_id, $taxonomy );
		return $terms ? $terms[0] : null;
	}
}
