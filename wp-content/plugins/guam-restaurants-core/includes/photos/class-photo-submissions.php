<?php
/**
 * Photos that visitors and owners upload to a restaurant page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;
use GuamRestaurants\Core\Security\Hardening;
use GuamRestaurants\Core\Site\Cache;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Visitor and owner photo uploads (Sanjay, 2026-10-20).
 *
 * - Each photo is an attachment of the restaurant with a status:
 *   `unconfirmed` (a visitor who is not logged in has not opened the email
 *   link yet), `pending` (waiting for an administrator) or `approved`.
 *   A rejected photo is deleted with its files.
 * - A dish name is required. A 1 to 5 star rating of the dish is optional.
 *   Owners and anyone who says they are connected to the restaurant can
 *   upload but not rate (as with reviews, 16 CFR 465).
 * - The uploader must accept the photo upload agreement (Agreement), which
 *   transfers the photo to the site. The version and time are stored.
 * - Files up to MAX_MB. The server keeps a reduced copy (long edge
 *   MAX_EDGE px, JPEG, camera and location data removed) for the site and
 *   deletes the full-size original ORIGINAL_DAYS days after upload.
 * - Moderation follows published rules (rules()); none of them looks at
 *   the rating, so low and high ratings are treated alike.
 * - Owners' photos also wait, both from this form and from the owner
 *   dashboard (OWNER_GALLERY), unless an administrator gave the owner the
 *   trusted owner role (Roles::PUBLISH_PHOTOS). Administrators have it too.
 */
final class Photo_Submissions {

	public const STATUS     = '_gr_photo_status';
	public const DISH       = '_gr_photo_dish';
	public const RATING     = '_gr_photo_rating';
	public const NAME       = '_gr_photo_uploader';
	public const USER       = '_gr_photo_user';
	public const FROM_OWNER = '_gr_photo_from_owner';
	public const CONNECTED  = '_gr_photo_connected';
	public const AGREEMENT  = '_gr_photo_agreement';
	public const ORIGINAL   = '_gr_photo_original';
	public const KEEP_UNTIL = '_gr_photo_original_until';

	/**
	 * Marks a photo from the owner dashboard (Owners\Owner_Content). Once
	 * approved it shows in the owner's section, not in the photo gallery.
	 */
	public const OWNER_GALLERY = '_gr_photo_owner_gallery';

	public const UNCONFIRMED = 'unconfirmed';
	public const PENDING     = 'pending';
	public const APPROVED    = 'approved';

	public const MAX_MB        = 20;
	public const MAX_EDGE      = 2048;
	public const QUALITY       = 82;
	public const ORIGINAL_DAYS = 30;
	public const MAX_DISH      = 80;
	public const MAX_NAME      = 60;
	public const PER_HOUR      = 5;
	public const MIN_FILL_MS   = 3000;

