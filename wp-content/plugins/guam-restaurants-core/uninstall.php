<?php
/**
 * Removes plugin options when the plugin is deleted from the Plugins screen.
 *
 * Restaurant posts, terms and the custom tables (awards, highlights, change
 * log and so on) are content and are deliberately kept. Removing the schema
 * version means a reinstall re-checks the tables.
 *
 * @package GuamRestaurants\Core
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'guam_restaurants_core_version' );
delete_option( 'guam_restaurants_core_activated_at' );
delete_option( 'guam_restaurants_core_db_version' );
