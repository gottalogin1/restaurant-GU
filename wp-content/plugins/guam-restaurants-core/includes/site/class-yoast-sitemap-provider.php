<?php
/**
 * Sitemaps::urls() in Yoast SEO's sitemap.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Served as guam-pages-sitemap.xml, guam-ja-sitemap.xml and
 * guam-ko-sitemap.xml, and listed in sitemap_index.xml. Created only
 * when Yoast's provider interface exists (Sitemaps::yoast_providers()).
 */
final class Yoast_Sitemap_Provider implements \WPSEO_Sitemap_Provider {

	/**
	 * Prefix of the sitemap types.
	 */
	public const PREFIX = 'guam-';

	/**
	 * Whether a sitemap type is ours.
	 *
	 * @param string $type Sitemap type.
	 * @return bool
	 */
	public function handles_type( $type ) {
		return null !== self::list_name( (string) $type );
	}

	/**
	 * Our sitemaps in the index.
	 *
	 * @param int $max_entries Entries per sitemap page.
	 * @return array<int, array<string, string>>
	 */
	public function get_index_links( $max_entries ) {
		$max   = max( 1, (int) $max_entries );
		$links = array();
		foreach ( Sitemaps::names() as $name ) {
			$entries = Sitemaps::urls( $name );
			$pages   = (int) ceil( count( $entries ) / $max );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$link = array( 'loc' => \WPSEO_Sitemaps_Router::get_base_url( self::PREFIX . $name . '-sitemap' . ( 1 === $page ? '' : $page ) . '.xml' ) );
				$mod  = self::newest( array_slice( $entries, ( $page - 1 ) * $max, $max ) );
				if ( '' !== $mod ) {
					$link['lastmod'] = $mod;
				}
				$links[] = $link;
			}
		}
		return $links;
	}

	/**
	 * One page of one of our sitemaps.
	 *
	 * @param string $type         Sitemap type.
	 * @param int    $max_entries  Entries per page.
	 * @param int    $current_page Page, from 1.
	 * @return array<int, array<string, string>>
	 * @throws \OutOfBoundsException When the page is past the end; Yoast answers 404.
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		$name    = self::list_name( (string) $type );
		$max     = max( 1, (int) $max_entries );
		$entries = null === $name ? array() : array_slice( Sitemaps::urls( $name ), ( max( 1, (int) $current_page ) - 1 ) * $max, $max );
		if ( ! $entries ) {
			throw new \OutOfBoundsException( 'Invalid sitemap page requested' );
		}
		$links = array();
		foreach ( $entries as $entry ) {
			$link = array( 'loc' => $entry['loc'] );
			if ( ! empty( $entry['lastmod'] ) ) {
				$link['mod'] = $entry['lastmod'];
			}
			$links[] = $link;
		}
		return $links;
	}

	/**
	 * The Sitemaps list a sitemap type names, or null.
	 *
	 * @param string $type Sitemap type, for example guam-ja.
	 * @return string|null
	 */
	private static function list_name( string $type ): ?string {
		if ( 0 !== strpos( $type, self::PREFIX ) ) {
			return null;
		}
		$name = substr( $type, strlen( self::PREFIX ) );
		return in_array( $name, Sitemaps::names(), true ) ? $name : null;
	}

	/**
	 * Latest change among entries, or ''.
	 *
	 * @param array<int, array{loc: string, lastmod?: string}> $entries Entries.
	 * @return string
	 */
	private static function newest( array $entries ): string {
		$dates = array_filter( array_column( $entries, 'lastmod' ) );
		return $dates ? (string) max( $dates ) : '';
	}
}
