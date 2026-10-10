<?php
/**
 * Moderation screen for visitor reviews in wp-admin.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Reviews;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Restaurants → Visitor reviews. Moderators approve a review, or reject it
 * by picking the published rule it breaks. There is no "reject" without a
 * rule, and no option based on how positive or negative a review is.
 */
final class Moderation_Screen {

	public const PAGE       = 'gr-visitor-reviews';
	public const ACTION     = 'guam_restaurants_moderate_review';
	public const CAPABILITY = 'moderate_comments';

	/**
	 * Hooks the menu and form handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Adds the submenu with a pending count.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		$pending = Visitor_Reviews::count( 'pending' );
		$label   = __( 'Visitor reviews', 'guam-restaurants-core' );
		if ( $pending > 0 ) {
			$label .= sprintf( ' <span class="awaiting-mod">%d</span>', $pending );
		}
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			__( 'Visitor reviews', 'guam-restaurants-core' ),
			$label,
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Handles an approve or reject submission.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to moderate reviews.', 'guam-restaurants-core' ), 403 );
		}
		$review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $review_id );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$result   = Visitor_Reviews::moderate( $review_id, 'approve' === $decision ? 'approved' : 'rejected', $reason, get_current_user_id() );

		$back = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => Content_Types::POST_TYPE,
					'page'      => self::PAGE,
					'status'    => $back,
					'gr_notice' => is_wp_error( $result ) ? $result->get_error_code() : 'gr_review_done',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$notice = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $status, Vocabulary::HIGHLIGHT_STATUSES, true ) ) {
			$status = 'pending';
		}
		$rules   = Visitor_Reviews::rules();
		$reviews = Visitor_Reviews::for_moderation( $status );
		$base    = admin_url( 'edit.php?post_type=' . Content_Types::POST_TYPE . '&page=' . self::PAGE );

		echo '<div class="wrap"><h1>' . esc_html__( 'Visitor reviews', 'guam-restaurants-core' ) . '</h1>';

		if ( 'gr_review_done' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Review updated.', 'guam-restaurants-core' ) . '</p></div>';
		} elseif ( 'gr_review_reason' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose the review rule this review breaks before rejecting it.', 'guam-restaurants-core' ) . '</p></div>';
		} elseif ( '' !== $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The review could not be updated.', 'guam-restaurants-core' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Approve every review that follows the rules, positive or negative. Reject only under a published rule, and never because a restaurant or advertiser asks (docs/legal-and-policy.md §2.3 and §9).', 'guam-restaurants-core' ) . '</p>';

		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( array(
			'pending'  => __( 'Pending', 'guam-restaurants-core' ),
			'approved' => __( 'Approved', 'guam-restaurants-core' ),
			'rejected' => __( 'Rejected', 'guam-restaurants-core' ),
		) as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a></li>',
				esc_url( add_query_arg( 'status', $key, $base ) ),
				$key === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) Visitor_Reviews::count( $key )
			);
		}
		echo wp_kses_post( implode( ' | ', $links ) ) . '</ul><br class="clear">';

		if ( ! $reviews ) {
			echo '<p>' . esc_html__( 'No reviews here.', 'guam-restaurants-core' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Restaurant', 'guam-restaurants-core' ), __( 'Rating', 'guam-restaurants-core' ), __( 'Review', 'guam-restaurants-core' ), __( 'Submitted', 'guam-restaurants-core' ), __( 'Decision', 'guam-restaurants-core' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $reviews as $review ) {
			$id = (int) $review['id'];
			echo '<tr id="review-' . esc_attr( (string) $id ) . '">';
			echo '<td><a href="' . esc_url( (string) get_permalink( (int) $review['restaurant_id'] ) ) . '">' . esc_html( get_the_title( (int) $review['restaurant_id'] ) ) . '</a></td>';
			/* translators: %d: star rating. */
			echo '<td>' . esc_html( sprintf( __( '%d of 5', 'guam-restaurants-core' ), (int) $review['rating'] ) ) . '</td>';
			echo '<td><strong>' . esc_html( (string) $review['author_name'] ) . '</strong>';
			if ( '' !== (string) $review['disclosure'] ) {
				/* translators: %s: reviewer's own description of their connection. */
				echo '<br><em>' . esc_html( sprintf( __( 'Says they are connected to the restaurant: %s', 'guam-restaurants-core' ), (string) $review['disclosure'] ) ) . '</em>';
			}
			echo '<p style="white-space:pre-line">' . esc_html( (string) $review['body'] ) . '</p>';
			if ( 'rejected' === $review['status'] && isset( $rules[ $review['moderation_reason'] ] ) ) {
				/* translators: %s: rule text. */
				echo '<p><em>' . esc_html( sprintf( __( 'Rejected: %s', 'guam-restaurants-core' ), $rules[ $review['moderation_reason'] ] ) ) . '</em></p>';
			}
			echo '</td><td>' . esc_html( mysql2date( 'Y-m-d H:i', (string) $review['created_at'] ) ) . ' UTC</td><td>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::ACTION . '_' . $id );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			echo '<input type="hidden" name="review_id" value="' . esc_attr( (string) $id ) . '">';
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
			if ( 'approved' !== $review['status'] ) {
				echo '<p><button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Approve', 'guam-restaurants-core' ) . '</button></p>';
			}
			if ( 'rejected' !== $review['status'] ) {
				echo '<p><label class="screen-reader-text" for="reason-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Rule broken', 'guam-restaurants-core' ) . '</label>';
				echo '<select id="reason-' . esc_attr( (string) $id ) . '" name="reason"><option value="">' . esc_html__( 'Rule broken…', 'guam-restaurants-core' ) . '</option>';
				foreach ( $rules as $rule => $text ) {
					echo '<option value="' . esc_attr( $rule ) . '">' . esc_html( $text ) . '</option>';
				}
				echo '</select> <button type="submit" name="decision" value="reject" class="button">' . esc_html__( 'Reject', 'guam-restaurants-core' ) . '</button></p>';
			}
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
