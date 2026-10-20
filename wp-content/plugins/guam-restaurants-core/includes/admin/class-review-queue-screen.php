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
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Monitoring\Closures;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Branch_Names;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Tagging\Merger;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Pending review items. Administrators approve or reject closures and
 * merge or separate probable duplicates, and close correction and listing
 * requests with a private note; other item types can be dismissed once
 * handled by hand. Only administrators see the screen, so every
 * closure is an administrator's decision.
 *
 * Items are shown 100 to a page. The whole page is one form, so names
 * typed for discovered places and ticked "Skip" boxes are all saved by one
 * "Save this page" (or Enter in a name box). On request the page shows each
 * discovered place's current name and address from Google and fills the
 * name boxes with Google's names, so a page can be saved as it is. Only a
 * name the administrator saves is kept (as the draft's title; Sanjay's
 * attorney cleared storing business names, 2026-10-20); the address and
 * status are shown for that page view only.
 */
final class Review_Queue_Screen {

	public const PAGE       = 'gr-review-queue';
	public const ACTION     = 'guam_restaurants_review_item';
	public const CAPABILITY = Closures::CAPABILITY;

	public const PER_PAGE     = 100;
	public const LOOKUP_NONCE = 'guam_restaurants_review_lookup';

	/**
	 * Types whose only decision is "skip / handled", so they can be skipped in bulk.
	 */
	public const SKIPPABLE = array( 'new_place', 'match_conflict', 'village_unresolved', 'mall_unresolved', 'owner_submission' );

