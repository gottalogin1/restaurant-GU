<?php
/**
 * Removes plugin options when the plugin is deleted from the Plugins screen.
 *
 * Restaurant posts and terms are content and are deliberately kept.
 *
 * @package GuamRestaurants\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'guam_restaurants_core_version' );
delete_option( 'guam_restaurants_core_activated_at' );
