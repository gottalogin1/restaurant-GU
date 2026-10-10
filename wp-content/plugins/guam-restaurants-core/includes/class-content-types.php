<?php
/**
 * Restaurant post type and the village, mall and cuisine taxonomies.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the directory's content model.
 */
final class Content_Types {

	public const POST_TYPE = 'gr_restaurant';

	public const TAX_VILLAGE = 'gr_village';
	public const TAX_MALL    = 'gr_mall';
	public const TAX_CUISINE = 'gr_cuisine';

	/**
	 * Registers the post type and taxonomies. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Restaurants', 'guam-restaurants-core' ),
					'singular_name' => __( 'Restaurant', 'guam-restaurants-core' ),
					'add_new_item'  => __( 'Add New Restaurant', 'guam-restaurants-core' ),
					'edit_item'     => __( 'Edit Restaurant', 'guam-restaurants-core' ),
				),
				'public'       => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-food',
				'rewrite'      => array( 'slug' => 'restaurants' ),
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
			)
		);

		$taxonomies = array(
			self::TAX_VILLAGE => array(
				'name'          => __( 'Villages', 'guam-restaurants-core' ),
				'singular_name' => __( 'Village', 'guam-restaurants-core' ),
				'slug'          => 'village',
				'hierarchical'  => false,
			),
			self::TAX_MALL    => array(
				'name'          => __( 'Malls', 'guam-restaurants-core' ),
				'singular_name' => __( 'Mall', 'guam-restaurants-core' ),
				'slug'          => 'mall',
				'hierarchical'  => false,
			),
			self::TAX_CUISINE => array(
				'name'          => __( 'Cuisines', 'guam-restaurants-core' ),
				'singular_name' => __( 'Cuisine', 'guam-restaurants-core' ),
				'slug'          => 'cuisine',
				'hierarchical'  => true,
			),
		);

		foreach ( $taxonomies as $taxonomy => $args ) {
			register_taxonomy(
				$taxonomy,
				self::POST_TYPE,
				array(
					'labels'            => array(
						'name'          => $args['name'],
						'singular_name' => $args['singular_name'],
					),
					'hierarchical'      => $args['hierarchical'],
					'public'            => true,
					'show_admin_column' => true,
					'show_in_rest'      => true,
					'rewrite'           => array( 'slug' => $args['slug'] ),
				)
			);
		}
	}
}
