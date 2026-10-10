<?php
/**
 * First-party visitor reviews.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Reviews;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Vocabulary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Visitor reviews stored as `user_review` rows in the highlights table
 * (design/content-model.md §5.6), following the review policy in
 * docs/legal-and-policy.md §2.3, §2.4 and §9:
 *
 * - Every review waits for a moderator. Positive and negative reviews are
 *   treated alike: a review is rejected only under a published rule, and
 *   the rule is recorded. Nothing here looks at sentiment.
 * - Reviewers say whether they are connected to the restaurant (16 CFR
 *   465.5); a connected review shows that disclosure next to its text.
 * - Text is stored and shown as written (sanitized for safety only).
 * - Only a display name is collected. The visitor's IP address is used,
 *   hashed, for rate limiting in short-lived transients and never stored
 *   with the review.
 */
final class Visitor_Reviews {

	public const TYPE = 'user_review';

	public const MIN_LENGTH      = 20;
	public const MAX_LENGTH      = 2000;
	public const MAX_NAME_LENGTH = 60;
	public const MAX_DISCLOSURE  = 200;

	/**
	 * Fastest plausible time to write a review, in milliseconds (bot trap).
	 */
	public const MIN_FILL_MS = 3000;

	/**
	 * Reviews one visitor may submit per hour, across all restaurants.
	 */
	public const PER_HOUR = 3;

	/**
	 * Months of history in the trend.
	 */
	public const TREND_MONTHS = 24;

