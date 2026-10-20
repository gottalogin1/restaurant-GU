<?php
/**
 * Restaurants > Photo submissions.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Approval queue for uploaded photos. Approve shows the photo on the
 * restaurant page; Decline deletes it, under one of the published photo
 * rules. Approved photos can be removed the same way.
 */
final class Moderation_Screen {

	public const PAGE   = 'gr-photo-submissions';
	public const ACTION = 'gr_moderate_photo';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Adds the screen with a pending count.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		if ( ! current_user_can( Roles::MODERATE ) ) {
			return;
		}
		$pending = Photo_Submissions::count( Photo_Submissions::PENDING );
		$label   = __( 'Photo submissions', 'guam-restaurants-core' ) . ( $pending > 0 ? sprintf( ' <span class="awaiting-mod">%d</span>', $pending ) : '' );
		add_submenu_page( 'edit.php?post_type=' . Content_Types::POST_TYPE, __( 'Photo submissions', 'guam-restaurants-core' ), $label, Roles::MODERATE, self::PAGE, array( self::class, 'render' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => Content_Types::POST_TYPE,
					'page'      => self::PAGE,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Approves or declines one photo.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( Roles::MODERATE ) ) {
			wp_die( esc_html__( 'You cannot moderate photos.', 'guam-restaurants-core' ), 403 );
		}
		$id = isset( $_POST['photo'] ) ? absint( $_POST['photo'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		$action = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$view   = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : Photo_Submissions::PENDING;
		$result = Photo_Submissions::moderate( $id, $action, $reason, get_current_user_id() );
		wp_safe_redirect(
			self::url(
				array(
					'view' => $view,
					'done' => is_wp_error( $result ) ? 'error' : $action,
				)
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
		if ( ! current_user_can( Roles::MODERATE ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view switches and result messages only.
		$view = isset( $_GET['view'] ) && Photo_Submissions::APPROVED === $_GET['view'] ? Photo_Submissions::APPROVED : Photo_Submissions::PENDING;
		$done = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap"><h1>' . esc_html__( 'Photo submissions', 'guam-restaurants-core' ) . '</h1>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Owners\' photos wait here too. To let an owner publish photos without approval, edit their user and set the role to "Restaurant owner (trusted)". They can still change only their own restaurant.', 'guam-restaurants-core' )
		);
		if ( ! Agreement::has_legal_name() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'To do: the photo upload agreement uses the site name until the company\'s formal name is set (GUAM_RESTAURANTS_LEGAL_NAME in wp-config.php). Have counsel review the agreement page before launch.', 'guam-restaurants-core' )
			);
		}
		$messages = array(
			'approve' => __( 'Photo approved. It now shows on the restaurant page.', 'guam-restaurants-core' ),
			'reject'  => __( 'Photo declined and deleted.', 'guam-restaurants-core' ),
			'error'   => __( 'That did not work. Pick a rule when declining, and try again.', 'guam-restaurants-core' ),
		);
		if ( isset( $messages[ $done ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $done ? 'error' : 'success', esc_html( $messages[ $done ] ) );
		}

		printf(
			'<p>%1$s</p><ul class="subsubsub"><li><a href="%2$s"%3$s>%4$s (%5$d)</a> | </li><li><a href="%6$s"%7$s>%8$s (%9$d)</a></li></ul><br class="clear" />',
			esc_html__( 'Check each photo against the published photo rules. Ratings are never a reason to decline. Photos uploaded by visitors who are not logged in appear here after they confirm their email.', 'guam-restaurants-core' ),
			esc_url( self::url() ),
			Photo_Submissions::PENDING === $view ? ' class="current"' : '',
			esc_html__( 'Waiting', 'guam-restaurants-core' ),
			(int) Photo_Submissions::count( Photo_Submissions::PENDING ),
			esc_url( self::url( array( 'view' => Photo_Submissions::APPROVED ) ) ),
			Photo_Submissions::APPROVED === $view ? ' class="current"' : '',
			esc_html__( 'Approved', 'guam-restaurants-core' ),
			(int) Photo_Submissions::count( Photo_Submissions::APPROVED )
		);

		$ids = Photo_Submissions::ids( $view, 0, 50 );
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'Nothing here.', 'guam-restaurants-core' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Photo', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Restaurant and dish', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Uploaded by', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Decision', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			self::row( $id, $view );
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One table row.
	 *
	 * @param int    $id   Attachment.
	 * @param string $view Current view.
	 * @return void
	 */
	private static function row( int $id, string $view ): void {
		$restaurant = (int) wp_get_post_parent_id( $id );
		$rating     = (int) get_post_meta( $id, Photo_Submissions::RATING, true );
		$agreement  = (array) get_post_meta( $id, Photo_Submissions::AGREEMENT, true );
		$who        = esc_html( (string) get_post_meta( $id, Photo_Submissions::NAME, true ) );
		if ( get_post_meta( $id, Photo_Submissions::OWNER_GALLERY, true ) ) {
			$who .= ' <strong>' . esc_html__( '(owner, from the owner dashboard; the dish field shows their photo description)', 'guam-restaurants-core' ) . '</strong>';
		} elseif ( get_post_meta( $id, Photo_Submissions::FROM_OWNER, true ) ) {
			$who .= ' <strong>' . esc_html__( '(owner)', 'guam-restaurants-core' ) . '</strong>';
		} elseif ( get_post_meta( $id, Photo_Submissions::CONNECTED, true ) ) {
			$who .= ' <strong>' . esc_html__( '(says they are connected to the restaurant)', 'guam-restaurants-core' ) . '</strong>';
		}

		$reasons = '<option value="">' . esc_html__( 'Rule…', 'guam-restaurants-core' ) . '</option>';
		foreach ( Photo_Submissions::rules() as $rule => $label ) {
			$reasons .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $rule ), esc_html( $label ) );
		}
		$form = static function ( string $decision, string $button, string $extra ) use ( $id, $view ): string {
			return sprintf(
				'<form method="post" action="%1$s" style="margin:0 0 .5rem">%2$s<input type="hidden" name="action" value="%3$s" /><input type="hidden" name="photo" value="%4$d" /><input type="hidden" name="decision" value="%5$s" /><input type="hidden" name="view" value="%6$s" />%7$s <button class="button%8$s">%9$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( self::ACTION . '_' . $id, '_wpnonce', true, false ),
				esc_attr( self::ACTION ),
				$id,
				esc_attr( $decision ),
				esc_attr( $view ),
				$extra,
				'approve' === $decision ? ' button-primary' : '',
				esc_html( $button )
			);
		};

		printf(
			'<tr><td style="width:180px"><a href="%1$s" target="_blank" rel="noopener">%2$s</a></td><td><a href="%3$s">%4$s</a><br /><strong>%5$s</strong>%6$s</td><td>%7$s<br /><span class="description">%8$s</span></td><td>%9$s%10$s</td></tr>',
			esc_url( (string) wp_get_attachment_image_url( $id, 'large' ) ),
			wp_get_attachment_image( $id, 'thumbnail' ),
			esc_url( (string) get_permalink( $restaurant ) ),
			esc_html( get_the_title( $restaurant ) ),
			esc_html( (string) get_post_meta( $id, Photo_Submissions::DISH, true ) ),
			$rating > 0 ? '<br />' . Photo_Gallery::stars( $rating ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$who, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			esc_html(
				sprintf(
					/* translators: 1: upload date, 2: agreement version. */
					__( '%1$s, accepted agreement version %2$s', 'guam-restaurants-core' ),
					get_the_date( '', $id ),
					(string) ( $agreement['version'] ?? '?' )
				)
			),
			Photo_Submissions::PENDING === $view ? $form( 'approve', __( 'Approve', 'guam-restaurants-core' ), '' ) : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
			$form( 'reject', Photo_Submissions::PENDING === $view ? __( 'Decline and delete', 'guam-restaurants-core' ) : __( 'Remove and delete', 'guam-restaurants-core' ), '<select name="reason" required>' . $reasons . '</select>' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}
}
