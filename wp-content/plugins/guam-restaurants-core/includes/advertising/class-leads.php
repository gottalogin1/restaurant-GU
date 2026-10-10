<?php
/**
 * Advertiser inquiries, stored as private `advertiser_lead` posts.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Advertising;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Email_Verification;
use GuamRestaurants\Core\Highlights\Policy_Page;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * An inquiry from the "Advertise with us" form becomes one `advertiser_lead`
 * post with post status `private`. The post type has no front end, no REST
 * route and no search: only users with the `gr_manage_advertiser_leads`
 * capability (administrators) can see or change a lead.
 *
 * The contact details live in post meta. The pipeline status (new,
 * contacted, proposal sent, won, lost), private notes and the assigned
 * user are meta too, so they never collide with WordPress's own post
 * statuses.
 *
 * Spam handling follows the visitor reviews form: a honeypot field, a
 * minimum time on the form, a nonce (checked by the REST controller) and an
 * hourly limit per visitor and per email address, keyed by HMAC hashes kept
 * only in short-lived transients.
 *
 * Every new lead emails the site owner, and the sender gets a confirmation
 * that promises no price or placement and repeats none of the submitted
 * text, so the form cannot be used to send arbitrary mail.
 */
final class Leads {

	public const POST_TYPE    = 'advertiser_lead';
	public const CAP          = 'gr_manage_advertiser_leads';
	public const NONCE_ACTION = 'gr_advertiser_lead';

	public const META_NAME       = 'gr_lead_name';
	public const META_BUSINESS   = 'gr_lead_business';
	public const META_RESTAURANT = 'gr_lead_restaurant_id';
	public const META_EMAIL      = 'gr_lead_email';
	public const META_PHONE      = 'gr_lead_phone';
	public const META_PACKAGE    = 'gr_lead_package';
	public const META_BUDGET     = 'gr_lead_budget';
	public const META_MESSAGE    = 'gr_lead_message';
	public const META_STATUS     = 'gr_lead_status';
	public const META_NOTES      = 'gr_lead_notes';
	public const META_ASSIGNED   = 'gr_lead_assigned_user';

	public const MAX_NAME     = 100;
	public const MAX_BUSINESS = 150;
	public const MAX_PHONE    = 30;
	public const MAX_MESSAGE  = 3000;
	public const MAX_NOTES    = 10000;

	/**
	 * Fastest plausible time to fill in the form, in milliseconds (bot trap).
	 */
	public const MIN_FILL_MS = 3000;

