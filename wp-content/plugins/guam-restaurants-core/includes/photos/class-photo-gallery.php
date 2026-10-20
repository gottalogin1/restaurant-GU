<?php
/**
 * Restaurant photos block: approved uploads and the upload form.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Site\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Block `guam-restaurants/photo-gallery`. The photos and the form are
 * rendered on the server; assets/site/photos.js sends the form. Logged-in
 * visitors (whose pages are not page-cached) get a REST nonce so the photo
 * is linked to their account; everyone else confirms an email address.
 */
final class Photo_Gallery {

	public const BLOCK_FOLDER = 'blocks/photo-gallery';
	public const LIMIT        = 24;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Registers the block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER,
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function render_block( $attributes ): string {
		return self::render( (int) ( $attributes['restaurantId'] ?? 0 ), (bool) ( $attributes['showForm'] ?? true ) );
	}

	/**
	 * The section.
	 *
	 * @param int  $restaurant_id Restaurant, or 0 for the current post.
	 * @param bool $form          Show the upload form.
	 * @return string
	 */
	public static function render( int $restaurant_id, bool $form = true ): string {
		if ( $restaurant_id <= 0 ) {
			$restaurant_id = (int) get_the_ID();
		}
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return '';
		}
		Assets::enqueue( $form ? 'photos' : '' );

		$photos = Photo_Submissions::approved( $restaurant_id, self::LIMIT );
		$items  = '';
		foreach ( $photos as $photo ) {
			$items .= self::figure( $photo );
		}
		$list = '' !== $items
			? '<ul class="gr-photos__grid">' . $items . '</ul>'
			: '<p class="gr-photos__empty">' . esc_html__( 'No photos yet. Be the first to add one.', 'guam-restaurants-core' ) . '</p>';

