<?php
/**
 * The two Yoast SEO sitemap names the plugin uses, for tests without Yoast.
 *
 * @package GuamRestaurants\Core
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidFunctionName, Squiz.Commenting, WordPress.NamingConventions.PrefixAllGlobals -- Stand-ins for Yoast's own classes.

if ( ! interface_exists( 'WPSEO_Sitemap_Provider' ) ) {
	interface WPSEO_Sitemap_Provider {
		public function handles_type( $type );
		public function get_index_links( $max_entries );
		public function get_sitemap_links( $type, $max_entries, $current_page );
	}
}

if ( ! class_exists( 'WPSEO_Sitemaps_Router' ) ) {
	class WPSEO_Sitemaps_Router {
		public static function get_base_url( $page ) {
			return home_url( '/' . $page );
		}
	}
}
