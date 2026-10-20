<?php
/**
 * First-party visitor reviews.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Reviews;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Email_Verification;
use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Highlights\Policy;
use GuamRestaurants\Core\Highlights\Roles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Visitor reviews stored as `user_review` rows in the highlights table
 * (design/content-model.md §5.6), following the review policy in
 * docs/legal-and-policy.md §2.3, §2.4 and §9:
 *
 * - Every review waits for an administrator. Positive and negative reviews
 *   are treated alike: a review is rejected only under a published rule
 *   (Highlights\Policy::rules()), and the rule is recorded. Nothing here
 *   looks at sentiment.
 * - A visitor either is logged in or confirms an email address through a
 *   one-time link before the review reaches the moderation queue. The
 *   address is used to send that link and is never stored. A confirmed
 *   address earns the "Verified email" badge, which claims nothing more.
 * - Reviewers say whether they are connected to the restaurant (16 CFR
 *   465.5); a connected review shows that disclosure next to its text.
 *   Users linked to the restaurant as owners cannot review it.
 * - Text is stored and shown as written (sanitized for safety only).
 * - A reviewer may name up to MAX_DISHES dishes they tried. They show with
 *   the review and count toward Dishes\Popular_Dishes (a connected
 *   reviewer's dishes show but never count).
 * - The visitor's IP address and email are used, hashed, for rate limiting
 *   in short-lived transients and never stored with the review.
 */
final class Visitor_Reviews {

	public const TYPE = Policy::USER_REVIEW;

	public const MIN_LENGTH      = 20;
	public const MAX_LENGTH      = 2000;
	public const MAX_NAME_LENGTH = 60;
	public const MAX_DISCLOSURE  = 200;
	public const MAX_DISHES      = 3;

	/**
	 * Fastest plausible time to write a review, in milliseconds (bot trap).
	 */
	public const MIN_FILL_MS = 3000;

	/**
	 * Reviews one visitor (or one email address) may submit per hour, across all restaurants.
	 */
	public const PER_HOUR = 3;

	/**
	 * Months of history in the trend.
	 */
	public const TREND_MONTHS = 24;

	/**
	 * Published review rules.
	 *
	 * @return array<string, string>
	 */
	public static function rules(): array {
		return Policy::rules();
	}

