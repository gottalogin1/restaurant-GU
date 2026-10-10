<?php
/**
 * Restaurants → Review queue screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Corrections\Corrections;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Monitoring\Closures;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;
use GuamRestaurants\Core\Tagging\Merger;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Pending review items. Administrators approve or reject closures and
 * merge or separate probable duplicates, and close correction and listing
 * requests with a private note; other item types can be dismissed once
 * handled by hand. Only administrators see the screen, so every
 * closure is an administrator's decision.
 */
final class Review_Queue_Screen {

	public const PAGE       = 'gr-review-queue';
	public const ACTION     = 'guam_restaurants_review_item';
	public const CAPABILITY = Closures::CAPABILITY;

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
	 * Screen URL.
	 *
	 * @param string $type Type filter.
	 * @return string
	 */
	public static function url( string $type = '' ): string {
		$args = array(
			'post_type' => Content_Types::POST_TYPE,
			'page'      => self::PAGE,
		);
		if ( '' !== $type ) {
			$args['type'] = $type;
		}
		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Adds the submenu under Restaurants with a pending count.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		$label   = __( 'Review queue', 'guam-restaurants-core' );
		$pending = current_user_can( self::CAPABILITY ) ? array_sum( Review_Queue::pending_counts() ) : 0;
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			$label,
			$label . ( $pending > 0 ? sprintf( ' <span class="awaiting-mod">%d</span>', $pending ) : '' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Handles a decision.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Only administrators can decide review items.', 'guam-restaurants-core' ), 403 );
		}
		$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $item_id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';

		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		$notice = self::decide( $item_id, $decision, get_current_user_id(), $note ) ? 'gr_review_done' : 'gr_review_failed';
		wp_safe_redirect( add_query_arg( 'gr_notice', $notice, self::url( $type ) ) );
		exit;
	}

