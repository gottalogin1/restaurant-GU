<?php
/**
 * Sitemaps::urls() in WordPress's own sitemap.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Served as wp-sitemap-guam-pages-1.xml, wp-sitemap-guam-ja-1.xml and
 * wp-sitemap-guam-ko-1.xml, and listed in wp-sitemap.xml. Loaded only from
 * the `wp_sitemaps_init` action, so WP_Sitemaps_Provider exists.
 */
final class Core_Sitemap_Provider extends \WP_Sitemaps_Provider {

	/**
	 * Provider name; WordPress allows letters only.
	 */
	public const NAME = 'guam';

	/**
	 * Sets the names.
	 */
	public function __construct() {
		$this->name        = self::NAME;
		$this->object_type = self::NAME;
	}

	/**
	 * The lists, as WordPress expects them.
	 *
	 * @return array<string, object>
	 */
	public function get_object_subtypes() {
		$subtypes = array();
		foreach ( Sitemaps::names() as $name ) {
			$subtypes[ $name ] = (object) array( 'name' => $name );
		}
		return $subtypes;
	}

	/**
	 * One page of a list.
	 *
	 * @param int    $page_num       Page, from 1.
	 * @param string $object_subtype List name.
	 * @return array<int, array<string, string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$max     = wp_sitemaps_get_max_urls( $this->object_type );
		$entries = array_slice( Sitemaps::urls( (string) $object_subtype ), ( max( 1, (int) $page_num ) - 1 ) * $max, $max );
		$list    = array();
		foreach ( $entries as $entry ) {
			$item = array( 'loc' => $entry['loc'] );
			if ( ! empty( $entry['lastmod'] ) && '0000-00-00 00:00:00' !== $entry['lastmod'] ) {
				$item['lastmod'] = gmdate( DATE_W3C, (int) strtotime( $entry['lastmod'] . ' UTC' ) );
			}
			$list[] = $item;
		}
		return $list;
	}

	/**
	 * Number of pages in a list; 0 leaves it out of the index.
	 *
	 * @param string $object_subtype List name.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		return (int) ceil( count( Sitemaps::urls( (string) $object_subtype ) ) / wp_sitemaps_get_max_urls( $this->object_type ) );
	}
}
