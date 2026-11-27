<?php
/**
 * Buffet and all-you-can-eat on the restaurant edit screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Buffets;

defined( 'ABSPATH' ) || exit;

/**
 * Lets our editors set a place's buffet or all-you-can-eat facts from the
 * restaurant's own page or from what the owner told them, with the same
 * rules as the owner dashboard. The source address is shown on the listing
 * (CLAUDE.md rule 3); each change is written to the change log as "editor".
 */
final class Buffet_Box {

	public const NONCE_ACTION = 'gr_buffet_box';
	public const NONCE_FIELD  = 'gr_buffet_nonce';
	public const FIELD        = 'gr_buffet';
	private const NOTICE      = 'gr_buffet_notice_';

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
		add_meta_box( 'gr-buffet', __( 'Buffet or all-you-can-eat', 'guam-restaurants-core' ), array( self::class, 'render' ), Content_Types::POST_TYPE, 'normal', 'low' );
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Restaurant.
	 * @return void
	 */
	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p class="description">' . esc_html__( 'Only what the restaurant publishes itself or what the owner told us. Give the adult price per person before service charge. Owners can also set this in their dashboard; saving here marks it as checked today.', 'guam-restaurants-core' ) . '</p>';
		echo Buffets::form_fields( $post->ID, self::FIELD, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}

	/**
	 * Saves the facts.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in Buffets::from_input().
		$input = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array();
		$value = Buffets::from_input( $input, 'editor' );
		if ( is_wp_error( $value ) ) {
			set_transient( self::NOTICE . get_current_user_id(), __( 'The buffet was not saved:', 'guam-restaurants-core' ) . ' ' . implode( ' ', array_unique( $value->get_error_messages() ) ), 60 );
			return;
		}
		Buffets::save( $post_id, $value, 'editor' );
	}

	/**
	 * Shows what could not be saved.
	 *
	 * @return void
	 */
	public static function notice(): void {
		$message = get_transient( self::NOTICE . get_current_user_id() );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( self::NOTICE . get_current_user_id() );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
