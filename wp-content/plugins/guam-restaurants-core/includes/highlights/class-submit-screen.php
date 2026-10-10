<?php
/**
 * Where owners and staff write highlights in wp-admin.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Highlights → Write:
 *
 * - Owners: an owner quote for each restaurant they are linked to, and one
 *   public response to each published review of it. There is no way to
 *   hide, edit or delete a review here.
 * - Staff: editor's notes for any published restaurant.
 *
 * Everything submitted here waits for an administrator.
 */
final class Submit_Screen {

	public const PAGE = 'gr-highlights-write';

	public const ACTION_QUOTE    = 'guam_restaurants_owner_quote';
	public const ACTION_NOTE     = 'guam_restaurants_editor_note';
	public const ACTION_RESPONSE = 'guam_restaurants_owner_response';

	/**
	 * Hooks the form handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_QUOTE, array( self::class, 'handle_quote' ) );
		add_action( 'admin_post_' . self::ACTION_NOTE, array( self::class, 'handle_note' ) );
		add_action( 'admin_post_' . self::ACTION_RESPONSE, array( self::class, 'handle_response' ) );
	}

	/**
	 * Saves an owner quote.
	 *
	 * @return void
	 */
	public static function handle_quote(): void {
		check_admin_referer( self::ACTION_QUOTE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$result = Highlights::submit_owner_quote(
			isset( $_POST['restaurant_id'] ) ? absint( $_POST['restaurant_id'] ) : 0,
			array(
				'body'        => isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '',
				'dishes'      => isset( $_POST['dishes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dishes'] ) ) : '',
				'author_name' => isset( $_POST['author_name'] ) ? sanitize_text_field( wp_unslash( $_POST['author_name'] ) ) : '',
			),
			get_current_user_id()
		);
		// phpcs:enable
		self::back( $result );
	}

