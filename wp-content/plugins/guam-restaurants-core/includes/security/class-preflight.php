<?php
/**
 * Production readiness checks.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Security;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Site\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants preflight` (docs/deployment.md §4): one list of the
 * settings a live site needs, read from the running site. Each check is
 * `ok`, `warn` (fix before launch or accept knowingly) or `fail` (the
 * site is unsafe or broken). The deploy script stops on a fail.
 */
final class Preflight {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';

	/**
	 * Runs every check.
	 *
	 * @return array<int, array{check: string, status: string, detail: string}>
	 */
	public static function run(): array {
		$production = 'production' === wp_get_environment_type();
		$out        = array();
		$add        = static function ( string $check, string $status, string $detail ) use ( &$out ): void {
			$out[] = array(
				'check'  => $check,
				'status' => $status,
				'detail' => $detail,
			);
		};

		$add( 'Environment type', $production ? self::OK : self::WARN, wp_get_environment_type() . ( $production ? '' : ' (set WP_ENVIRONMENT_TYPE to production on the live site)' ) );

		$https = 0 === strpos( home_url(), 'https://' );
		$add( 'HTTPS site address', $https ? self::OK : ( $production ? self::FAIL : self::WARN ), home_url() );

		$display = ( defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) ) || in_array( strtolower( (string) ini_get( 'display_errors' ) ), array( '1', 'on', 'stdout' ), true );
		$add( 'Errors hidden from visitors', $display ? ( $production ? self::FAIL : self::WARN ) : self::OK, $display ? 'PHP errors are printed on pages: set WP_DEBUG_DISPLAY to false and display_errors off.' : 'WP_DEBUG_DISPLAY and display_errors are off.' );

		$file_edit = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$add( 'Theme and plugin editor off', $file_edit ? self::OK : self::WARN, $file_edit ? 'DISALLOW_FILE_EDIT is true.' : 'Add define( \'DISALLOW_FILE_EDIT\', true ); to wp-config.php.' );

		$add( 'Custom tables current', Schema::is_current() ? self::OK : self::FAIL, sprintf( 'stored %s, code %s', (string) get_option( Schema::VERSION_OPTION, '0' ), Schema::VERSION ) );

		$role = (string) get_option( 'default_role' );
		$add( 'New user role', in_array( $role, array( 'subscriber', 'customer' ), true ) ? self::OK : self::FAIL, 'default_role is ' . $role . ( get_option( 'users_can_register' ) ? '; anyone can register.' : '; registration is closed.' ) );

		$admin_user = get_user_by( 'login', 'admin' );
		$add( 'No "admin" login name', $admin_user ? self::WARN : self::OK, $admin_user ? 'A user named "admin" exists; brute-force tools try it first. Create a new administrator and delete it.' : 'No user is named "admin".' );

		$admins   = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		$enforced = Config::flag( Config::REQUIRE_ADMIN_2FA );
		if ( ! Two_Factor_Policy::plugin_available() ) {
			$add( 'Two-factor for administrators', self::WARN, 'The Two Factor plugin is not active (docs/security.md §4).' );
		} else {
			$missing = array_filter(
				array_map( 'intval', $admins ),
				static function ( int $id ): bool {
					return ! (bool) call_user_func( array( 'Two_Factor_Core', 'is_user_using_two_factor' ), $id );
				}
			);
			$add( 'Two-factor for administrators', $missing || ! $enforced ? self::WARN : self::OK, sprintf( '%d of %d administrators enrolled; GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA is %s.', count( $admins ) - count( $missing ), count( $admins ), $enforced ? 'on' : 'off' ) );
		}

		$limiter = self::active_plugin_folder( array( 'loginpress-pro', 'malcare-security' ) );
		$add( 'Login rate limiting', '' !== $limiter ? self::OK : self::WARN, '' !== $limiter ? $limiter . ' is active; check its login limit is on.' : 'Neither LoginPress Pro (Limit Login Attempts add-on) nor MalCare is active.' );

		$add( 'XML-RPC off', Config::flag( Config::ALLOW_XMLRPC ) ? self::WARN : self::OK, Config::flag( Config::ALLOW_XMLRPC ) ? 'GUAM_RESTAURANTS_ALLOW_XMLRPC is on.' : 'Off.' );

		$add( 'Security headers', Headers::enabled() ? self::OK : self::WARN, Headers::enabled() ? sprintf( 'Sent by the plugin; CSP %s; HSTS %s.', Headers::csp_mode(), Config::flag( Config::HSTS ) ? 'on' : 'off' ) : 'Plugin headers are off; make sure the server or CDN sends them (docs/security.md §3).' );

		$add( 'Persistent object cache', wp_using_ext_object_cache() ? self::OK : self::WARN, wp_using_ext_object_cache() ? 'In use.' : 'None: directory and map queries are cached for one request only (docs/performance.md §1).' );

		$server_cron = Jobs::MODE_SERVER === Jobs::mode();
		$add( 'Scheduled jobs', $server_cron ? self::OK : self::WARN, $server_cron ? 'Server crontab (GUAM_RESTAURANTS_CRON_MODE=server).' : 'WP-Cron runs only on visits; use the server crontab (docs/scheduling.md).' );

		$alert = sanitize_email( Config::get( Config::ALERT_EMAIL ) );
		$add( 'Alert email', '' !== $alert ? self::OK : self::WARN, '' !== $alert ? $alert : 'GUAM_RESTAURANTS_ALERT_EMAIL is not set; alerts go to the admin email.' );

		$public = (bool) get_option( 'blog_public' );
		if ( $production ) {
			$add( 'Search engines', $public ? self::OK : self::FAIL, $public ? 'Allowed.' : 'Settings > Reading discourages search engines on the live site.' );
		} else {
			$add( 'Search engines', $public ? self::WARN : self::OK, $public ? 'This copy can be indexed; discourage search engines on staging.' : 'Discouraged (right for staging).' );
		}

		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		$add( 'Privacy policy published', $privacy && 'publish' === get_post_status( $privacy ) ? self::OK : self::WARN, $privacy ? 'Page ' . $privacy . ' is ' . get_post_status( $privacy ) . '.' : 'No privacy policy page is set.' );
		$add( 'Terms of use published', '' !== Pages::url( 'terms' ) ? self::OK : self::WARN, '' !== Pages::url( 'terms' ) ? Pages::url( 'terms' ) : 'The terms page is still a draft (counsel review).' );

		return $out;
	}

	/**
	 * Whether any check failed.
	 *
	 * @param array<int, array{status: string}> $results From run().
	 * @return bool
	 */
	public static function has_failures( array $results ): bool {
		return in_array( self::FAIL, array_column( $results, 'status' ), true );
	}

	/**
	 * First active plugin whose folder is in the list, or ''.
	 *
	 * @param string[] $folders Plugin folders.
	 * @return string
	 */
	private static function active_plugin_folder( array $folders ): string {
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );
			if ( in_array( $folder, $folders, true ) ) {
				return (string) $folder;
			}
		}
		return '';
	}
}
