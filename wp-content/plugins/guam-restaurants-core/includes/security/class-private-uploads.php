<?php
/**
 * A folder under uploads that browsers cannot open.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * `wp-content/uploads/gr-private/` holds files the site keeps but never
 * shows, such as the original of a visitor photo with its camera and
 * location data (Photos\Photo_Submissions).
 *
 * Apache obeys the `.htaccess` written here. nginx and some managed hosts
 * ignore it, so they need a server rule (docs/security.md §2), and
 * `wp guam-restaurants preflight` requests a probe file to check that the
 * folder really is closed.
 */
final class Private_Uploads {

	public const FOLDER = 'gr-private';
	public const PROBE  = 'preflight-probe.txt';

	/**
	 * Apache 2.4 and 2.2 rules that refuse every request into the folder.
	 */
	public const HTACCESS = "# Written by guam-restaurants-core. Files here are never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n";

	/**
	 * Absolute path of the private folder, or of a subfolder of it, created
	 * with its protection files when missing.
	 *
	 * @param string $sub Subfolder such as `photo-originals`, or ''.
	 * @return string Path without a trailing slash, or '' when it could not be created.
	 */
	public static function dir( string $sub = '' ): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$root = untrailingslashit( (string) $uploads['basedir'] ) . '/' . self::FOLDER;
		$path = '' === $sub ? $root : $root . '/' . sanitize_file_name( $sub );
		if ( ! wp_mkdir_p( $path ) ) {
			return '';
		}
		self::protect( $root );
		if ( $path !== $root ) {
			self::write_once( $path . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $path;
	}

	/**
	 * Public URL the folder would have if the server served it (for the
	 * preflight probe).
	 *
	 * @return string
	 */
	public static function url(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) ( $uploads['baseurl'] ?? '' ) ) . '/' . self::FOLDER;
	}

	/**
	 * Asks the web server for a probe file in the private folder, as a
	 * visitor would.
	 *
	 * @return string `closed`, `open`, or `unknown` when the site could not
	 *                be reached from here.
	 */
	public static function probe(): string {
		$dir = self::dir();
		if ( '' === $dir ) {
			return 'unknown';
		}
		$token = wp_generate_password( 24, false, false );
		self::write_once( $dir . '/' . self::PROBE, $token );
		$response = wp_remote_get(
			self::url() . '/' . self::PROBE,
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);
		wp_delete_file( $dir . '/' . self::PROBE );
		if ( is_wp_error( $response ) || 0 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'unknown';
		}
		return false !== strpos( (string) wp_remote_retrieve_body( $response ), $token ) ? 'open' : 'closed';
	}

	/**
	 * Asks the web server for the uploads folder itself, which has no index
	 * file, to see whether it lists the files in it.
	 *
	 * @return string `off`, `on`, or `unknown`.
	 */
	public static function listing(): string {
		$uploads  = wp_upload_dir( null, false );
		$response = wp_remote_get(
			trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) ),
			array(
				'timeout'     => 5,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) || 0 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'unknown';
		}
		$listed = 200 === (int) wp_remote_retrieve_response_code( $response ) && 1 === preg_match( '/<title>\s*Index of/i', (string) wp_remote_retrieve_body( $response ) );
		return $listed ? 'on' : 'off';
	}

	/**
	 * Whether a path is inside the private folder.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function contains( string $path ): bool {
		$root = self::dir();
		return '' !== $root && 0 === strpos( wp_normalize_path( $path ), wp_normalize_path( $root ) . '/' );
	}

	/**
	 * Writes the deny rules and an empty index into the folder.
	 *
	 * @param string $root Folder.
	 * @return void
	 */
	private static function protect( string $root ): void {
		self::write_once( $root . '/.htaccess', self::HTACCESS );
		self::write_once( $root . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	/**
	 * Writes a file unless it already has this content.
	 *
	 * @param string $file    Path.
	 * @param string $content Content.
	 * @return void
	 */
	private static function write_once( string $file, string $content ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- tiny local files; WP_Filesystem may need credentials.
		if ( is_readable( $file ) && file_get_contents( $file ) === $content ) {
			return;
		}
		file_put_contents( $file, $content );
		// phpcs:enable
	}
}
