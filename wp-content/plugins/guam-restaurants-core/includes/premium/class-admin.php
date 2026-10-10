<?php
/**
 * Premium profile box on the restaurant edit screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Premium;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an administrator (Ads::CAP) see and set a restaurant's premium
 * profile end date, for example after a manual invoice is paid, and shows
 * the history of changes (orders, refunds, manual edits).
 */
final class Admin {

	public const NONCE = 'gr_save_premium';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = Content_Types::POST_TYPE;
		add_action( "add_meta_boxes_{$type}", array( self::class, 'add_meta_box' ) );
		add_action( "save_post_{$type}", array( self::class, 'save' ), 20 );
	}

	/**
	 * Adds the box for ad managers.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		if ( current_user_can( Ads::CAP ) ) {
			add_meta_box( 'gr-premium', __( 'Premium profile', 'guam-restaurants-core' ), array( self::class, 'box' ), Content_Types::POST_TYPE, 'side', 'default' );
		}
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post Restaurant.
	 * @return void
	 */
	public static function box( $post ): void {
		$id    = (int) $post->ID;
		$until = Premium::until( $id );
		wp_nonce_field( self::NONCE, '_gr_premium_nonce' );
		if ( Premium::is_active( $id ) ) {
			/* translators: %s: date. */
			echo '<p>' . esc_html( sprintf( __( 'Running until %s (Guam).', 'guam-restaurants-core' ), $until ) ) . '</p>';
		} else {
			echo '<p>' . esc_html( '' === $until ? __( 'No premium profile.', 'guam-restaurants-core' ) : __( 'Ended.', 'guam-restaurants-core' ) ) . '</p>';
		}
		printf(
			'<p><label for="gr-premium-until">%1$s</label><br><input type="date" class="widefat" id="gr-premium-until" name="gr_premium[until]" value="%2$s"></p><p><label for="gr-premium-note">%3$s</label><br><input type="text" class="widefat" id="gr-premium-note" name="gr_premium[note]" maxlength="200"></p>',
			esc_html__( 'Last day (empty: none)', 'guam-restaurants-core' ),
			esc_attr( $until ),
			esc_html__( 'Reason for a change, e.g. invoice number', 'guam-restaurants-core' )
		);
		echo '<p class="description">' . esc_html__( 'Paid WooCommerce orders add time here by themselves. A premium profile never changes ratings, awards or list position.', 'guam-restaurants-core' ) . '</p>';
		$log = array_slice( Premium::log( $id ), 0, 10 );
		if ( $log ) {
			echo '<ul style="margin:0;font-size:12px">';
			foreach ( $log as $entry ) {
				printf( '<li>%1$s: %2$s → %3$s %4$s</li>', esc_html( wp_date( 'Y-m-d', (int) $entry['at'] ) ), esc_html( (string) $entry['change'] ), esc_html( '' === $entry['until'] ? '-' : (string) $entry['until'] ), esc_html( (string) $entry['note'] ) );
			}
			echo '</ul>';
		}
	}

	/**
	 * Saves the box.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_gr_premium_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gr_premium_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$input = isset( $_POST['gr_premium'] ) && is_array( $_POST['gr_premium'] ) ? wp_unslash( $_POST['gr_premium'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_input() cleans each field.
		self::save_input( $post_id, $input );
	}

	/**
	 * Saves box input. Used by save() and the tests.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input         `until` and `note`.
	 * @return bool Whether it changed.
	 */
	public static function save_input( int $restaurant_id, array $input ): bool {
		if ( ! current_user_can( Ads::CAP ) || Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) ) {
			return false;
		}
		$until = sanitize_text_field( (string) ( $input['until'] ?? '' ) );
		$note  = sanitize_text_field( (string) ( $input['note'] ?? '' ) );
		/* translators: %s: today's date. */
		return Premium::set_until( $restaurant_id, $until, '' === $note ? sprintf( __( 'Set by an administrator on %s', 'guam-restaurants-core' ), Server::today() ) : $note );
	}
}
