<?php
/**
 * WordPress hardening the site needs whatever plugins are installed.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Security;

use GuamRestaurants\Core\Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Closes doors this site does not use (docs/security.md §2):
 *
 * - XML-RPC off (pingbacks and its login method are brute-force targets),
 *   unless GUAM_RESTAURANTS_ALLOW_XMLRPC=1.
 * - No user enumeration: the users REST routes need a signed-in user,
 *   `?author=N` scans get a 404, and the core sitemap has no users list.
 * - No WordPress version in the page head.
 * - Upload limits: one size cap for everyone (default 10 MB), images over
 *   40 megapixels refused (they exhaust memory when thumbnails are made),
 *   and only images and PDFs for anyone who is not an administrator.
 *
 * Login rate limiting, CAPTCHA and the firewall come from the owned
 * plugins (LoginPress Pro's Limit Login Attempts add-on, MalCare); this
 * class does not duplicate them.
 */
final class Hardening {

	public const MAX_MEGAPIXELS = 40;

	/**
	 * Types people without manage_options may upload.
	 */
	public const LIMITED_MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'webp'         => 'image/webp',
		'avif'         => 'image/avif',
		'pdf'          => 'application/pdf',
	);

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! Config::flag( Config::ALLOW_XMLRPC ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
		}
		add_filter( 'rest_pre_dispatch', array( self::class, 'protect_user_routes' ), 10, 3 );
		add_action( 'template_redirect', array( self::class, 'block_author_scans' ), 1 );
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'drop_user_sitemap' ), 10, 2 );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );

		add_filter( 'upload_size_limit', array( self::class, 'upload_size_limit' ) );
		add_filter( 'upload_mimes', array( self::class, 'upload_mimes' ), 20, 2 );
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'check_image_size' ) );
	}

	/**
	 * Refuses /wp/v2/users for visitors who are not signed in.
	 *
	 * @param mixed            $result  Response so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function protect_user_routes( $result, $server, $request ) {
		unset( $server );
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}
		$route = (string) $request->get_route();
		if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
			return new WP_Error( 'rest_user_cannot_view', __( 'Sign in to list users.', 'guam-restaurants-core' ), array( 'status' => 401 ) );
		}
		return $result;
	}

	/**
	 * Whether this request is an author archive or an `?author=` scan.
	 *
	 * @return bool
	 */
	public static function is_author_scan(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check.
		return ! is_user_logged_in() && ( isset( $_GET['author'] ) || is_author() );
	}

	/**
	 * Sends author archives and `?author=N` to a 404 for visitors. The site
	 * has no author pages; they only reveal login names.
	 *
	 * @return void
	 */
	public static function block_author_scans(): void {
		if ( ! self::is_author_scan() ) {
			return;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Leaves the users list out of the core sitemap.
	 *
	 * @param mixed  $provider Provider.
	 * @param string $name     Provider name.
	 * @return mixed False for users.
	 */
	public static function drop_user_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Configured upload cap in bytes.
	 *
	 * @return int
	 */
	public static function max_upload_bytes(): int {
		$mb = Config::get( Config::MAX_UPLOAD_MB );
		$mb = is_numeric( $mb ) && (float) $mb > 0 ? (float) $mb : Config::DEFAULT_MAX_UPLOAD_MB;
		return (int) round( $mb * MB_IN_BYTES );
	}

	/**
	 * Caps uploads at the configured size (`upload_size_limit`).
	 *
	 * @param int $bytes Limit from PHP's settings.
	 * @return int
	 */
	public static function upload_size_limit( $bytes ): int {
		$bytes = (int) $bytes;
		return $bytes > 0 ? min( $bytes, self::max_upload_bytes() ) : self::max_upload_bytes();
	}

	/**
	 * Only images and PDFs for people who cannot manage the site.
	 *
	 * @param array<string, string> $mimes Allowed types.
	 * @param mixed                 $user  User ID, WP_User or null.
	 * @return array<string, string>
	 */
	public static function upload_mimes( $mimes, $user = null ): array {
		$user_id = $user instanceof \WP_User ? (int) $user->ID : ( is_numeric( $user ) ? (int) $user : get_current_user_id() );
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			return (array) $mimes;
		}
		return array_intersect_key( (array) $mimes, self::LIMITED_MIMES );
	}

	/**
	 * Refuses oversized files and images above MAX_MEGAPIXELS before
	 * WordPress tries to make thumbnails of them.
	 *
	 * @param array<string, mixed> $file Upload ($_FILES entry).
	 * @return array<string, mixed>
	 */
	public static function check_image_size( $file ): array {
		$file = (array) $file;
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}
		if ( isset( $file['size'] ) && (int) $file['size'] > self::max_upload_bytes() ) {
			/* translators: %s: size such as "10 MB". */
			$file['error'] = sprintf( __( 'Files must be %s or smaller.', 'guam-restaurants-core' ), size_format( self::max_upload_bytes() ) );
			return $file;
		}
		$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $path || ! is_readable( $path ) ) {
			return $file;
		}
		$size = wp_getimagesize( $path );
		if ( is_array( $size ) && ( (int) $size[0] * (int) $size[1] ) > self::MAX_MEGAPIXELS * 1000000 ) {
			/* translators: %d: megapixels. */
			$file['error'] = sprintf( __( 'Images can be at most %d megapixels. Resize it and try again.', 'guam-restaurants-core' ), self::MAX_MEGAPIXELS );
		}
		return $file;
	}
}
