<?php
/**
 * Holiday hours and truck stops on the restaurant edit screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Site\Special_Hours;
use GuamRestaurants\Core\Site\Truck_Stops;

defined( 'ABSPATH' ) || exit;

/**
 * Lets our editors set holiday and special hours and truck or pop-up stops
 * for a restaurant whose owner told them, for example by phone, using the
 * same rules as the owner dashboard (Owners\Dashboard). Each change is
 * written to the change log as "editor".
 */
final class Special_Hours_Box {

	public const NONCE_ACTION = 'gr_special_hours_box';
	public const NONCE_FIELD  = 'gr_special_hours_nonce';
	private const NOTICE      = 'gr_special_hours_notice_';

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
		add_meta_box( 'gr-special-hours', __( 'Holiday hours and truck stops', 'guam-restaurants-core' ), array( self::class, 'render' ), Content_Types::POST_TYPE, 'normal', 'low' );
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Restaurant.
	 * @return void
	 */
	public static function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p class="description">' . esc_html__( 'Only what the owner told us, or what the restaurant posted itself. Owners can also set these in their dashboard.', 'guam-restaurants-core' ) . '</p>';
		echo '<h4>' . esc_html__( 'Holiday and special hours', 'guam-restaurants-core' ) . '</h4>';
		echo Special_Hours::form_rows( $post->ID, 'gr_special_hours' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		echo '<h4>' . esc_html__( 'Food truck and pop-up stops', 'guam-restaurants-core' ) . '</h4>';
		echo Truck_Stops::form_rows( $post->ID, 'gr_truck_stops' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}

	/**
	 * Saves both lists.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each row is sanitized by from_input().
		$special = isset( $_POST['gr_special_hours'] ) && is_array( $_POST['gr_special_hours'] ) ? Special_Hours::from_input( wp_unslash( $_POST['gr_special_hours'] ) ) : array();
		$stops   = isset( $_POST['gr_truck_stops'] ) && is_array( $_POST['gr_truck_stops'] ) ? Truck_Stops::from_input( wp_unslash( $_POST['gr_truck_stops'] ) ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$problems = array();
		$values   = array();
		foreach ( array(
			Special_Hours::META => $special,
			Truck_Stops::META   => $stops,
		) as $key => $value ) {
			if ( is_wp_error( $value ) ) {
				$problems = array_merge( $problems, $value->get_error_messages() );
			} else {
				$values[ $key ] = $value;
			}
		}
		Change_Tracker::suppress(
			static function () use ( $values, $post_id ) {
				foreach ( $values as $key => $value ) {
					$old = get_post_meta( $post_id, $key, true );
					if ( ( $old ? $old : array() ) === $value ) {
						continue;
					}
					if ( $value ) {
						update_post_meta( $post_id, $key, $value );
					} else {
						delete_post_meta( $post_id, $key );
					}
					Change_Log::record( $post_id, $key, '' === $old ? null : $old, $value, 'editor' );
				}
			}
		);
		if ( $problems ) {
			set_transient( self::NOTICE . get_current_user_id(), __( 'Some holiday hours or stops were not saved:', 'guam-restaurants-core' ) . ' ' . implode( ' ', array_unique( $problems ) ), 60 );
		}
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
