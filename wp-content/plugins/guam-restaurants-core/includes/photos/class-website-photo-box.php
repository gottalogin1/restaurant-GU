<?php
/**
 * "Photo from the restaurant's website" box on the restaurant edit screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an editor set a photo from the restaurant's own website as the
 * featured image, but only after recording who gave permission and how.
 * Photos on a website belong to whoever took them, so this never runs
 * without that record.
 */
final class Website_Photo_Box {

	public const PERMISSION = '_gr_photo_permission';

	private const NONCE_ACTION = 'guam_restaurants_website_photo';
	private const NONCE_FIELD  = 'guam_restaurants_website_photo_nonce';
	private const URL_FIELD    = 'guam_restaurants_website_photo_url';
	private const NOTE_FIELD   = 'guam_restaurants_website_photo_note';
	private const OK_FIELD     = 'guam_restaurants_website_photo_ok';
	private const NOTICE       = 'gr_website_photo_notice_';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes_' . Content_Types::POST_TYPE, array( self::class, 'add' ) );
		add_action( 'save_post_' . Content_Types::POST_TYPE, array( self::class, 'save' ), 30 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	/**
	 * Adds the box.
	 *
	 * @return void
	 */
	public static function add(): void {
		add_meta_box( 'gr-website-photo', __( 'Photo from the restaurant\'s website', 'guam-restaurants-core' ), array( self::class, 'render' ), Content_Types::POST_TYPE, 'side', 'low' );
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Restaurant.
	 * @return void
	 */
	public static function render( \WP_Post $post ): void {
		$featured   = (int) get_post_thumbnail_id( $post->ID );
		$permission = $featured > 0 ? get_post_meta( $featured, self::PERMISSION, true ) : '';
		if ( is_array( $permission ) ) {
			printf(
				'<p>%1$s <em>%2$s</em></p>',
				esc_html__( 'The featured image came from the website with permission:', 'guam-restaurants-core' ),
				esc_html( (string) ( $permission['note'] ?? '' ) )
			);
		}
		$website = (string) get_post_meta( $post->ID, Restaurant_Meta::WEBSITE, true );
		echo '<p class="description">' . esc_html__( 'Use only when the owner has said we may use the photo. Without permission, leave it empty: the card shows a labeled cuisine photo instead.', 'guam-restaurants-core' ) . '</p>';
		if ( '' !== $website ) {
			printf( '<p><a href="%1$s" target="_blank" rel="noopener">%2$s</a></p>', esc_url( $website ), esc_html__( 'Open the website', 'guam-restaurants-core' ) );
		}
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		printf(
			'<p><label for="%1$s">%2$s</label><input type="url" class="widefat" id="%1$s" name="%1$s" placeholder="https://" /></p>',
			esc_attr( self::URL_FIELD ),
			esc_html__( 'Image address (right-click the photo, Copy image address)', 'guam-restaurants-core' )
		);
		printf(
			'<p><label for="%1$s">%2$s</label><input type="text" class="widefat" id="%1$s" name="%1$s" /></p>',
			esc_attr( self::NOTE_FIELD ),
			esc_html__( 'Who said yes, how and when (for example: owner Maria by email, 2026-10-21)', 'guam-restaurants-core' )
		);
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
			esc_attr( self::OK_FIELD ),
			esc_html__( 'The owner gave us permission to use this photo', 'guam-restaurants-core' )
		);
		echo '<p class="description">' . esc_html__( 'On Update, the photo is downloaded and set as the featured image.', 'guam-restaurants-core' ) . '</p>';
	}

	/**
	 * Downloads the photo on save when the fields are filled in.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( empty( $_POST[ self::URL_FIELD ] ) || ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$url    = esc_url_raw( wp_unslash( $_POST[ self::URL_FIELD ] ) );
		$note   = isset( $_POST[ self::NOTE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NOTE_FIELD ] ) ) : '';
		$ok     = ! empty( $_POST[ self::OK_FIELD ] );
		$result = $ok ? self::attach( $post_id, $url, $note ) : new \WP_Error( 'gr_photo_permission', __( 'Tick "The owner gave us permission" to use a website photo.', 'guam-restaurants-core' ) );

		set_transient(
			self::NOTICE . get_current_user_id(),
			is_wp_error( $result )
				? array( 'error', __( 'Website photo not saved:', 'guam-restaurants-core' ) . ' ' . $result->get_error_message() )
				: array( 'success', __( 'Website photo saved as the featured image.', 'guam-restaurants-core' ) ),
			60
		);
	}

	/**
	 * Downloads a website photo and sets it as the featured image.
	 *
	 * @param int                  $post_id Restaurant.
	 * @param string               $url     Image URL.
	 * @param string               $note    Who gave permission, how and when.
	 * @param Retrying_Client|null $client  HTTP client.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function attach( int $post_id, string $url, string $note, ?Retrying_Client $client = null ) {
		if ( '' === trim( $note ) ) {
			return new \WP_Error( 'gr_photo_permission', __( 'Write down who gave permission, how and when.', 'guam-restaurants-core' ) );
		}
		$attachment = Photo_Download::fetch( $client ?? new Retrying_Client( null, null, array( 429 ), 5, 1 ), $url, $post_id, sanitize_title( get_the_title( $post_id ) ) . '-photo' );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		update_post_meta( $attachment, '_wp_attachment_image_alt', sanitize_text_field( get_the_title( $post_id ) ) );
		update_post_meta(
			$attachment,
			self::PERMISSION,
			array(
				'note'       => $note,
				'source_url' => $url,
				'user_id'    => get_current_user_id(),
				'date'       => gmdate( 'Y-m-d' ),
			)
		);
		set_post_thumbnail( $post_id, $attachment );
		return $attachment;
	}

	/**
	 * Shows the result of the last save.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice[0] ? 'error' : 'success' ), esc_html( (string) $notice[1] ) );
	}
}