	/**
	 * Google lookups that may fail in a row before the page stops asking.
	 */
	public const MAX_LOOKUP_FAILURES = 3;

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
	 * @param string $type   Type filter.
	 * @param int    $paged  Page number.
	 * @param bool   $lookup Show Google names (adds a nonce, since each view uses API calls).
	 * @return string
	 */
	public static function url( string $type = '', int $paged = 1, bool $lookup = false ): string {
		$args = array(
			'post_type' => Content_Types::POST_TYPE,
			'page'      => self::PAGE,
		);
		if ( '' !== $type ) {
			$args['type'] = $type;
		}
		if ( $paged > 1 ) {
			$args['paged'] = $paged;
		}
		if ( $lookup ) {
			$args['lookup']   = 1;
			$args['_wpnonce'] = wp_create_nonce( self::LOOKUP_NONCE );
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
	 * Handles the page form: one row's decision button, if one was pressed,
	 * then every typed name and ticked "Skip" box on the page.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Only administrators can decide review items.', 'guam-restaurants-core' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$paged   = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		$lookup  = ! empty( $_POST['lookup'] );
		$user_id = get_current_user_id();

		$names = array();
		foreach ( isset( $_POST['restaurant_name'] ) ? (array) wp_unslash( $_POST['restaurant_name'] ) : array() as $id => $name ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value.
			$names[ absint( $id ) ] = sanitize_text_field( (string) $name );
		}
		$notes = array();
		foreach ( isset( $_POST['note'] ) ? (array) wp_unslash( $_POST['note'] ) : array() as $id => $note ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value.
			$notes[ absint( $id ) ] = sanitize_textarea_field( (string) $note );
		}
		$skip = isset( $_POST['skip'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['skip'] ) ) : array();

		$args = array();
		if ( isset( $_POST['row_decision'] ) ) {
			list( $item_id, $decision ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_POST['row_decision'] ) ), 2 ), 2, '' );
			$item_id                    = absint( $item_id );
			$args['gr_notice']          = self::decide( $item_id, sanitize_key( $decision ), $user_id, $notes[ $item_id ] ?? '' ) ? 'gr_review_done' : 'gr_review_failed';
		}

		$saved = self::save_page( $names, $skip, $user_id );
		foreach ( $saved as $key => $count ) {
			if ( $count > 0 ) {
				$args[ 'gr_' . $key ] = $count;
			}
		}

		wp_safe_redirect( add_query_arg( $args, self::url( $type, $paged, $lookup ) ) );
		exit;
	}

	/**
	 * Saves a page: adds each named place as a draft and skips each ticked
	 * item. A typed name wins over a ticked box, so a place the administrator
	 * named is never skipped. Items already decided count as failed.
	 *
	 * @param array<int, string> $names   Item ID => typed name (blank names are ignored).
	 * @param int[]              $skip    Item IDs to skip.
	 * @param int                $user_id Administrator.
	 * @return array{added: int, skipped: int, failed: int}
	 */
	public static function save_page( array $names, array $skip, int $user_id ): array {
		$out   = array(
			'added'   => 0,
			'skipped' => 0,
			'failed'  => 0,
		);
		$named = array();
		foreach ( $names as $item_id => $name ) {
			if ( '' === trim( $name ) ) {
				continue;
			}
			$named[ (int) $item_id ] = true;
			if ( self::add_restaurant( (int) $item_id, $name, $user_id ) ) {
				++$out['added'];
			} else {
				++$out['failed'];
			}
		}
		foreach ( array_unique( $skip ) as $item_id ) {
			if ( isset( $named[ $item_id ] ) ) {
				continue;
			}
			$item = Review_Queue::get( $item_id );
			if ( $item && in_array( $item['type'], self::SKIPPABLE, true ) && self::decide( $item_id, 'dismiss', $user_id ) ) {
				++$out['skipped'];
			} else {
				++$out['failed'];
			}
		}
		return $out;
	}

	/**
	 * Current Google name, address and business status for each discovered
	 * place on the page. The name fills the place's name box; nothing is
	 * stored unless the administrator saves the page. Each
	 * place is one Place Details Pro call, counted against the monthly cap,
	 * so lookups stop when the free allowance runs out.
	 *
	 * @param array<int, array<string, mixed>> $items  Items on the page.
	 * @param Places_Client|null               $client Client; built from the key when null.
	 * @return array{places: array<int, array{name: string, address: string, business_status: string}>, error: string}
	 */
	public static function google_details( array $items, ?Places_Client $client = null ): array {
		$out    = array(
			'places' => array(),
			'error'  => '',
		);
		$client = $client ?? Places_Client::from_config( new Retrying_Client( null, null, array( 429 ), 5, 1 ) );
		if ( ! $client ) {
			$out['error'] = __( 'No Google Places key is set, so Google names cannot be shown.', 'guam-restaurants-core' );
			return $out;
		}

		$failures = 0;
		foreach ( $items as $item ) {
			$place_id = (string) ( $item['payload']['place_id'] ?? '' );
			if ( 'new_place' !== $item['type'] || '' === $place_id ) {
				continue;
			}
			if ( ! Budget::try_call_monthly( 'google', Pricing::GOOGLE_DETAILS_PRO ) ) {
				$out['error'] = __( 'Stopped showing Google names: this month\'s free Google Place Details calls are used up.', 'guam-restaurants-core' );
				break;
			}
			try {
				$out['places'][ (int) $item['id'] ] = $client->details( $place_id );
				$failures                           = 0;
			} catch ( \Throwable $e ) {
				if ( ++$failures >= self::MAX_LOOKUP_FAILURES ) {
					$out['error'] = __( 'Stopped showing Google names: Google did not answer.', 'guam-restaurants-core' );
					break;
				}
			}
		}
		return $out;
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
	 * Turns a discovered place into a draft restaurant. The administrator
	 * confirms the name (typed, or filled in from Google on the page); the
	 * draft keeps the place ID and the village or mall
	 * the search was about, and is published from the editor once the
	 * details are filled in.
	 *
	 * @param int    $item_id A pending `new_place` item.
	 * @param string $name    Restaurant name.
	 * @param int    $user_id Administrator.
	 * @return int Draft post ID, or 0.
	 */
	public static function add_restaurant( int $item_id, string $name, int $user_id ): int {
		$item = Review_Queue::get( $item_id );
		$name = trim( $name );
		if ( ! $item || 'new_place' !== $item['type'] || 'pending' !== $item['status'] || '' === $name || ! user_can( $user_id, self::CAPABILITY ) ) {
			return 0;
		}

		$payload  = (array) $item['payload'];
		$place_id = (string) ( $payload['place_id'] ?? '' );
		$post_id  = wp_insert_post(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $name,
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
		if ( '' !== $place_id ) {
			update_post_meta( $post_id, Restaurant_Meta::GOOGLE_PLACE_ID, $place_id );
		}

		$found_by = (array) ( $payload['found_by'] ?? array() );
		$slug     = (string) ( $found_by['slug'] ?? '' );
		if ( 'village' === ( $found_by['kind'] ?? '' ) && '' !== $slug ) {
			wp_set_object_terms( $post_id, $slug, Content_Types::TAX_VILLAGE );
		} elseif ( 'mall' === ( $found_by['kind'] ?? '' ) && '' !== $slug ) {
			Mall_Verification::with_unverified(
				static function () use ( $post_id, $slug ) {
					$mall = get_term_by( 'slug', $slug, Content_Types::TAX_MALL );
					if ( $mall instanceof \WP_Term ) {
						wp_set_object_terms( $post_id, array( $mall->term_id ), Content_Types::TAX_MALL );
						$village = (int) get_term_meta( $mall->term_id, Term_Meta::MALL_VILLAGE, true );
						if ( $village ) {
							wp_set_object_terms( $post_id, array( $village ), Content_Types::TAX_VILLAGE );
						}
					}
				}
			);
		}

		Review_Queue::resolve( $item_id, 'approved', $user_id );
		// A place that shares its name with another listing is titled "Name, Village", and so are its branches.
		Branch_Names::apply( false, array( Names::key( $name ) ) );
		return (int) $post_id;
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view filters; the Google lookup checks its own nonce.
		$type   = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$notice = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		$saved  = array();
		foreach ( array( 'added', 'skipped', 'failed' ) as $key ) {
			$saved[ $key ] = isset( $_GET[ 'gr_' . $key ] ) ? absint( $_GET[ 'gr_' . $key ] ) : 0;
		}
		$lookup = ! empty( $_GET['lookup'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), self::LOOKUP_NONCE );
		// phpcs:enable
		if ( ! in_array( $type, Vocabulary::REVIEW_QUEUE_TYPES, true ) ) {
			$type = '';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Review queue', 'guam-restaurants-core' ) . '</h1>';
		self::render_notices( $notice, $saved );
		echo '<p>' . esc_html__( 'A restaurant is marked permanently closed only when you approve it here, after two checks in a row reported it closed. Closures are shown to visitors as a plain status.', 'guam-restaurants-core' ) . '</p>';

		$counts = Review_Queue::pending_counts();
		$links  = array( sprintf( '<a href="%s"%s>%s (%d)</a>', esc_url( self::url() ), '' === $type ? ' class="current"' : '', esc_html__( 'All', 'guam-restaurants-core' ), array_sum( $counts ) ) );
		foreach ( $counts as $key => $count ) {
			$links[] = sprintf( '<a href="%s"%s>%s (%d)</a>', esc_url( self::url( $key ) ), $key === $type ? ' class="current"' : '', esc_html( self::type_label( $key ) ), $count );
		}
		echo '<ul class="subsubsub"><li>' . implode( ' | </li><li>', $links ) . '</li></ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		$total = '' === $type ? array_sum( $counts ) : (int) ( $counts[ $type ] ?? 0 );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$items = Review_Queue::pending( $type, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'Nothing to review.', 'guam-restaurants-core' ) . '</p></div>';
			return;
		}

		$new_places = count( wp_list_filter( $items, array( 'type' => 'new_place' ) ) );
		if ( $lookup && $new_places ) {
			if ( function_exists( 'set_time_limit' ) ) {
				set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- up to 100 sequential lookups.
			}
			$google = self::google_details( $items );
		} else {
			$google = array(
				'places' => array(),
				'error'  => '',
			);
		}
		if ( '' !== $google['error'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $google['error'] ) . '</p></div>';
		}
		if ( $new_places ) {
			self::render_naming_help( $type, $paged, $lookup, $new_places );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" /><input type="hidden" name="type" value="' . esc_attr( $type ) . '" /><input type="hidden" name="paged" value="' . esc_attr( (string) $paged ) . '" />';
		if ( $lookup ) {
			echo '<input type="hidden" name="lookup" value="1" />';
		}

		self::render_tablenav( 'top', $type, $paged, $pages, $total, $lookup );
		echo '<table class="wp-list-table widefat striped"><thead><tr><td class="manage-column column-cb check-column"><label class="screen-reader-text" for="gr-skip-all">' . esc_html__( 'Select all to skip', 'guam-restaurants-core' ) . '</label><input type="checkbox" id="gr-skip-all" /></td><th>' . esc_html__( 'Item', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Details', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Decision', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$id      = (int) $item['id'];
			$details = $google['places'][ $id ] ?? null;
			echo '<tr><th scope="row" class="check-column">';
			if ( in_array( $item['type'], self::SKIPPABLE, true ) ) {
				$closed = $details && 'CLOSED_PERMANENTLY' === $details['business_status'];
				echo '<label class="screen-reader-text" for="gr-skip-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Skip', 'guam-restaurants-core' ) . '</label><input type="checkbox" id="gr-skip-' . esc_attr( (string) $id ) . '" name="skip[]" value="' . esc_attr( (string) $id ) . '"' . checked( $closed, true, false ) . ' />';
			}
			echo '</th><td><strong>' . esc_html( self::type_label( (string) $item['type'] ) ) . '</strong><br />#' . esc_html( (string) $id ) . ' · ' . esc_html( (string) $item['source'] ) . '<br />' . esc_html( (string) $item['created_at'] ) . ' UTC</td><td>';
			self::render_details( $item, $details );
			echo '</td><td>';
			self::render_actions( $item, $details );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_tablenav( 'bottom', $type, $paged, $pages, $total, $lookup );
		echo '</form></div>';
	}

	/**
	 * Result notices.
	 *
	 * @param string             $notice Row decision notice.
	 * @param array<string, int> $saved  Counts from save_page().
	 * @return void
	 */
	private static function render_notices( string $notice, array $saved ): void {
		if ( 'gr_review_done' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Item updated.', 'guam-restaurants-core' ) . '</p></div>';
		} elseif ( 'gr_review_failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The item could not be updated. It may already have been decided.', 'guam-restaurants-core' ) . '</p></div>';
		}
		if ( $saved['added'] || $saved['skipped'] ) {
			$drafts = add_query_arg(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_status' => 'draft',
				),
				admin_url( 'edit.php' )
			);
			printf(
				'<div class="notice notice-success"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: 1: places added, 2: items skipped. */
						__( 'Added %1$d as draft restaurants; skipped %2$d.', 'guam-restaurants-core' ),
						$saved['added'],
						$saved['skipped']
					)
				),
				esc_url( $drafts ),
				esc_html__( 'See the drafts', 'guam-restaurants-core' )
			);
		}
		if ( $saved['failed'] ) {
			/* translators: %d: number of items. */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( _n( '%d item could not be saved. It may already have been decided.', '%d items could not be saved. They may already have been decided.', $saved['failed'], 'guam-restaurants-core' ), $saved['failed'] ) ) . '</p></div>';
		}
	}

	/**
	 * How to name discovered places quickly, and the Google names switch.
	 *
	 * @param string $type       Type filter.
	 * @param int    $paged      Page.
	 * @param bool   $lookup     Whether Google names are shown.
	 * @param int    $new_places Discovered places on this page.
	 * @return void
	 */
	private static function render_naming_help( string $type, int $paged, bool $lookup, int $new_places ): void {
		echo '<p>' . esc_html__( 'For each new place, type its name, or tick its box to skip it, then press Enter or "Save this page". Named places become draft restaurants with their Google place ID and village; you can fill in the details later from the drafts list. A name another listing already has gets its village added (McDonald\'s, Mangilao), and so do the other branches.', 'guam-restaurants-core' ) . '</p><p>';
		if ( $lookup ) {
			echo esc_html__( 'The name boxes are filled in with Google Maps names. Check them, fix or clear any you do not want, then save the page. Places Google lists as permanently closed are left blank and ticked to skip.', 'guam-restaurants-core' ) . ' <a href="' . esc_url( self::url( $type, $paged ) ) . '">' . esc_html__( 'Stop showing Google names', 'guam-restaurants-core' ) . '</a>';
		} else {
			printf(
				'<a class="button" href="%1$s">%2$s</a> %3$s',
				esc_url( self::url( $type, $paged, true ) ),
				esc_html__( 'Fill in Google names on this page', 'guam-restaurants-core' ),
				esc_html(
					sprintf(
						/* translators: 1: places on the page, 2: free calls left. */
						__( 'Uses one Google Place Details call per new place (%1$d on this page) each time the page loads; %2$s free calls left this month. It stops when they run out, so it never costs money. Loading can take up to a minute.', 'guam-restaurants-core' ),
						$new_places,
						number_format_i18n( Budget::free_left( Pricing::GOOGLE_DETAILS_PRO ) )
					)
				)
			);
		}
		echo '</p>';
	}

	/**
	 * Save button and pagination, above or below the table.
	 *
	 * @param string $which  top or bottom.
	 * @param string $type   Type filter.
	 * @param int    $paged  Page.
	 * @param int    $pages  Pages.
	 * @param int    $total  Pending items in this view.
	 * @param bool   $lookup Keep Google names on in page links.
	 * @return void
	 */
	private static function render_tablenav( string $which, string $type, int $paged, int $pages, int $total, bool $lookup ): void {
		echo '<div class="tablenav ' . esc_attr( $which ) . '"><div class="alignleft actions">';
		// The first submit button in the form, so Enter in a name box saves the page.
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save this page', 'guam-restaurants-core' ) . '</button>';
		echo '</div><div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( /* translators: %s: number of items. */ _n( '%s item', '%s items', $total, 'guam-restaurants-core' ), number_format_i18n( $total ) ) ) . '</span>';
		if ( $pages > 1 ) {
			echo '<span class="pagination-links">';
			$links = array(
				array( 1, '&laquo;', __( 'First page', 'guam-restaurants-core' ), $paged > 1 ),
				array( $paged - 1, '&lsaquo;', __( 'Previous page', 'guam-restaurants-core' ), $paged > 1 ),
				null,
				array( $paged + 1, '&rsaquo;', __( 'Next page', 'guam-restaurants-core' ), $paged < $pages ),
				array( $pages, '&raquo;', __( 'Last page', 'guam-restaurants-core' ), $paged < $pages ),
			);
			foreach ( $links as $link ) {
				if ( null === $link ) {
					/* translators: 1: current page, 2: total pages. */
					echo ' <span class="paging-input">' . esc_html( sprintf( __( 'Page %1$s of %2$s', 'guam-restaurants-core' ), number_format_i18n( $paged ), number_format_i18n( $pages ) ) ) . '</span> ';
					continue;
				}
				list( $page, $symbol, $label, $enabled ) = $link;
				if ( $enabled ) {
					echo '<a class="button" href="' . esc_url( self::url( $type, $page, $lookup ) ) . '"><span class="screen-reader-text">' . esc_html( $label ) . '</span><span aria-hidden="true">' . $symbol . '</span></a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entity.
				} else {
					echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">' . $symbol . '</span> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entity.
				}
			}
			echo '</span>';
		}
		echo '</div><br class="clear" /></div>';
	}

	/**
	 * Details cell.
	 *
	 * @param array<string, mixed>                                               $item   Item.
	 * @param array{name: string, address: string, business_status: string}|null $google Google's current details, for a new place when shown.
	 * @return void
	 */
	private static function render_details( array $item, ?array $google = null ): void {
		$payload = (array) $item['payload'];
		if ( 'new_place' === $item['type'] ) {
			self::render_new_place( $payload, $google );
			return;
		}
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
			if ( is_string( $value ) && '_url' === substr( (string) $key, -4 ) && wp_http_validate_url( $value ) ) {
				echo '<li><code>' . esc_html( (string) $key ) . '</code>: <a href="' . esc_url( $value ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open', 'guam-restaurants-core' ) . '</a></li>';
				continue;
			}
			echo '<li><code>' . esc_html( (string) $key ) . '</code>: ' . esc_html( is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * A discovered place: how it was found, a Google Maps link, and Google's
	 * current name and address when the administrator asked to see them.
	 *
	 * @param array<string, mixed>                                               $payload Payload.
	 * @param array{name: string, address: string, business_status: string}|null $google Google details.
	 * @return void
	 */
	private static function render_new_place( array $payload, ?array $google ): void {
		if ( $google ) {
			echo '<p style="margin:0 0 .3em"><strong>' . esc_html( '' !== $google['name'] ? $google['name'] : __( '(no name from Google)', 'guam-restaurants-core' ) ) . '</strong>';
			if ( '' !== $google['address'] ) {
				echo '<br />' . esc_html( $google['address'] );
			}
			if ( 'CLOSED_PERMANENTLY' === $google['business_status'] ) {
				echo '<br /><span style="color:#b32d2e;font-weight:600">' . esc_html__( 'Permanently closed on Google', 'guam-restaurants-core' ) . '</span>';
			} elseif ( 'CLOSED_TEMPORARILY' === $google['business_status'] ) {
				echo '<br /><em>' . esc_html__( 'Temporarily closed on Google', 'guam-restaurants-core' ) . '</em>';
			}
			echo '<br /><small>' . esc_html__( 'Google Maps', 'guam-restaurants-core' ) . '</small></p>';
		}
		$found_by = (array) ( $payload['found_by'] ?? array() );
		if ( '' !== (string) ( $found_by['query'] ?? '' ) ) {
			echo esc_html__( 'Found by:', 'guam-restaurants-core' ) . ' ' . esc_html( (string) $found_by['query'] ) . '<br />';
		}
		if ( ! empty( $payload['same_name_elsewhere'] ) ) {
			echo '<em>' . esc_html__( 'A restaurant with this name is listed in another village or mall (could be a new branch).', 'guam-restaurants-core' ) . '</em><br />';
		}
		// Built from the place ID; wp_http_validate_url() would look up DNS for every row.
		$place_id = (string) ( $payload['place_id'] ?? '' );
		if ( '' !== $place_id ) {
			$maps = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode( $place_id );
			echo '<a href="' . esc_url( $maps ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Google Maps', 'guam-restaurants-core' ) . '</a>';
		}
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
	 * Decision cell: a name box for a discovered place, or the row's
	 * decision buttons (each submits the page form with that one decision).
	 *
	 * @param array<string, mixed>                                               $item   Item.
	 * @param array{name: string, address: string, business_status: string}|null $google Google's current details, which fill the name box unless the place is permanently closed.
	 * @return void
	 */
	private static function render_actions( array $item, ?array $google = null ): void {
		$id = (string) $item['id'];
		if ( 'new_place' === $item['type'] ) {
			$name = $google && 'CLOSED_PERMANENTLY' !== $google['business_status'] ? $google['name'] : '';
			echo '<label for="gr-name-' . esc_attr( $id ) . '">' . esc_html__( 'Name, as shown on Google Maps', 'guam-restaurants-core' ) . '</label><br /><input type="text" id="gr-name-' . esc_attr( $id ) . '" name="restaurant_name[' . esc_attr( $id ) . ']" value="' . esc_attr( $name ) . '" class="regular-text" maxlength="200" autocomplete="off" />';
			return;
		}

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
				// Skipped with the row's box and "Save this page".
				echo '<em>' . esc_html__( 'Tick the box to mark it handled.', 'guam-restaurants-core' ) . '</em>';
				return;
		}

		if ( Corrections::is_request( $item ) ) {
			echo '<p><label for="gr-note-' . esc_attr( $id ) . '">' . esc_html__( 'Private note: what you checked, or why you declined', 'guam-restaurants-core' ) . '</label><br /><textarea id="gr-note-' . esc_attr( $id ) . '" name="note[' . esc_attr( $id ) . ']" rows="2" cols="30" maxlength="' . esc_attr( (string) Corrections::MAX_NOTE ) . '"></textarea></p>';
		}
		foreach ( $buttons as $value => $label ) {
			echo '<button type="submit" class="button' . ( in_array( $value, array( 'approve_closure', 'merge', 'request_done' ), true ) ? ' button-primary' : '' ) . '" name="row_decision" value="' . esc_attr( $id . ':' . $value ) . '" style="margin:0 .5em .5em 0">' . esc_html( $label ) . '</button>';
		}
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
