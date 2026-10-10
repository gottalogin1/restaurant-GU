<?php
/**
 * Restaurants → Newsletter screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Newsletter;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Administrators set the next issue's sponsor, assemble a draft on demand,
 * preview the latest draft, and copy its subject and HTML. Nothing here
 * sends email to subscribers.
 */
final class Admin {

	public const PAGE           = 'gr-newsletter';
	public const CAPABILITY     = 'manage_options';
	public const SPONSOR_ACTION = 'guam_restaurants_newsletter_sponsor';
	public const BUILD_ACTION   = 'guam_restaurants_newsletter_build';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::SPONSOR_ACTION, array( self::class, 'handle_sponsor' ) );
		add_action( 'admin_post_' . self::BUILD_ACTION, array( self::class, 'handle_build' ) );
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			$args + array(
				'post_type' => Content_Types::POST_TYPE,
				'page'      => self::PAGE,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Adds the submenu under Restaurants.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			__( 'Newsletter', 'guam-restaurants-core' ),
			__( 'Newsletter', 'guam-restaurants-core' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Saves the sponsor.
	 *
	 * @return void
	 */
	public static function handle_sponsor(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Only administrators can change the newsletter.', 'guam-restaurants-core' ), 403 );
		}
		check_admin_referer( self::SPONSOR_ACTION );
		$result = Newsletter::save_sponsor(
			array(
				'name' => isset( $_POST['sponsor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sponsor_name'] ) ) : '',
				'text' => isset( $_POST['sponsor_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sponsor_text'] ) ) : '',
				'url'  => isset( $_POST['sponsor_url'] ) ? esc_url_raw( wp_unslash( $_POST['sponsor_url'] ) ) : '',
			)
		);
		wp_safe_redirect( self::url( array( 'gr_notice' => is_wp_error( $result ) ? 'sponsor_invalid' : 'sponsor_saved' ) ) );
		exit;
	}

	/**
	 * Assembles a draft now.
	 *
	 * @return void
	 */
	public static function handle_build(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Only administrators can change the newsletter.', 'guam-restaurants-core' ), 403 );
		}
		check_admin_referer( self::BUILD_ACTION );
		$draft = Newsletter::assemble( array( 'notify' => false ) );
		wp_safe_redirect( self::url( array( 'gr_notice' => $draft['sendfox_id'] ? 'built_sendfox' : 'built_html' ) ) );
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
		$notice  = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
		$notices = array(
			'sponsor_saved'   => array( 'success', __( 'Sponsor saved. It appears in the next draft only.', 'guam-restaurants-core' ) ),
			'sponsor_invalid' => array( 'error', __( 'The sponsor needs a name and a message within the length limits.', 'guam-restaurants-core' ) ),
			'built_sendfox'   => array( 'success', __( 'Draft created in SendFox. It has not been sent.', 'guam-restaurants-core' ) ),
			'built_html'      => array( 'warning', __( 'Draft assembled. Copy the HTML below into SendFox.', 'guam-restaurants-core' ) ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Newsletter', 'guam-restaurants-core' ) . '</h1>';
		if ( isset( $notices[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notices[ $notice ][0] ), esc_html( $notices[ $notice ][1] ) );
		}
		echo '<p>' . esc_html__( 'Every week a draft is assembled from the last seven days: new and reopened restaurants, the most viewed listings, new award results and closures. With a SendFox token the draft is created in SendFox; it is never sent automatically.', 'guam-restaurants-core' ) . '</p>';
		echo '<p>' . esc_html(
			'' !== Config::get( Config::SENDFOX_TOKEN )
				? __( 'SendFox token: set.', 'guam-restaurants-core' )
				: __( 'SendFox token: not set. Drafts are kept here as HTML to paste into SendFox.', 'guam-restaurants-core' )
		) . '</p>';

		self::render_sponsor_form();

		echo '<h2>' . esc_html__( 'Latest draft', 'guam-restaurants-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::BUILD_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::BUILD_ACTION ) . '" />';
		submit_button( __( 'Assemble a draft now', 'guam-restaurants-core' ), 'secondary', 'submit', false );
		echo '</form>';

		$draft = Newsletter::latest();
		if ( null === $draft ) {
			echo '<p>' . esc_html__( 'No draft yet.', 'guam-restaurants-core' ) . '</p></div>';
			return;
		}

		printf(
			'<p>%1$s</p>',
			esc_html(
				sprintf(
					/* translators: 1: start date, 2: end date, 3: date and time assembled. */
					__( 'Covers %1$s to %2$s. Assembled %3$s.', 'guam-restaurants-core' ),
					(string) $draft['start'],
					(string) $draft['end'],
					wp_date( 'Y-m-d H:i', (int) $draft['built_at'] )
				)
			)
		);
		if ( $draft['sendfox_id'] ) {
			/* translators: %d: SendFox campaign ID. */
			echo '<p><strong>' . esc_html( sprintf( __( 'SendFox draft #%d created. Review it in SendFox, choose the list and send it there.', 'guam-restaurants-core' ), (int) $draft['sendfox_id'] ) ) . '</strong></p>';
		} elseif ( '' !== (string) $draft['sendfox_error'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) $draft['sendfox_error'] ) . '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="gr-newsletter-subject">' . esc_html__( 'Subject', 'guam-restaurants-core' ) . '</label></th><td><input type="text" id="gr-newsletter-subject" class="large-text" readonly value="' . esc_attr( (string) $draft['subject'] ) . '" onfocus="this.select()" /></td></tr>';
		echo '<tr><th scope="row"><label for="gr-newsletter-preview">' . esc_html__( 'Preview text', 'guam-restaurants-core' ) . '</label></th><td><input type="text" id="gr-newsletter-preview" class="large-text" readonly value="' . esc_attr( (string) $draft['preview_text'] ) . '" onfocus="this.select()" /></td></tr>';
		echo '<tr><th scope="row"><label for="gr-newsletter-html">' . esc_html__( 'HTML', 'guam-restaurants-core' ) . '</label></th><td><textarea id="gr-newsletter-html" class="large-text code" rows="8" readonly onfocus="this.select()">' . esc_textarea( (string) $draft['html'] ) . '</textarea><p class="description">' . esc_html__( 'In SendFox, create a campaign, choose the HTML editor and paste this.', 'guam-restaurants-core' ) . '</p></td></tr></table>';

		echo '<h3>' . esc_html__( 'Preview', 'guam-restaurants-core' ) . '</h3>';
		// The draft is our own escaped HTML; the sandbox keeps any link from navigating the admin.
		echo '<iframe title="' . esc_attr__( 'Newsletter preview', 'guam-restaurants-core' ) . '" sandbox="" style="width:100%;max-width:680px;height:900px;border:1px solid #c3c4c7;background:#fff" srcdoc="' . esc_attr( (string) $draft['html'] ) . '"></iframe>';
		echo '</div>';
	}

	/**
	 * Sponsor form.
	 *
	 * @return void
	 */
	private static function render_sponsor_form(): void {
		$sponsor = Newsletter::sponsor() ?? array(
			'name' => '',
			'text' => '',
			'url'  => '',
		);
		echo '<h2>' . esc_html__( 'Sponsor for the next issue', 'guam-restaurants-core' ) . '</h2>';
		echo '<p>' . esc_html__( 'Shown after the issue\'s own content in a box labeled "Sponsored". It cannot mention ratings, rankings or awards (docs/editorial-policy.md). It is cleared once a draft uses it. Leave name and message empty for no sponsor.', 'guam-restaurants-core' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SPONSOR_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SPONSOR_ACTION ) . '" />';
		echo '<table class="form-table" role="presentation">';
		printf( '<tr><th scope="row"><label for="gr-sponsor-name">%1$s</label></th><td><input type="text" id="gr-sponsor-name" name="sponsor_name" class="regular-text" maxlength="%2$d" value="%3$s" /></td></tr>', esc_html__( 'Sponsor name', 'guam-restaurants-core' ), (int) Newsletter::MAX_SPONSOR_NAME, esc_attr( $sponsor['name'] ) );
		printf( '<tr><th scope="row"><label for="gr-sponsor-text">%1$s</label></th><td><textarea id="gr-sponsor-text" name="sponsor_text" class="large-text" rows="3" maxlength="%2$d">%3$s</textarea></td></tr>', esc_html__( 'Message', 'guam-restaurants-core' ), (int) Newsletter::MAX_SPONSOR_TEXT, esc_textarea( $sponsor['text'] ) );
		printf( '<tr><th scope="row"><label for="gr-sponsor-url">%1$s</label></th><td><input type="url" id="gr-sponsor-url" name="sponsor_url" class="regular-text" value="%2$s" /></td></tr>', esc_html__( 'Link (optional)', 'guam-restaurants-core' ), esc_attr( $sponsor['url'] ) );
		echo '</table>';
		submit_button( __( 'Save sponsor', 'guam-restaurants-core' ) );
		echo '</form>';
	}
}