	/**
	 * Checks a submission and, for a logged-in visitor, saves it as pending.
	 * A visitor who is not logged in gets an email with a confirmation link
	 * instead, and the review is saved when the link is opened.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type int    $rating       1 to 5.
	 *     @type string $body         Review text.
	 *     @type string $author_name  Display name (logged-in visitors may leave it empty).
	 *     @type string $email        Required when not logged in; never stored.
	 *     @type bool   $verify_email Logged in: also confirm the account email for the badge.
	 *     @type bool   $connected    Whether the reviewer is connected to the restaurant.
	 *     @type string $connection   How, when connected.
	 *     @type string|string[] $dishes Dishes tried, comma separated or a list (optional).
	 *     @type string $website      Honeypot; must be empty.
	 *     @type int    $elapsed_ms   Time spent on the form.
	 * }
	 * @param string               $client_key Hashed visitor key for rate limiting.
	 * @return array{status: string, id: int}|WP_Error `pending` with the review ID, or `awaiting_email` with ID 0.
	 */
	public static function submit( int $restaurant_id, array $input, string $client_key ) {
		if ( ! Highlights::is_published_restaurant( $restaurant_id ) ) {
			return new WP_Error( 'gr_review_restaurant', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}

		// Bots fill hidden fields and submit instantly; answer them like a normal error.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) || (int) ( $input['elapsed_ms'] ?? 0 ) < self::MIN_FILL_MS ) {
			return new WP_Error( 'gr_review_rejected', __( 'Your review could not be sent. Please try again.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id && Roles::owns( $user_id, $restaurant_id ) ) {
			return new WP_Error( 'gr_review_owner', __( 'You manage this restaurant, so you cannot review it. You can reply to reviews instead.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}

		$rating = (int) ( $input['rating'] ?? 0 );
		$body   = trim( sanitize_textarea_field( (string) ( $input['body'] ?? '' ) ) );
		$name   = trim( sanitize_text_field( (string) ( $input['author_name'] ?? '' ) ) );
		$linked = filter_var( $input['connected'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$how    = trim( sanitize_text_field( (string) ( $input['connection'] ?? '' ) ) );
		$email  = trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) );
		$dishes = Policy::clean_dishes( $input['dishes'] ?? array() );
		if ( '' === $name && $user_id ) {
			$name = trim( (string) wp_get_current_user()->display_name );
		}

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
		if ( count( $dishes ) > self::MAX_DISHES ) {
			/* translators: %d: maximum number of dishes. */
			$errors->add( 'gr_review_dishes', sprintf( __( 'Name up to %d dishes.', 'guam-restaurants-core' ), self::MAX_DISHES ) );
		}
		foreach ( $dishes as $dish ) {
			if ( mb_strlen( $dish ) > Policy::MAX_DISH_LENGTH ) {
				/* translators: %d: maximum characters. */
				$errors->add( 'gr_review_dish_length', sprintf( __( 'Keep each dish name to %d characters.', 'guam-restaurants-core' ), Policy::MAX_DISH_LENGTH ) );
				break;
			}
		}
		if ( ! $user_id && ! is_email( $email ) ) {
			$errors->add( 'gr_review_email', __( 'Enter your email address so we can send you a confirmation link, or log in.', 'guam-restaurants-core' ) );
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		$email_key = $user_id ? '' : Email_Verification::email_key( $email );
		$limit     = self::check_rate_limit( $restaurant_id, $client_key, $email_key );
		if ( is_wp_error( $limit ) ) {
			return $limit;
		}
		self::record_submission( $restaurant_id, $client_key, $email_key );

		$row = array(
			'restaurant_id' => $restaurant_id,
			'type'          => self::TYPE,
			'rating'        => $rating,
			'body'          => $body,
			'author_name'   => $name,
			'disclosure'    => $linked ? mb_substr( $how, 0, self::MAX_DISCLOSURE ) : null,
			'dishes'        => $dishes ? implode( "\n", $dishes ) : null,
		);

		if ( ! $user_id ) {
			$token = Email_Verification::create(
				array(
					'kind' => 'review',
					'row'  => $row,
				)
			);
			if ( ! Email_Verification::send( $email, Rest_Controller::confirm_url( $token ), $restaurant_id ) ) {
				return new WP_Error( 'gr_review_email_failed', __( 'We could not send the confirmation email. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
			}
			return array(
				'status' => 'awaiting_email',
				'id'     => 0,
			);
		}

		$row['author_user_id'] = $user_id;
		$id                    = Highlights::insert( $row );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( filter_var( $input['verify_email'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			$token = Email_Verification::create(
				array(
					'kind' => 'badge',
					'id'   => $id,
				)
			);
			Email_Verification::send( (string) wp_get_current_user()->user_email, Rest_Controller::confirm_url( $token ), $restaurant_id );
		}

		return array(
			'status' => 'pending',
			'id'     => $id,
		);
	}

	/**
	 * Opens a confirmation link: saves the review as pending (or, for a
	 * logged-in reviewer, adds the badge) and marks the email as confirmed.
	 *
	 * @param string $token Token from the link.
	 * @return array{id: int, restaurant_id: int}|WP_Error
	 */
	public static function confirm( string $token ) {
		$payload = Email_Verification::take( $token );
		if ( null === $payload ) {
			return new WP_Error( 'gr_review_token', __( 'This link has expired or was already used.', 'guam-restaurants-core' ), array( 'status' => 410 ) );
		}

		if ( 'badge' === ( $payload['kind'] ?? '' ) ) {
			$review = self::get( (int) ( $payload['id'] ?? 0 ) );
			if ( null === $review ) {
				return new WP_Error( 'gr_review_missing', __( 'Review not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
			}
			Highlights::mark_email_verified( (int) $review['id'] );
			return array(
				'id'            => (int) $review['id'],
				'restaurant_id' => (int) $review['restaurant_id'],
			);
		}

		$row = (array) ( $payload['row'] ?? array() );
		if ( self::TYPE !== ( $row['type'] ?? '' ) || ! Highlights::is_published_restaurant( (int) ( $row['restaurant_id'] ?? 0 ) ) ) {
			return new WP_Error( 'gr_review_restaurant', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		$row['email_verified'] = 1;
		$id                    = Highlights::insert( $row );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array(
			'id'            => $id,
			'restaurant_id' => (int) $row['restaurant_id'],
		);
	}

	/**
	 * Approves or rejects a review (see Highlights::moderate()).
	 *
	 * @param int    $review_id Review ID.
	 * @param string $status    approved or rejected.
	 * @param string $reason    Rule ID, required when rejecting.
	 * @param int    $user_id   Moderator.
	 * @return true|WP_Error
	 */
	public static function moderate( int $review_id, string $status, string $reason, int $user_id ) {
		if ( null === self::get( $review_id ) ) {
			return new WP_Error( 'gr_review_missing', __( 'Review not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		return Highlights::moderate( $review_id, $status, $reason, $user_id );
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
	 * Approved reviews, newest first, in the public shape, each with the
	 * owner's approved response if there is one.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $per_page      Page size.
	 * @param int $page          Page, from 1.
	 * @return array<int, array{id: int, rating: int, text: string, author: string, disclosure: string, dishes: string[], date: string, email_verified: bool, response: array{text: string, author: string, date: string}|null}>
	 */
	public static function approved( int $restaurant_id, int $per_page = 10, int $page = 1 ): array {
		global $wpdb;
		$table     = Schema::table( Schema::HIGHLIGHTS );
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, rating, body, author_name, disclosure, dishes, created_at, email_verified FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$restaurant_id,
				self::TYPE,
				'approved',
				max( 1, $per_page ),
				max( 0, $page - 1 ) * max( 1, $per_page )
			),
			ARRAY_A
		);
		$responses = Highlights::responses_to( array_map( 'intval', array_column( (array) $rows, 'id' ) ) );

		return array_map(
			static function ( $row ) use ( $responses ) {
				$response = $responses[ (int) $row['id'] ] ?? null;
				return array(
					'id'             => (int) $row['id'],
					'rating'         => (int) $row['rating'],
					'text'           => (string) $row['body'],
					'author'         => (string) $row['author_name'],
					'disclosure'     => (string) $row['disclosure'],
					'dishes'         => '' === (string) $row['dishes'] ? array() : explode( "\n", (string) $row['dishes'] ),
					'date'           => mysql2date( 'Y-m-d', $row['created_at'] ),
					'email_verified' => (bool) (int) $row['email_verified'],
					'response'       => null === $response ? null : array(
						'text'   => (string) $response['body'],
						'author' => (string) $response['author_name'],
						'date'   => mysql2date( 'Y-m-d', (string) ( $response['moderated_at'] ?? $response['created_at'] ) ),
					),
				);
			},
			(array) $rows
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
		return Highlights::for_moderation( $status, self::TYPE, $per_page, $page );
	}

	/**
	 * Number of reviews in a status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count( string $status ): int {
		return Highlights::count( $status, self::TYPE );
	}

	/**
	 * Hashed rate-limit key for the current visitor. The IP address itself is never stored.
	 *
	 * @return string
	 */
	public static function client_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Behind a CDN every visitor shares the CDN's addresses, so one
		// visitor's limit would block everyone (docs/security.md §5).
		$header = strtoupper( Config::get( Config::CLIENT_IP_HEADER ) );
		if ( '' !== $header && preg_match( '/^HTTP_[A-Z0-9_]+$/', $header ) && ! empty( $_SERVER[ $header ] ) ) {
			$forwarded = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
			if ( false !== filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
				$ip = $forwarded;
			}
		}

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
	 * Refuses a submission over the hourly limit (per visitor and per email
	 * address) or a second review of the same restaurant within a day.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $client_key    Visitor key.
	 * @param string $email_key     Hashed email, or ''.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( int $restaurant_id, string $client_key, string $email_key ) {
		foreach ( array_filter( array( $client_key, $email_key ) ) as $full_key ) {
			$key = substr( $full_key, 0, 32 );
			if ( (int) get_transient( 'gr_review_rl_' . $key ) >= self::PER_HOUR ) {
				return new WP_Error( 'gr_review_rate', __( 'You have sent several reviews recently. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
			}
			if ( get_transient( 'gr_review_dup_' . $key . '_' . $restaurant_id ) ) {
				return new WP_Error( 'gr_review_duplicate', __( 'You have already reviewed this restaurant today.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
			}
		}
		return true;
	}

	/**
	 * Counts a submission for rate limiting.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $client_key    Visitor key.
	 * @param string $email_key     Hashed email, or ''.
	 * @return void
	 */
	private static function record_submission( int $restaurant_id, string $client_key, string $email_key ): void {
		foreach ( array_filter( array( $client_key, $email_key ) ) as $full_key ) {
			$key   = substr( $full_key, 0, 32 );
			$count = (int) get_transient( 'gr_review_rl_' . $key );
			set_transient( 'gr_review_rl_' . $key, $count + 1, HOUR_IN_SECONDS );
			set_transient( 'gr_review_dup_' . $key . '_' . $restaurant_id, 1, DAY_IN_SECONDS );
		}
	}
}
