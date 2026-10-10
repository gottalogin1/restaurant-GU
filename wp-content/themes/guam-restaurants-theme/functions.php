<?php
/**
 * Theme setup. Presentation only: anything that changes data belongs in guam-restaurants-core.
 *
 * @package GuamRestaurantsTheme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the front-end stylesheet.
 *
 * @return void
 */
function guam_restaurants_theme_enqueue_styles() {
	$theme = wp_get_theme();
	wp_enqueue_style(
		'guam-restaurants-theme',
		get_theme_file_uri( 'assets/css/theme.css' ),
		array(),
		$theme->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'guam_restaurants_theme_enqueue_styles' );