	/**
	 * Applies a decision to an item.
	 *
	 * @param int    $item_id  Item.
	 * @param string $decision approve_closure, reject_closure, merge, not_duplicate, request_done, request_decline or dismiss.
	 * @param int    $user_id  Administrator.
	 * @param string $note     Private note, kept with correction and listing requests.
	 * @return bool
	 */
	public static function decide( int $item_id, string $decision, int $user_id, string $note = '' ): bool {
		$item = Review_Queue::get( $item_id );
		if ( ! $item || 'pending' !== $item['status'] || ! user_can( $user_id, self::CAPABILITY ) ) {
			return false;
		}

		try {
			switch ( $decision ) {
				case 'approve_closure':
					return Closures::approve( $item_id, $user_id );
				case 'reject_closure':
					return Closures::reject( $item_id, $user_id );
				case 'merge':
					if ( 'duplicate' !== $item['type'] ) {
						return false;
					}
					( new Merger() )->merge( (int) $item['restaurant_id'], (int) ( $item['payload']['duplicate_of'] ?? 0 ), 'editor', null, array( 'confidence' => (float) $item['confidence'] ) );
					return Review_Queue::resolve( $item_id, 'merged', $user_id ) || 'merged' === ( Review_Queue::get( $item_id )['status'] ?? '' );
				case 'not_duplicate':
					if ( 'duplicate' !== $item['type'] ) {
						return false;
					}
					Duplicate_Detector::mark_not_duplicate( (int) $item['restaurant_id'], (int) ( $item['payload']['duplicate_of'] ?? 0 ) );
					return Review_Queue::resolve( $item_id, 'rejected', $user_id );
				case 'request_done':
				case 'request_decline':
					return Corrections::close( $item_id, 'request_done' === $decision, $note, $user_id );
				case 'dismiss':
					return Review_Queue::resolve( $item_id, 'rejected', $user_id );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
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
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$notice = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $type, Vocabulary::REVIEW_QUEUE_TYPES, true ) ) {
			$type = '';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Review queue', 'guam-restaurants-core' ) . '</h1>';
		if ( 'gr_review_done' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Item updated.', 'guam-restaurants-core' ) . '</p></div>';
		} elseif ( 'gr_review_failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The item could not be updated. It may already have been decided.', 'guam-restaurants-core' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'A restaurant is marked permanently closed only when you approve it here, after two checks in a row reported it closed. Closures are shown to visitors as a plain status.', 'guam-restaurants-core' ) . '</p>';

		$counts = Review_Queue::pending_counts();
		$links  = array( sprintf( '<a href="%s"%s>%s (%d)</a>', esc_url( self::url() ), '' === $type ? ' class="current"' : '', esc_html__( 'All', 'guam-restaurants-core' ), array_sum( $counts ) ) );
		foreach ( $counts as $key => $count ) {
			$links[] = sprintf( '<a href="%s"%s>%s (%d)</a>', esc_url( self::url( $key ) ), $key === $type ? ' class="current"' : '', esc_html( self::type_label( $key ) ), $count );
		}
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		$items = Review_Queue::pending( $type, 100 );
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'Nothing to review.', 'guam-restaurants-core' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Details', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Decision', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr><td><strong>' . esc_html( self::type_label( (string) $item['type'] ) ) . '</strong><br />#' . esc_html( (string) $item['id'] ) . ' · ' . esc_html( (string) $item['source'] ) . '<br />' . esc_html( (string) $item['created_at'] ) . ' UTC</td><td>';
			self::render_details( $item );
			echo '</td><td>';
			self::render_actions( $item, $type );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Details cell.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return void
	 */
	private static function render_details( array $item ): void {
		$payload = (array) $item['payload'];
		if ( 'duplicate' === $item['type'] ) {
			echo '<div style="display:flex;gap:2em;flex-wrap:wrap">';
			self::render_restaurant( (int) $item['restaurant_id'] );
			self::render_restaurant( (int) ( $payload['duplicate_of'] ?? 0 ) );
			echo '</div><p>';
			printf(
				/* translators: 1: confidence, 2: name similarity, 3: phone match, 4: distance. */
				esc_html__( 'Confidence %1$s · name similarity %2$s · phone %3$s · distance %4$s', 'guam-restaurants-core' ),
				esc_html( number_format_i18n( (float) $item['confidence'], 2 ) ),
				esc_html( number_format_i18n( (float) ( $payload['name_similarity'] ?? 0 ), 2 ) ),
				esc_html( null === ( $payload['phone_match'] ?? null ) ? __( 'unknown', 'guam-restaurants-core' ) : ( $payload['phone_match'] ? __( 'same', 'guam-restaurants-core' ) : __( 'different', 'guam-restaurants-core' ) ) ),
				esc_html( isset( $payload['distance_m'] ) ? $payload['distance_m'] . ' m' : __( 'unknown', 'guam-restaurants-core' ) )
			);
			echo '</p>';
			return;
		}

		if ( $item['restaurant_id'] ) {
			self::render_restaurant( (int) $item['restaurant_id'] );
		}
		if ( 'closure_signal' === $item['type'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: number of checks, 2: source, 3: first date, 4: last date. */
						__( '%1$d checks in a row from %2$s reported it permanently closed (%3$s to %4$s UTC).', 'guam-restaurants-core' ),
						(int) ( $payload['checks'] ?? 0 ),
						(string) $item['source'],
						(string) ( $payload['first_signal_at'] ?? '' ),
						(string) ( $payload['last_signal_at'] ?? '' )
					)
				)
			);
			return;
		}

		if ( Corrections::is_request( $item ) ) {
			self::render_request( $payload );
			return;
		}

		echo '<ul style="margin:0">';
		foreach ( $payload as $key => $value ) {
			echo '<li><code>' . esc_html( (string) $key ) . '</code>: ' . esc_html( is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * A correction or listing request: what was asked, by whom, and the reply-by date.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return void
	 */
	private static function render_request( array $payload ): void {
		$request = (string) ( $payload['request'] ?? '' );
		$choices = Corrections::REMOVAL === $request ? Corrections::removal_reasons() : Corrections::topics();
		$by      = (string) ( $payload['respond_by'] ?? '' );
		$late    = '' !== $by && $by < gmdate( 'Y-m-d' );

		echo '<p><strong>' . esc_html( Corrections::requests()[ $request ] ?? $request ) . '</strong>: ' . esc_html( $choices[ (string) ( $payload['topic'] ?? '' ) ] ?? (string) ( $payload['topic'] ?? '' ) ) . '</p>';
		echo '<blockquote style="margin:0 0 .5em;white-space:pre-wrap">' . esc_html( (string) ( $payload['details'] ?? '' ) ) . '</blockquote>';
		if ( '' !== (string) ( $payload['evidence'] ?? '' ) ) {
			echo '<p>' . esc_html__( 'Where to check:', 'guam-restaurants-core' ) . ' ' . esc_html( (string) $payload['evidence'] ) . '</p>';
		}
		$email = (string) ( $payload['email'] ?? '' );
		printf(
			'<p>%1$s %2$s · <a href="%3$s">%4$s</a> · %5$s</p>',
			esc_html__( 'From:', 'guam-restaurants-core' ),
			esc_html( '' !== (string) ( $payload['name'] ?? '' ) ? (string) $payload['name'] : __( '(no name)', 'guam-restaurants-core' ) ),
			esc_url( 'mailto:' . $email ),
			esc_html( $email ),
			esc_html( Corrections::relationships()[ (string) ( $payload['relationship'] ?? '' ) ] ?? '' )
		);
		printf(
			'<p%1$s>%2$s</p>',
			$late ? ' style="color:#b32d2e;font-weight:600"' : '',
			/* translators: %s: date. */
			esc_html( sprintf( $late ? __( 'Reply was due %s (overdue).', 'guam-restaurants-core' ) : __( 'Reply by %s.', 'guam-restaurants-core' ), $by ) )
		);
		if ( Corrections::REMOVAL === $request ) {
			echo '<p><em>' . esc_html__( 'If you agree to remove it, set the restaurant to Draft. Do not add any note about the removal to the listing.', 'guam-restaurants-core' ) . '</em></p>';
		} else {
			echo '<p><em>' . esc_html__( 'Check the fact against a source, then edit the restaurant. The change history records the edit.', 'guam-restaurants-core' ) . '</em></p>';
		}
	}

	/**
	 * One restaurant's identifying facts.
	 *
	 * @param int $id Restaurant.
	 * @return void
	 */
	private static function render_restaurant( int $id ): void {
		if ( ! $id || Content_Types::POST_TYPE !== get_post_type( $id ) ) {
			echo '<p>' . esc_html__( '(restaurant no longer exists)', 'guam-restaurants-core' ) . '</p>';
			return;
		}
		$status = get_post_status( $id );
		echo '<div><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><strong>' . esc_html( html_entity_decode( get_the_title( $id ), ENT_QUOTES ) ) . '</strong></a> #' . esc_html( (string) $id );
		if ( 'trash' === $status ) {
			echo ' <em>' . esc_html__( '(in trash)', 'guam-restaurants-core' ) . '</em>';
		}
		echo '<br />' . esc_html( (string) get_post_meta( $id, Restaurant_Meta::ADDRESS, true ) );
		echo '<br />' . esc_html( (string) get_post_meta( $id, Restaurant_Meta::PHONE, true ) );
		$osm = (string) get_post_meta( $id, Restaurant_Meta::OSM_ID, true );
		if ( '' !== $osm ) {
			echo '<br />OSM <a href="' . esc_url( 'https://www.openstreetmap.org/' . $osm ) . '">' . esc_html( $osm ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * Decision buttons.
	 *
	 * @param array<string, mixed> $item Item.
	 * @param string               $type Current filter.
	 * @return void
	 */
	private static function render_actions( array $item, string $type ): void {
		switch ( $item['type'] ) {
			case 'closure_signal':
				$buttons = array(
					'approve_closure' => __( 'Approve: permanently closed', 'guam-restaurants-core' ),
					'reject_closure'  => __( 'Reject: still open', 'guam-restaurants-core' ),
				);
				break;
			case 'duplicate':
				$buttons = array(
					'merge'         => __( 'Merge', 'guam-restaurants-core' ),
					'not_duplicate' => __( 'Not the same place', 'guam-restaurants-core' ),
				);
				break;
			case Corrections::QUEUE_CORRECTION:
			case Corrections::QUEUE_LISTING:
				$buttons = array(
					'request_done'    => __( 'Done: change made', 'guam-restaurants-core' ),
					'request_decline' => __( 'Decline', 'guam-restaurants-core' ),
				);
				break;
			default:
				$buttons = array( 'dismiss' => __( 'Mark handled', 'guam-restaurants-core' ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION . '_' . $item['id'] );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="item_id" value="' . esc_attr( (string) $item['id'] ) . '" /><input type="hidden" name="type" value="' . esc_attr( $type ) . '" />';
		if ( Corrections::is_request( $item ) ) {
			echo '<p><label for="gr-note-' . esc_attr( (string) $item['id'] ) . '">' . esc_html__( 'Private note: what you checked, or why you declined', 'guam-restaurants-core' ) . '</label><br /><textarea id="gr-note-' . esc_attr( (string) $item['id'] ) . '" name="note" rows="2" cols="30" maxlength="' . esc_attr( (string) Corrections::MAX_NOTE ) . '"></textarea></p>';
		}
		foreach ( $buttons as $value => $label ) {
			echo '<button type="submit" class="button' . ( in_array( $value, array( 'approve_closure', 'merge', 'request_done' ), true ) ? ' button-primary' : '' ) . '" name="decision" value="' . esc_attr( $value ) . '" style="margin:0 .5em .5em 0">' . esc_html( $label ) . '</button>';
		}
		echo '</form>';
	}

	/**
	 * Label for an item type.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private static function type_label( string $type ): string {
		$labels = array(
			'new_place'          => __( 'New place', 'guam-restaurants-core' ),
			'match_conflict'     => __( 'Match to check', 'guam-restaurants-core' ),
			'village_unresolved' => __( 'Village unknown', 'guam-restaurants-core' ),
			'mall_unresolved'    => __( 'Mall unknown', 'guam-restaurants-core' ),
			'closure_signal'     => __( 'Closure to approve', 'guam-restaurants-core' ),
			'owner_submission'   => __( 'Owner submission', 'guam-restaurants-core' ),
			'duplicate'          => __( 'Possible duplicate', 'guam-restaurants-core' ),
			'correction'         => __( 'Correction', 'guam-restaurants-core' ),
			'listing_request'    => __( 'Owner listing request', 'guam-restaurants-core' ),
		);
		return $labels[ $type ] ?? $type;
	}
}
