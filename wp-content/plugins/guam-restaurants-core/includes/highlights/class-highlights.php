<?php
/**
 * First-party highlights: owner quotes, editor's notes, user reviews and owner responses.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Vocabulary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Every highlight is a row in `gr_highlights` (design/content-model.md §5.6)
 * and starts as `pending`; nothing is public until an administrator
 * approves it. User reviews are submitted through Reviews\Visitor_Reviews,
 * which stores them in the same table, so there is one source for every
 * highlight and one moderation queue.
 *
 * Owners write quotes and one public response per review, for restaurants
 * they are linked to. Nobody but a moderator can change a review's status,
 * and there is no delete: a review leaves the site only by being rejected
 * under a published rule.
 */
final class Highlights {

	/**
	 * Column formats for $wpdb.
	 */
	private const FORMATS = array(
		'restaurant_id'     => '%d',
		'type'              => '%s',
		'body'              => '%s',
		'author_name'       => '%s',
		'status'            => '%s',
		'created_at'        => '%s',
		'updated_at'        => '%s',
		'parent_id'         => '%d',
		'author_user_id'    => '%d',
		'disclosure'        => '%s',
		'moderation_reason' => '%s',
		'moderated_by'      => '%d',
		'moderated_at'      => '%s',
		'rating'            => '%d',
		'dishes'            => '%s',
		'email_verified'    => '%d',
	);

	public const MAX_NAME_LENGTH = 60;
	public const MAX_DISCLOSURE  = 200;

	/**
	 * Editor's notes shown per restaurant, newest first.
	 */
	public const EDITOR_NOTES_SHOWN = 3;

	/**
	 * Whether a restaurant is published.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return bool
	 */
	public static function is_published_restaurant( int $restaurant_id ): bool {
		return Content_Types::POST_TYPE === get_post_type( $restaurant_id ) && 'publish' === get_post_status( $restaurant_id );
	}

