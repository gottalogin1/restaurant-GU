<?php
/**
 * Restaurants > Claims: approve or reject listing claims.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Claims;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Lists claims whose code was confirmed, waiting for a decision, with who
 * asked, how they confirmed and the address the code went to; then claims
 * still waiting for a code, and the latest decisions. Needs Claims::CAP.
 */
final class Admin {

	public const PAGE   = 'gr-claims';
	public const ACTION = 'gr_claim_decide';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Adds the screen under Restaurants, with a count of claims to review.
	 *
	 * @return void
	 */
	public static function menu(): void {
		$waiting = current_user_can( Claims::CAP ) ? count( Claims::with_status( Claims::VERIFIED ) ) : 0;
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			__( 'Listing claims', 'guam-restaurants-core' ),
			__( 'Claims', 'guam-restaurants-core' ) . ( $waiting ? ' <span class="awaiting-mod">' . (int) $waiting . '</span>' : '' ),
			Claims::CAP,
			self::PAGE,
			array( self::class, 'screen' )
		);
	}

	/**
	 * Approve or reject.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( Claims::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to decide claims.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		$claim_id = isset( $_POST['claim'] ) ? absint( $_POST['claim'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
		$result   = 'approve' === $decision
			? Claims::approve( $claim_id, get_current_user_id() )
			: Claims::reject( $claim_id, get_current_user_id(), $reason );
		$args     = array(
			'post_type' => Content_Types::POST_TYPE,
			'page'      => self::PAGE,
			'done'      => is_wp_error( $result ) ? 'error' : $decision,
		);
		if ( is_wp_error( $result ) ) {
			$args['message'] = rawurlencode( $result->get_error_message() );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
		exit;
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function screen(): void {
		if ( ! current_user_can( Claims::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to see claims.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Listing claims', 'guam-restaurants-core' ) . '</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice after redirect.
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		if ( 'error' === $done ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( $_GET['message'] ) ) : '' ) );
		} elseif ( '' !== $done ) {
			printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( 'approve' === $done ? __( 'Claim approved. The account is now linked to the listing as its owner.', 'guam-restaurants-core' ) : __( 'Claim rejected.', 'guam-restaurants-core' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		echo '<p>' . esc_html__( 'Approve a claim only when the address or phone the code went to clearly belongs to the restaurant. Approval lets the account edit hours, menu links, photos and specials and reply to reviews; it never lets them change ratings, awards, status history or reviews.', 'guam-restaurants-core' ) . '</p>';

		echo '<h2>' . esc_html__( 'Confirmed, waiting for review', 'guam-restaurants-core' ) . '</h2>';
		$rows = Claims::with_status( Claims::VERIFIED );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing to review.', 'guam-restaurants-core' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Listing', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Account', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Confirmed with', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Confirmed (UTC)', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Decision', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $claim ) {
				self::row( $claim, true );
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Waiting for the code', 'guam-restaurants-core' ) . '</h2>';
		$rows = Claims::with_status( Claims::PENDING );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'None.', 'guam-restaurants-core' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Listing', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Account', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Code sent to', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Requested (UTC)', 'guam-restaurants-core' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $claim ) {
				self::row( $claim, false );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * One claim row.
	 *
	 * @param array<string, mixed> $claim    Claim.
	 * @param bool                 $decision Whether to show approve and reject.
	 * @return void
	 */
	private static function row( array $claim, bool $decision ): void {
		$user   = get_userdata( (int) $claim['user_id'] );
		$method = Claims::METHOD_EMAIL === $claim['method'] ? __( 'Email', 'guam-restaurants-core' ) : __( 'Text to listed phone', 'guam-restaurants-core' );
		echo '<tr>';
		printf( '<td><a href="%1$s">%2$s</a></td>', esc_url( (string) get_edit_post_link( (int) $claim['restaurant_id'] ) ), esc_html( get_the_title( (int) $claim['restaurant_id'] ) ) );
		printf( '<td>%s</td>', $user ? sprintf( '<a href="%1$s">%2$s</a><br>%3$s', esc_url( get_edit_user_link( $user->ID ) ), esc_html( $user->display_name ), esc_html( $user->user_email ) ) : esc_html__( '(deleted account)', 'guam-restaurants-core' ) );
		printf( '<td>%1$s: %2$s</td>', esc_html( $method ), esc_html( (string) $claim['contact'] ) );
		printf( '<td>%s</td>', esc_html( (string) ( $decision ? $claim['verified_at'] : $claim['created_at'] ) ) );
		echo '<td>';
		if ( $decision || current_user_can( Claims::CAP ) ) {
			printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
			wp_nonce_field( self::ACTION );
			printf( '<input type="hidden" name="action" value="%1$s"><input type="hidden" name="claim" value="%2$d">', esc_attr( self::ACTION ), (int) $claim['id'] );
			if ( $decision ) {
				printf( '<button class="button button-primary" name="decision" value="approve">%s</button> ', esc_html__( 'Approve', 'guam-restaurants-core' ) );
			}
			printf( '<input type="text" name="reason" placeholder="%1$s" maxlength="255"> <button class="button" name="decision" value="reject">%2$s</button>', esc_attr__( 'Reason (sent to them)', 'guam-restaurants-core' ), esc_html__( 'Reject', 'guam-restaurants-core' ) );
			echo '</form>';
		}
		echo '</td></tr>';
	}
}
