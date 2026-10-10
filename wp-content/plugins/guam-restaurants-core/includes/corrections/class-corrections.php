<?php
/**
 * Corrections and owner listing requests from the public form.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Corrections;

use GuamRestaurants\Core\Advertising\Leads;
use GuamRestaurants\Core\Admin\Review_Queue_Screen;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Highlights\Email_Verification;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The "Suggest a correction" form sends three kinds of request:
 *
 * - `correction`: anyone reports a wrong fact (hours, phone, closed...).
 *   Queued as a `correction` item.
 * - `listing_change`: the owner or a manager asks to change how the
 *   restaurant is listed. Queued as a `listing_request` item.
 * - `removal`: the owner or a manager asks for the listing to be taken
 *   down. Queued as a `listing_request` item.
 *
 * Nothing is published or changed automatically. An administrator checks
 * each request against a source (docs/editorial-policy.md, Corrections) and
 * edits the listing by hand, so the change log records it as usual. A
 * removal unpublishes the listing; no notice, label or reason is ever shown
 * on the site, so a request can never put a negative mark on a restaurant.
 *
 * We publish our response times: 7 days for corrections and 14 days for
 * removal and listing-change requests. Each queue item carries its reply-by
 * date.
 *
 * Spam handling follows the advertiser inquiry form: a nonce (checked by
 * the REST controller), a honeypot field, a minimum time on the form, and an
 * hourly limit per visitor and per email address, keyed by HMAC hashes kept
 * only in short-lived transients.
 */
final class Corrections {

	public const NONCE_ACTION = 'gr_correction';

	public const CORRECTION     = 'correction';
	public const LISTING_CHANGE = 'listing_change';
	public const REMOVAL        = 'removal';

	public const QUEUE_CORRECTION = 'correction';
	public const QUEUE_LISTING    = 'listing_request';

	/**
	 * Published response times, in days.
	 */
	public const RESPONSE_DAYS_CORRECTION = 7;
	public const RESPONSE_DAYS_LISTING    = 14;

	public const MIN_DETAILS  = 10;
	public const MAX_DETAILS  = 2000;
	public const MAX_EVIDENCE = 500;
	public const MAX_NAME     = 100;
	public const MAX_NOTE     = 2000;

	/**
	 * Fastest plausible time to fill in the form, in milliseconds (bot trap).
	 */
	public const MIN_FILL_MS = 3000;

	/**
	 * Requests one visitor (or one email address) may send per hour.
	 */
	public const PER_HOUR = 5;

	/**
	 * Request kinds.
	 *
	 * @return array<string, string>
	 */
	public static function requests(): array {
		return array(
			self::CORRECTION     => __( 'Report a wrong or missing fact', 'guam-restaurants-core' ),
			self::LISTING_CHANGE => __( 'Owner or manager: change how this restaurant is listed', 'guam-restaurants-core' ),
			self::REMOVAL        => __( 'Owner or manager: remove this listing', 'guam-restaurants-core' ),
		);
	}