		return sprintf(
			'<section class="gr-photos" id="gr-photos"><h2 class="gr-photos__title">%1$s</h2><p class="gr-photos__intro">%2$s</p>%3$s%4$s</section>',
			esc_html__( 'Photos', 'guam-restaurants-core' ),
			esc_html__( 'Uploaded by visitors and the restaurant, and approved by an administrator before they appear.', 'guam-restaurants-core' ),
			$list,
			$form ? self::form( $restaurant_id ) : ''
		);
	}

	/**
	 * One photo.
	 *
	 * @param array{id: int, dish: string, rating: int, name: string, from_owner: bool, date: string} $photo Photo.
	 * @return string
	 */
	private static function figure( array $photo ): string {
		$full = (string) wp_get_attachment_image_url( $photo['id'], 'large' );
		$by   = $photo['from_owner']
			? '<span class="gr-photos__owner">' . esc_html__( 'From the owner', 'guam-restaurants-core' ) . '</span>'
			/* translators: %s: uploader's display name. */
			: esc_html( sprintf( __( 'by %s', 'guam-restaurants-core' ), $photo['name'] ) );
		return sprintf(
			'<li><figure class="gr-photos__item"><a href="%1$s">%2$s</a><figcaption><strong class="gr-photos__dish">%3$s</strong>%4$s<span class="gr-photos__by">%5$s</span></figcaption></figure></li>',
			esc_url( $full ),
			wp_get_attachment_image(
				$photo['id'],
				'medium_large',
				false,
				array(
					'loading' => 'lazy',
					'alt'     => $photo['dish'],
				)
			),
			esc_html( $photo['dish'] ),
			$photo['rating'] > 0 ? self::stars( $photo['rating'] ) : '',
			$by
		);
	}

	/**
	 * Star rating, shown as given.
	 *
	 * @param int $rating 1 to 5.
	 * @return string
	 */
	public static function stars( int $rating ): string {
		$rating = max( 1, min( 5, $rating ) );
		return sprintf(
			'<span class="gr-photos__stars" role="img" aria-label="%1$s"><span aria-hidden="true">%2$s</span></span>',
			/* translators: %d: stars from 1 to 5. */
			esc_attr( sprintf( _n( '%d out of 5 stars', '%d out of 5 stars', $rating, 'guam-restaurants-core' ), $rating ) ),
			str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating )
		);
	}

	/**
	 * The upload form.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	private static function form( int $restaurant_id ): string {
		$user_id = get_current_user_id();
		$owner   = $user_id && Roles::owns( $user_id, $restaurant_id );
		$attrs   = '';
		if ( $user_id ) {
			$attrs = sprintf( ' data-nonce="%s"', esc_attr( wp_create_nonce( 'wp_rest' ) ) );
		}
		$agreement = Agreement::url();
		$max       = Photo_Submissions::max_bytes();

		$fields = array();
		/* translators: %s: size such as "20 MB". */
		$fields[] = self::field( 'gr-photo-file', __( 'Photo', 'guam-restaurants-core' ), sprintf( '<input type="file" id="gr-photo-file" name="photo" accept="image/jpeg,image/png,image/webp" required data-max-bytes="%1$d" /><span class="gr-photos__hint">%2$s</span>', $max, esc_html( sprintf( __( 'JPEG, PNG or WebP, up to %s.', 'guam-restaurants-core' ), size_format( $max ) ) ) ) );
		/* translators: %d: maximum characters. */
		$fields[] = self::field( 'gr-photo-dish', __( 'Dish name (required)', 'guam-restaurants-core' ), sprintf( '<input type="text" id="gr-photo-dish" name="dish" maxlength="%d" required placeholder="%s" />', Photo_Submissions::MAX_DISH, esc_attr__( 'For example: chicken kelaguen', 'guam-restaurants-core' ) ) );
		if ( ! $owner ) {
			$fields[] = self::rating_fieldset();
		}
		if ( ! $user_id ) {
			$fields[] = self::field( 'gr-photo-name', __( 'Your display name', 'guam-restaurants-core' ), sprintf( '<input type="text" id="gr-photo-name" name="author_name" maxlength="%d" required autocomplete="nickname" />', Photo_Submissions::MAX_NAME ) );
			$fields[] = self::field( 'gr-photo-email', __( 'Email (for a confirmation link; not stored)', 'guam-restaurants-core' ), '<input type="email" id="gr-photo-email" name="email" required autocomplete="email" />' );
		}
		if ( $owner ) {
			$fields[] = '<p class="gr-photos__hint">' . esc_html__( 'You manage this restaurant, so your photos show as "From the owner" and have no rating.', 'guam-restaurants-core' ) . '</p>';
		} else {
			$fields[] = '<p class="gr-photos__check"><label><input type="checkbox" name="connected" value="1" data-gr-photo-connected /> ' . esc_html__( 'I own, work at, or am related to someone at this restaurant (you can add photos, but not ratings)', 'guam-restaurants-core' ) . '</label></p>';
		}
		$link     = '' !== $agreement
			? sprintf( '<a href="%1$s" target="_blank" rel="noopener">%2$s</a>', esc_url( $agreement ), esc_html__( 'photo upload agreement', 'guam-restaurants-core' ) )
			: esc_html__( 'photo upload agreement', 'guam-restaurants-core' );
		$fields[] = sprintf(
			'<p class="gr-photos__check"><label><input type="checkbox" name="agree" value="1" required /> %1$s</label></p>',
			sprintf(
				/* translators: 1: link to the photo upload agreement, 2: company name. */
				esc_html__( 'I took this photo and accept the %1$s. The photo becomes the property of %2$s.', 'guam-restaurants-core' ),
				$link,
				esc_html( Agreement::legal_name() )
			)
		);
		$fields[] = '<p class="gr-photos__trap" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off" /></label></p>';
		$fields[] = sprintf( '<p><button type="submit" class="gr-photos__submit">%s</button></p><p class="gr-photos__status" role="status" aria-live="polite"></p>', esc_html__( 'Send for approval', 'guam-restaurants-core' ) );

		return sprintf(
			'<details class="gr-photos__add"%1$s><summary>%2$s</summary><form class="gr-photos__form" data-gr-photo-form data-url="%3$s"%4$s enctype="multipart/form-data" novalidate>%5$s</form></details>',
			isset( $_GET['gr_photo'] ) ? ' open' : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only opens the form after the email link.
			esc_html__( 'Add a photo', 'guam-restaurants-core' ),
			esc_url( Photo_Rest_Controller::url( $restaurant_id ) ),
			$attrs, // Escaped above.
			implode( '', $fields ) . self::confirmation_note()
		);
	}

	/**
	 * Optional star rating radios.
	 *
	 * @return string
	 */
	private static function rating_fieldset(): string {
		$radios = sprintf( '<label class="gr-photos__none"><input type="radio" name="rating" value="0" checked /> %s</label>', esc_html__( 'No rating', 'guam-restaurants-core' ) );
		for ( $i = 1; $i <= 5; $i++ ) {
			$radios .= sprintf(
				'<label><input type="radio" name="rating" value="%1$d" /> %2$s</label>',
				$i,
				/* translators: %d: number of stars. */
				esc_html( sprintf( _n( '%d star', '%d stars', $i, 'guam-restaurants-core' ), $i ) )
			);
		}
		return '<fieldset class="gr-photos__rating" data-gr-photo-rating><legend>' . esc_html__( 'How was the dish? (optional)', 'guam-restaurants-core' ) . '</legend>' . $radios . '</fieldset>';
	}

	/**
	 * Label and control.
	 *
	 * @param string $id      Control ID.
	 * @param string $label   Label.
	 * @param string $control Control HTML (escaped).
	 * @return string
	 */
	private static function field( string $id, string $label, string $control ): string {
		return sprintf( '<p class="gr-photos__field"><label for="%1$s">%2$s</label>%3$s</p>', esc_attr( $id ), esc_html( $label ), $control );
	}

	/**
	 * Message after the email link was opened.
	 *
	 * @return string
	 */
	private static function confirmation_note(): string {
		$state = isset( $_GET['gr_photo'] ) ? sanitize_key( wp_unslash( $_GET['gr_photo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( 'confirmed' === $state ) {
			return '<p class="gr-photos__notice">' . esc_html__( 'Thanks, your email is confirmed. Your photo will appear after an administrator approves it.', 'guam-restaurants-core' ) . '</p>';
		}
		if ( 'expired' === $state ) {
			return '<p class="gr-photos__notice">' . esc_html__( 'That link has expired or was already used. Please upload the photo again.', 'guam-restaurants-core' ) . '</p>';
		}
		return '';
	}
}
