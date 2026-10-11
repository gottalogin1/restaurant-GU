<?php
/**
 * Owner and editor roles for highlights, and which restaurants an owner manages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Advertising\Leads;
use GuamRestaurants\Core\Claims\Claims;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities:
 *
 * - `gr_moderate_highlights`: approve or reject every highlight type.
 *   Administrators only, so an approval is always an admin's.
 * - `gr_write_editor_notes`: write editor's notes. Administrators and editors.
 * - `gr_manage_advertiser_leads` (Advertising\Leads::CAP): see and work
 *   advertiser leads. Administrators only.
 * - `gr_approve_claims` (Claims\Claims::CAP): approve or reject listing
 *   claims. Administrators only.
 * - `gr_submit_owner_content`: write owner quotes, reply to reviews and edit
 *   the owner fields (hours, menu links, photos, specials; Owners\Owner_Content),
 *   but only for restaurants linked to the user. The `gr_restaurant_owner`
 *   role has this and `read`, nothing else: owners cannot edit the
 *   restaurant post, its ratings, awards or status history, and cannot
 *   edit, hide or delete any review.
 *
 * Which restaurant an owner speaks for is the restaurant's existing
 * `gr_claimed_by_user_id` meta (design/content-model.md §3), one account
 * per restaurant. It is set when an administrator approves a verified
 * claim (Claims\Claims), or by an administrator from the user's profile.
 */
final class Roles {

	public const MODERATE   = 'gr_moderate_highlights';
	public const EDITOR     = 'gr_write_editor_notes';
	public const OWNER      = 'gr_submit_owner_content';
	public const OWNER_ROLE = 'gr_restaurant_owner';

	/**
	 * Bump when capabilities change so maybe_install() runs again.
	 */
	public const VERSION        = '4';
	public const VERSION_OPTION = 'guam_restaurants_core_roles_version';

	/**
	 * Hooks the profile field.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'show_user_profile', array( self::class, 'profile_field' ) );
		add_action( 'edit_user_profile', array( self::class, 'profile_field' ) );
		add_action( 'personal_options_update', array( self::class, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save_profile_field' ) );
	}

	/**
	 * Adds the owner role and capabilities.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( null === get_role( self::OWNER_ROLE ) ) {
			// Untranslated, like core's role names: this runs on plugins_loaded, before translations may load
			// (WordPress 6.7+ warns), and a stored role name should not depend on the locale at install time.
			add_role(
				self::OWNER_ROLE,
				'Restaurant owner',
				array(
					'read'      => true,
					self::OWNER => true,
				)
			);
		}
		$grants = array(
			'administrator' => array( self::MODERATE, self::EDITOR, self::OWNER, Leads::CAP, Ads::CAP, Claims::CAP ),
			'editor'        => array( self::EDITOR ),
		);
		foreach ( $grants as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( null === $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Runs install() when the stored version is out of date.
	 *
	 * @return void
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Restaurants linked to an owner.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function owned_restaurants( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Content_Types::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'orderby'        => 'title',
					'order'          => 'ASC',
					'meta_key'       => Restaurant_Meta::CLAIMED_BY_USER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Whether a user is linked to a restaurant as its owner.
	 *
	 * @param int $user_id       User.
	 * @param int $restaurant_id Restaurant.
	 * @return bool
	 */
	public static function owns( int $user_id, int $restaurant_id ): bool {
		return $user_id > 0 && $restaurant_id > 0 && (int) get_post_meta( $restaurant_id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) === $user_id;
	}

	/**
	 * Whether a user may write owner content for a restaurant.
	 *
	 * @param int $user_id       User.
	 * @param int $restaurant_id Restaurant.
	 * @return bool
	 */
	public static function can_speak_for( int $user_id, int $restaurant_id ): bool {
		return user_can( $user_id, self::OWNER ) && self::owns( $user_id, $restaurant_id );
	}

	/**
	 * Links a user to restaurants, replacing the user's earlier links. A
	 * restaurant has one owner account, so this replaces any other owner.
	 *
	 * @param int   $user_id        User.
	 * @param int[] $restaurant_ids Restaurants.
	 * @return void
	 */
	public static function set_owned_restaurants( int $user_id, array $restaurant_ids ): void {
		$restaurant_ids = array_map( 'intval', $restaurant_ids );
		foreach ( self::owned_restaurants( $user_id ) as $id ) {
			if ( ! in_array( $id, $restaurant_ids, true ) ) {
				update_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, 0 );
			}
		}
		foreach ( array_unique( $restaurant_ids ) as $id ) {
			if ( Content_Types::POST_TYPE === get_post_type( $id ) ) {
				update_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, $user_id );
			}
		}
	}

	/**
	 * Profile field, editable by administrators only.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	public static function profile_field( $user ): void {
		$owned = self::owned_restaurants( (int) $user->ID );
		if ( ! current_user_can( 'promote_users' ) ) {
			if ( $owned ) {
				echo '<h2>' . esc_html__( 'Restaurants you manage', 'guam-restaurants-core' ) . '</h2><ul>';
				foreach ( $owned as $id ) {
					echo '<li>' . esc_html( get_the_title( $id ) ) . '</li>';
				}
				echo '</ul>';
			}
			return;
		}
		wp_nonce_field( 'gr_owner_restaurants_' . $user->ID, 'gr_owner_restaurants_nonce' );
		echo '<h2>' . esc_html__( 'Restaurant owner', 'guam-restaurants-core' ) . '</h2><table class="form-table" role="presentation"><tr><th><label for="gr-owner-restaurants">' . esc_html__( 'Restaurant IDs this user manages', 'guam-restaurants-core' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="gr-owner-restaurants" name="gr_owner_restaurants" value="' . esc_attr( implode( ', ', $owned ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Comma-separated. Link a user only after checking they own or manage the restaurant; a restaurant has one owner account, so this replaces any other. Give them the Restaurant owner role so they can write owner quotes and reply to reviews.', 'guam-restaurants-core' ) . '</p>';
		foreach ( $owned as $id ) {
			echo '<br>' . esc_html( sprintf( '%d: %s', $id, get_the_title( $id ) ) );
		}
		echo '</td></tr></table>';
	}

	/**
	 * Saves the profile field.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function save_profile_field( $user_id ): void {
		$user_id = (int) $user_id;
		if ( ! current_user_can( 'promote_users' ) || ! isset( $_POST['gr_owner_restaurants'], $_POST['gr_owner_restaurants_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['gr_owner_restaurants_nonce'] ) ), 'gr_owner_restaurants_' . $user_id ) ) {
			return;
		}
		$ids = wp_parse_id_list( sanitize_text_field( wp_unslash( $_POST['gr_owner_restaurants'] ) ) );
		self::set_owned_restaurants( $user_id, $ids );
	}
}
