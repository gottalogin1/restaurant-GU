<?php
/**
 * Moderation screen for every highlight type in wp-admin.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Highlights → Moderate. Administrators approve a highlight, or reject it by
 * picking the published rule it breaks. There is no "reject" without a rule,
 * no option based on how positive or negative a review is, and no delete.
 */
final class Moderation_Screen {

	public const PAGE   = 'gr-highlights';
	public const ACTION = 'guam_restaurants_moderate_highlight';

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
	 * URL of the screen.
	 *
	 * @param string $status Status tab.
	 * @param string $type   Type filter, or ''.
	 * @return string
	 */
	public static function url( string $status = 'pending', string $type = '' ): string {
		$args = array(
			'page'   => self::PAGE,
			'status' => $status,
		);
		if ( '' !== $type ) {
			$args['type'] = $type;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Adds the Highlights menu. Moderators get the queue with a pending count
	 * and a Write page; owners and staff who cannot moderate get the Write
	 * page only.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		$title = __( 'Highlights', 'guam-restaurants-core' );
		if ( current_user_can( Roles::MODERATE ) ) {
			$pending = Highlights::count( 'pending' );
			$label   = $title . ( $pending > 0 ? sprintf( ' <span class="awaiting-mod">%d</span>', $pending ) : '' );
			add_menu_page( $title, $label, Roles::MODERATE, self::PAGE, array( self::class, 'render' ), 'dashicons-format-quote', 26 );
			add_submenu_page( self::PAGE, __( 'Moderate highlights', 'guam-restaurants-core' ), __( 'Moderate', 'guam-restaurants-core' ), Roles::MODERATE, self::PAGE, array( self::class, 'render' ) );
			add_submenu_page( self::PAGE, __( 'Write a highlight', 'guam-restaurants-core' ), __( 'Write', 'guam-restaurants-core' ), Roles::MODERATE, Submit_Screen::PAGE, array( Submit_Screen::class, 'render' ) );
			return;
		}
		foreach ( array( Roles::EDITOR, Roles::OWNER ) as $cap ) {
			if ( current_user_can( $cap ) ) {
				add_menu_page( $title, $title, $cap, Submit_Screen::PAGE, array( Submit_Screen::class, 'render' ), 'dashicons-format-quote', 26 );
				return;
			}
		}
	}

	/**
	 * Handles an approve or reject submission.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( Roles::MODERATE ) ) {
			wp_die( esc_html__( 'You are not allowed to moderate highlights.', 'guam-restaurants-core' ), 403 );
		}
		$highlight_id = isset( $_POST['highlight_id'] ) ? absint( $_POST['highlight_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $highlight_id );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		$result   = Highlights::moderate( $highlight_id, 'approve' === $decision ? 'approved' : 'rejected', $reason, get_current_user_id() );

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending';
		$type   = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		wp_safe_redirect( add_query_arg( 'gr_notice', is_wp_error( $result ) ? $result->get_error_code() : 'gr_highlight_done', self::url( $status, $type ) ) );
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'pending';
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$notice = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $status, Vocabulary::HIGHLIGHT_STATUSES, true ) ) {
			$status = 'pending';
		}
		if ( ! in_array( $type, Vocabulary::HIGHLIGHT_TYPES, true ) ) {
			$type = '';
		}
		$rules = Policy::rules();
		$rows  = Highlights::for_moderation( $status, $type );

		echo '<div class="wrap"><h1>' . esc_html__( 'Moderate highlights', 'guam-restaurants-core' ) . '</h1>';

		$notices = array(
			'gr_highlight_done'     => array( 'success', __( 'Highlight updated.', 'guam-restaurants-core' ) ),
			'gr_review_reason'      => array( 'error', __( 'Choose the published rule this highlight breaks before rejecting it.', 'guam-restaurants-core' ) ),
			'gr_highlight_conflict' => array( 'error', __( 'You are linked to this restaurant, so another administrator must moderate its reviews.', 'guam-restaurants-core' ) ),
		);
		if ( isset( $notices[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
		} elseif ( '' !== $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The highlight could not be updated.', 'guam-restaurants-core' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Approve everything that follows the rules, positive or negative. Reject only under a published rule, never because a restaurant or advertiser asks, and never because a review is critical. Highlights are never sold and never change ratings or the order of lists.', 'guam-restaurants-core' ) . ' <a href="' . esc_url( Policy_Page::url() ) . '">' . esc_html__( 'Editorial policy', 'guam-restaurants-core' ) . '</a></p>';

		$links = array();
		foreach ( array(
			'pending'  => __( 'Pending', 'guam-restaurants-core' ),
			'approved' => __( 'Approved', 'guam-restaurants-core' ),
			'rejected' => __( 'Rejected', 'guam-restaurants-core' ),
		) as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a></li>',
				esc_url( self::url( $key, $type ) ),
				$key === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				Highlights::count( $key, $type )
			);
		}
		echo '<ul class="subsubsub">' . wp_kses_post( implode( ' | ', $links ) ) . '</ul>';

		$type_links = array( sprintf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( self::url( $status ) ), '' === $type ? ' class="current"' : '', esc_html__( 'All types', 'guam-restaurants-core' ) ) );
		foreach ( Vocabulary::HIGHLIGHT_TYPES as $option ) {
			$type_links[] = sprintf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( self::url( $status, $option ) ), $option === $type ? ' class="current"' : '', esc_html( Policy::type_label( $option ) ) );
		}
		echo '<p class="clear" style="padding-top:.5em">' . wp_kses_post( implode( ' · ', $type_links ) ) . '</p>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing here.', 'guam-restaurants-core' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Type', 'guam-restaurants-core' ), __( 'Restaurant', 'guam-restaurants-core' ), __( 'Content', 'guam-restaurants-core' ), __( 'Submitted', 'guam-restaurants-core' ), __( 'Decision', 'guam-restaurants-core' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			self::render_row( $row, $status, $type, $rules );
		}
		echo '</tbody></table></div>';
	}

	/**
	 * One table row.
	 *
	 * @param array<string, mixed>  $row    Highlight.
	 * @param string                $status Current tab.
	 * @param string                $type   Current type filter.
	 * @param array<string, string> $rules  Published rules.
	 * @return void
	 */
	private static function render_row( array $row, string $status, string $type, array $rules ): void {
		$id = (int) $row['id'];
		echo '<tr id="highlight-' . esc_attr( (string) $id ) . '">';
		echo '<td>' . esc_html( Policy::type_label( (string) $row['type'] ) );
		if ( Policy::USER_REVIEW === $row['type'] ) {
			/* translators: %d: star rating. */
			echo '<br>' . esc_html( sprintf( __( '%d of 5', 'guam-restaurants-core' ), (int) $row['rating'] ) );
			if ( (int) $row['email_verified'] ) {
				echo '<br><em>' . esc_html( Policy::badge_label() ) . '</em>';
			}
		}
		echo '</td>';
		echo '<td><a href="' . esc_url( (string) get_permalink( (int) $row['restaurant_id'] ) ) . '">' . esc_html( get_the_title( (int) $row['restaurant_id'] ) ) . '</a></td>';

		echo '<td><strong>' . esc_html( (string) $row['author_name'] ) . '</strong>';
		if ( ! empty( $row['author_user_id'] ) ) {
			$user = get_userdata( (int) $row['author_user_id'] );
			if ( $user ) {
				echo ' <span class="description">(' . esc_html( $user->user_login ) . ')</span>';
			}
		}
		if ( '' !== (string) $row['disclosure'] ) {
			/* translators: %s: writer's own disclosure. */
			echo '<br><em>' . esc_html( sprintf( __( 'Disclosure: %s', 'guam-restaurants-core' ), (string) $row['disclosure'] ) ) . '</em>';
		}
		if ( Policy::OWNER_RESPONSE === $row['type'] ) {
			$parent = Highlights::get( (int) $row['parent_id'] );
			if ( $parent ) {
				/* translators: 1: reviewer name, 2: review text. */
				echo '<blockquote style="opacity:.75">' . esc_html( sprintf( __( 'In reply to %1$s: %2$s', 'guam-restaurants-core' ), (string) $parent['author_name'], wp_trim_words( (string) $parent['body'], 40 ) ) ) . '</blockquote>';
			}
		}
		echo '<p style="white-space:pre-line">' . esc_html( (string) $row['body'] ) . '</p>';
		if ( '' !== (string) $row['dishes'] ) {
			/* translators: %s: comma-separated dishes. */
			echo '<p>' . esc_html( sprintf( __( 'Signature dishes: %s', 'guam-restaurants-core' ), implode( ', ', explode( "\n", (string) $row['dishes'] ) ) ) ) . '</p>';
		}
		if ( 'rejected' === $row['status'] && isset( $rules[ $row['moderation_reason'] ] ) ) {
			/* translators: %s: rule text. */
			echo '<p><em>' . esc_html( sprintf( __( 'Rejected: %s', 'guam-restaurants-core' ), $rules[ $row['moderation_reason'] ] ) ) . '</em></p>';
		}
		echo '</td><td>' . esc_html( mysql2date( 'Y-m-d H:i', (string) $row['created_at'] ) ) . ' UTC</td><td>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION . '_' . $id );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="highlight_id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		echo '<input type="hidden" name="type" value="' . esc_attr( $type ) . '">';
		if ( 'approved' !== $row['status'] ) {
			echo '<p><button type="submit" name="decision" value="approve" class="button button-primary">' . esc_html__( 'Approve', 'guam-restaurants-core' ) . '</button></p>';
		}
		if ( 'rejected' !== $row['status'] ) {
			echo '<p><label class="screen-reader-text" for="reason-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Rule broken', 'guam-restaurants-core' ) . '</label>';
			echo '<select id="reason-' . esc_attr( (string) $id ) . '" name="reason"><option value="">' . esc_html__( 'Rule broken…', 'guam-restaurants-core' ) . '</option>';
			foreach ( $rules as $rule => $text ) {
				echo '<option value="' . esc_attr( $rule ) . '">' . esc_html( $text ) . '</option>';
			}
			echo '</select> <button type="submit" name="decision" value="reject" class="button">' . esc_html__( 'Reject', 'guam-restaurants-core' ) . '</button></p>';
		}
		echo '</form></td></tr>';
	}
}