	/**
	 * What a correction or listing change is about.
	 *
	 * @return array<string, string>
	 */
	public static function topics(): array {
		return array(
			'hours'   => __( 'Opening hours', 'guam-restaurants-core' ),
			'phone'   => __( 'Phone number', 'guam-restaurants-core' ),
			'address' => __( 'Address or map location', 'guam-restaurants-core' ),
			'website' => __( 'Website or menu link', 'guam-restaurants-core' ),
			'name'    => __( 'Restaurant name', 'guam-restaurants-core' ),
			'cuisine' => __( 'Cuisine, village or mall', 'guam-restaurants-core' ),
			'closed'  => __( 'Closed, reopened or moved', 'guam-restaurants-core' ),
			'other'   => __( 'Something else', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Why an owner asks for removal. These are the cases our editorial
	 * policy can act on; a truthful listing of a restaurant open to the
	 * public stays listed (closed restaurants are marked closed instead).
	 *
	 * @return array<string, string>
	 */
	public static function removal_reasons(): array {
		return array(
			'personal_info' => __( 'It shows personal information, such as a home address or personal phone', 'guam-restaurants-core' ),
			'not_public'    => __( 'It is not open to the public (for example, private catering only)', 'guam-restaurants-core' ),
			'duplicate'     => __( 'It is a duplicate of another listing', 'guam-restaurants-core' ),
			'not_a_place'   => __( 'This place never existed or is not a restaurant', 'guam-restaurants-core' ),
			'other'         => __( 'Another reason', 'guam-restaurants-core' ),
		);
	}

	/**
	 * How the sender is connected to the restaurant.
	 *
	 * @return array<string, string>
	 */
	public static function relationships(): array {
		return array(
			'customer' => __( 'Customer or visitor', 'guam-restaurants-core' ),
			'owner'    => __( 'Owner', 'guam-restaurants-core' ),
			'manager'  => __( 'Manager or staff', 'guam-restaurants-core' ),
			'other'    => __( 'Other', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Days we promise to reply within, for a request kind.
	 *
	 * @param string $request Request kind.
	 * @return int
	 */
	public static function response_days( string $request ): int {
		return self::CORRECTION === $request ? self::RESPONSE_DAYS_CORRECTION : self::RESPONSE_DAYS_LISTING;
	}

	/**
	 * Review queue type for a request kind.
	 *
	 * @param string $request Request kind.
	 * @return string
	 */
	public static function queue_type( string $request ): string {
		return self::CORRECTION === $request ? self::QUEUE_CORRECTION : self::QUEUE_LISTING;
	}

	/**
	 * Checks and cleans the fields.
	 *
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type int    $restaurant_id Restaurant (published).
	 *     @type string $request       Key from requests().
	 *     @type string $topic         Key from topics(), or from removal_reasons() for a removal.
	 *     @type string $details       What is wrong or what should change.
	 *     @type string $evidence      Where we can confirm it, optional.
	 *     @type string $name          Name; required for owner requests.
	 *     @type string $email         Email address for our reply.
	 *     @type string $relationship  Key from relationships().
	 * }
	 * @return array{restaurant_id: int, request: string, topic: string, details: string, evidence: string, name: string, email: string, relationship: string}|WP_Error
	 */
	public static function validate( array $input ) {
		$fields = array(
			'restaurant_id' => absint( $input['restaurant_id'] ?? 0 ),
			'request'       => sanitize_key( (string) ( $input['request'] ?? '' ) ),
			'topic'         => sanitize_key( (string) ( $input['topic'] ?? '' ) ),
			'details'       => trim( sanitize_textarea_field( (string) ( $input['details'] ?? '' ) ) ),
			'evidence'      => trim( sanitize_textarea_field( (string) ( $input['evidence'] ?? '' ) ) ),
			'name'          => trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) ),
			'email'         => trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) ),
			'relationship'  => sanitize_key( (string) ( $input['relationship'] ?? '' ) ),
		);

		$errors   = new WP_Error();
		$is_owner = in_array( $fields['request'], array( self::LISTING_CHANGE, self::REMOVAL ), true );

		if ( ! Leads::is_published_restaurant( $fields['restaurant_id'] ) ) {
			$errors->add( 'gr_correction_restaurant', __( 'Choose the restaurant from the list.', 'guam-restaurants-core' ) );
		}
		if ( ! isset( self::requests()[ $fields['request'] ] ) ) {
			$errors->add( 'gr_correction_request', __( 'Choose what you would like to do.', 'guam-restaurants-core' ) );
		} else {
			$choices = self::REMOVAL === $fields['request'] ? self::removal_reasons() : self::topics();
			if ( ! isset( $choices[ $fields['topic'] ] ) ) {
				$errors->add(
					'gr_correction_topic',
					self::REMOVAL === $fields['request']
						? __( 'Choose the reason for removal.', 'guam-restaurants-core' )
						: __( 'Choose what needs changing.', 'guam-restaurants-core' )
				);
			}
		}
		$length = mb_strlen( $fields['details'] );
		if ( $length < self::MIN_DETAILS || $length > self::MAX_DETAILS ) {
			/* translators: 1: minimum characters, 2: maximum characters. */
			$errors->add( 'gr_correction_details', sprintf( __( 'Describe the change in %1$d to %2$d characters.', 'guam-restaurants-core' ), self::MIN_DETAILS, self::MAX_DETAILS ) );
		}
		if ( mb_strlen( $fields['evidence'] ) > self::MAX_EVIDENCE ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_correction_evidence', sprintf( __( 'Keep the source under %d characters.', 'guam-restaurants-core' ), self::MAX_EVIDENCE ) );
		}
		if ( mb_strlen( $fields['name'] ) > self::MAX_NAME || ( $is_owner && '' === $fields['name'] ) ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_correction_name', sprintf( __( 'Enter your name (up to %d characters).', 'guam-restaurants-core' ), self::MAX_NAME ) );
		}
		if ( ! is_email( $fields['email'] ) ) {
			$errors->add( 'gr_correction_email', __( 'Enter a valid email address so we can reply.', 'guam-restaurants-core' ) );
		}
		if ( ! isset( self::relationships()[ $fields['relationship'] ] ) ) {
			$errors->add( 'gr_correction_relationship', __( 'Tell us how you are connected to the restaurant.', 'guam-restaurants-core' ) );
		} elseif ( $is_owner && ! in_array( $fields['relationship'], array( 'owner', 'manager' ), true ) ) {
			$errors->add( 'gr_correction_relationship', __( 'Only the owner or a manager can ask to change or remove a listing. To report a wrong fact, choose "Report a wrong or missing fact".', 'guam-restaurants-core' ) );
		}

		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}
		return $fields;
	}

	/**
	 * Checks a form submission, adds it to the review queue and sends both
	 * emails. The nonce is checked by the caller (Rest_Controller).
	 *
	 * @param array<string, mixed> $input      Fields for validate(), plus `website` (honeypot, must be empty) and `elapsed_ms`.
	 * @param string               $client_key Hashed visitor key for rate limiting.
	 * @return int|WP_Error Review queue item ID.
	 */
	public static function submit( array $input, string $client_key ) {
		// Bots fill hidden fields and submit instantly; answer them like a normal error.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) || (int) ( $input['elapsed_ms'] ?? 0 ) < self::MIN_FILL_MS ) {
			return new WP_Error( 'gr_correction_rejected', __( 'Your request could not be sent. Please try again.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}

		$fields = self::validate( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$keys = array_filter( array( $client_key, Email_Verification::email_key( $fields['email'] ) ) );
		foreach ( $keys as $key ) {
			if ( (int) get_transient( self::rate_key( $key ) ) >= self::PER_HOUR ) {
				return new WP_Error( 'gr_correction_rate', __( 'You have sent several requests recently. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
			}
		}
		foreach ( $keys as $key ) {
			set_transient( self::rate_key( $key ), (int) get_transient( self::rate_key( $key ) ) + 1, HOUR_IN_SECONDS );
		}

		$item_id = self::enqueue( $fields );
		if ( ! $item_id ) {
			return new WP_Error( 'gr_correction_failed', __( 'Your request could not be saved. Please try again.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
		}

		// The request is saved either way; a failed email must not lose it.
		self::notify_owner( $item_id );
		self::confirm_sender( $fields['email'], $fields['request'], $fields['restaurant_id'] );

		return $item_id;
	}

	/**
	 * Adds a validated request to the review queue.
	 *
	 * @param array<string, mixed> $fields Output of validate().
	 * @param int|null             $now    Unix time, for tests.
	 * @return int Item ID, or 0.
	 */
	public static function enqueue( array $fields, ?int $now = null ): int {
		$now     = $now ?? time();
		$request = (string) $fields['request'];
		$payload = array(
			'request'      => $request,
			'topic'        => (string) $fields['topic'],
			'details'      => (string) $fields['details'],
			'evidence'     => (string) $fields['evidence'],
			'name'         => (string) $fields['name'],
			'email'        => (string) $fields['email'],
			'relationship' => (string) $fields['relationship'],
			'respond_by'   => gmdate( 'Y-m-d', $now + self::response_days( $request ) * DAY_IN_SECONDS ),
		);
		return Review_Queue::enqueue( self::queue_type( $request ), $payload, 'visitor', (int) $fields['restaurant_id'] );
	}

	/**
	 * Whether a queue item is one of ours.
	 *
	 * @param array<string, mixed> $item Queue item.
	 * @return bool
	 */
	public static function is_request( array $item ): bool {
		return in_array( (string) ( $item['type'] ?? '' ), array( self::QUEUE_CORRECTION, self::QUEUE_LISTING ), true );
	}

	/**
	 * Closes a request with an optional private note. Nothing is published:
	 * the administrator edits or unpublishes the listing separately.
	 *
	 * @param int    $item_id  Item.
	 * @param bool   $accepted True when the change was made, false when declined.
	 * @param string $note     Private note (what was checked, or why it was declined).
	 * @param int    $user_id  Administrator.
	 * @return bool
	 */
	public static function close( int $item_id, bool $accepted, string $note, int $user_id ): bool {
		$item = Review_Queue::get( $item_id );
		if ( ! $item || 'pending' !== $item['status'] || ! self::is_request( $item ) ) {
			return false;
		}
		$note = mb_substr( trim( sanitize_textarea_field( $note ) ), 0, self::MAX_NOTE );
		if ( '' !== $note ) {
			Review_Queue::annotate( $item_id, array( 'decision_note' => $note ) );
		}
		return Review_Queue::resolve( $item_id, $accepted ? 'approved' : 'rejected', $user_id );
	}

	/**
	 * Where new-request emails go: the site admin email.
	 *
	 * @return string
	 */
	public static function owner_email(): string {
		/**
		 * Filters the address that receives new corrections and listing requests.
		 *
		 * @param string $email Site admin email.
		 */
		return (string) apply_filters( 'guam_restaurants_correction_notify_email', (string) get_option( 'admin_email' ) );
	}

	/**
	 * Emails the site owner about a new request. Reply-To is the sender.
	 *
	 * @param int $item_id Queue item.
	 * @return bool
	 */
	public static function notify_owner( int $item_id ): bool {
		$item = Review_Queue::get( $item_id );
		$to   = self::owner_email();
		if ( ! $item || ! self::is_request( $item ) || ! is_email( $to ) ) {
			return false;
		}
		$payload    = (array) $item['payload'];
		$request    = (string) ( $payload['request'] ?? '' );
		$restaurant = (int) $item['restaurant_id'];
		$choices    = self::REMOVAL === $request ? self::removal_reasons() : self::topics();

		$lines = array(
			__( 'Request', 'guam-restaurants-core' )    => self::requests()[ $request ] ?? $request,
			__( 'Restaurant', 'guam-restaurants-core' ) => sprintf( '%1$s (%2$s)', html_entity_decode( get_the_title( $restaurant ), ENT_QUOTES ), get_permalink( $restaurant ) ),
			__( 'About', 'guam-restaurants-core' )      => $choices[ (string) ( $payload['topic'] ?? '' ) ] ?? (string) ( $payload['topic'] ?? '' ),
			__( 'From', 'guam-restaurants-core' )       => trim( (string) ( $payload['name'] ?? '' ) . ' <' . (string) ( $payload['email'] ?? '' ) . '>' ),
			__( 'Connection', 'guam-restaurants-core' ) => self::relationships()[ (string) ( $payload['relationship'] ?? '' ) ] ?? '',
			__( 'Reply by', 'guam-restaurants-core' )   => (string) ( $payload['respond_by'] ?? '' ),
			__( 'Where to check', 'guam-restaurants-core' ) => '' !== (string) ( $payload['evidence'] ?? '' ) ? (string) $payload['evidence'] : '-',
		);
		$body  = '';
		foreach ( $lines as $label => $value ) {
			$body .= $label . ': ' . $value . "\n";
		}
		$body .= "\n" . __( 'Details:', 'guam-restaurants-core' ) . "\n" . (string) ( $payload['details'] ?? '' ) . "\n\n";
		/* translators: %s: link to the review queue. */
		$body .= sprintf( __( 'Open the review queue: %s', 'guam-restaurants-core' ), Review_Queue_Screen::url( (string) $item['type'] ) ) . "\n";

		return wp_mail(
			$to,
			/* translators: 1: request kind, 2: restaurant name. */
			sprintf( __( '%1$s: %2$s', 'guam-restaurants-core' ), self::CORRECTION === $request ? __( 'New correction', 'guam-restaurants-core' ) : __( 'New listing request', 'guam-restaurants-core' ), html_entity_decode( get_the_title( $restaurant ), ENT_QUOTES ) ),
			$body,
			array( 'Reply-To: ' . (string) ( $payload['email'] ?? '' ) )
		);
	}

	/**
	 * Sends the sender an automatic confirmation with our response time. It
	 * quotes nothing they typed, so the form cannot be used to send arbitrary
	 * mail; the restaurant name comes from our own listing.
	 *
	 * @param string $email         Sender's address.
	 * @param string $request       Request kind.
	 * @param int    $restaurant_id Restaurant.
	 * @return bool
	 */
	public static function confirm_sender( string $email, string $request, int $restaurant_id ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$days = self::response_days( $request );
		return wp_mail(
			$email,
			/* translators: %s: site name. */
			sprintf( __( 'We received your request - %s', 'guam-restaurants-core' ), $site ),
			sprintf(
				/* translators: 1: site name, 2: restaurant name, 3: number of days, 4: editorial policy URL. */
				__( "Thank you for writing to %1\$s about the listing for %2\$s. We have received your request and will reply by email within %3\$d days.\n\nWe check every change against a source, such as the restaurant's own website or signage, before we make it. How we handle corrections and listing requests is explained in our editorial policy:\n%4\$s\n\nThis is an automatic confirmation. If you did not send this request, you can ignore this email.", 'guam-restaurants-core' ),
				$site,
				html_entity_decode( get_the_title( $restaurant_id ), ENT_QUOTES ),
				$days,
				Policy_Page::url()
			)
		);
	}

	/**
	 * Hashed rate-limit key for the current visitor (shared IP handling with
	 * visitor reviews; the IP is never stored).
	 *
	 * @return string
	 */
	public static function client_key(): string {
		return Visitor_Reviews::client_key();
	}

	/**
	 * Transient name for a rate-limit key.
	 *
	 * @param string $key Hashed key.
	 * @return string
	 */
	private static function rate_key( string $key ): string {
		return 'gr_corr_rl_' . substr( $key, 0, 32 );
	}
}