	/**
	 * Saves an editor's note.
	 *
	 * @return void
	 */
	public static function handle_note(): void {
		check_admin_referer( self::ACTION_NOTE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$result = Highlights::submit_editor_note(
			isset( $_POST['restaurant_id'] ) ? absint( $_POST['restaurant_id'] ) : 0,
			array(
				'body'       => isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '',
				'disclosure' => isset( $_POST['disclosure'] ) ? sanitize_text_field( wp_unslash( $_POST['disclosure'] ) ) : '',
			),
			get_current_user_id()
		);
		// phpcs:enable
		self::back( $result );
	}

	/**
	 * Saves an owner response.
	 *
	 * @return void
	 */
	public static function handle_response(): void {
		$review_id = isset( $_POST['review_id'] ) ? absint( $_POST['review_id'] ) : 0;
		check_admin_referer( self::ACTION_RESPONSE . '_' . $review_id );
		$result = Highlights::submit_owner_response(
			$review_id,
			array( 'body' => isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '' ),
			get_current_user_id()
		);
		self::back( $result );
	}

	/**
	 * Renders the page for owners and staff.
	 *
	 * @return void
	 */
	public static function render(): void {
		$user_id = get_current_user_id();
		$owned   = current_user_can( Roles::OWNER ) ? Roles::owned_restaurants( $user_id ) : array();
		$editor  = current_user_can( Roles::EDITOR );
		if ( ! $owned && ! $editor && ! current_user_can( Roles::OWNER ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Write a highlight', 'guam-restaurants-core' ) . '</h1>';
		self::notice();
		echo '<p>' . esc_html__( 'Everything you send waits for an administrator, who checks it against our published rules. Highlights cannot be bought and never change ratings or the order of lists.', 'guam-restaurants-core' ) . ' <a href="' . esc_url( Policy_Page::url() ) . '">' . esc_html__( 'Editorial policy', 'guam-restaurants-core' ) . '</a></p>';

		if ( current_user_can( Roles::OWNER ) ) {
			if ( $owned ) {
				self::render_owner( $owned );
			} elseif ( ! $editor ) {
				echo '<p>' . esc_html__( 'Your account is not linked to a restaurant yet. Contact us to confirm that you own or manage it.', 'guam-restaurants-core' ) . '</p>';
			}
		}
		if ( $editor ) {
			self::render_editor();
		}
		self::render_mine( $user_id );
		echo '</div>';
	}

	/**
	 * Owner quote form and reviews to respond to.
	 *
	 * @param int[] $owned Restaurants.
	 * @return void
	 */
	private static function render_owner( array $owned ): void {
		echo '<h2>' . esc_html( Policy::type_label( Policy::OWNER_QUOTE ) ) . '</h2>';
		echo '<p>' . esc_html__( 'A short description of your restaurant and up to five signature dishes, shown on its page labeled "From the owner".', 'guam-restaurants-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_QUOTE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_QUOTE ) . '"><table class="form-table" role="presentation">';
		echo '<tr><th><label for="gr-quote-restaurant">' . esc_html__( 'Restaurant', 'guam-restaurants-core' ) . '</label></th><td>' . self::restaurant_select( 'gr-quote-restaurant', $owned ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in restaurant_select().
		/* translators: 1: minimum characters, 2: maximum characters. */
		echo '<tr><th><label for="gr-quote-body">' . esc_html__( 'Description', 'guam-restaurants-core' ) . '</label></th><td><textarea id="gr-quote-body" name="body" rows="4" class="large-text" required maxlength="' . esc_attr( (string) Policy::OWNER_QUOTE_MAX ) . '"></textarea><p class="description">' . esc_html( sprintf( __( '%1$d to %2$d characters.', 'guam-restaurants-core' ), Policy::MIN_LENGTH, Policy::OWNER_QUOTE_MAX ) ) . '</p></td></tr>';
		echo '<tr><th><label for="gr-quote-dishes">' . esc_html__( 'Signature dishes', 'guam-restaurants-core' ) . '</label></th><td><textarea id="gr-quote-dishes" name="dishes" rows="5" class="regular-text"></textarea><p class="description">' . esc_html__( 'One per line, up to five.', 'guam-restaurants-core' ) . '</p></td></tr>';
		echo '<tr><th><label for="gr-quote-name">' . esc_html__( 'Name shown', 'guam-restaurants-core' ) . '</label></th><td><input id="gr-quote-name" name="author_name" type="text" class="regular-text" maxlength="60" placeholder="' . esc_attr( wp_get_current_user()->display_name ) . '"></td></tr>';
		echo '</table>';
		submit_button( __( 'Send for approval', 'guam-restaurants-core' ) );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Reviews of your restaurants', 'guam-restaurants-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'You can post one public response to each published review. You cannot edit, hide or delete reviews. If you think a review breaks one of our published rules, contact us and say which rule.', 'guam-restaurants-core' ) . '</p>';
		$any = false;
		foreach ( $owned as $restaurant_id ) {
			foreach ( Highlights::approved( $restaurant_id, Policy::USER_REVIEW, 30 ) as $review ) {
				$any = true;
				self::render_review( $review );
			}
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'No published reviews yet.', 'guam-restaurants-core' ) . '</p>';
		}
	}

	/**
	 * One review with its response or a response form.
	 *
	 * @param array<string, mixed> $review Review row.
	 * @return void
	 */
	private static function render_review( array $review ): void {
		$id = (int) $review['id'];
		echo '<div class="card" style="max-width:none"><p><strong>' . esc_html( get_the_title( (int) $review['restaurant_id'] ) ) . '</strong> · ';
		/* translators: 1: star rating, 2: reviewer name, 3: date. */
		echo esc_html( sprintf( __( '%1$d of 5 by %2$s, %3$s', 'guam-restaurants-core' ), (int) $review['rating'], (string) $review['author_name'], mysql2date( 'Y-m-d', (string) $review['created_at'] ) ) ) . '</p>';
		echo '<p style="white-space:pre-line">' . esc_html( (string) $review['body'] ) . '</p>';

		$response = Highlights::response_to( $id, array( 'pending', 'approved' ) );
		if ( null !== $response ) {
			$state = 'approved' === $response['status'] ? __( 'Your published response', 'guam-restaurants-core' ) : __( 'Your response, waiting for approval', 'guam-restaurants-core' );
			echo '<p><em>' . esc_html( $state ) . ':</em> ' . esc_html( (string) $response['body'] ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_RESPONSE . '_' . $id );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RESPONSE ) . '"><input type="hidden" name="review_id" value="' . esc_attr( (string) $id ) . '">';
		echo '<p><label for="gr-response-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Public response', 'guam-restaurants-core' ) . '</label><br><textarea id="gr-response-' . esc_attr( (string) $id ) . '" name="body" rows="3" class="large-text" maxlength="' . esc_attr( (string) Policy::OWNER_RESPONSE_MAX ) . '" required></textarea></p>';
		submit_button( __( 'Send response for approval', 'guam-restaurants-core' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Editor's note form.
	 *
	 * @return void
	 */
	private static function render_editor(): void {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		echo '<h2>' . esc_html( Policy::type_label( Policy::EDITOR_NOTE ) ) . '</h2>';
		echo '<p>' . esc_html__( 'A short note in your own words. Do not write about a restaurant you or your family are connected to. If the restaurant paid for your meal or gave you anything, say so in the disclosure.', 'guam-restaurants-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_NOTE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_NOTE ) . '"><table class="form-table" role="presentation">';
		echo '<tr><th><label for="gr-note-restaurant">' . esc_html__( 'Restaurant', 'guam-restaurants-core' ) . '</label></th><td>' . self::restaurant_select( 'gr-note-restaurant', array_map( 'intval', $ids ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in restaurant_select().
		/* translators: 1: minimum characters, 2: maximum characters. */
		echo '<tr><th><label for="gr-note-body">' . esc_html__( 'Note', 'guam-restaurants-core' ) . '</label></th><td><textarea id="gr-note-body" name="body" rows="4" class="large-text" required maxlength="' . esc_attr( (string) Policy::EDITOR_NOTE_MAX ) . '"></textarea><p class="description">' . esc_html( sprintf( __( '%1$d to %2$d characters.', 'guam-restaurants-core' ), Policy::MIN_LENGTH, Policy::EDITOR_NOTE_MAX ) ) . '</p></td></tr>';
		echo '<tr><th><label for="gr-note-disclosure">' . esc_html__( 'Disclosure (optional)', 'guam-restaurants-core' ) . '</label></th><td><input id="gr-note-disclosure" name="disclosure" type="text" class="large-text" maxlength="200"><p class="description">' . esc_html__( 'For example: The restaurant paid for this meal. Shown with the note.', 'guam-restaurants-core' ) . '</p></td></tr>';
		echo '</table>';
		submit_button( __( 'Send for approval', 'guam-restaurants-core' ) );
		echo '</form>';
	}

	/**
	 * The user's own recent submissions and their status.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private static function render_mine( int $user_id ): void {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE author_user_id = %d AND type <> %s ORDER BY created_at DESC, id DESC LIMIT 20", $user_id, Policy::USER_REVIEW ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return;
		}
		$rules    = Policy::rules();
		$statuses = array(
			'pending'  => __( 'Waiting for approval', 'guam-restaurants-core' ),
			'approved' => __( 'Published', 'guam-restaurants-core' ),
			'rejected' => __( 'Not published', 'guam-restaurants-core' ),
		);
		echo '<h2>' . esc_html__( 'Your recent submissions', 'guam-restaurants-core' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as $row ) {
			$state = $statuses[ $row['status'] ] ?? '';
			if ( 'rejected' === $row['status'] && isset( $rules[ $row['moderation_reason'] ] ) ) {
				$state .= ': ' . $rules[ $row['moderation_reason'] ];
			}
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( Policy::type_label( (string) $row['type'] ) ),
				esc_html( get_the_title( (int) $row['restaurant_id'] ) ),
				esc_html( wp_trim_words( (string) $row['body'], 20 ) ),
				esc_html( $state )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Restaurant dropdown.
	 *
	 * @param string $id  Element ID.
	 * @param int[]  $ids Restaurants.
	 * @return string
	 */
	private static function restaurant_select( string $id, array $ids ): string {
		$options = '';
		foreach ( $ids as $restaurant_id ) {
			if ( Highlights::is_published_restaurant( $restaurant_id ) ) {
				$options .= sprintf( '<option value="%1$d">%2$s</option>', $restaurant_id, esc_html( get_the_title( $restaurant_id ) ) );
			}
		}
		return sprintf( '<select id="%1$s" name="restaurant_id" required>%2$s</select>', esc_attr( $id ), $options );
	}

	/**
	 * Result notice.
	 *
	 * @return void
	 */
	private static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$code = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		$msg  = isset( $_GET['gr_message'] ) ? sanitize_text_field( wp_unslash( $_GET['gr_message'] ) ) : '';
		// phpcs:enable
		if ( 'gr_highlight_sent' === $code ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Sent. An administrator will check it before it appears.', 'guam-restaurants-core' ) . '</p></div>';
		} elseif ( '' !== $code ) {
			echo '<div class="notice notice-error"><p>' . esc_html( '' !== $msg ? $msg : __( 'That could not be saved.', 'guam-restaurants-core' ) ) . '</p></div>';
		}
	}

	/**
	 * Redirects back to the page with the result.
	 *
	 * @param int|WP_Error $result Result.
	 * @return void
	 */
	private static function back( $result ): void {
		$args = array(
			'page'      => self::PAGE,
			'gr_notice' => is_wp_error( $result ) ? $result->get_error_code() : 'gr_highlight_sent',
		);
		if ( is_wp_error( $result ) ) {
			$args['gr_message'] = rawurlencode( implode( ' ', $result->get_error_messages() ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