	private const TYPES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * Published reasons a photo may be declined.
	 *
	 * @return array<string, string>
	 */
	public static function rules(): array {
		return array(
			'not_food'         => __( 'Does not show food, drink, the menu or the restaurant.', 'guam-restaurants-core' ),
			'wrong_restaurant' => __( 'Taken at a different restaurant.', 'guam-restaurants-core' ),
			'not_yours'        => __( 'Not taken by the person who uploaded it (for example, copied from a website or another app).', 'guam-restaurants-core' ),
			'people'           => __( 'Shows people\'s faces up close, or personal information such as a receipt with a card number.', 'guam-restaurants-core' ),
			'inappropriate'    => __( 'Offensive, explicit or illegal content.', 'guam-restaurants-core' ),
			'unclear'          => __( 'Too dark or blurry to see the dish, or a duplicate of a photo already shown.', 'guam-restaurants-core' ),
			'dish_name'        => __( 'The dish name is not a dish name (for example, advertising or a comment about someone).', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Largest upload in bytes: MAX_MB, or less when the site's cap is lower.
	 *
	 * @return int
	 */
	public static function max_bytes(): int {
		return min( self::MAX_MB * MB_IN_BYTES, Hardening::max_upload_bytes() );
	}

	/**
	 * Checks and stores a photo.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input {
	 *     Submitted fields.
	 *     @type string $dish        Dish name (required).
	 *     @type int    $rating      0 for none, or 1 to 5.
	 *     @type string $author_name Display name (logged-in users may leave it empty).
	 *     @type string $email       Required when not logged in; never stored.
	 *     @type bool   $connected   Whether the uploader is connected to the restaurant.
	 *     @type bool   $agree       Accepted the photo upload agreement.
	 *     @type string $website     Honeypot; must be empty.
	 *     @type int    $elapsed_ms  Time spent on the form.
	 * }
	 * @param array<string, mixed> $file       Uploaded file ($_FILES entry).
	 * @param string               $client_key Hashed visitor key for rate limiting.
	 * @return array{status: string, id: int}|WP_Error `pending`, or `unconfirmed` when an email was sent.
	 */
	public static function submit( int $restaurant_id, array $input, array $file, string $client_key ) {
		if ( ! Highlights::is_published_restaurant( $restaurant_id ) ) {
			return new WP_Error( 'gr_photo_restaurant', __( 'Unknown restaurant.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		if ( '' !== trim( (string) ( $input['website'] ?? '' ) ) || (int) ( $input['elapsed_ms'] ?? 0 ) < self::MIN_FILL_MS ) {
			return new WP_Error( 'gr_photo_rejected', __( 'Your photo could not be sent. Please try again.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}

		$user_id   = get_current_user_id();
		$owner     = $user_id && Roles::owns( $user_id, $restaurant_id );
		$connected = $owner || filter_var( $input['connected'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$dish      = trim( sanitize_text_field( (string) ( $input['dish'] ?? '' ) ) );
		$rating    = (int) ( $input['rating'] ?? 0 );
		$name      = trim( sanitize_text_field( (string) ( $input['author_name'] ?? '' ) ) );
		$email     = trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) );
		if ( '' === $name && $user_id ) {
			$name = trim( (string) wp_get_current_user()->display_name );
		}

		$errors = new WP_Error();
		if ( '' === $dish || mb_strlen( $dish ) > self::MAX_DISH ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_photo_dish', sprintf( __( 'Enter the dish name, up to %d characters.', 'guam-restaurants-core' ), self::MAX_DISH ) );
		}
		if ( $rating < 0 || $rating > 5 ) {
			$errors->add( 'gr_photo_rating', __( 'Choose a rating from 1 to 5 stars, or leave it empty.', 'guam-restaurants-core' ) );
		}
		if ( $rating > 0 && $connected ) {
			$errors->add( 'gr_photo_rating_connected', __( 'Owners, staff and family of the restaurant can add photos but not ratings.', 'guam-restaurants-core' ) );
		}
		if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_photo_name', sprintf( __( 'Enter a display name of up to %d characters.', 'guam-restaurants-core' ), self::MAX_NAME ) );
		}
		if ( ! $user_id && ! is_email( $email ) ) {
			$errors->add( 'gr_photo_email', __( 'Enter your email address so we can send you a confirmation link, or log in.', 'guam-restaurants-core' ) );
		}
		if ( ! filter_var( $input['agree'] ?? false, FILTER_VALIDATE_BOOLEAN ) ) {
			$errors->add( 'gr_photo_agree', __( 'Read and accept the photo upload agreement.', 'guam-restaurants-core' ) );
		}
		$file_error = self::check_file( $file );
		if ( is_wp_error( $file_error ) ) {
			$errors->merge_from( $file_error );
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		$email_key = $user_id ? '' : hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'nonce' ) );
		foreach ( array_filter( array( $client_key, $email_key ) ) as $key ) {
			if ( (int) get_transient( 'gr_photo_rl_' . substr( $key, 0, 32 ) ) >= self::PER_HOUR ) {
				return new WP_Error( 'gr_photo_rate', __( 'You have sent several photos recently. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 429 ) );
			}
		}

		$id = self::store( $restaurant_id, $file, $dish );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( array_filter( array( $client_key, $email_key ) ) as $key ) {
			$name_key = 'gr_photo_rl_' . substr( $key, 0, 32 );
			set_transient( $name_key, (int) get_transient( $name_key ) + 1, HOUR_IN_SECONDS );
		}

		$status = $user_id ? ( user_can( $user_id, Roles::PUBLISH_PHOTOS ) ? self::APPROVED : self::PENDING ) : self::UNCONFIRMED;
		$meta   = array(
			self::STATUS     => $status,
			self::DISH       => $dish,
			self::RATING     => $connected ? 0 : $rating,
			self::NAME       => $name,
			self::USER       => $user_id,
			self::FROM_OWNER => $owner ? 1 : 0,
			self::CONNECTED  => $connected ? 1 : 0,
			self::AGREEMENT  => array(
				'version' => Agreement::VERSION,
				'time'    => gmdate( 'c' ),
				'name'    => Agreement::legal_name(),
			),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		if ( self::APPROVED === $status ) {
			Cache::flush();
		}
		if ( ! $user_id ) {
			$token = wp_generate_password( 32, false, false );
			set_transient( 'gr_photo_confirm_' . hash( 'sha256', $token ), $id, DAY_IN_SECONDS );
			if ( ! self::send_confirmation( $email, Photo_Rest_Controller::confirm_url( $token ), $restaurant_id ) ) {
				self::delete( $id );
				return new WP_Error( 'gr_photo_email_failed', __( 'We could not send the confirmation email. Please try again later.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
			}
		}

		return array(
			'status' => $status,
			'id'     => $id,
		);
	}

	/**
	 * Size and type check before anything is stored.
	 *
	 * @param array<string, mixed> $file Uploaded file.
	 * @return true|WP_Error
	 */
	public static function check_file( array $file ) {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_INI_SIZE === (int) ( $file['error'] ?? 0 ) || UPLOAD_ERR_FORM_SIZE === (int) ( $file['error'] ?? 0 ) || (int) ( $file['size'] ?? 0 ) > self::max_bytes() ) {
			/* translators: %s: size such as "20 MB". */
			return new WP_Error( 'gr_photo_size', sprintf( __( 'Photos can be at most %s.', 'guam-restaurants-core' ), size_format( self::max_bytes() ) ) );
		}
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_readable( $tmp ) ) {
			return new WP_Error( 'gr_photo_file', __( 'Choose a photo to upload.', 'guam-restaurants-core' ) );
		}
		if ( ! isset( self::TYPES[ (string) wp_get_image_mime( $tmp ) ] ) ) {
			return new WP_Error( 'gr_photo_type', __( 'Photos must be JPEG, PNG or WebP images.', 'guam-restaurants-core' ) );
		}
		$checked = Hardening::check_image_size( $file );
		if ( ! empty( $checked['error'] ) ) {
			return new WP_Error( 'gr_photo_pixels', (string) $checked['error'] );
		}
		return true;
	}

	/**
	 * Saves the original under a random name, makes the reduced copy the
	 * attachment's file, and records when to delete the original.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $file          Checked upload.
	 * @param string               $dish          Dish name, for the title.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function store( int $restaurant_id, array $file, string $dish ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$mime         = (string) wp_get_image_mime( (string) $file['tmp_name'] );
		$ext          = self::TYPES[ $mime ];
		$file['name'] = 'gr-original-' . strtolower( wp_generate_password( 20, false, false ) ) . '.' . $ext;
		// The REST controller has already checked that this is a real upload.
		$moved = wp_handle_sideload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( $ext => $mime ),
			)
		);
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'gr_photo_store', (string) $moved['error'], array( 'status' => 500 ) );
		}
		$original = (string) $moved['file'];

		$editor = wp_get_image_editor( $original );
		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $original );
			return new WP_Error( 'gr_photo_read', __( 'That image could not be read. Try a different photo.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}
		$editor->maybe_exif_rotate();
		$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false ); // Smaller photos keep their size.
		$editor->set_quality( self::QUALITY );
		$reduced = $editor->save( dirname( $original ) . '/' . sanitize_title( $dish ) . '-' . strtolower( wp_generate_password( 8, false, false ) ) . '.jpg', 'image/jpeg' );
		if ( is_wp_error( $reduced ) || ! self::strip_metadata( (string) $reduced['path'] ) ) {
			wp_delete_file( $original );
			return new WP_Error( 'gr_photo_reduce', __( 'That image could not be processed. Try a different photo.', 'guam-restaurants-core' ), array( 'status' => 500 ) );
		}

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $dish,
				'post_status'    => 'inherit',
				'post_content'   => '',
			),
			(string) $reduced['path'],
			$restaurant_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $original );
			wp_delete_file( (string) $reduced['path'] );
			return $id;
		}
		add_filter( 'big_image_size_threshold', '__return_false' );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, (string) $reduced['path'] ) );
		remove_filter( 'big_image_size_threshold', '__return_false' );

		update_post_meta( $id, '_wp_attachment_image_alt', '' );
		update_post_meta( $id, self::ORIGINAL, $original );
		update_post_meta( $id, self::KEEP_UNTIL, time() + self::ORIGINAL_DAYS * DAY_IN_SECONDS );
		return (int) $id;
	}

	/**
	 * Removes camera and location data from the reduced copy. GD writes
	 * none; Imagick copies it from the original, so the copy is stripped
	 * (keeping only its colour profile), or re-encoded with GD when Imagick
	 * cannot read JPEG.
	 *
	 * @param string $path JPEG file.
	 * @return bool Whether the file is now free of camera and location data.
	 */
	public static function strip_metadata( string $path ): bool {
		if ( self::is_clean( $path ) ) {
			return true;
		}
		if ( class_exists( 'Imagick' ) && \Imagick::queryFormats( 'JPEG' ) ) {
			try {
				$image    = new \Imagick( $path );
				$profiles = $image->getImageProfiles( 'icc', true );
				$image->stripImage();
				if ( ! empty( $profiles['icc'] ) ) {
					$image->profileImage( 'icc', $profiles['icc'] );
				}
				$image->writeImage( $path );
				$image->clear();
			} catch ( \Exception $e ) {
				unset( $e ); // Fall through to GD.
			}
		}
		if ( ! self::is_clean( $path ) && function_exists( 'imagecreatefromjpeg' ) ) {
			$image = imagecreatefromjpeg( $path );
			if ( false !== $image ) {
				imagejpeg( $image, $path, self::QUALITY );
			}
		}
		clearstatcache( true, $path );
		return self::is_clean( $path );
	}

	/**
	 * Whether a JPEG has no camera or location data.
	 *
	 * @param string $path File.
	 * @return bool
	 */
	private static function is_clean( string $path ): bool {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return false;
		}
		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- files without EXIF warn.
		return ! is_array( $exif ) || ( ! isset( $exif['GPSLatitude'] ) && ! isset( $exif['Make'] ) && ! isset( $exif['Model'] ) );
	}

	/**
	 * Emails the confirmation link.
	 *
	 * @param string $email         Address (never stored).
	 * @param string $url           Link.
	 * @param int    $restaurant_id Restaurant.
	 * @return bool
	 */
	private static function send_confirmation( string $email, string $url, int $restaurant_id ): bool {
		return wp_mail(
			$email,
			/* translators: %s: restaurant name. */
			sprintf( __( 'Confirm your photo of %s', 'guam-restaurants-core' ), get_the_title( $restaurant_id ) ),
			sprintf(
				/* translators: %s: confirmation URL. */
				__( "Please confirm your email address by opening this link:\n\n%s\n\nThe link works once, for 24 hours. After you confirm, an administrator checks your photo before it appears. If you do not confirm, the photo is deleted.\n\nWe do not keep your email address. If you did not upload a photo, ignore this message.", 'guam-restaurants-core' ),
				$url
			)
		);
	}

	/**
	 * Opens a confirmation link: the photo moves to the approval queue.
	 *
	 * @param string $token Token.
	 * @return int|WP_Error Restaurant ID.
	 */
	public static function confirm( string $token ) {
		$token = (string) preg_replace( '/[^A-Za-z0-9]/', '', $token );
		$key   = 'gr_photo_confirm_' . hash( 'sha256', $token );
		$id    = 32 === strlen( $token ) ? (int) get_transient( $key ) : 0;
		if ( $id <= 0 || self::UNCONFIRMED !== self::status( $id ) ) {
			return new WP_Error( 'gr_photo_token', __( 'This link has expired or was already used.', 'guam-restaurants-core' ), array( 'status' => 410 ) );
		}
		delete_transient( $key );
		update_post_meta( $id, self::STATUS, self::PENDING );
		return (int) wp_get_post_parent_id( $id );
	}

	/**
	 * Holds a photo an owner added on the dashboard for approval, unless
	 * the owner may publish photos.
	 *
	 * @param int $id      Attachment.
	 * @param int $user_id Owner.
	 * @return bool Whether the photo waits for approval.
	 */
	public static function hold_owner_photo( int $id, int $user_id ): bool {
		if ( user_can( $user_id, Roles::PUBLISH_PHOTOS ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		update_post_meta( $id, self::STATUS, self::PENDING );
		update_post_meta( $id, self::OWNER_GALLERY, 1 );
		update_post_meta( $id, self::FROM_OWNER, 1 );
		update_post_meta( $id, self::USER, $user_id );
		update_post_meta( $id, self::NAME, $user ? $user->display_name : '' );
		update_post_meta( $id, self::DISH, (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		return true;
	}

	/**
	 * Whether a photo may be shown: approved, or never held (owner photos
	 * from before approval existed, and photos of trusted owners).
	 *
	 * @param int $id Attachment.
	 * @return bool
	 */
	public static function is_live( int $id ): bool {
		return in_array( self::status( $id ), array( '', self::APPROVED ), true );
	}

	/**
	 * A photo's status, or '' when it is not a submission.
	 *
	 * @param int $id Attachment.
	 * @return string
	 */
	public static function status( int $id ): string {
		return (string) get_post_meta( $id, self::STATUS, true );
	}

	/**
	 * Approves a photo, or rejects (deletes) it under a published rule.
	 *
	 * @param int    $id      Attachment.
	 * @param string $action  approve or reject.
	 * @param string $reason  Rule ID, required when rejecting.
	 * @param int    $user_id Moderator.
	 * @return true|WP_Error
	 */
	public static function moderate( int $id, string $action, string $reason, int $user_id ) {
		if ( ! in_array( self::status( $id ), array( self::PENDING, self::APPROVED ), true ) ) {
			return new WP_Error( 'gr_photo_missing', __( 'Photo not found.', 'guam-restaurants-core' ), array( 'status' => 404 ) );
		}
		if ( 'approve' === $action ) {
			if ( get_post_meta( $id, self::OWNER_GALLERY, true ) ) {
				// Back to an ordinary owner photo, shown in the owner's section.
				delete_post_meta( $id, self::STATUS );
			} else {
				update_post_meta( $id, self::STATUS, self::APPROVED );
			}
			update_post_meta( $id, '_gr_photo_moderated', array( 'user' => $user_id, 'time' => gmdate( 'c' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short record.
			Cache::flush();
			return true;
		}
		if ( 'reject' !== $action || ! isset( self::rules()[ $reason ] ) ) {
			return new WP_Error( 'gr_photo_reason', __( 'Choose one of the published photo rules.', 'guam-restaurants-core' ), array( 'status' => 400 ) );
		}
		self::delete( $id );
		Cache::flush();
		return true;
	}

	/**
	 * Deletes a submission, its reduced files and its original.
	 *
	 * @param int $id Attachment.
	 * @return void
	 */
	public static function delete( int $id ): void {
		$restaurant = (int) wp_get_post_parent_id( $id );
		$owner      = get_post_meta( $restaurant, Owner_Content::PHOTOS, true );
		if ( is_array( $owner ) && in_array( $id, array_map( 'intval', $owner ), true ) ) {
			update_post_meta( $restaurant, Owner_Content::PHOTOS, array_values( array_diff( array_map( 'intval', $owner ), array( $id ) ) ) );
		}
		self::delete_original( $id );
		wp_delete_attachment( $id, true );
	}

	/**
	 * Deletes the full-size original if it is still there.
	 *
	 * @param int $id Attachment.
	 * @return bool Whether a file was deleted.
	 */
	private static function delete_original( int $id ): bool {
		$path = (string) get_post_meta( $id, self::ORIGINAL, true );
		delete_post_meta( $id, self::ORIGINAL );
		delete_post_meta( $id, self::KEEP_UNTIL );
		$uploads = wp_get_upload_dir();
		// Only ever delete inside the uploads folder.
		if ( '' === $path || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $uploads['basedir'] ) . '/' ) || ! file_exists( $path ) ) {
			return false;
		}
		wp_delete_file( $path );
		return true;
	}

	/**
	 * Daily cleanup: deletes originals past their 30 days and photos whose
	 * email was never confirmed.
	 *
	 * @param int|null $now Time.
	 * @return array{originals: int, unconfirmed: int}
	 */
	public static function cleanup( ?int $now = null ): array {
		$now    = $now ?? time();
		$counts = array(
			'originals'   => 0,
			'unconfirmed' => 0,
		);
		$due    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- daily job, in batches.
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily job.
					array(
						'key'     => self::KEEP_UNTIL,
						'value'   => $now,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( $due as $id ) {
			$counts['originals'] += self::delete_original( (int) $id ) ? 1 : 0;
		}
		foreach ( self::ids( self::UNCONFIRMED, 0, 500 ) as $id ) {
			if ( strtotime( (string) get_post_field( 'post_date_gmt', $id ) . ' UTC' ) <= $now - DAY_IN_SECONDS ) {
				self::delete( $id );
				++$counts['unconfirmed'];
			}
		}
		return $counts;
	}

	/**
	 * Submission IDs by status, newest first.
	 *
	 * @param string $status        Status.
	 * @param int    $restaurant_id Restaurant, or 0 for all.
	 * @param int    $limit         Limit.
	 * @param int    $offset        Offset.
	 * @return int[]
	 */
	public static function ids( string $status, int $restaurant_id = 0, int $limit = 50, int $offset = 0 ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => self::STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one indexed key.
			'meta_value'     => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
		);
		if ( $restaurant_id > 0 ) {
			$args['post_parent'] = $restaurant_id;
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Newest approved upload of each of several restaurants, in one query
	 * (the cards of a list), ordered as ids() orders them.
	 *
	 * @param int[] $restaurant_ids Restaurants.
	 * @return array<int, int> Restaurant ID => attachment ID, for those with one.
	 */
	public static function newest_approved( array $restaurant_ids ): array {
		global $wpdb;
		$restaurant_ids = array_values( array_filter( array_map( 'intval', $restaurant_ids ) ) );
		if ( ! $restaurant_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $restaurant_ids ), '%d' ) );
		$rows         = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one query for a list of cards; ids() runs per restaurant otherwise.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the values are one array.
				"SELECT p.ID, p.post_parent FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_parent IN ({$placeholders}) ORDER BY p.post_date DESC, p.ID DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders only.
				array_merge( array( self::STATUS, self::APPROVED ), $restaurant_ids )
			),
			ARRAY_A
		);
		$out          = array();
		foreach ( (array) $rows as $row ) {
			$parent = (int) $row['post_parent'];
			if ( ! isset( $out[ $parent ] ) ) {
				$out[ $parent ] = (int) $row['ID'];
			}
		}
		return $out;
	}

	/**
	 * Number of submissions with a status.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count( string $status ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_key'       => self::STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one indexed key.
				'meta_value'     => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Approved photos of a restaurant, for display.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $limit         Limit.
	 * @return array<int, array{id: int, dish: string, rating: int, name: string, from_owner: bool, date: string}>
	 */
	public static function approved( int $restaurant_id, int $limit = 24 ): array {
		return array_map(
			static function ( int $id ): array {
				return array(
					'id'         => $id,
					'dish'       => (string) get_post_meta( $id, self::DISH, true ),
					'rating'     => (int) get_post_meta( $id, self::RATING, true ),
					'name'       => (string) get_post_meta( $id, self::NAME, true ),
					'from_owner' => (bool) get_post_meta( $id, self::FROM_OWNER, true ),
					'date'       => (string) get_post_field( 'post_date', $id ),
				);
			},
			self::ids( self::APPROVED, $restaurant_id, $limit )
		);
	}

	/**
	 * Visitor key for rate limiting (the same hashed IP the review form uses).
	 *
	 * @return string
	 */
	public static function client_key(): string {
		return Visitor_Reviews::client_key();
	}
}
