<?php
/**
 * Listing claims: request, verification and approval.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Claims;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Restaurant_Meta;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A claim links a WordPress account to a restaurant in three steps:
 *
 * 1. Request: a logged-in user asks to claim a published, unclaimed
 *    restaurant and picks how to prove it:
 *    - `email`: a code is sent to an address at the restaurant's own
 *      website domain (the website on the listing). Not offered when the
 *      listing has no website or the website is on a shared host such as
 *      Facebook, Instagram or a site builder, where anyone can have an
 *      address or page.
 *    - `phone`: a code is texted to the phone number on the listing. Off
 *      unless GUAM_RESTAURANTS_CLAIM_PHONE_CODES is set, because texts cost
 *      money, and it needs an SMS sender hooked to
 *      `guam_restaurants_send_claim_sms`.
 * 2. Verify: the user enters the six-digit code within 30 minutes; five
 *    wrong tries end the claim. A verified claim links nothing yet.
 * 3. Approve: an administrator (CAP) approves or rejects. Approval sets the
 *    restaurant's `gr_claimed_by_user_id`, gives the user the Restaurant
 *    owner role (keeping their other roles) and closes other open claims
 *    on the restaurant.
 *
 * Codes are stored only as a keyed hash. The address a code went to is
 * kept so the administrator can see it when approving.
 */
final class Claims {

	public const CAP = 'gr_approve_claims';

	public const METHOD_EMAIL = 'email';
	public const METHOD_PHONE = 'phone';

	public const PENDING   = 'pending';
	public const VERIFIED  = 'verified';
	public const APPROVED  = 'approved';
	public const REJECTED  = 'rejected';
	public const CANCELLED = 'cancelled';

	public const OPEN_STATUSES = array( self::PENDING, self::VERIFIED );

	public const CODE_TTL       = 30 * MINUTE_IN_SECONDS;
	public const MAX_ATTEMPTS   = 5;
	public const MAX_OPEN       = 3;
	public const SENDS_PER_HOUR = 5;
	public const RATE_PREFIX    = 'gr_claim_rl_';

	/**
	 * Website hosts where an address or page does not show who runs the
	 * restaurant. A listing whose website is on one of these (or a
	 * subdomain) cannot be verified by email.
	 */
	public const SHARED_HOSTS = array(
		'facebook.com',
		'fb.com',
		'instagram.com',
		'linktr.ee',
		'google.com',
		'business.site',
		'wixsite.com',
		'wix.com',
		'square.site',
		'squarespace.com',
		'weebly.com',
		'wordpress.com',
		'blogspot.com',
		'godaddysites.com',
		'yelp.com',
		'tripadvisor.com',
		'ubereats.com',
		'doordash.com',
		'toasttab.com',
		'gmail.com',
		'yahoo.com',
		'outlook.com',
		'hotmail.com',
		'icloud.com',
	);

	/**
	 * Whether phone codes are switched on.
	 *
	 * @return bool
	 */
	public static function phone_enabled(): bool {
		return Config::flag( Config::CLAIM_PHONE_CODES );
	}

	/**
	 * The restaurant's own website domain (lowercase, without "www."), or
	 * '' when it has none or the website is on a shared host.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function claim_domain( int $restaurant_id ): string {
		$host = strtolower( (string) wp_parse_url( (string) get_post_meta( $restaurant_id, Restaurant_Meta::WEBSITE, true ), PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		if ( '' === $host || false === strpos( $host, '.' ) ) {
			return '';
		}
		foreach ( self::SHARED_HOSTS as $shared ) {
			if ( $host === $shared || str_ends_with( $host, '.' . $shared ) ) {
				return '';
			}
		}
		return $host;
	}

	/**
	 * Whether an email address is at a domain: the same host, a parent of
	 * it (owner@example.com for menu.example.com) or a subdomain of it
	 * (owner@mail.example.com for example.com).
	 *
	 * @param string $email  Email address.
	 * @param string $domain From claim_domain().
	 * @return bool
	 */
	public static function email_matches_domain( string $email, string $domain ): bool {
		if ( '' === $domain || ! is_email( $email ) ) {
			return false;
		}
		$at = strtolower( substr( (string) strrchr( $email, '@' ), 1 ) );
		if ( '' === $at || false === strpos( $at, '.' ) ) {
			return false;
		}
		return $at === $domain || str_ends_with( $domain, '.' . $at ) || str_ends_with( $at, '.' . $domain );
	}

