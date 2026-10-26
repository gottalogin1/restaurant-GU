<?php
/**
 * Sitemap entries the SEO plugin and WordPress cannot find on their own.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Awards\Pages as Award_Pages;
use GuamRestaurants\Core\Awards\Repository as Awards;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Dishes\Dish_Index;
use GuamRestaurants\Core\Dishes\Dish_Pages;
use GuamRestaurants\Core\Translation\Languages;
use GuamRestaurants\Core\Translation\Source_Text;

defined( 'ABSPATH' ) || exit;

/**
 * Three lists, each a sitemap of its own (docs/seo-and-analytics.md §4):
 *
 * - `pages`: the dish pages and the awards pages. They are served by
 *   rewrite rules, not posts or terms, so neither WordPress nor Yoast lists
 *   them.
 * - `ja` and `ko`: every public page in Japanese or Korean. hreflang links
 *   point Google at them, but Naver crawls only what it is given.
 *
 * WordPress's sitemap gets them through Core_Sitemap_Provider, Yoast's
 * through Yoast_Sitemap_Provider; only one of the two runs, since Yoast
 * turns WordPress's sitemap off.
 */
final class Sitemaps {

	/**
	 * Sitemap names: English pages, then one per translated language.
	 */
	public const PAGES = 'pages';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_sitemaps_init', array( self::class, 'register_core' ) );
		add_filter( 'wpseo_sitemaps_providers', array( self::class, 'yoast_providers' ) );
	}

	/**
	 * Adds the provider to WordPress's sitemap.
	 *
	 * @param \WP_Sitemaps $sitemaps Sitemaps server.
	 * @return void
	 */
	public static function register_core( $sitemaps ): void {
		if ( $sitemaps instanceof \WP_Sitemaps ) {
			$sitemaps->registry->add_provider( Core_Sitemap_Provider::NAME, new Core_Sitemap_Provider() );
		}
	}

	/**
	 * Adds the provider to Yoast's sitemap.
	 *
	 * @param array<int, object> $providers Providers.
	 * @return array<int, object>
	 */
	public static function yoast_providers( $providers ): array {
		$providers = (array) $providers;
		if ( interface_exists( '\WPSEO_Sitemap_Provider' ) ) {
			$providers[] = new Yoast_Sitemap_Provider();
		}
		return $providers;
	}

	/**
	 * The list names: `pages`, `ja`, `ko`.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_merge( array( self::PAGES ), array_keys( Languages::LOCALES ) );
	}

	/**
	 * One list.
	 *
	 * @param string $name A name from names().
	 * @return array<int, array{loc: string, lastmod?: string}> lastmod in UTC, `Y-m-d H:i:s`.
	 */
	public static function urls( string $name ): array {
		if ( self::PAGES === $name ) {
			return self::plugin_pages();
		}
		if ( ! isset( Languages::LOCALES[ $name ] ) ) {
			return array();
		}
		$home = untrailingslashit( (string) get_option( 'home' ) );
		$list = array();
		foreach ( array_merge( self::wordpress_pages(), self::plugin_pages() ) as $entry ) {
			if ( 0 !== strpos( $entry['loc'], $home ) ) {
				continue;
			}
			$entry['loc'] = Languages::url( $name, (string) substr( $entry['loc'], strlen( $home ) ) );
			$list[]       = $entry;
		}
		return $list;
	}

	/**
	 * Dish and awards pages, in English.
	 *
	 * @return array<int, array{loc: string}>
	 */
	public static function plugin_pages(): array {
		$list   = array();
		$dishes = array_keys( Dish_Index::get()['pages'] );
		if ( $dishes ) {
			$list[] = array( 'loc' => Dish_Pages::url() );
			foreach ( $dishes as $slug ) {
				$list[] = array( 'loc' => Dish_Pages::url( (string) $slug ) );
			}
		}
		$categories = Awards::categories();
		if ( $categories ) {
			$list[] = array( 'loc' => Award_Pages::url( 'archive' ) );
			$list[] = array( 'loc' => Award_Pages::url( 'ranking' ) );
			foreach ( $categories as $category ) {
				$list[] = array( 'loc' => Award_Pages::url( 'category', sanitize_title( $category ) ) );
			}
		}
		return $list;
	}

	/**
	 * The pages WordPress's own sitemap lists, in English: the home page,
	 * the restaurant list, pages (not those kept in English), restaurants,
	 * and the village, verified mall, cuisine and price pages.
	 *
	 * @return array<int, array{loc: string, lastmod?: string}>
	 */
	private static function wordpress_pages(): array {
		$list    = array( array( 'loc' => home_url( '/' ) ) );
		$archive = get_post_type_archive_link( Content_Types::POST_TYPE );
		if ( is_string( $archive ) ) {
			$list[] = array( 'loc' => $archive );
		}
		$posts = get_posts(
			array(
				'post_type'              => array( 'page', Content_Types::POST_TYPE ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'post__not_in'           => array_merge( Source_Text::english_only_ids(), array( (int) get_option( 'page_on_front' ) ) ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- a handful of pages, once per sitemap request.
				'has_password'           => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $posts as $post ) {
			$list[] = array(
				'loc'     => (string) get_permalink( $post ),
				'lastmod' => (string) $post->post_modified_gmt,
			);
		}
		$terms = get_terms(
			array(
				'taxonomy'   => array_values( Filters::FACETS ),
				'hide_empty' => true,
				'exclude'    => Seo::unverified_mall_ids(),
				'orderby'    => 'term_id',
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$list[] = array( 'loc' => $link );
			}
		}
		return $list;
	}
}