	/**
	 * Saves an owner quote (a short description and signature dishes) as pending.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type string          $body        Description.
	 *     @type string|string[] $dishes      Signature dishes.
	 *     @type string          $author_name Name shown, defaults to the user's display name.
	 * }
	 * @param int                  $user_id       Owner.
	 * @return int|WP_Error Highlight ID.
	 */
	public static function submit_owner_quote( int $restaurant_id, array $input, int $user_id ) {
		if ( ! self::is_published_restaurant( $restaurant_id ) ) {
			return self::unknown_restaurant();
		}
		if ( ! Roles::can_speak_for( $user_id, $restaurant_id ) ) {
			return new WP_Error( 'gr_highlight_forbidden', __( 'Only an owner linked to this restaurant can write its owner quote.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}

		$body   = trim( sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ) );
		$dishes = Policy::clean_dishes( $input['dishes'] ?? array() );
		$name   = self::author_name( $input, $user_id );

		$errors = new WP_Error();
		self::check_length( $errors, $body, Policy::MIN_LENGTH, Policy::OWNER_QUOTE_MAX );
		if ( count( $dishes ) > Policy::MAX_DISHES ) {
			/* translators: %d: maximum number of dishes. */
			$errors->add( 'gr_highlight_dishes', sprintf( __( 'List up to %d signature dishes.', 'guam-restaurants-core' ), Policy::MAX_DISHES ) );
		}
		foreach ( $dishes as $dish ) {
			if ( mb_strlen( $dish ) > Policy::MAX_DISH_LENGTH ) {
				/* translators: %d: maximum characters. */
				$errors->add( 'gr_highlight_dish_length', sprintf( __( 'Keep each dish name to %d characters.', 'guam-restaurants-core' ), Policy::MAX_DISH_LENGTH ) );
				break;
			}
		}
		self::check_name( $errors, $name );
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		return self::insert(
			array(
				'restaurant_id'  => $restaurant_id,
				'type'           => Policy::OWNER_QUOTE,
				'body'           => $body,
				'dishes'         => $dishes ? implode( "\n", $dishes ) : null,
				'author_name'    => $name,
				'author_user_id' => $user_id,
			)
		);
	}

	/**
	 * Saves an editor's note as pending.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type string $body       Note.
	 *     @type string $disclosure Anything readers should know, such as a meal the restaurant paid for.
	 * }
	 * @param int                  $user_id       Staff member.
	 * @return int|WP_Error Highlight ID.
	 */
	public static function submit_editor_note( int $restaurant_id, array $input, int $user_id ) {
		if ( ! self::is_published_restaurant( $restaurant_id ) ) {
			return self::unknown_restaurant();
		}
		if ( ! user_can( $user_id, Roles::EDITOR ) ) {
			return new WP_Error( 'gr_highlight_forbidden', __( "Only staff can write editor's notes.", 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		// Editorial policy: staff connected to a restaurant do not write about it.
		if ( Roles::owns( $user_id, $restaurant_id ) ) {
			return new WP_Error( 'gr_highlight_conflict', __( 'You are linked to this restaurant as an owner, so you cannot write its editor\'s note.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}

		$body       = trim( sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ) );
		$disclosure = trim( sanitize_text_field( (string) ( $input['disclosure'] ?? '' ) ) );
		$name       = self::author_name( array(), $user_id );

		$errors = new WP_Error();
		self::check_length( $errors, $body, Policy::MIN_LENGTH, Policy::EDITOR_NOTE_MAX );
		if ( mb_strlen( $disclosure ) > self::MAX_DISCLOSURE ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_highlight_disclosure', sprintf( __( 'Keep the disclosure to %d characters.', 'guam-restaurants-core' ), self::MAX_DISCLOSURE ) );
		}
		self::check_name( $errors, $name );
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		return self::insert(
			array(
				'restaurant_id'  => $restaurant_id,
				'type'           => Policy::EDITOR_NOTE,
				'body'           => $body,
				'disclosure'     => '' === $disclosure ? null : $disclosure,
				'author_name'    => $name,
				'author_user_id' => $user_id,
			)
		);
	}

	/**
	 * Saves an owner's public response to an approved user review as pending.
	 * One response per review; a rejected response may be rewritten.
	 *
	 * @param int                  $review_id Review answered.
	 * @param array<string, mixed> $input     `body`.
	 * @param int                  $user_id   Owner.
	 * @return int|WP_Error Highlight ID.
	 */
	public static function submit_owner_response( int $review_id, array $input, int $user_id ) {
		$review = self::get( $review_id );
		if ( null === $review || Policy::USER_REVIEW !== $review['type'] || 'approved' !== $review['status'] ) {
			return new WP_Error( 'gr_highlight_review', __( 'Owners can respond only to published reviews.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		$restaurant_id = (int) $review['restaurant_id'];
		if ( ! Roles::can_speak_for( $user_id, $restaurant_id ) ) {
			return new WP_Error( 'gr_highlight_forbidden', __( 'Only an owner linked to this restaurant can respond to its reviews.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		if ( null !== self::response_to( $review_id, array( 'pending', 'approved' ) ) ) {
			return new WP_Error( 'gr_highlight_response_exists', __( 'This review already has a response from the owner.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}

		$body   = trim( sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ) );
		$name   = self::author_name( $input, $user_id );
		$errors = new WP_Error();
		self::check_length( $errors, $body, 2, Policy::OWNER_RESPONSE_MAX );
		self::check_name( $errors, $name );
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		return self::insert(
			array(
				'restaurant_id'  => $restaurant_id,
				'type'           => Policy::OWNER_RESPONSE,
				'parent_id'      => $review_id,
				'body'           => $body,
				'author_name'    => $name,
				'author_user_id' => $user_id,
			)
		);
	}

	/**
	 * Approves or rejects a highlight. Only moderators may, a rejection must
	 * name a published rule, and nobody moderates reviews of a restaurant
	 * they are linked to.
	 *
	 * @param int    $highlight_id Highlight.
	 * @param string $status       approved or rejected.
	 * @param string $reason       Rule ID, required when rejecting.
	 * @param int    $user_id      Moderator.
	 * @return true|WP_Error
	 */
	public static function moderate( int $highlight_id, string $status, string $reason, int $user_id ) {
		global $wpdb;

		if ( ! user_can( $user_id, Roles::MODERATE ) ) {
			return new WP_Error( 'gr_highlight_forbidden', __( 'You are not allowed to moderate highlights.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
			return new WP_Error( 'gr_review_status', __( 'Unknown status.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}
		if ( 'rejected' === $status && ! Policy::is_rule( $reason ) ) {
			return new WP_Error( 'gr_review_reason', __( 'Choose the published rule this highlight breaks.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}
		$row = self::get( $highlight_id );
		if ( null === $row ) {
			return new WP_Error( 'gr_review_missing', __( 'Highlight not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		if ( in_array( $row['type'], array( Policy::USER_REVIEW, Policy::OWNER_RESPONSE ), true ) && Roles::owns( $user_id, (int) $row['restaurant_id'] ) ) {
			return new WP_Error( 'gr_highlight_conflict', __( 'You are linked to this restaurant, so another administrator must moderate its reviews.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}

		$now = current_time( 'mysql', true );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( Schema::HIGHLIGHTS ),
			array(
				'status'            => $status,
				'moderation_reason' => 'rejected' === $status ? $reason : null,
				'moderated_by'      => $user_id,
				'moderated_at'      => $now,
				'updated_at'        => $now,
			),
			array( 'id' => $highlight_id ),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		// Owner quotes and editor's notes are in the page HTML; let page caches know.
		clean_post_cache( (int) $row['restaurant_id'] );

		/**
		 * Fires after a highlight is approved or rejected.
		 *
		 * @param int                  $highlight_id Highlight.
		 * @param string               $status       New status.
		 * @param array<string, mixed> $row          Row before the change.
		 */
		do_action( 'guam_restaurants_highlight_moderated', $highlight_id, $status, $row );
		return true;
	}

	/**
	 * One row.
	 *
	 * @param int $highlight_id Highlight.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $highlight_id ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $highlight_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Approved highlights of one type for a restaurant, newest first.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $type          Type.
	 * @param int    $limit         Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function approved( int $restaurant_id, string $type, int $limit = 10 ): array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s ORDER BY COALESCE(moderated_at, created_at) DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$restaurant_id,
				$type,
				'approved',
				max( 1, $limit )
			),
			ARRAY_A
		);
	}

	/**
	 * The owner's response to a review, in one of the given statuses.
	 *
	 * @param int      $review_id Review.
	 * @param string[] $statuses  Statuses.
	 * @return array<string, mixed>|null
	 */
	public static function response_to( int $review_id, array $statuses = array( 'approved' ) ): ?array {
		$responses = self::responses_to( array( $review_id ), $statuses );
		return $responses[ $review_id ] ?? null;
	}

	/**
	 * Owner responses for several reviews, keyed by review ID (the newest per review).
	 *
	 * @param int[]    $review_ids Reviews.
	 * @param string[] $statuses   Statuses.
	 * @return array<int, array<string, mixed>>
	 */
	public static function responses_to( array $review_ids, array $statuses = array( 'approved' ) ): array {
		global $wpdb;
		$review_ids = array_values( array_filter( array_map( 'intval', $review_ids ) ) );
		$statuses   = array_values( array_intersect( $statuses, Vocabulary::HIGHLIGHT_STATUSES ) );
		if ( ! $review_ids || ! $statuses ) {
			return array();
		}
		$table     = Schema::table( Schema::HIGHLIGHTS );
		$ids       = implode( ',', array_fill( 0, count( $review_ids ), '%d' ) );
		$stats     = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$rows      = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND parent_id IN ({$ids}) AND status IN ({$stats}) ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( Policy::OWNER_RESPONSE ), $review_ids, $statuses )
			),
			ARRAY_A
		);
		$by_review = array();
		foreach ( $rows as $row ) {
			$by_review[ (int) $row['parent_id'] ] = $row;
		}
		return $by_review;
	}

	/**
	 * Rows for the moderation screen, newest first.
	 *
	 * @param string $status   Status.
	 * @param string $type     Type, or '' for all.
	 * @param int    $per_page Page size.
	 * @param int    $page     Page, from 1.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_moderation( string $status, string $type = '', int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND ( %s = '' OR type = %s ) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status,
				$type,
				$type,
				max( 1, $per_page ),
				max( 0, $page - 1 ) * max( 1, $per_page )
			),
			ARRAY_A
		);
	}

	/**
	 * Number of highlights in a status.
	 *
	 * @param string $status Status.
	 * @param string $type   Type, or '' for all.
	 * @return int
	 */
	public static function count( string $status, string $type = '' ): int {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND ( %s = '' OR type = %s )", $status, $type, $type ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Inserts a pending row and tells the moderators.
	 *
	 * @param array<string, mixed> $row Columns; status and created_at are set here.
	 * @return int|WP_Error
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$row['status']     = 'pending';
		$row['created_at'] = current_time( 'mysql', true );
		$row               = array_filter(
			array_intersect_key( $row, self::FORMATS ),
			static function ( $value ) {
				return null !== $value;
			}
		);
		$formats           = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = self::FORMATS[ $column ];
		}
		if ( ! $wpdb->insert( Schema::table( Schema::HIGHLIGHTS ), $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'gr_review_save', __( 'This could not be saved. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		self::notify_moderators( $id, (int) $row['restaurant_id'], (string) $row['type'] );
		return $id;
	}

	/**
	 * Marks a highlight's writer as having confirmed an email address.
	 *
	 * @param int $highlight_id Highlight.
	 * @return void
	 */
	public static function mark_email_verified( int $highlight_id ): void {
		global $wpdb;
		$wpdb->update( Schema::table( Schema::HIGHLIGHTS ), array( 'email_verified' => 1 ), array( 'id' => $highlight_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Emails the site admin that something is waiting.
	 *
	 * @param int    $highlight_id  Highlight.
	 * @param int    $restaurant_id Restaurant.
	 * @param string $type          Type.
	 * @return void
	 */
	private static function notify_moderators( int $highlight_id, int $restaurant_id, string $type ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: 1: kind of highlight, for example "User review", 2: restaurant name. */
			sprintf( __( 'Waiting for approval (%1$s): %2$s', 'guam-restaurants-core' ), Policy::type_label( $type ), get_the_title( $restaurant_id ) ),
			sprintf(
				/* translators: %s: moderation screen URL. */
				__( "A highlight is waiting for moderation.\n\nReview it here: %s", 'guam-restaurants-core' ),
				Moderation_Screen::url( 'pending' ) . '#highlight-' . $highlight_id
			)
		);
	}

	/**
	 * Name to show: the one given, else the user's display name.
	 *
	 * @param array<string, mixed> $input   Input.
	 * @param int                  $user_id User.
	 * @return string
	 */
	private static function author_name( array $input, int $user_id ): string {
		$name = trim( sanitize_text_field( (string) ( $input['author_name'] ?? '' ) ) );
		if ( '' === $name && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			$name = $user ? trim( (string) $user->display_name ) : '';
		}
		return $name;
	}

	/**
	 * Adds a length error.
	 *
	 * @param WP_Error $errors Errors.
	 * @param string   $body   Text.
	 * @param int      $min    Minimum characters.
	 * @param int      $max    Maximum characters.
	 * @return void
	 */
	private static function check_length( WP_Error $errors, string $body, int $min, int $max ): void {
		if ( mb_strlen( $body ) < $min || mb_strlen( $body ) > $max ) {
			/* translators: 1: minimum characters, 2: maximum characters. */
			$errors->add( 'gr_review_body', sprintf( __( 'Write between %1$d and %2$d characters.', 'guam-restaurants-core' ), $min, $max ) );
		}
	}

	/**
	 * Adds a display-name error.
	 *
	 * @param WP_Error $errors Errors.
	 * @param string   $name   Name.
	 * @return void
	 */
	private static function check_name( WP_Error $errors, string $name ): void {
		if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_review_name', sprintf( __( 'Enter a display name of up to %d characters.', 'guam-restaurants-core' ), self::MAX_NAME_LENGTH ) );
		}
	}

	/**
	 * Unknown-restaurant error.
	 *
	 * @return WP_Error
	 */
	private static function unknown_restaurant(): WP_Error {
		return new WP_Error( 'gr_review_restaurant', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
	}
}
