<?php
/**
 * Two-factor sign-in required for administrators.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Security;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * With GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA=1 and the Two Factor plugin
 * active, an administrator who has not turned on a two-factor method is
 * sent to their profile on every wp-admin screen until they do
 * (docs/security.md §4). The profile, AJAX, cron and WP-CLI keep working,
 * so nobody is locked out, and the Two Factor plugin's own recovery (backup
 * codes, or `wp user meta delete` from the command line) still applies.
 *
 * The Two Factor plugin has no enforcement setting of its own (its readme:
 * "there are no built-in enforcement settings").
 */
final class Two_Factor_Policy {

	public const ROLE_CAPABILITY = 'manage_options';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'enforce' ), 1 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Whether the policy is on and can be checked.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return Config::flag( Config::REQUIRE_ADMIN_2FA ) && self::plugin_available();
	}

	/**
	 * Whether the Two Factor plugin is loaded.
	 *
	 * @return bool
	 */
	public static function plugin_available(): bool {
		return class_exists( 'Two_Factor_Core' ) && method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' );
	}

	/**
	 * Whether this user must set up two-factor sign-in before using wp-admin.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function needs_setup( int $user_id ): bool {
		if ( ! $user_id || ! self::active() || ! user_can( $user_id, self::ROLE_CAPABILITY ) ) {
			return false;
		}
		return ! (bool) call_user_func( array( 'Two_Factor_Core', 'is_user_using_two_factor' ), $user_id );
	}

	/**
	 * Redirects to the profile until two-factor is on.
	 *
	 * @return void
	 */
	public static function enforce(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ! self::needs_setup( get_current_user_id() ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'profile.php', 'admin-post.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'gr_2fa', 'required', admin_url( 'profile.php' ) ) . '#two-factor-options' );
		exit;
	}

	/**
	 * Explains the redirect on the profile screen.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! self::needs_setup( get_current_user_id() ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Administrators must turn on two-factor sign-in. Choose a method under Two-Factor Options below (an authenticator app plus backup codes is recommended), save your profile, and the rest of wp-admin opens again.', 'guam-restaurants-core' ) . '</p></div>';
	}
}
