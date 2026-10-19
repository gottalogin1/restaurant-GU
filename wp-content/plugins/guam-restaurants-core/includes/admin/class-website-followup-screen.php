<?php
/**
 * Restaurants → Website follow-up screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Lists restaurants with no website of their own (none on file, or only a
 * Facebook or Instagram page), with their contact details, so an
 * administrator can offer to build one. Each row keeps a follow-up status
 * and a private note. Administrators only.
 */
final class Website_Followup_Screen {

	public const PAGE       = 'gr-website-followup';
	public const CAPABILITY = 'manage_options';
	public const ACTION     = 'gr_website_followup';
	public const PER_PAGE   = 50;

	public const STATUS_META  = '_gr_website_followup';
	public const NOTE_META    = '_gr_website_followup_note';
	public const UPDATED_META = '_gr_website_followup_updated';

	/**
	 * Listing statuses shown.
	 */
	private const POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

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
	 * Follow-up statuses; '' is the default for a restaurant nobody has contacted.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			''               => __( 'To contact', 'guam-restaurants-core' ),
			'contacted'      => __( 'Contacted', 'guam-restaurants-core' ),
			'proposal_sent'  => __( 'Proposal sent', 'guam-restaurants-core' ),
			'signed_up'      => __( 'Signed up', 'guam-restaurants-core' ),
			'not_interested' => __( 'Not interested', 'guam-restaurants-core' ),
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
			__( 'Website follow-up', 'guam-restaurants-core' ),
			__( 'Website follow-up', 'guam-restaurants-core' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Screen URL.
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
	 * Whether a listing has no website of its own.
	 *
	 * @param int $post_id Restaurant.
	 * @return bool
	 */
	public static function needs_website( int $post_id ): bool {
		$website = (string) get_post_meta( $post_id, Restaurant_Meta::WEBSITE, true );
		return '' === $website || null !== Restaurant_Meta::social_network( $website );
	}

	/**
	 * Restaurants with no website of their own, by name: not trashed and not
	 * permanently closed.
	 *
	 * @param string $status Follow-up status to show, 'open' for everything not signed up or declined, or 'all'.
	 * @return int[]
	 */
	public static function restaurants( string $status = 'open' ): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => self::POST_STATUSES,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) || ! self::needs_website( $id ) ) {
				continue;
			}
			$current = (string) get_post_meta( $id, self::STATUS_META, true );
			if ( 'all' === $status
				|| ( 'open' === $status && ! in_array( $current, array( 'signed_up', 'not_interested' ), true ) )
				|| $current === $status ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Saves a row's follow-up status and note.
	 *
	 * @param int    $post_id Restaurant.
	 * @param string $status  One of statuses().
	 * @param string $note    Private note.
	 * @param int    $user_id Administrator.
	 * @return bool
	 */
	public static function save( int $post_id, string $status, string $note, int $user_id ): bool {
		if ( Content_Types::POST_TYPE !== get_post_type( $post_id ) || ! array_key_exists( $status, self::statuses() ) || ! user_can( $user_id, self::CAPABILITY ) ) {
			return false;
		}
		if ( '' === $status ) {
			delete_post_meta( $post_id, self::STATUS_META );
		} else {
			update_post_meta( $post_id, self::STATUS_META, $status );
		}
		if ( '' === $note ) {
			delete_post_meta( $post_id, self::NOTE_META );
		} else {
			update_post_meta( $post_id, self::NOTE_META, $note );
		}
		update_post_meta(
			$post_id,
			self::UPDATED_META,
			array(
				'user' => $user_id,
				'time' => time(),
			)
		);
		return true;
	}

	/**
	 * Handles a row's form.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Only administrators can update website follow-ups.', 'guam-restaurants-core' ), 403 );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $post_id );
		$status = isset( $_POST['followup_status'] ) ? sanitize_key( wp_unslash( $_POST['followup_status'] ) ) : '';
		$note   = isset( $_POST['followup_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['followup_note'] ) ) : '';
		$view   = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'open';
		$paged  = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;

		$notice = self::save( $post_id, $status, $note, get_current_user_id() ) ? 'saved' : 'failed';
		wp_safe_redirect(
			self::url(
				array(
					'view'      => $view,
					'paged'     => (string) max( 1, $paged ),
					'gr_notice' => $notice,
				)
			) . '#gr-followup-' . $post_id
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view filters and a notice flag.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'open';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$notice = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		// phpcs:enable
		$statuses = self::statuses();
		if ( ! in_array( $view, array( 'open', 'all', 'new' ), true ) && ( '' === $view || ! isset( $statuses[ $view ] ) ) ) {
			$view = 'open';
		}

		$all    = self::restaurants( 'all' );
		$counts = array_fill_keys( array_keys( $statuses ), 0 );
		foreach ( $all as $id ) {
			$current = (string) get_post_meta( $id, self::STATUS_META, true );
			++$counts[ isset( $counts[ $current ] ) ? $current : '' ];
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Website follow-up', 'guam-restaurants-core' ) . '</h1>';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'guam-restaurants-core' ) . '</p></div>';
		} elseif ( 'failed' === $notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That could not be saved.', 'guam-restaurants-core' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Restaurants with no website of their own: none on file, or only a Facebook or Instagram page. Before offering a website, check the Google Maps link in case they have one we have not recorded; if they do, add it to the listing and the restaurant leaves this list.', 'guam-restaurants-core' ) . '</p>';

		$views = array(
			'open' => __( 'Open', 'guam-restaurants-core' ),
		) + array_combine(
			array_map(
				static function ( string $key ): string {
					return '' === $key ? 'new' : $key;
				},
				array_keys( $statuses )
			),
			array_values( $statuses )
		) + array( 'all' => __( 'All', 'guam-restaurants-core' ) );
		$links = array();
		foreach ( $views as $key => $label ) {
			if ( 'open' === $key ) {
				$count = $counts[''] + $counts['contacted'] + $counts['proposal_sent'];
			} elseif ( 'all' === $key ) {
				$count = count( $all );
			} else {
				$count = $counts[ 'new' === $key ? '' : $key ];
			}
			$links[] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( self::url( array( 'view' => $key ) ) ),
				$key === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		$ids   = self::restaurants( 'new' === $view ? '' : $view );
		$total = count( $ids );
		$ids   = array_slice( $ids, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		echo '<table class="widefat striped" style="margin-top:1em"><thead><tr>';
		foreach ( array(
			__( 'Restaurant', 'guam-restaurants-core' ),
			__( 'Phone', 'guam-restaurants-core' ),
			__( 'Email', 'guam-restaurants-core' ),
			__( 'Social pages', 'guam-restaurants-core' ),
			__( 'Address', 'guam-restaurants-core' ),
			__( 'Check online', 'guam-restaurants-core' ),
			__( 'Follow-up', 'guam-restaurants-core' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $ids ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No restaurants here.', 'guam-restaurants-core' ) . '</td></tr>';
		}
		foreach ( $ids as $id ) {
			self::render_row( $id, $view, $paged );
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * One restaurant's row.
	 *
	 * @param int    $id    Restaurant.
	 * @param string $view  Current view.
	 * @param int    $paged Current page.
	 * @return void
	 */
	private static function render_row( int $id, string $view, int $paged ): void {
		$name     = (string) get_post_field( 'post_title', $id, 'raw' );
		$phone    = (string) get_post_meta( $id, Restaurant_Meta::PHONE, true );
		$email    = (string) get_post_meta( $id, Restaurant_Meta::EMAIL, true );
		$address  = (string) get_post_meta( $id, Restaurant_Meta::ADDRESS, true );
		$villages = get_the_terms( $id, Content_Types::TAX_VILLAGE );
		$village  = is_array( $villages ) && $villages ? $villages[0]->name : '';
		$social   = array_filter(
			array(
				'Facebook'  => (string) get_post_meta( $id, Restaurant_Meta::FACEBOOK, true ),
				'Instagram' => (string) get_post_meta( $id, Restaurant_Meta::INSTAGRAM, true ),
			)
		);
		$website  = (string) get_post_meta( $id, Restaurant_Meta::WEBSITE, true );
		$network  = Restaurant_Meta::social_network( $website );
		if ( null !== $network ) {
			$social[ 'facebook' === $network ? 'Facebook' : 'Instagram' ] = $website;
		}
		$status = (string) get_post_meta( $id, self::STATUS_META, true );
		$note   = (string) get_post_meta( $id, self::NOTE_META, true );

		echo '<tr id="gr-followup-' . esc_attr( (string) $id ) . '">';

		echo '<td><strong><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( '' !== $name ? $name : __( '(no name)', 'guam-restaurants-core' ) ) . '</a></strong>';
		if ( 'publish' !== get_post_status( $id ) ) {
			echo ' — <span class="post-state">' . esc_html( (string) get_post_status_object( (string) get_post_status( $id ) )->label ) . '</span>';
		}
		if ( '' !== $village ) {
			echo '<br><span class="description">' . esc_html( html_entity_decode( $village ) ) . '</span>';
		}
		echo '</td>';

		echo '<td>' . ( '' !== $phone ? '<a href="' . esc_url( 'tel:' . $phone ) . '">' . esc_html( $phone ) . '</a>' : '—' ) . '</td>';
		echo '<td>' . ( '' !== $email ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : '—' ) . '</td>';

		echo '<td>';
		if ( ! $social ) {
			echo '—';
		}
		foreach ( $social as $label => $url ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a><br>';
		}
		echo '</td>';

		echo '<td>' . esc_html( '' !== $address ? $address : '—' ) . '</td>';

		echo '<td><a href="' . esc_url( self::maps_url( $id, $name, $village ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Maps', 'guam-restaurants-core' ) . '</a><br>';
		echo '<a href="' . esc_url( 'https://www.google.com/search?q=' . rawurlencode( $name . ' ' . ( '' !== $village ? html_entity_decode( $village ) . ' ' : '' ) . 'Guam' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Web search', 'guam-restaurants-core' ) . '</a></td>';

		echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION . '_' . $id );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $id ) . '" />';
		echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';
		echo '<input type="hidden" name="paged" value="' . esc_attr( (string) $paged ) . '" />';
		echo '<label class="screen-reader-text" for="gr-followup-status-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Follow-up status', 'guam-restaurants-core' ) . '</label>';
		echo '<select id="gr-followup-status-' . esc_attr( (string) $id ) . '" name="followup_status">';
		foreach ( self::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $status, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><br>';
		echo '<label class="screen-reader-text" for="gr-followup-note-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Note', 'guam-restaurants-core' ) . '</label>';
		echo '<textarea id="gr-followup-note-' . esc_attr( (string) $id ) . '" name="followup_note" rows="2" cols="28" placeholder="' . esc_attr__( 'Note (only admins see this)', 'guam-restaurants-core' ) . '">' . esc_textarea( $note ) . '</textarea><br>';
		submit_button( __( 'Save', 'guam-restaurants-core' ), 'secondary small', 'submit', false );
		$updated = get_post_meta( $id, self::UPDATED_META, true );
		if ( is_array( $updated ) && ! empty( $updated['time'] ) ) {
			$user = get_userdata( (int) ( $updated['user'] ?? 0 ) );
			/* translators: 1: date, 2: user name. */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Updated %1$s by %2$s', 'guam-restaurants-core' ), wp_date( get_option( 'date_format' ), (int) $updated['time'] ), $user ? $user->display_name : '?' ) ) . '</span>';
		}
		echo '</form></td>';

		echo '</tr>';
	}

	/**
	 * Google Maps link: the place itself when we have its place ID (no API
	 * call, so free), otherwise a search for the name.
	 *
	 * @param int    $id      Restaurant.
	 * @param string $name    Name.
	 * @param string $village Village name.
	 * @return string
	 */
	public static function maps_url( int $id, string $name, string $village ): string {
		$query    = trim( $name . ', ' . ( '' !== $village ? html_entity_decode( $village ) . ', ' : '' ) . 'Guam' );
		$args     = array(
			'api'   => '1',
			'query' => $query,
		);
		$place_id = (string) get_post_meta( $id, Restaurant_Meta::GOOGLE_PLACE_ID, true );
		if ( '' !== $place_id ) {
			$args['query_place_id'] = $place_id;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.google.com/maps/search/' );
	}
}
