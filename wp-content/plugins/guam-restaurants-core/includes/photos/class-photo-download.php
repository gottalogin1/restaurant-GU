<?php
/**
 * Downloads one image into the media library.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Http\Retrying_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches an image through the retrying client, checks that it really is a
 * JPEG, PNG or WebP image, and adds it to the media library.
 */
final class Photo_Download {

	public const MAX_BYTES = 10485760;

	private const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * Downloads an image and attaches it to a post.
	 *
	 * @param Retrying_Client $client    HTTP client.
	 * @param string          $url       Image URL.
	 * @param int             $parent_id Post the attachment belongs to (0 for none).
	 * @param string          $name      File name without extension, and the attachment title.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function fetch( Retrying_Client $client, string $url, int $parent_id, string $name ) {
		// reject_unsafe_urls below makes WordPress refuse local and private addresses.
		if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) || '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return new \WP_Error( 'gr_photo_url', __( 'The photo address is not a public http or https URL.', 'guam-restaurants-core' ) );
		}
		try {
			$response = $client->request(
				$url,
				array(
					'method'              => 'GET',
					'timeout'             => 30,
					'redirection'         => 3,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => self::MAX_BYTES,
					'user-agent'          => self::user_agent(),
				)
			);
		} catch ( Request_Failed $e ) {
			return new \WP_Error( 'gr_photo_http', $e->getMessage() );
		}
		if ( strlen( $response['body'] ) >= self::MAX_BYTES ) {
			return new \WP_Error( 'gr_photo_size', __( 'The photo is larger than 10 MB.', 'guam-restaurants-core' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( 'gr-photo' );
		if ( ! $tmp || false === file_put_contents( $tmp, $response['body'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file for media_handle_sideload().
			return new \WP_Error( 'gr_photo_tmp', __( 'Could not write a temporary file.', 'guam-restaurants-core' ) );
		}
		$mime = (string) wp_get_image_mime( $tmp );
		if ( ! isset( self::TYPES[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'gr_photo_type', __( 'The address did not return a JPEG, PNG or WebP image.', 'guam-restaurants-core' ) );
		}

		$attachment = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( $name ) . '.' . self::TYPES[ $mime ],
				'tmp_name' => $tmp,
			),
			$parent_id,
			$name
		);
		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $tmp );
		}
		return $attachment;
	}

	/**
	 * User agent for photo requests, naming the site.
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		return 'GuamRestaurants/1.0 (+' . home_url( '/' ) . ')';
	}
}
