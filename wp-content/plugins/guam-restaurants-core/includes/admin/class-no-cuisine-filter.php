<?php
/**
 * "No cuisine" view on the Restaurants list.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "No cuisine" link above the Restaurants list that shows only
 * restaurants without a cuisine term, with a column for OpenStreetMap's
 * raw cuisine tag, so editors can tag them by hand or add the value to
 * data/osm-cuisine-map.json.
 */
final class No_Cuisine_Filter {

	public const QUERY_VAR = 'gr_no_cuisine';
	public const COLUMN    = 'gr_osm_cuisine';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = Content_Types::POST_TYPE;
		add_filter( "views_edit-{$type}", array( self::class, 'views' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_query' ) );
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ), 20 );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * Whether the current list request asks for the view.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return isset( $_GET[ self::QUERY_VAR ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
	}

	/**
	 * Tax query for restaurants without a cuisine.
	 *
	 * @return array<int|string, mixed>
	 */
	public static function tax_query(): array {
		return array(
			array(
				'taxonomy' => Content_Types::TAX_CUISINE,
				'operator' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Restaurants without a cuisine, in the statuses the "All" view counts.
	 *
	 * @return int
	 */
	public static function count(): int {
		$query = new \WP_Query(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'tax_query'      => self::tax_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Adds the link to the views row.
	 *
	 * @param array<string, string> $views Views.
	 * @return array<string, string>
	 */
	public static function views( $views ): array {
		$views = (array) $views;
		$url   = add_query_arg(
			array(
				'post_type'     => Content_Types::POST_TYPE,
				self::QUERY_VAR => '1',
			),
			admin_url( 'edit.php' )
		);
		if ( self::active() ) {
			foreach ( $views as $key => $html ) {
				$views[ $key ] = str_replace( ' class="current" aria-current="page"', '', $html );
			}
		}
		$views[ self::QUERY_VAR ] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			self::active() ? ' class="current" aria-current="page"' : '',
			esc_html__( 'No cuisine', 'guam-restaurants-core' ),
			esc_html( number_format_i18n( self::count() ) )
		);
		return $views;
	}

	/**
	 * Limits the list to restaurants without a cuisine.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function filter_query( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ! self::active() ) {
			return;
		}
		if ( Content_Types::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$query->set( 'tax_query', self::tax_query() );
	}

	/**
	 * Adds the OSM cuisine tag column in the view.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		$columns = (array) $columns;
		if ( ! self::active() ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out[ self::COLUMN ] = __( 'OSM cuisine tag', 'guam-restaurants-core' );
			}
		}
		return $out;
	}

	/**
	 * Prints the column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Restaurant.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$value = (string) get_post_meta( (int) $post_id, Restaurant_Meta::OSM_CUISINE, true );
		echo '' === $value ? '&ndash;' : '<code>' . esc_html( $value ) . '</code>';
	}
}
