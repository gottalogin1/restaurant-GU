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
use GuamRestaurants\Core\Database\Schema;
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
 *
 * Every restaurant shown can be renamed, moved to another village or mall,
 * or deleted (moved to the Trash) from the page, and ticked rows take a
 * bulk action: skip, merge, "not the same place" or delete. Setting a
 * village or mall closes that restaurant's "Village unknown" or "Mall
 * unknown" item.
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
	 * Bulk actions for ticked rows.
	 */
	public const BULK_ACTIONS = array( 'skip', 'merge', 'not_duplicate', 'delete' );

	/**
	 * Village and mall terms for the dropdowns, built once per request.
	 *
	 * @var array{village: array<int, string>, mall: array<int, string>}|null
	 */
	private static ?array $locations = null;

	/**
	 * Restaurants already given edit boxes on the page being rendered.
	 *
	 * @var array<int, true>
	 */
	private static array $shown = array();

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
	 * Handles the page form. In order: restaurant edits (name, village,
	 * mall), a pressed Delete button, a pressed row decision button, typed
	 * names for discovered places, then the bulk action for ticked rows.
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
		$places = array();
		foreach ( isset( $_POST['place'] ) ? (array) wp_unslash( $_POST['place'] ) : array() as $id => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value.
			$places[ absint( $id ) ] = self::sanitize_location( (array) $fields );
		}
		$edits = array();
		foreach ( isset( $_POST['edit'] ) ? (array) wp_unslash( $_POST['edit'] ) : array() as $id => $fields ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value.
			$fields = (array) $fields;
			$edit   = self::sanitize_location( $fields );
			if ( isset( $fields['name'] ) ) {
				$edit['name'] = sanitize_text_field( (string) $fields['name'] );
			}
			$edits[ absint( $id ) ] = $edit;
		}
		$selected = isset( $_POST['selected'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['selected'] ) ) : array();
		$which    = isset( $_POST['save'] ) && 'bottom' === $_POST['save'] ? 'bottom' : 'top';
		$bulk     = isset( $_POST[ 'bulk_action_' . $which ] ) ? sanitize_key( wp_unslash( $_POST[ 'bulk_action_' . $which ] ) ) : '';
		if ( ! isset( $_POST['save'] ) && 'skip' !== $bulk ) {
			// A row's own button was pressed: merge, "not the same" and delete only run from "Save this page".
			$bulk = '';
		}

		$out = array_fill_keys( array( 'edited', 'deleted' ), 0 );
		foreach ( $edits as $restaurant_id => $edit ) {
			if ( self::edit_restaurant( $restaurant_id, $edit, $user_id ) ) {
				++$out['edited'];
			}
		}
		$args = array();
		if ( isset( $_POST['delete_restaurant'] ) ) {
			if ( self::delete_restaurant( absint( $_POST['delete_restaurant'] ), $user_id ) ) {
				++$out['deleted'];
			} else {
				$args['gr_notice'] = 'gr_review_failed';
			}
		}
		if ( isset( $_POST['row_decision'] ) ) {
			list( $item_id, $decision ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_POST['row_decision'] ) ), 2 ), 2, '' );
			$item_id                    = absint( $item_id );
			$args['gr_notice']          = self::decide( $item_id, sanitize_key( $decision ), $user_id, $notes[ $item_id ] ?? '' ) ? 'gr_review_done' : 'gr_review_failed';
		}

		$saved             = self::save_page( $names, $selected, $user_id, $bulk, $places );
		$saved['deleted'] += $out['deleted'];
		$saved['edited']   = $out['edited'];
		foreach ( $saved as $key => $count ) {
			if ( $count > 0 ) {
				$args[ 'gr_' . $key ] = $count;
			}
		}

		wp_safe_redirect( add_query_arg( $args, self::url( $type, $paged, $lookup ) ) );
		exit;
	}

	/**
	 * Village and mall term IDs from a posted row (absent keys are left out).
	 *
	 * @param array<string, mixed> $fields Posted fields.
	 * @return array{village?: int, mall?: int}
	 */
	private static function sanitize_location( array $fields ): array {
		$out = array();
		foreach ( array( 'village', 'mall' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$out[ $key ] = absint( $fields[ $key ] );
			}
		}
		return $out;
	}

	/**
	 * Saves a page: adds each named place as a draft, then applies the bulk
	 * action to each ticked item. A typed name wins over a ticked box, so a
	 * place the administrator named is never skipped or deleted. Items
	 * already decided count as failed.
	 *
	 * @param array<int, string>                           $names    Item ID => typed name (blank names are ignored).
	 * @param int[]                                        $selected Ticked item IDs.
	 * @param int                                          $user_id  Administrator.
	 * @param string                                       $bulk     One of BULK_ACTIONS, or '' for none.
	 * @param array<int, array{village?: int, mall?: int}> $places   Item ID => village or mall picked for a discovered place.
	 * @return array{added: int, skipped: int, merged: int, separated: int, deleted: int, failed: int}
	 */
	public static function save_page( array $names, array $selected, int $user_id, string $bulk = 'skip', array $places = array() ): array {
		$out   = array_fill_keys( array( 'added', 'skipped', 'merged', 'separated', 'deleted', 'failed' ), 0 );
		$named = array();
		foreach ( $names as $item_id => $name ) {
			if ( '' === trim( $name ) ) {
				continue;
			}
			$named[ (int) $item_id ] = true;
			if ( self::add_restaurant( (int) $item_id, $name, $user_id, $places[ (int) $item_id ] ?? array() ) ) {
				++$out['added'];
			} else {
				++$out['failed'];
			}
		}
		if ( ! in_array( $bulk, self::BULK_ACTIONS, true ) ) {
			return $out;
		}
		foreach ( array_unique( $selected ) as $item_id ) {
			if ( isset( $named[ $item_id ] ) ) {
				continue;
			}
			$item = Review_Queue::get( $item_id );
			if ( ! $item || 'pending' !== $item['status'] ) {
				++$out['failed'];
				continue;
			}
			switch ( $bulk ) {
				case 'skip':
					$ok  = in_array( $item['type'], self::SKIPPABLE, true ) && self::decide( $item_id, 'dismiss', $user_id );
					$key = 'skipped';
					break;
				case 'merge':
					$ok  = self::decide( $item_id, 'merge', $user_id );
					$key = 'merged';
					break;
				case 'not_duplicate':
					$ok  = self::decide( $item_id, 'not_duplicate', $user_id );
					$key = 'separated';
					break;
				default:
					// A discovered place has no restaurant yet, so deleting it just takes it off the queue.
					$ok  = $item['restaurant_id'] ? self::delete_restaurant( (int) $item['restaurant_id'], $user_id ) : self::decide( $item_id, 'dismiss', $user_id );
					$key = 'deleted';
			}
			++$out[ $ok ? $key : 'failed' ];
		}
		return $out;
	}

	/**
	 * Renames a restaurant or moves it to another village or mall. Only
	 * values that differ from the current ones are written. Setting a village
	 * or mall closes the restaurant's "Village unknown" or "Mall unknown"
	 * items; a new name is checked against branch naming (Branch_Names).
	 *
	 * @param int                                             $restaurant_id Restaurant.
	 * @param array{name?: string, village?: int, mall?: int} $edit          New values; absent keys are left as they are, 0 clears a village or mall.
	 * @param int                                             $user_id       Administrator.
	 * @return bool Whether anything changed.
	 */
	public static function edit_restaurant( int $restaurant_id, array $edit, int $user_id ): bool {
		if ( ! user_can( $user_id, self::CAPABILITY ) || Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'trash' === get_post_status( $restaurant_id ) ) {
			return false;
		}
		$changed = false;

		$name = trim( (string) ( $edit['name'] ?? '' ) );
		$old  = (string) get_post_field( 'post_title', $restaurant_id, 'raw' );
		if ( '' !== $name && html_entity_decode( $old, ENT_QUOTES, 'UTF-8' ) !== $name ) {
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'         => $restaurant_id,
						'post_title' => $name,
					)
				),
				true
			);
			if ( ! is_wp_error( $result ) ) {
				$changed = true;
				Branch_Names::apply( false, array_unique( array( Names::key( $old ), Names::key( $name ) ) ) );
			}
		}

		$village_changed = false;
		if ( isset( $edit['village'] ) && self::term_ids( $restaurant_id, Content_Types::TAX_VILLAGE ) !== array_filter( array( $edit['village'] ) ) && self::valid_term( $edit['village'], Content_Types::TAX_VILLAGE ) ) {
			wp_set_object_terms( $restaurant_id, $edit['village'] ? array( $edit['village'] ) : array(), Content_Types::TAX_VILLAGE );
			$changed = $village_changed = true; // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		}
		if ( isset( $edit['mall'] ) ) {
			$mall = $edit['mall'];
			Mall_Verification::with_unverified(
				static function () use ( $restaurant_id, $mall, $village_changed, &$changed ) {
					if ( self::term_ids( $restaurant_id, Content_Types::TAX_MALL ) === array_filter( array( $mall ) ) || ! self::valid_term( $mall, Content_Types::TAX_MALL ) ) {
						return;
					}
					wp_set_object_terms( $restaurant_id, $mall ? array( $mall ) : array(), Content_Types::TAX_MALL );
					$changed = true;
					// A mall is in one village; use it unless the village was set in the same edit.
					$village = $mall ? (int) get_term_meta( $mall, Term_Meta::MALL_VILLAGE, true ) : 0;
					if ( $village && ! $village_changed ) {
						wp_set_object_terms( $restaurant_id, array( $village ), Content_Types::TAX_VILLAGE );
					}
				}
			);
		}

		if ( $changed ) {
			self::close_location_items( $restaurant_id, $user_id );
		}
		return $changed;
	}

	/**
	 * Deletes a restaurant (moves it to the Trash, so it can be restored and
	 * imports do not create it again) and closes every pending item about it.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $user_id       Administrator.
	 * @return bool
	 */
	public static function delete_restaurant( int $restaurant_id, int $user_id ): bool {
		if ( ! user_can( $user_id, self::CAPABILITY ) || Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'trash' === get_post_status( $restaurant_id ) ) {
			return false;
		}
		if ( ! wp_trash_post( $restaurant_id ) ) {
			return false;
		}
		foreach ( self::pending_for( $restaurant_id ) as $item_id ) {
			Review_Queue::resolve( $item_id, 'rejected', $user_id );
		}
		return true;
	}

	/**
	 * Pending items about a restaurant, including possible duplicates that
	 * name it as the other listing.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return int[] Item IDs.
	 */
	private static function pending_for( int $restaurant_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, restaurant_id, payload FROM {$table} WHERE status = 'pending' AND ( restaurant_id = %d OR type = 'duplicate' )", $restaurant_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids   = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( (int) $row['restaurant_id'] === $restaurant_id || (int) ( $payload['duplicate_of'] ?? 0 ) === $restaurant_id ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * Closes a restaurant's "Village unknown" item once it has one village,
	 * and its "Mall unknown" item once it has a mall.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $user_id       Administrator.
	 * @return void
	 */
	private static function close_location_items( int $restaurant_id, int $user_id ): void {
		$has = array(
			'village_unresolved' => 1 === count( wp_get_object_terms( $restaurant_id, Content_Types::TAX_VILLAGE, array( 'fields' => 'ids' ) ) ),
			'mall_unresolved'    => (bool) Mall_Verification::with_unverified(
				static function () use ( $restaurant_id ) {
					return wp_get_object_terms( $restaurant_id, Content_Types::TAX_MALL, array( 'fields' => 'ids' ) );
				}
			),
		);
		foreach ( self::pending_for( $restaurant_id ) as $item_id ) {
			$item = Review_Queue::get( $item_id );
			if ( $item && (int) $item['restaurant_id'] === $restaurant_id && ! empty( $has[ $item['type'] ] ) ) {
				Review_Queue::resolve( $item_id, 'approved', $user_id );
			}
		}
	}

	/**
	 * A restaurant's village or mall term IDs, sorted. Malls include
	 * unverified ones only inside Mall_Verification::with_unverified().
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $taxonomy      Taxonomy.
	 * @return int[]
	 */
	private static function term_ids( int $restaurant_id, string $taxonomy ): array {
		$ids = wp_get_object_terms( $restaurant_id, $taxonomy, array( 'fields' => 'ids' ) );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		sort( $ids );
		return $ids;
	}

	/**
	 * Whether a term ID is 0 (none) or a term in the taxonomy.
	 *
	 * @param int    $term_id  Term.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function valid_term( int $term_id, string $taxonomy ): bool {
		if ( 0 === $term_id ) {
			return true;
		}
		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term && $term->taxonomy === $taxonomy;
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
	 * @param int                              $item_id  A pending `new_place` item.
	 * @param string                           $name     Restaurant name.
	 * @param int                              $user_id  Administrator.
	 * @param array{village?: int, mall?: int} $location Village or mall picked on the page, in place of the search's.
	 * @return int Draft post ID, or 0.
	 */
	public static function add_restaurant( int $item_id, string $name, int $user_id, array $location = array() ): int {
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
		if ( $location ) {
			self::edit_restaurant( (int) $post_id, $location, $user_id );
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
		foreach ( array( 'added', 'skipped', 'merged', 'separated', 'deleted', 'edited', 'failed' ) as $key ) {
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

		self::$shown     = array();
		self::$locations = null;
		self::render_tablenav( 'top', $type, $paged, $pages, $total, $lookup, $new_places > 0 );
		echo '<table class="wp-list-table widefat striped"><thead><tr><td class="manage-column column-cb check-column"><label class="screen-reader-text" for="gr-select-all">' . esc_html__( 'Select all', 'guam-restaurants-core' ) . '</label><input type="checkbox" id="gr-select-all" /></td><th>' . esc_html__( 'Item', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Details', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Decision', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$id      = (int) $item['id'];
			$details = $google['places'][ $id ] ?? null;
			$closed  = $details && 'CLOSED_PERMANENTLY' === $details['business_status'];
			echo '<tr><th scope="row" class="check-column"><label class="screen-reader-text" for="gr-select-' . esc_attr( (string) $id ) . '">' . esc_html__( 'Select', 'guam-restaurants-core' ) . '</label><input type="checkbox" id="gr-select-' . esc_attr( (string) $id ) . '" name="selected[]" value="' . esc_attr( (string) $id ) . '"' . checked( $closed, true, false ) . ' /></th><td><strong>' . esc_html( self::type_label( (string) $item['type'] ) ) . '</strong><br />#' . esc_html( (string) $id ) . ' · ' . esc_html( (string) $item['source'] ) . '<br />' . esc_html( (string) $item['created_at'] ) . ' UTC</td><td>';
			self::render_details( $item, $details );
			echo '</td><td>';
			self::render_actions( $item, $details );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::render_tablenav( 'bottom', $type, $paged, $pages, $total, $lookup, $new_places > 0 );
		echo '</form>';
		self::render_script();
		echo '</div>';
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
		$done = array_filter(
			array(
				/* translators: %d: number of restaurants. */
				'edited'    => _n( '%d restaurant changed.', '%d restaurants changed.', $saved['edited'], 'guam-restaurants-core' ),
				/* translators: %d: number of pairs. */
				'merged'    => _n( '%d pair merged.', '%d pairs merged.', $saved['merged'], 'guam-restaurants-core' ),
				/* translators: %d: number of pairs. */
				'separated' => _n( '%d pair marked not the same place.', '%d pairs marked not the same place.', $saved['separated'], 'guam-restaurants-core' ),
				/* translators: %d: number of restaurants. */
				'deleted'   => _n( '%d deleted (moved to the Trash).', '%d deleted (moved to the Trash).', $saved['deleted'], 'guam-restaurants-core' ),
			),
			static function ( $key ) use ( $saved ) {
				return $saved[ $key ] > 0;
			},
			ARRAY_FILTER_USE_KEY
		);
		if ( $done ) {
			$lines = array();
			foreach ( $done as $key => $format ) {
				$lines[] = sprintf( $format, $saved[ $key ] );
			}
			echo '<div class="notice notice-success"><p>' . esc_html( implode( ' ', $lines ) ) . '</p></div>';
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
		echo '<p>' . esc_html__( 'For each new place, type its name (and change its village or mall if the search got it wrong), or tick its box to skip it, then press Enter or "Save this page". Named places become draft restaurants with their Google place ID and village; you can fill in the details later from the drafts list. A name another listing already has gets its village added (McDonald\'s, Mangilao), and so do the other branches.', 'guam-restaurants-core' ) . '</p><p>';
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
	 * @param bool   $skip   Whether the bulk action starts on "Skip" (a page with new places, where closed ones come ticked).
	 * @return void
	 */
	private static function render_tablenav( string $which, string $type, int $paged, int $pages, int $total, bool $lookup, bool $skip = false ): void {
		$actions = array(
			''              => __( 'With ticked rows: do nothing', 'guam-restaurants-core' ),
			'skip'          => __( 'Skip / mark handled', 'guam-restaurants-core' ),
			'merge'         => __( 'Merge (possible duplicates)', 'guam-restaurants-core' ),
			'not_duplicate' => __( 'Not the same place (possible duplicates)', 'guam-restaurants-core' ),
			'delete'        => __( 'Delete restaurant (moves to the Trash)', 'guam-restaurants-core' ),
		);
		echo '<div class="tablenav ' . esc_attr( $which ) . '"><div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="gr-bulk-' . esc_attr( $which ) . '">' . esc_html__( 'Action for ticked rows', 'guam-restaurants-core' ) . '</label><select id="gr-bulk-' . esc_attr( $which ) . '" name="bulk_action_' . esc_attr( $which ) . '" class="gr-bulk-action">';
		foreach ( $actions as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $skip ? 'skip' : '', $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		// The first submit button in the form, so Enter in a name box saves the page with the top action.
		echo '<button type="submit" class="button button-primary" name="save" value="' . esc_attr( $which ) . '">' . esc_html__( 'Save this page', 'guam-restaurants-core' ) . '</button>';
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
			$a    = (int) $item['restaurant_id'];
			$b    = (int) ( $payload['duplicate_of'] ?? 0 );
			$keep = self::live( $a ) && self::live( $b ) ? Merger::choose( $a, $b )[0] : 0;
			echo '<div style="display:flex;gap:2em;flex-wrap:wrap">';
			self::render_restaurant( $a, $keep === $a );
			self::render_restaurant( $b, $keep === $b );
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
		} elseif ( 'village_unresolved' === $item['type'] || 'mall_unresolved' === $item['type'] ) {
			echo '<p><em>' . esc_html__( 'Not linked to a restaurant. Set the village on the restaurant itself, then tick this row to mark it handled.', 'guam-restaurants-core' ) . '</em></p>';
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
	 * One restaurant's identifying facts, with boxes to rename it or change
	 * its village or mall and a Delete button. A restaurant shown more than
	 * once on a page is editable only the first time, so two sets of boxes
	 * never disagree.
	 *
	 * @param int  $id   Restaurant.
	 * @param bool $kept Whether a merge keeps this one (possible duplicates).
	 * @return void
	 */
	private static function render_restaurant( int $id, bool $kept = false ): void {
		if ( ! $id || Content_Types::POST_TYPE !== get_post_type( $id ) ) {
			echo '<p>' . esc_html__( '(restaurant no longer exists)', 'guam-restaurants-core' ) . '</p>';
			return;
		}
		$status = get_post_status( $id );
		$title  = html_entity_decode( (string) get_post_field( 'post_title', $id, 'raw' ), ENT_QUOTES, 'UTF-8' );
		echo '<div style="min-width:16em"><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><strong>' . esc_html( $title ) . '</strong></a> #' . esc_html( (string) $id );
		if ( 'trash' === $status ) {
			echo ' <em>' . esc_html__( '(in trash)', 'guam-restaurants-core' ) . '</em>';
		} elseif ( 'publish' !== $status ) {
			echo ' <em>(' . esc_html( (string) get_post_status_object( (string) $status )->label ) . ')</em>';
		}
		if ( $kept ) {
			echo ' <span style="color:#00a32a">' . esc_html__( 'kept if merged', 'guam-restaurants-core' ) . '</span>';
		}
		echo '<br />' . esc_html( (string) get_post_meta( $id, Restaurant_Meta::ADDRESS, true ) );
		echo '<br />' . esc_html( (string) get_post_meta( $id, Restaurant_Meta::PHONE, true ) );
		$osm = (string) get_post_meta( $id, Restaurant_Meta::OSM_ID, true );
		if ( '' !== $osm ) {
			echo '<br />OSM <a href="' . esc_url( 'https://www.openstreetmap.org/' . $osm ) . '">' . esc_html( $osm ) . '</a>';
		}

		if ( 'trash' !== $status ) {
			if ( isset( self::$shown[ $id ] ) ) {
				echo '<br /><em>' . esc_html__( 'Shown above; change it there.', 'guam-restaurants-core' ) . '</em>';
			} else {
				self::$shown[ $id ] = true;
				$field              = 'edit[' . $id . ']';
				$prefix             = 'gr-edit-' . $id;
				echo '<p style="margin:.5em 0 0"><label for="' . esc_attr( $prefix . '-name' ) . '">' . esc_html__( 'Name', 'guam-restaurants-core' ) . '</label><br /><input type="text" id="' . esc_attr( $prefix . '-name' ) . '" name="' . esc_attr( $field . '[name]' ) . '" value="' . esc_attr( $title ) . '" class="regular-text" maxlength="200" autocomplete="off" data-gr-changed-only="1" /><br />';
				$village = self::term_ids( $id, Content_Types::TAX_VILLAGE );
				$mall    = Mall_Verification::with_unverified(
					static function () use ( $id ) {
						return self::term_ids( $id, Content_Types::TAX_MALL );
					}
				);
				self::render_location_selects( $field, $prefix, (int) ( $village[0] ?? 0 ), (int) ( $mall[0] ?? 0 ) );
				/* translators: %s: restaurant name. */
				$confirm = sprintf( __( 'Delete "%s"? It moves to the Trash, where you can restore it.', 'guam-restaurants-core' ), $title );
				echo ' <button type="submit" class="button-link button-link-delete" name="delete_restaurant" value="' . esc_attr( (string) $id ) . '" onclick="return confirm(' . esc_attr( (string) wp_json_encode( $confirm ) ) . ');">' . esc_html__( 'Delete', 'guam-restaurants-core' ) . '</button></p>';
			}
		}
		echo '</div>';
	}

	/**
	 * Village and mall dropdowns. Picking a mall also sets its village.
	 *
	 * @param string $field   Field name prefix, e.g. edit[12].
	 * @param string $prefix  Element ID prefix.
	 * @param int    $village Selected village term ID, or 0.
	 * @param int    $mall    Selected mall term ID, or 0.
	 * @return void
	 */
	private static function render_location_selects( string $field, string $prefix, int $village, int $mall ): void {
		$locations = self::locations();
		$selects   = array(
			'village' => array( __( 'Village', 'guam-restaurants-core' ), __( '(unknown)', 'guam-restaurants-core' ), $village ),
			'mall'    => array( __( 'Mall', 'guam-restaurants-core' ), __( '(not in a mall)', 'guam-restaurants-core' ), $mall ),
		);
		foreach ( $selects as $key => $select ) {
			list( $label, $none, $current ) = $select;
			echo '<label for="' . esc_attr( $prefix . '-' . $key ) . '">' . esc_html( $label ) . '</label> <select id="' . esc_attr( $prefix . '-' . $key ) . '" name="' . esc_attr( $field . '[' . $key . ']' ) . '" data-gr-changed-only="1" style="max-width:14em"><option value="0">' . esc_html( $none ) . '</option>';
			foreach ( $locations[ $key ] as $term_id => $name ) {
				echo '<option value="' . esc_attr( (string) $term_id ) . '"' . selected( $current, $term_id, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</select> ';
		}
	}

	/**
	 * Village and mall choices, by name.
	 *
	 * @return array{village: array<int, string>, mall: array<int, string>}
	 */
	private static function locations(): array {
		if ( null === self::$locations ) {
			$list            = static function ( string $taxonomy ): array {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => false,
						'orderby'    => 'name',
					)
				);
				$out   = array();
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$out[ (int) $term->term_id ] = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
				}
				return $out;
			};
			self::$locations = array(
				'village' => $list( Content_Types::TAX_VILLAGE ),
				'mall'    => Mall_Verification::with_unverified(
					static function () use ( $list ) {
						return $list( Content_Types::TAX_MALL );
					}
				),
			);
		}
		return self::$locations;
	}

	/**
	 * The village and mall a discovered place was found by, as term IDs.
	 *
	 * @param array<string, mixed> $payload Item payload.
	 * @return array{village: int, mall: int}
	 */
	private static function found_by_location( array $payload ): array {
		$found_by = (array) ( $payload['found_by'] ?? array() );
		$slug     = (string) ( $found_by['slug'] ?? '' );
		$out      = array(
			'village' => 0,
			'mall'    => 0,
		);
		if ( '' === $slug ) {
			return $out;
		}
		if ( 'village' === ( $found_by['kind'] ?? '' ) ) {
			$term           = get_term_by( 'slug', $slug, Content_Types::TAX_VILLAGE );
			$out['village'] = $term instanceof \WP_Term ? (int) $term->term_id : 0;
		} elseif ( 'mall' === ( $found_by['kind'] ?? '' ) ) {
			$term = Mall_Verification::with_unverified(
				static function () use ( $slug ) {
					return get_term_by( 'slug', $slug, Content_Types::TAX_MALL );
				}
			);
			if ( $term instanceof \WP_Term ) {
				$out['mall']    = (int) $term->term_id;
				$out['village'] = (int) get_term_meta( $term->term_id, Term_Meta::MALL_VILLAGE, true );
			}
		}
		return $out;
	}

	/**
	 * Whether a restaurant exists and is not in the Trash.
	 *
	 * @param int $id Restaurant.
	 * @return bool
	 */
	private static function live( int $id ): bool {
		return $id && Content_Types::POST_TYPE === get_post_type( $id ) && 'trash' !== get_post_status( $id );
	}

	/**
	 * Before the form is sent, leaves out the restaurant boxes nobody changed,
	 * so a full page stays under PHP's max_input_vars and an untouched box
	 * never overwrites a change made elsewhere.
	 *
	 * @return void
	 */
	private static function render_script(): void {
		?>
		<script>
		( function () {
			var form = document.currentScript.previousElementSibling;
			if ( ! form ) {
				return;
			}
			var fields = form.querySelectorAll( '[data-gr-changed-only]' );
			fields.forEach( function ( el ) {
				el.grInitial = el.value;
			} );
			form.addEventListener( 'submit', function () {
				fields.forEach( function ( el ) {
					el.disabled = el.value === el.grInitial;
				} );
			} );
			window.addEventListener( 'pageshow', function () {
				fields.forEach( function ( el ) {
					el.disabled = false;
				} );
			} );
		}() );
		</script>
		<?php
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
			$found = self::found_by_location( (array) $item['payload'] );
			echo '<br />';
			self::render_location_selects( 'place[' . $id . ']', 'gr-place-' . $id, $found['village'], $found['mall'] );
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
				// Handled with the row's box, "Skip / mark handled" and "Save this page".
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