	/**
	 * Inquiries one visitor (or one email address) may send per hour.
	 */
	public const PER_HOUR = 3;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'force_private' ), 10, 2 );
		add_filter( 'private_title_format', array( self::class, 'private_title_format' ), 10, 2 );
	}

	/**
	 * Every lead is private, so its title needs no "Private:" prefix.
	 *
	 * @param string   $format Title format.
	 * @param \WP_Post $post   Post.
	 * @return string
	 */
	public static function private_title_format( $format, $post ) {
		return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ? '%s' : $format;
	}

	/**
	 * Registers the post type. Every capability maps to CAP.
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Advertiser leads', 'guam-restaurants-core' ),
					'singular_name'      => __( 'Advertiser lead', 'guam-restaurants-core' ),
					'menu_name'          => __( 'Advertiser leads', 'guam-restaurants-core' ),
					'add_new_item'       => __( 'Add advertiser lead', 'guam-restaurants-core' ),
					'edit_item'          => __( 'Edit advertiser lead', 'guam-restaurants-core' ),
					'search_items'       => __( 'Search leads', 'guam-restaurants-core' ),
					'not_found'          => __( 'No leads found.', 'guam-restaurants-core' ),
					'not_found_in_trash' => __( 'No leads found in the trash.', 'guam-restaurants-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'menu_icon'           => 'dashicons-megaphone',
				'menu_position'       => 26,
				'supports'            => false,
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'edit_post'              => 'edit_' . self::POST_TYPE,
					'read_post'              => 'read_' . self::POST_TYPE,
					'delete_post'            => 'delete_' . self::POST_TYPE,
					'edit_posts'             => self::CAP,
					'edit_others_posts'      => self::CAP,
					'edit_private_posts'     => self::CAP,
					'edit_published_posts'   => self::CAP,
					'publish_posts'          => self::CAP,
					'read_private_posts'     => self::CAP,
					'read'                   => self::CAP,
					'delete_posts'           => self::CAP,
					'delete_others_posts'    => self::CAP,
					'delete_private_posts'   => self::CAP,
					'delete_published_posts' => self::CAP,
					'create_posts'           => self::CAP,
				),
			)
		);
	}

	/**
	 * Keeps every lead private, whatever the editor's Publish button says.
	 *
	 * @param array<string, mixed> $data    Post data about to be saved.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public static function force_private( $data, $postarr ) {
		unset( $postarr );
		if ( self::POST_TYPE === ( $data['post_type'] ?? '' ) && in_array( $data['post_status'] ?? '', array( 'publish', 'future', 'pending' ), true ) ) {
			$data['post_status'] = 'private';
		}
		return $data;
	}

	/**
	 * Pipeline statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'new'           => __( 'New', 'guam-restaurants-core' ),
			'contacted'     => __( 'Contacted', 'guam-restaurants-core' ),
			'proposal_sent' => __( 'Proposal sent', 'guam-restaurants-core' ),
			'won'           => __( 'Won', 'guam-restaurants-core' ),
			'lost'          => __( 'Lost', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Packages a visitor can ask about, from the rate card (docs/monetization.md).
	 *
	 * "Featured listing" is the rate card's name only; visitors see
	 * "Sponsored". The homepage leaderboard is left out until the traffic
	 * threshold is met, and affiliate links are not sold.
	 *
	 * @return array<string, string>
	 */
	public static function packages(): array {
		return array(
			'premium_profile'   => __( 'Premium profile', 'guam-restaurants-core' ),
			'featured_listing'  => __( 'Sponsored listing on a village, mall or cuisine page', 'guam-restaurants-core' ),
			'category_sponsor'  => __( 'Page sponsorship (village, mall or cuisine)', 'guam-restaurants-core' ),
			'banner'            => __( 'Banner ads', 'guam-restaurants-core' ),
			'deal_post'         => __( 'Deal or event post', 'guam-restaurants-core' ),
			'newsletter'        => __( 'Newsletter sponsorship', 'guam-restaurants-core' ),
			'sponsored_feature' => __( 'Sponsored article', 'guam-restaurants-core' ),
			'not_sure'          => __( 'Not sure yet', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Monthly budget ranges.
	 *
	 * @return array<string, string>
	 */
	public static function budgets(): array {
		return array(
			'under_100' => __( 'Under $100 a month', 'guam-restaurants-core' ),
			'100_250'   => __( '$100 to $250 a month', 'guam-restaurants-core' ),
			'250_500'   => __( '$250 to $500 a month', 'guam-restaurants-core' ),
			'500_1000'  => __( '$500 to $1,000 a month', 'guam-restaurants-core' ),
			'over_1000' => __( 'Over $1,000 a month', 'guam-restaurants-core' ),
			'not_sure'  => __( 'Not sure yet', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Checks and cleans the contact fields.
	 *
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type string $name          Contact name.
	 *     @type string $business      Business name.
	 *     @type int    $restaurant_id Linked restaurant, or 0.
	 *     @type string $email         Email address.
	 *     @type string $phone         Phone number, optional.
	 *     @type string $package       Key from packages().
	 *     @type string $budget        Key from budgets().
	 *     @type string $message       Message, optional.
	 * }
	 * @return array{name: string, business: string, restaurant_id: int, email: string, phone: string, package: string, budget: string, message: string}|WP_Error
	 */
	public static function validate( array $input ) {
		$fields = array(
			'name'          => trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) ),
			'business'      => trim( sanitize_text_field( (string) ( $input['business'] ?? '' ) ) ),
			'restaurant_id' => absint( $input['restaurant_id'] ?? 0 ),
			'email'         => trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) ),
			'phone'         => trim( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) ),
			'package'       => sanitize_key( (string) ( $input['package'] ?? '' ) ),
			'budget'        => sanitize_key( (string) ( $input['budget'] ?? '' ) ),
			'message'       => trim( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ) ),
		);

		$errors = new WP_Error();
		if ( '' === $fields['name'] || mb_strlen( $fields['name'] ) > self::MAX_NAME ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_lead_name', sprintf( __( 'Enter your name (up to %d characters).', 'guam-restaurants-core' ), self::MAX_NAME ) );
		}
		if ( '' === $fields['business'] || mb_strlen( $fields['business'] ) > self::MAX_BUSINESS ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_lead_business', sprintf( __( 'Enter your business name (up to %d characters).', 'guam-restaurants-core' ), self::MAX_BUSINESS ) );
		}
		if ( $fields['restaurant_id'] > 0 && ! self::is_published_restaurant( $fields['restaurant_id'] ) ) {
			$errors->add( 'gr_lead_restaurant', __( 'Choose a restaurant from the list, or leave it empty.', 'guam-restaurants-core' ) );
		}
		if ( ! is_email( $fields['email'] ) ) {
			$errors->add( 'gr_lead_email', __( 'Enter a valid email address.', 'guam-restaurants-core' ) );
		}
		if ( '' !== $fields['phone'] && ! self::is_valid_phone( $fields['phone'] ) ) {
			$errors->add( 'gr_lead_phone', __( 'Enter a phone number using digits, spaces, +, - and brackets, or leave it empty.', 'guam-restaurants-core' ) );
		}
		if ( ! isset( self::packages()[ $fields['package'] ] ) ) {
			$errors->add( 'gr_lead_package', __( 'Choose the package you are interested in.', 'guam-restaurants-core' ) );
		}
		if ( ! isset( self::budgets()[ $fields['budget'] ] ) ) {
			$errors->add( 'gr_lead_budget', __( 'Choose a budget range.', 'guam-restaurants-core' ) );
		}
		if ( mb_strlen( $fields['message'] ) > self::MAX_MESSAGE ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_lead_message', sprintf( __( 'Keep your message under %d characters.', 'guam-restaurants-core' ), self::MAX_MESSAGE ) );
		}

		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}
		return $fields;
	}

	/**
	 * Checks a form submission, stores it as a new lead and sends both emails.
	 * The nonce is checked by the caller (Rest_Controller).
	 *
	 * @param array<string, mixed> $input      Fields for validate(), plus `website` (honeypot, must be empty) and `elapsed_ms`.
	 * @param string               $client_key Hashed visitor key for rate limiting.
	 * @return int|WP_Error Lead ID.
	 */
	public static function submit( array $input, string $client_key ) {
		// Bots fill hidden fields and submit instantly; answer them like a normal error.
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) || (int) ( $input['elapsed_ms'] ?? 0 ) < self::MIN_FILL_MS ) {
			return new WP_Error( 'gr_lead_rejected', __( 'Your message could not be sent. Please try again.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}

		$fields = self::validate( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$keys = array_filter( array( $client_key, Email_Verification::email_key( $fields['email'] ) ) );
		foreach ( $keys as $key ) {
			if ( (int) get_transient( self::rate_key( $key ) ) >= self::PER_HOUR ) {
				return new WP_Error( 'gr_lead_rate', __( 'You have sent several messages recently. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
			}
		}
		foreach ( $keys as $key ) {
			set_transient( self::rate_key( $key ), (int) get_transient( self::rate_key( $key ) ) + 1, HOUR_IN_SECONDS );
		}

		$id = self::create( $fields );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// The lead is saved either way; a failed email must not lose it.
		self::notify_owner( $id );
		self::confirm_sender( $fields['email'] );

		return $id;
	}

	/**
	 * Stores a validated lead with status "new".
	 *
	 * @param array<string, mixed> $fields Output of validate().
	 * @return int|WP_Error
	 */
	public static function create( array $fields ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => self::title( $fields ),
				'post_author' => 0,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::save_fields( (int) $id, $fields );
		update_post_meta( (int) $id, self::META_STATUS, 'new' );
		return (int) $id;
	}

	/**
	 * Writes the contact fields of a lead.
	 *
	 * @param int                  $lead_id Lead.
	 * @param array<string, mixed> $fields  Output of validate().
	 * @return void
	 */
	public static function save_fields( int $lead_id, array $fields ): void {
		$map = array(
			'name'          => self::META_NAME,
			'business'      => self::META_BUSINESS,
			'restaurant_id' => self::META_RESTAURANT,
			'email'         => self::META_EMAIL,
			'phone'         => self::META_PHONE,
			'package'       => self::META_PACKAGE,
			'budget'        => self::META_BUDGET,
			'message'       => self::META_MESSAGE,
		);
		foreach ( $map as $field => $meta ) {
			update_post_meta( $lead_id, $meta, $fields[ $field ] ?? '' );
		}
	}

	/**
	 * Post title for a lead: "Business (Name)".
	 *
	 * @param array<string, mixed> $fields Output of validate().
	 * @return string
	 */
	public static function title( array $fields ): string {
		/* translators: 1: business name, 2: contact name. */
		return sprintf( __( '%1$s (%2$s)', 'guam-restaurants-core' ), (string) ( $fields['business'] ?? '' ), (string) ( $fields['name'] ?? '' ) );
	}

	/**
	 * One lead, or null when the ID is not a lead.
	 *
	 * @param int $lead_id Lead.
	 * @return array{id: int, name: string, business: string, restaurant_id: int, email: string, phone: string, package: string, budget: string, message: string, status: string, notes: string, assigned_user: int, received: string}|null
	 */
	public static function get( int $lead_id ): ?array {
		$post = get_post( $lead_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$status = (string) get_post_meta( $lead_id, self::META_STATUS, true );
		return array(
			'id'            => $lead_id,
			'name'          => (string) get_post_meta( $lead_id, self::META_NAME, true ),
			'business'      => (string) get_post_meta( $lead_id, self::META_BUSINESS, true ),
			'restaurant_id' => (int) get_post_meta( $lead_id, self::META_RESTAURANT, true ),
			'email'         => (string) get_post_meta( $lead_id, self::META_EMAIL, true ),
			'phone'         => (string) get_post_meta( $lead_id, self::META_PHONE, true ),
			'package'       => (string) get_post_meta( $lead_id, self::META_PACKAGE, true ),
			'budget'        => (string) get_post_meta( $lead_id, self::META_BUDGET, true ),
			'message'       => (string) get_post_meta( $lead_id, self::META_MESSAGE, true ),
			'status'        => isset( self::statuses()[ $status ] ) ? $status : 'new',
			'notes'         => (string) get_post_meta( $lead_id, self::META_NOTES, true ),
			'assigned_user' => (int) get_post_meta( $lead_id, self::META_ASSIGNED, true ),
			'received'      => (string) $post->post_date,
		);
	}

	/**
	 * Updates the status, notes and assigned user of a lead.
	 *
	 * @param int                  $lead_id Lead.
	 * @param array<string, mixed> $input   `status`, `notes` and `assigned_user`; missing keys are left alone.
	 * @return true|WP_Error
	 */
	public static function update_pipeline( int $lead_id, array $input ) {
		if ( null === self::get( $lead_id ) ) {
			return new WP_Error( 'gr_lead_missing', __( 'Lead not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}

		$errors = new WP_Error();
		if ( array_key_exists( 'status', $input ) && ! isset( self::statuses()[ (string) $input['status'] ] ) ) {
			$errors->add( 'gr_lead_status', __( 'Unknown lead status.', 'guam-restaurants-core' ) );
		}
		$assignee = array_key_exists( 'assigned_user', $input ) ? absint( $input['assigned_user'] ) : null;
		if ( $assignee && ! user_can( $assignee, self::CAP ) ) {
			$errors->add( 'gr_lead_assignee', __( 'Leads can only be assigned to users who can manage them.', 'guam-restaurants-core' ) );
		}
		$notes = array_key_exists( 'notes', $input ) ? sanitize_textarea_field( (string) $input['notes'] ) : null;
		if ( null !== $notes && mb_strlen( $notes ) > self::MAX_NOTES ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_lead_notes', sprintf( __( 'Keep notes under %d characters.', 'guam-restaurants-core' ), self::MAX_NOTES ) );
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		if ( array_key_exists( 'status', $input ) ) {
			update_post_meta( $lead_id, self::META_STATUS, (string) $input['status'] );
		}
		if ( null !== $assignee ) {
			update_post_meta( $lead_id, self::META_ASSIGNED, $assignee );
		}
		if ( null !== $notes ) {
			update_post_meta( $lead_id, self::META_NOTES, $notes );
		}
		return true;
	}

	/**
	 * Users a lead may be assigned to.
	 *
	 * @return \WP_User[]
	 */
	public static function assignable_users(): array {
		return get_users(
			array(
				'capability' => self::CAP,
				'orderby'    => 'display_name',
				'fields'     => array( 'ID', 'display_name' ),
			)
		);
	}

	/**
	 * Where new-lead emails go: the site admin email.
	 *
	 * @return string
	 */
	public static function owner_email(): string {
		/**
		 * Filters the address that receives new advertiser leads.
		 *
		 * @param string $email Site admin email.
		 */
		return (string) apply_filters( 'guam_restaurants_lead_notify_email', (string) get_option( 'admin_email' ) );
	}

	/**
	 * Emails the owner about a new lead. Reply-To is the sender, so replying
	 * answers them directly.
	 *
	 * @param int $lead_id Lead.
	 * @return bool
	 */
	public static function notify_owner( int $lead_id ): bool {
		$lead = self::get( $lead_id );
		$to   = self::owner_email();
		if ( null === $lead || ! is_email( $to ) ) {
			return false;
		}

		$restaurant = $lead['restaurant_id'] > 0
			? sprintf( '%1$s (%2$s)', get_the_title( $lead['restaurant_id'] ), get_permalink( $lead['restaurant_id'] ) )
			: __( 'None chosen', 'guam-restaurants-core' );

		$lines = array(
			__( 'Name', 'guam-restaurants-core' )       => $lead['name'],
			__( 'Business', 'guam-restaurants-core' )   => $lead['business'],
			__( 'Restaurant', 'guam-restaurants-core' ) => $restaurant,
			__( 'Email', 'guam-restaurants-core' )      => $lead['email'],
			__( 'Phone', 'guam-restaurants-core' )      => '' !== $lead['phone'] ? $lead['phone'] : '-',
			__( 'Package', 'guam-restaurants-core' )    => self::packages()[ $lead['package'] ] ?? $lead['package'],
			__( 'Budget', 'guam-restaurants-core' )     => self::budgets()[ $lead['budget'] ] ?? $lead['budget'],
		);
		$body  = '';
		foreach ( $lines as $label => $value ) {
			$body .= $label . ': ' . $value . "\n";
		}
		$body .= "\n" . __( 'Message:', 'guam-restaurants-core' ) . "\n" . ( '' !== $lead['message'] ? $lead['message'] : '-' ) . "\n\n";
		/* translators: %s: link to the lead in wp-admin. */
		$body .= sprintf( __( 'Open the lead: %s', 'guam-restaurants-core' ), admin_url( 'post.php?post=' . $lead_id . '&action=edit' ) ) . "\n";

		return wp_mail(
			$to,
			/* translators: %s: business name. */
			sprintf( __( 'New advertiser inquiry: %s', 'guam-restaurants-core' ), $lead['business'] ),
			$body,
			array( 'Reply-To: ' . $lead['email'] )
		);
	}

	/**
	 * Sends the sender an automatic confirmation. It quotes nothing they
	 * typed and promises no price, placement or reply time.
	 *
	 * @param string $email Sender's address.
	 * @return bool
	 */
	public static function confirm_sender( string $email ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return wp_mail(
			$email,
			/* translators: %s: site name. */
			sprintf( __( 'We received your advertising inquiry - %s', 'guam-restaurants-core' ), $site ),
			sprintf(
				/* translators: 1: site name, 2: editorial policy URL. */
				__( "Thank you for asking about advertising on %1\$s. We have received your message and will reply by email.\n\nThis is an automatic confirmation. It is not an offer or a quote: prices, availability and placements are confirmed only in a written proposal.\n\nAdvertising on %1\$s never affects ratings, awards, rankings, reviews or which restaurants we list. Our editorial policy explains how we keep them separate:\n%2\$s\n\nIf you did not send this inquiry, you can ignore this email.", 'guam-restaurants-core' ),
				$site,
				Policy_Page::url()
			)
		);
	}

	/**
	 * Hashed rate-limit key for the current visitor. Shares the visitor
	 * reviews' IP handling (and its proxy filter); the IP is never stored.
	 *
	 * @return string
	 */
	public static function client_key(): string {
		return \GuamRestaurants\Core\Reviews\Visitor_Reviews::client_key();
	}

	/**
	 * Whether an ID is a published restaurant.
	 *
	 * @param int $restaurant_id ID.
	 * @return bool
	 */
	public static function is_published_restaurant( int $restaurant_id ): bool {
		return Content_Types::POST_TYPE === get_post_type( $restaurant_id ) && 'publish' === get_post_status( $restaurant_id );
	}

	/**
	 * Phone numbers: 7 to 15 digits, with spaces, +, -, dots and brackets allowed.
	 *
	 * @param string $phone Phone.
	 * @return bool
	 */
	private static function is_valid_phone( string $phone ): bool {
		if ( mb_strlen( $phone ) > self::MAX_PHONE || ! preg_match( '/^\+?[0-9().\-\s]+$/', $phone ) ) {
			return false;
		}
		$digits = strlen( (string) preg_replace( '/\D/', '', $phone ) );
		return $digits >= 7 && $digits <= 15;
	}

	/**
	 * Transient name for a rate-limit key.
	 *
	 * @param string $key Hashed key.
	 * @return string
	 */
	private static function rate_key( string $key ): string {
		return 'gr_lead_rl_' . substr( $key, 0, 32 );
	}
}
