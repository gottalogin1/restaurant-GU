<?php
/**
 * Visitor accounts: who they are and what they can reach.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Saved;

use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Site\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * A member is a visitor account: someone who signed up to keep a saved
 * list (by email and password, or with Google or Facebook through
 * LoginPress Pro's Social Login add-on). They get WordPress's Subscriber
 * role and nothing else.
 *
 * - Members never see wp-admin or the toolbar. Their account page is the
 *   Saved page, which has their list, sign out, change password and
 *   delete account. Restaurant owners (who use My restaurant in wp-admin)
 *   and staff are not members.
 * - After signing in, a member goes back to the page they came from. Where
 *   WordPress, WooCommerce or LoginPress would send them to wp-admin or
 *   WooCommerce's My account page instead, they go to the Saved page.
 * - Members can delete their own account from the Saved page. That removes
 *   the account and its saved list at once. Owners and staff write to us
 *   instead, because their accounts are linked to listings, orders or
 *   moderation records.
 * - The saved list is included in WordPress's personal data export and
 *   erased by its personal data eraser (Tools > Export / Erase Personal Data).
 */
final class Members {

	/**
	 * Admin-post action that deletes the signed-in member's account.
	 */
	public const DELETE_ACTION = 'gr_delete_account';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'show_admin_bar', array( self::class, 'show_admin_bar' ) );
		// Before WooCommerce's own redirect to My account (priority 10).
		add_action( 'admin_init', array( self::class, 'keep_out_of_admin' ), 1 );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 20, 3 );
		add_filter( 'woocommerce_login_redirect', array( self::class, 'woocommerce_login_redirect' ), 20, 2 );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( self::class, 'handle_delete' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'before_delete_post', array( Saved_List::class, 'forget_restaurant' ) );
	}

	/**
	 * Whether a user is a visitor account rather than staff or an owner.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_member( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		foreach ( array( 'edit_posts', 'manage_woocommerce', Roles::OWNER ) as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hides the toolbar from members.
	 *
	 * @param bool $show WordPress's decision.
	 * @return bool
	 */
	public static function show_admin_bar( $show ): bool {
		return self::is_member( get_current_user_id() ) ? false : (bool) $show;
	}

	/**
	 * Sends members who open wp-admin to the Saved page. Ajax and
	 * admin-post requests (which the site's forms use) pass.
	 *
	 * @return void
	 */
	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ! self::is_member( get_current_user_id() ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( 'admin-post.php' === $script ) {
			return;
		}
		wp_safe_redirect( self::home() );
		exit;
	}

	/**
	 * Where a member's account lives: the Saved page, else the home page.
	 *
	 * @return string
	 */
	public static function home(): string {
		$url = Pages::url( 'saved' );
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Whether a sign-in destination is one a member cannot use.
	 *
	 * @param string $url Destination.
	 * @return bool
	 */
	public static function is_dead_end( string $url ): bool {
		if ( '' === $url || 0 === strpos( $url, admin_url() ) || false !== strpos( $url, '/wp-admin/' ) ) {
			return true;
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$account = (string) wc_get_page_permalink( 'myaccount' );
			if ( '' !== $account && untrailingslashit( strtok( $url, '?#' ) ) === untrailingslashit( $account ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sends a member back where they came from, or to the Saved page
	 * instead of wp-admin or My account.
	 *
	 * @param string         $redirect_to Destination.
	 * @param string         $requested   Destination the request asked for.
	 * @param \WP_User|mixed $user        User signing in.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested = '', $user = null ): string {
		$redirect_to = (string) $redirect_to;
		if ( ! $user instanceof \WP_User || ! self::is_member( (int) $user->ID ) ) {
			return $redirect_to;
		}
		return self::is_dead_end( $redirect_to ) ? self::home() : $redirect_to;
	}

	/**
	 * Same rule for WooCommerce's own login form.
	 *
	 * @param string         $redirect_to Destination.
	 * @param \WP_User|mixed $user        User.
	 * @return string
	 */
	public static function woocommerce_login_redirect( $redirect_to, $user = null ): string {
		return self::login_redirect( $redirect_to, '', $user );
	}

	/**
	 * Deletes the signed-in member's account after they confirm, and sends
	 * them to the home page signed out.
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		$user_id = get_current_user_id();
		check_admin_referer( self::DELETE_ACTION );
		if ( ! self::is_member( $user_id ) ) {
			wp_die( esc_html__( 'Accounts linked to a restaurant or used by our staff cannot be deleted here. Write to us and we will help.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( add_query_arg( 'account', 'confirm-delete', self::home() ) . '#gr-account' );
			exit;
		}
		self::delete( $user_id );
		wp_logout();
		wp_safe_redirect( add_query_arg( 'account', 'deleted', home_url( '/' ) ) );
		exit;
	}

	/**
	 * Deletes a member's account and saved list.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function delete( int $user_id ): bool {
		if ( ! self::is_member( $user_id ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		Saved_List::clear( $user_id );
		return wp_delete_user( $user_id );
	}

	/**
	 * Adds the saved list to WordPress's personal data export.
	 *
	 * @param array<string, array<string, mixed>> $exporters Exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public static function exporters( $exporters ): array {
		$exporters                           = (array) $exporters;
		$exporters['guam-restaurants-saved'] = array(
			'exporter_friendly_name' => __( 'Saved restaurants', 'guam-restaurants-core' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Adds the saved list to WordPress's personal data eraser.
	 *
	 * @param array<string, array<string, mixed>> $erasers Erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public static function erasers( $erasers ): array {
		$erasers                           = (array) $erasers;
		$erasers['guam-restaurants-saved'] = array(
			'eraser_friendly_name' => __( 'Saved restaurants', 'guam-restaurants-core' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Personal data export of the saved list.
	 *
	 * @param string $email Email address.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public static function export( $email ): array {
		$user = get_user_by( 'email', (string) $email );
		$ids  = $user instanceof \WP_User ? Saved_List::get( (int) $user->ID ) : array();
		if ( ! $ids ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$names = array();
		foreach ( $ids as $id ) {
			$title   = get_the_title( $id );
			$names[] = '' !== $title ? $title . ' (' . get_permalink( $id ) . ')' : '#' . $id;
		}
		return array(
			'data' => array(
				array(
					'group_id'    => 'guam-restaurants-saved',
					'group_label' => __( 'Saved restaurants', 'guam-restaurants-core' ),
					'item_id'     => 'saved-' . $user->ID,
					'data'        => array(
						array(
							'name'  => __( 'Restaurants', 'guam-restaurants-core' ),
							'value' => implode( "\n", $names ),
						),
					),
				),
			),
			'done' => true,
		);
	}

	/**
	 * Personal data eraser for the saved list.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public static function erase( $email ): array {
		$user    = get_user_by( 'email', (string) $email );
		$removed = $user instanceof \WP_User && Saved_List::get( (int) $user->ID );
		if ( $removed ) {
			Saved_List::clear( (int) $user->ID );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
