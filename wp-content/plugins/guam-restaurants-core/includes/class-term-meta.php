<?php
/**
 * Term meta for the village, mall, cuisine and price level taxonomies.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers term meta (design/content-model.md §4).
 */
final class Term_Meta {

	public const CENSUS_GEOID  = 'gr_census_geoid';
	public const ALIASES       = 'gr_aliases';
	public const CHAMORRO_NAME = 'gr_chamorro_name';

	public const VERIFIED     = 'gr_verified';
	public const VERIFIED_BY  = 'gr_verified_by';
	public const VERIFIED_ON  = 'gr_verified_on';
	public const MALL_VILLAGE = 'gr_village';
	public const OSM_ID       = 'gr_osm_id';

	public const OSM_CUISINE      = 'gr_osm_cuisine';
	public const FSQ_CATEGORY_IDS = 'gr_fsq_category_ids';

	public const RANK = 'gr_rank';

	/**
	 * Registers every term meta key.
	 *
	 * @return void
	 */
	public static function register(): void {
		$string_list = array(
			'type'              => 'array',
			'single'            => true,
			'default'           => array(),
			'sanitize_callback' => array( self::class, 'sanitize_string_list' ),
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
		$string      = array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
		);

		// Villages.
		register_term_meta( Content_Types::TAX_VILLAGE, self::CENSUS_GEOID, $string + array( 'description' => __( 'US Census GEOID of the village boundary.', 'guam-restaurants-core' ) ) );
		register_term_meta( Content_Types::TAX_VILLAGE, self::ALIASES, $string_list + array( 'description' => __( 'Other names that should find this village in search.', 'guam-restaurants-core' ) ) );
		register_term_meta( Content_Types::TAX_VILLAGE, self::CHAMORRO_NAME, $string + array( 'description' => __( 'Official Chamorro name.', 'guam-restaurants-core' ) ) );

		// Malls.
		register_term_meta(
			Content_Types::TAX_MALL,
			self::VERIFIED,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'description'       => __( 'Whether an editor has confirmed this mall. Unverified malls are hidden from the public.', 'guam-restaurants-core' ),
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);
		register_term_meta(
			Content_Types::TAX_MALL,
			self::VERIFIED_BY,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => array( 'schema' => array( 'context' => array( 'edit' ) ) ),
			)
		);
		register_term_meta( Content_Types::TAX_MALL, self::VERIFIED_ON, $string );
		register_term_meta(
			Content_Types::TAX_MALL,
			self::MALL_VILLAGE,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'description'       => __( 'Village term the mall is in.', 'guam-restaurants-core' ),
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			)
		);
		register_term_meta( Content_Types::TAX_MALL, self::OSM_ID, $string );
		register_term_meta( Content_Types::TAX_MALL, self::ALIASES, $string_list );

		// Cuisines.
		register_term_meta( Content_Types::TAX_CUISINE, self::OSM_CUISINE, $string_list + array( 'description' => __( 'OpenStreetMap cuisine or amenity values that map to this cuisine.', 'guam-restaurants-core' ) ) );
		register_term_meta( Content_Types::TAX_CUISINE, self::FSQ_CATEGORY_IDS, $string_list );

		// Price levels.
		register_term_meta(
			Content_Types::TAX_PRICE,
			self::RANK,
			array(
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'description'       => __( 'Sort order, 1 (cheapest) to 4.', 'guam-restaurants-core' ),
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Sanitizes a list of strings: trims, drops empties and duplicates.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function sanitize_string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = '' === $value || null === $value ? array() : array( $value );
		}
		$clean = array_map( 'sanitize_text_field', array_map( 'strval', $value ) );
		return array_values( array_unique( array_filter( $clean, 'strlen' ) ) );
	}
}
