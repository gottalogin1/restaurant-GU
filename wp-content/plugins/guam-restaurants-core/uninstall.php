<?php
/**
 * Removes plugin options when the plugin is deleted from the Plugins screen.
 *
 * Also deletes cached third-party ratings (Yelp API Terms §4) and the
 * monthly ratings spend counter.
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
delete_option( 'guam_restaurants_core_ratings_spend' );

global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gr_rating_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gr_rating_' ) . '%'
	)
);