	/**
	 * Published review rules, keyed by Vocabulary::MODERATION_REASONS.
	 *
	 * @return array<string, string>
	 */
	public static function rules(): array {
		return array(
			'off_topic'              => __( 'Not about a visit to this restaurant (off topic).', 'guam-restaurants-core' ),
			'wrong_business'         => __( 'About a different business.', 'guam-restaurants-core' ),
			'hate_speech'            => __( 'Contains hate speech, threats or harassment.', 'guam-restaurants-core' ),
			'personal_info'          => __( 'Contains personal information about someone, such as a phone number or home address.', 'guam-restaurants-core' ),
			'undisclosed_connection' => __( 'Written by someone connected to the restaurant (owner, staff, relative, competitor) without saying so.', 'guam-restaurants-core' ),
			'fake'                   => __( 'Shows clear signs of being fake, paid for or posted in bulk.', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Saves a submitted review as pending.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type int    $rating      1 to 5.
	 *     @type string $body        Review text.
	 *     @type string $author_name Display name.
	 *     @type bool   $connected   Whether the reviewer is connected to the restaurant.
	 *     @type string $connection  How, when connected.
	 *     @type string $website     Honeypot; must be empty.
	 *     @type int    $elapsed_ms  Time spent on the form.
	 * }
	 * @param string               $client_key Hashed visitor key for rate limiting.
	 * @return int|WP_Error Review ID.
	 */
	public static function submit( int $restaurant_id, array $input, string $client_key ) {
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return new WP_Error( 'gr_review_restaurant', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}

		// Bots fill hidden fields and submit instantly; answer them like a normal error.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) || (int) ( $input['elapsed_ms'] ?? 0 ) < self::MIN_FILL_MS ) {
			return new WP_Error( 'gr_review_rejected', __( 'Your review could not be sent. Please try again.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}

		$rating = (int) ( $input['rating'] ?? 0 );
		$body   = trim( sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ) );
		$name   = trim( sanitize_text_field( (string) ( $input['author_name'] ?? '' ) ) );
		$linked = filter_var( $input['connected'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$how    = trim( sanitize_text_field( (string) ( $input['connection'] ?? '' ) ) );

		$errors = new WP_Error();
		if ( $rating < 1 || $rating > 5 ) {
			$errors->add( 'gr_review_rating', __( 'Choose a rating from 1 to 5 stars.', 'guam-restaurants-core' ) );
		}
		if ( mb_strlen( $body ) < self::MIN_LENGTH || mb_strlen( $body ) > self::MAX_LENGTH ) {
			/* translators: 1: minimum characters, 2: maximum characters. */
			$errors->add( 'gr_review_body', sprintf( __( 'Write between %1$d and %2$d characters.', 'guam-restaurants-core' ), self::MIN_LENGTH, self::MAX_LENGTH ) );
		}
		if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_review_name', sprintf( __( 'Enter a display name of up to %d characters.', 'guam-restaurants-core' ), self::MAX_NAME_LENGTH ) );
		}
		if ( $linked && ( '' === $how || mb_strlen( $how ) > self::MAX_DISCLOSURE ) ) {
			$errors->add( 'gr_review_connection', __( 'Say how you are connected to the restaurant.', 'guam-restaurants-core' ) );
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		$limit = self::check_rate_limit( $restaurant_id, $client_key );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}

		global $wpdb;
		$row     = array(
			'restaurant_id' => $restaurant_id,
			'type'          => self::TYPE,
			'rating'        => $rating,
			'body'          => $body,
			'author_name'   => $name,
			'status'        => 'pending',
			'created_at'    => current_time( 'mysql', true ),
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' );
		if ( $linked ) {
			$row['disclosure'] = mb_substr( $how, 0, self::MAX_DISCLOSURE );
			$formats[]         = '%s';
		}
		if ( get_current_user_id() ) {
			$row['author_user_id'] = get_current_user_id();
			$formats[]             = '%d';
		}

		if ( ! $wpdb->insert( Schema::table( Schema::HIGHLIGHTS ), $row, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			return new WP_Error( 'gr_review_save', __( 'Your review could not be saved. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		self::record_submission( $restaurant_id, $client_key );
		self::notify_moderators( $id, $restaurant_id );

		return $id;
	}

	/**
	 * Approves or rejects a review. A rejection must name a published rule.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $status    approved or rejected.
	 * @param string $reason    Rule ID, required when rejecting.
	 * @param int    $user_id   Moderator.
	 * @return true|WP_Error
	 */
	public static function moderate( int $review_id, string $status, string $reason, int $user_id ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'approved', 'rejected' ), true ) ) {
			return new WP_Error( 'gr_review_status', __( 'Unknown status.', 'guam-restaurants-core' ) );
		}
		if ( 'rejected' === $status && ! in_array( $reason, Vocabulary::MODERATION_REASONS, true ) ) {
			return new WP_Error( 'gr_review_reason', __( 'Choose the review rule this review breaks.', 'guam-restaurants-core' ) );
		}
		if ( null === self::get( $review_id ) ) {
			return new WP_Error( 'gr_review_missing', __( 'Review not found.', 'guam-restaurants-core' ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( Schema::HIGHLIGHTS ),
			array(
				'status'            => $status,
				'moderation_reason' => 'rejected' === $status ? $reason : null,
				'moderated_by'      => $user_id,
				'moderated_at'      => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			),
			array(
				'id'   => $review_id,
				'type' => self::TYPE,
			),
			array( '%s', '%s', '%d', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return true;
	}

	/**
	 * One review row.
	 *
	 * @param int $review_id Review ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $review_id ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND type = %s", $review_id, self::TYPE ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Approved reviews, newest first, in the public shape.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $per_page      Page size.
	 * @param int $page          Page, from 1.
	 * @return array<int, array{id: int, rating: int, text: string, author: string, disclosure: string, date: string}>
	 */
	public static function approved( int $restaurant_id, int $per_page = 10, int $page = 1 ): array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, rating, body, author_name, disclosure, created_at FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$restaurant_id,
				self::TYPE,
				'approved',
				max( 1, $per_page ),
				max( 0, $page - 1 ) * max( 1, $per_page )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'id'         => (int) $row['id'],
					'rating'     => (int) $row['rating'],
					'text'       => (string) $row['body'],
					'author'     => (string) $row['author_name'],
					'disclosure' => (string) $row['disclosure'],
					'date'       => mysql2date( 'Y-m-d', $row['created_at'] ),
				);
			},
			$rows
		);
	}

	/**
	 * Count and average of approved reviews.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{count: int, average: float|null}
	 */
	public static function summary( int $restaurant_id ): array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT COUNT(*) AS n, AVG(rating) AS avg_rating FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s AND rating IS NOT NULL", $restaurant_id, self::TYPE, 'approved' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$count = (int) ( $row['n'] ?? 0 );
		return array(
			'count'   => $count,
			'average' => $count > 0 ? round( (float) $row['avg_rating'], 2 ) : null,
		);
	}

	/**
	 * Monthly average of approved ratings for the last TREND_MONTHS months,
	 * by the month each review was written. Months without reviews are left out.
	 *
	 * @param int      $restaurant_id Restaurant.
	 * @param int|null $now           Timestamp for "now" (tests).
	 * @return array<int, array{month: string, average: float, count: int}>
	 */
	public static function monthly_trend( int $restaurant_id, ?int $now = null ): array {
		global $wpdb;
		$now   = $now ?? time();
		$since = gmdate( 'Y-m-01 00:00:00', strtotime( '-' . ( self::TREND_MONTHS - 1 ) . ' months', strtotime( gmdate( 'Y-m-01', $now ) ) ) );
		$table = Schema::table( Schema::HIGHLIGHTS );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month, AVG(rating) AS avg_rating, COUNT(*) AS n FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s AND rating IS NOT NULL AND created_at >= %s GROUP BY month ORDER BY month", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$restaurant_id,
				self::TYPE,
				'approved',
				$since
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'month'   => (string) $row['month'],
					'average' => round( (float) $row['avg_rating'], 2 ),
					'count'   => (int) $row['n'],
				);
			},
			$rows
		);
	}

	/**
	 * Reviews for the moderation screen, newest first.
	 *
	 * @param string $status   pending, approved or rejected.
	 * @param int    $per_page Page size.
	 * @param int    $page     Page, from 1.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_moderation( string $status, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		return $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::TYPE,
				$status,
				max( 1, $per_page ),
				max( 0, $page - 1 ) * max( 1, $per_page )
			),
			ARRAY_A
		);
	}

	/**
	 * Number of reviews in a status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count( string $status ): int {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND status = %s", self::TYPE, $status ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Hashed rate-limit key for the current visitor. The IP address itself is never stored.
	 *
	 * @return string
	 */
	public static function client_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the visitor IP used for rate limiting, for hosts behind a
		 * proxy that puts the real address in a header.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'guam_restaurants_review_client_ip', $ip );

		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Refuses a submission over the hourly limit or a second review of the
	 * same restaurant within a day.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $client_key    Visitor key.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( int $restaurant_id, string $client_key ) {
		$key = substr( $client_key, 0, 32 );
		if ( (int) get_transient( 'gr_review_rl_' . $key ) >= self::PER_HOUR ) {
			return new WP_Error( 'gr_review_rate', __( 'You have sent several reviews recently. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
		}
		if ( get_transient( 'gr_review_dup_' . $key . '_' . $restaurant_id ) ) {
			return new WP_Error( 'gr_review_duplicate', __( 'You have already reviewed this restaurant today.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Counts a submission for rate limiting.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $client_key    Visitor key.
	 * @return void
	 */
	private static function record_submission( int $restaurant_id, string $client_key ): void {
		$key   = substr( $client_key, 0, 32 );
		$count = (int) get_transient( 'gr_review_rl_' . $key );
		set_transient( 'gr_review_rl_' . $key, $count + 1, HOUR_IN_SECONDS );
		set_transient( 'gr_review_dup_' . $key . '_' . $restaurant_id, 1, DAY_IN_SECONDS );
	}

	/**
	 * Emails the site admin that a review is waiting.
	 *
	 * @param int $review_id     Review.
	 * @param int $restaurant_id Restaurant.
	 * @return void
	 */
	private static function notify_moderators( int $review_id, int $restaurant_id ): void {
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: restaurant name. */
			sprintf( __( 'New visitor review waiting: %s', 'guam-restaurants-core' ), get_the_title( $restaurant_id ) ),
			sprintf(
				/* translators: %s: moderation screen URL. */
				__( "A visitor review is waiting for moderation.\n\nReview it here: %s", 'guam-restaurants-core' ),
				admin_url( 'edit.php?post_type=' . Content_Types::POST_TYPE . '&page=' . Moderation_Screen::PAGE . '#review-' . $review_id )
			)
		);
	}
}
