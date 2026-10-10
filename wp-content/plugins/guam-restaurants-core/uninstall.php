<?php
/**
 * Removes plugin options when the plugin is deleted from the Plugins screen.
 *
 * Also deletes cached third-party ratings (Yelp API Terms §4) and the
 * monthly ratings usage counter.
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
delete_option( 'guam_restaurants_core_ratings_usage' );
delete_option( 'guam_restaurants_awards_routes' );
delete_option( 'guam_restaurants_core_roles_version' );
delete_option( 'guam_restaurants_policy_page' );

// The owner role and highlight and lead capabilities. Owner links (the restaurants'
// gr_claimed_by_user_id meta), advertiser leads, ads (with their invoice numbers and the invoice
// sequence option), claims and the editorial policy page are content and are kept.
remove_role( 'gr_restaurant_owner' );
foreach ( array( 'administrator', 'editor' ) as $guam_role_name ) {
	$guam_role = get_role( $guam_role_name );
	if ( $guam_role ) {
		foreach ( array( 'gr_moderate_highlights', 'gr_write_editor_notes', 'gr_submit_owner_content', 'gr_manage_advertiser_leads', 'gr_manage_ads', 'gr_approve_claims' ) as $guam_cap ) {
			$guam_role->remove_cap( $guam_cap );
		}
	}
}

global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_gr_rating_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gr_rating_' ) . '%',
		$wpdb->esc_like( '_transient_gr_hl_confirm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gr_hl_confirm_' ) . '%',
		$wpdb->esc_like( '_transient_gr_lead_rl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gr_lead_rl_' ) . '%'
	)
);