	/**
	 * Verification methods a restaurant offers.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string[]
	 */
	public static function methods( int $restaurant_id ): array {
		$methods = array();
		if ( '' !== self::claim_domain( $restaurant_id ) ) {
			$methods[] = self::METHOD_EMAIL;
		}
		if ( self::phone_enabled() && '' !== (string) get_post_meta( $restaurant_id, Restaurant_Meta::PHONE, true ) ) {
			$methods[] = self::METHOD_PHONE;
		}
		return $methods;
	}

	/**
	 * Starts a claim and sends a code. A user's earlier unverified claim on
	 * the same restaurant is replaced.
	 *
	 * @param int    $user_id       Claimant.
	 * @param int    $restaurant_id Restaurant.
	 * @param string $method        email or phone.
	 * @param string $email         Address at the restaurant's domain (email method).
	 * @return int|WP_Error Claim ID.
	 */
	public static function request( int $user_id, int $restaurant_id, string $method, string $email = '' ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'gr_claim_login', __( 'Log in or create an account to claim a listing.', 'guam-restaurants-core' ), array( 'status' => 401 ) );
		}
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return new WP_Error( 'gr_claim_restaurant', __( 'That listing was not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		$owner = (int) get_post_meta( $restaurant_id, Restaurant_Meta::CLAIMED_BY_USER_ID, true );
		if ( $owner === $user_id ) {
			return new WP_Error( 'gr_claim_yours', __( 'This listing is already linked to your account.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}
		if ( $owner > 0 ) {
			return new WP_Error( 'gr_claim_taken', __( 'This listing has already been claimed. If you manage this restaurant, contact us and we will look into it.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $method, self::methods( $restaurant_id ), true ) ) {
			return new WP_Error( 'gr_claim_method', __( 'That way of confirming is not available for this listing. Contact us and we will confirm it another way.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}
		$contact = '';
		if ( self::METHOD_EMAIL === $method ) {
			$email = sanitize_email( $email );
			if ( ! self::email_matches_domain( $email, self::claim_domain( $restaurant_id ) ) ) {
				return new WP_Error(
					'gr_claim_email',
					/* translators: %s: domain such as example.com. */
					sprintf( __( 'Use an email address at %s, the restaurant\'s website.', 'guam-restaurants-core' ), self::claim_domain( $restaurant_id ) ),
					array( 'status' => 400 )
				);
			}
			$contact = strtolower( $email );
		} else {
			$contact = (string) get_post_meta( $restaurant_id, Restaurant_Meta::PHONE, true );
		}

		$open = self::for_user( $user_id, self::OPEN_STATUSES );
		foreach ( $open as $claim ) {
			if ( (int) $claim['restaurant_id'] === $restaurant_id && self::VERIFIED === $claim['status'] ) {
				return new WP_Error( 'gr_claim_waiting', __( 'Your claim on this listing is confirmed and waiting for review.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
			}
		}
		$others = array_filter(
			$open,
			static function ( array $claim ) use ( $restaurant_id ): bool {
				return (int) $claim['restaurant_id'] !== $restaurant_id;
			}
		);
		if ( count( $others ) >= self::MAX_OPEN ) {
			return new WP_Error( 'gr_claim_too_many', __( 'You have several claims waiting already. Finish or wait for those first.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
		}
		if ( ! self::take_send_slot( $user_id ) ) {
			return new WP_Error( 'gr_claim_rate', __( 'Too many codes were requested. Try again in an hour.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
		}

		foreach ( $open as $claim ) {
			if ( (int) $claim['restaurant_id'] === $restaurant_id ) {
				self::set_status( (int) $claim['id'], self::CANCELLED, 'replaced by a new request', 0 );
			}
		}

		global $wpdb;
		$code     = self::new_code();
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Schema::table( Schema::CLAIMS ),
			array(
				'restaurant_id' => $restaurant_id,
				'user_id'       => $user_id,
				'method'        => $method,
				'contact'       => $contact,
				'code_hash'     => '',
				'code_expires'  => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL ),
				'attempts'      => 0,
				'status'        => self::PENDING,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'gr_claim_db', __( 'The claim could not be saved. Please try again.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
		}
		$claim_id = (int) $wpdb->insert_id;
		$wpdb->update( Schema::table( Schema::CLAIMS ), array( 'code_hash' => self::hash( $claim_id, $code ) ), array( 'id' => $claim_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$sent = self::METHOD_EMAIL === $method ? self::send_email_code( $contact, $restaurant_id, $code ) : self::send_sms_code( $contact, $restaurant_id, $code );
		if ( ! $sent ) {
			self::set_status( $claim_id, self::CANCELLED, 'code could not be sent', 0 );
			return new WP_Error( 'gr_claim_send', __( 'The code could not be sent. Please try again later or contact us.', 'guam-restaurants-core' ), array( 'status' => 502 ) );
		}
		return $claim_id;
	}

	/**
	 * Checks a code. A correct code marks the claim verified and tells the
	 * administrators; it does not link the account.
	 *
	 * @param int    $claim_id Claim.
	 * @param int    $user_id  Claimant.
	 * @param string $code     Code entered.
	 * @return true|WP_Error
	 */
	public static function verify( int $claim_id, int $user_id, string $code ) {
		global $wpdb;
		$claim = self::get( $claim_id );
		if ( null === $claim || (int) $claim['user_id'] !== $user_id ) {
			return new WP_Error( 'gr_claim_missing', __( 'Claim not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		if ( self::PENDING !== $claim['status'] ) {
			return new WP_Error( 'gr_claim_state', __( 'This claim is not waiting for a code.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}
		if ( strtotime( $claim['code_expires'] . ' UTC' ) < time() ) {
			self::set_status( $claim_id, self::CANCELLED, 'code expired', 0 );
			return new WP_Error( 'gr_claim_expired', __( 'The code has expired. Request a new one.', 'guam-restaurants-core' ), array( 'status' => 410 ) );
		}
		$code = preg_replace( '/\D/', '', $code );
		if ( ! hash_equals( (string) $claim['code_hash'], self::hash( $claim_id, (string) $code ) ) ) {
			$attempts = (int) $claim['attempts'] + 1;
			$wpdb->update( Schema::table( Schema::CLAIMS ), array( 'attempts' => $attempts ), array( 'id' => $claim_id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $attempts >= self::MAX_ATTEMPTS ) {
				self::set_status( $claim_id, self::CANCELLED, 'too many wrong codes', 0 );
				return new WP_Error( 'gr_claim_locked', __( 'Too many wrong codes. Request a new one.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
			}
			return new WP_Error( 'gr_claim_code', __( 'That code is not right. Check it and try again.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( Schema::CLAIMS ),
			array(
				'status'      => self::VERIFIED,
				'code_hash'   => '',
				'verified_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $claim_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		self::notify_admins( $claim_id );
		return true;
	}

	/**
	 * Approves a verified claim and links the account.
	 *
	 * @param int $claim_id Claim.
	 * @param int $admin_id Approver.
	 * @return true|WP_Error
	 */
	public static function approve( int $claim_id, int $admin_id ) {
		if ( ! user_can( $admin_id, self::CAP ) ) {
			return new WP_Error( 'gr_claim_forbidden', __( 'You are not allowed to approve claims.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		$claim = self::get( $claim_id );
		if ( null === $claim ) {
			return new WP_Error( 'gr_claim_missing', __( 'Claim not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		if ( self::VERIFIED !== $claim['status'] ) {
			return new WP_Error( 'gr_claim_state', __( 'Only a claim whose code was confirmed can be approved.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}
		$user_id       = (int) $claim['user_id'];
		$restaurant_id = (int) $claim['restaurant_id'];
		if ( $user_id === $admin_id ) {
			return new WP_Error( 'gr_claim_self', __( 'Another administrator must approve your own claim.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		$owner = (int) get_post_meta( $restaurant_id, Restaurant_Meta::CLAIMED_BY_USER_ID, true );
		if ( $owner > 0 && $owner !== $user_id ) {
			return new WP_Error( 'gr_claim_taken', __( 'The listing is already linked to another account. Remove that link on the user\'s profile first.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'gr_claim_user', __( 'The claimant\'s account no longer exists.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}

		update_post_meta( $restaurant_id, Restaurant_Meta::CLAIMED_BY_USER_ID, $user_id );
		if ( ! in_array( Roles::OWNER_ROLE, (array) $user->roles, true ) ) {
			$user->add_role( Roles::OWNER_ROLE );
		}
		self::set_status( $claim_id, self::APPROVED, '', $admin_id );
		foreach ( self::for_restaurant( $restaurant_id, self::OPEN_STATUSES ) as $other ) {
			self::set_status( (int) $other['id'], self::CANCELLED, 'listing claimed by another account', $admin_id );
		}
		clean_post_cache( $restaurant_id );
		self::notify_claimant( $claim_id, true );
		return true;
	}

	/**
	 * Rejects an open claim.
	 *
	 * @param int    $claim_id Claim.
	 * @param int    $admin_id Reviewer.
	 * @param string $reason   Shown to the claimant.
	 * @return true|WP_Error
	 */
	public static function reject( int $claim_id, int $admin_id, string $reason ) {
		if ( ! user_can( $admin_id, self::CAP ) ) {
			return new WP_Error( 'gr_claim_forbidden', __( 'You are not allowed to reject claims.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		$claim = self::get( $claim_id );
		if ( null === $claim || ! in_array( $claim['status'], self::OPEN_STATUSES, true ) ) {
			return new WP_Error( 'gr_claim_state', __( 'Only an open claim can be rejected.', 'guam-restaurants-core' ), array( 'status' => 409 ) );
		}
		self::set_status( $claim_id, self::REJECTED, mb_substr( sanitize_text_field( $reason ), 0, 255 ), $admin_id );
		self::notify_claimant( $claim_id, false );
		return true;
	}

	/**
	 * One claim.
	 *
	 * @param int $claim_id Claim.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $claim_id ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::CLAIMS );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $claim_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A user's claims, newest first.
	 *
	 * @param int      $user_id  User.
	 * @param string[] $statuses Statuses; empty for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_user( int $user_id, array $statuses = array() ): array {
		return self::where( 'user_id', $user_id, $statuses );
	}

	/**
	 * A restaurant's claims, newest first.
	 *
	 * @param int      $restaurant_id Restaurant.
	 * @param string[] $statuses      Statuses; empty for all.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_restaurant( int $restaurant_id, array $statuses = array() ): array {
		return self::where( 'restaurant_id', $restaurant_id, $statuses );
	}

	/**
	 * Claims with a status, oldest first (the review queue).
	 *
	 * @param string $status Status.
	 * @param int    $limit  Rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function with_status( string $status, int $limit = 100 ): array {
		global $wpdb;
		$table = Schema::table( Schema::CLAIMS );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC LIMIT %d', $table, $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Claims by a column.
	 *
	 * @param string   $column   user_id or restaurant_id.
	 * @param int      $value    Value.
	 * @param string[] $statuses Statuses; empty for all.
	 * @return array<int, array<string, mixed>>
	 */
	private static function where( string $column, int $value, array $statuses ): array {
		global $wpdb;
		$table  = Schema::table( Schema::CLAIMS );
		$column = 'user_id' === $column ? 'user_id' : 'restaurant_id';
		$sql    = $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %d', $table, $column, $value );
		if ( $statuses ) {
			$sql .= $wpdb->prepare( ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', $statuses ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sets a claim's status.
	 *
	 * @param int    $claim_id Claim.
	 * @param string $status   Status.
	 * @param string $reason   Reason.
	 * @param int    $admin_id Who decided; 0 for the system.
	 * @return void
	 */
	private static function set_status( int $claim_id, string $status, string $reason, int $admin_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( Schema::CLAIMS ),
			array(
				'status'     => $status,
				'reason'     => $reason,
				'code_hash'  => '',
				'decided_at' => current_time( 'mysql', true ),
				'decided_by' => $admin_id > 0 ? $admin_id : null,
			),
			array( 'id' => $claim_id ),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * A six-digit code.
	 *
	 * @return string
	 */
	private static function new_code(): string {
		/**
		 * Filters the generated code. For tests only.
		 *
		 * @param string $code Six digits.
		 */
		return (string) apply_filters( 'guam_restaurants_claim_code', sprintf( '%06d', random_int( 0, 999999 ) ) );
	}

	/**
	 * Keyed hash of a claim's code.
	 *
	 * @param int    $claim_id Claim.
	 * @param string $code     Code.
	 * @return string
	 */
	private static function hash( int $claim_id, string $code ): string {
		return hash_hmac( 'sha256', $claim_id . '|' . $code, wp_salt( 'nonce' ) );
	}

	/**
	 * Counts a code send against the user's hourly limit.
	 *
	 * @param int $user_id User.
	 * @return bool False when over the limit.
	 */
	private static function take_send_slot( int $user_id ): bool {
		$key  = self::RATE_PREFIX . $user_id;
		$sent = (int) get_transient( $key );
		if ( $sent >= self::SENDS_PER_HOUR ) {
			return false;
		}
		set_transient( $key, $sent + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Emails a code.
	 *
	 * @param string $email         Address.
	 * @param int    $restaurant_id Restaurant.
	 * @param string $code          Code.
	 * @return bool
	 */
	private static function send_email_code( string $email, int $restaurant_id, string $code ): bool {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: restaurant name. */
		$subject = sprintf( __( '[%1$s] Your code to claim %2$s', 'guam-restaurants-core' ), $site, get_the_title( $restaurant_id ) );
		$message = implode(
			"\n",
			array(
				/* translators: 1: code, 2: restaurant name. */
				sprintf( __( 'Your code is %1$s. Enter it on the claim page to confirm you manage %2$s.', 'guam-restaurants-core' ), $code, get_the_title( $restaurant_id ) ),
				__( 'The code works for 30 minutes. If you did not ask to claim this listing, ignore this email.', 'guam-restaurants-core' ),
				'',
				$site,
			)
		);
		return (bool) wp_mail( $email, $subject, $message );
	}

	/**
	 * Texts a code through whatever sender is hooked in. Without one,
	 * nothing is sent and the request fails.
	 *
	 * @param string $phone         Listed phone (E.164).
	 * @param int    $restaurant_id Restaurant.
	 * @param string $code          Code.
	 * @return bool
	 */
	private static function send_sms_code( string $phone, int $restaurant_id, string $code ): bool {
		/* translators: 1: code, 2: site name. */
		$message = sprintf( __( '%1$s is your code to claim this restaurant on %2$s. It works for 30 minutes.', 'guam-restaurants-core' ), $code, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		/**
		 * Sends a claim code by text message. Return true once sent.
		 *
		 * @param bool   $sent          Whether a sender has sent it.
		 * @param string $phone         Phone number on the listing (E.164).
		 * @param string $message       Text to send.
		 * @param int    $restaurant_id Restaurant.
		 */
		return true === apply_filters( 'guam_restaurants_send_claim_sms', false, $phone, $message, $restaurant_id );
	}

	/**
	 * Tells administrators a claim is waiting.
	 *
	 * @param int $claim_id Claim.
	 * @return void
	 */
	private static function notify_admins( int $claim_id ): void {
		$claim = self::get( $claim_id );
		if ( null === $claim ) {
			return;
		}
		$to = Config::get( Config::ALERT_EMAIL, (string) get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: restaurant name. */
		$subject = sprintf( __( '[%1$s] Listing claim to review: %2$s', 'guam-restaurants-core' ), $site, get_the_title( (int) $claim['restaurant_id'] ) );
		$message = implode(
			"\n",
			array(
				__( 'A listing claim was confirmed and is waiting for approval.', 'guam-restaurants-core' ),
				/* translators: %s: method. */
				sprintf( __( 'Confirmed by: %s', 'guam-restaurants-core' ), self::METHOD_EMAIL === $claim['method'] ? __( 'code sent to an email at the restaurant\'s website domain', 'guam-restaurants-core' ) : __( 'code texted to the listed phone', 'guam-restaurants-core' ) ),
				'',
				admin_url( 'edit.php?post_type=' . Content_Types::POST_TYPE . '&page=' . Admin::PAGE ),
			)
		);
		wp_mail( $to, $subject, $message );
	}

	/**
	 * Tells the claimant the decision.
	 *
	 * @param int  $claim_id Claim.
	 * @param bool $approved Approved or rejected.
	 * @return void
	 */
	private static function notify_claimant( int $claim_id, bool $approved ): void {
		$claim = self::get( $claim_id );
		$user  = null === $claim ? false : get_userdata( (int) $claim['user_id'] );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name  = get_the_title( (int) $claim['restaurant_id'] );
		$lines = $approved
			? array(
				/* translators: %s: restaurant name. */
				sprintf( __( 'Your claim on %s was approved.', 'guam-restaurants-core' ), $name ),
				__( 'Log in to update hours, menu links, photos and specials, see how your listing is doing, and reply to reviews:', 'guam-restaurants-core' ),
				admin_url( 'admin.php?page=' . \GuamRestaurants\Core\Owners\Dashboard::PAGE ),
			)
			: array(
				/* translators: %s: restaurant name. */
				sprintf( __( 'Your claim on %s was not approved.', 'guam-restaurants-core' ), $name ),
				'' === (string) $claim['reason'] ? '' : sprintf( /* translators: %s: reason. */ __( 'Reason: %s', 'guam-restaurants-core' ), $claim['reason'] ),
				__( 'If you manage this restaurant, reply to this email and we will look into it.', 'guam-restaurants-core' ),
			);
		/* translators: 1: site name, 2: restaurant name. */
		$subject = sprintf( __( '[%1$s] Your claim on %2$s', 'guam-restaurants-core' ), $site, $name );
		wp_mail( $user->user_email, $subject, implode( "\n", array_merge( $lines, array( '', $site ) ) ) );
	}
}
