<?php
/**
 * The restaurant owner dashboard in wp-admin.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Owners;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Report;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Ads\Stats;
use GuamRestaurants\Core\Commerce\Woo_Checkout;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Highlights\Submit_Screen;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Restaurant_View;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * "My restaurant", for users with Roles::OWNER, about the restaurants
 * linked to them:
 *
 * - Listing views and clicks to the website and phone, last 30 days and
 *   the 30 days before (Listing_Stats).
 * - Each ad linked to the restaurant: impressions, clicks and
 *   click-through rate for the last 30 days (Ads\Stats).
 * - The premium profile: when it ends, links to buy more time through
 *   WooCommerce (Commerce\Woo_Checkout) and, while it runs, the
 *   call-to-action form.
 * - Forms for the owner fields (Owner_Content): hours, menu links,
 *   specials and photos.
 * - A link to reply to reviews (Highlights > Write).
 *
 * Every number is a total; nothing identifies a visitor.
 */
final class Dashboard {

	public const PAGE         = 'gr-owner-dashboard';
	public const ACTION       = 'gr_owner_save';
	public const NOTICE       = 'gr_owner_notice_';
	public const DAYS         = 30;
	public const MAX_UPLOAD   = 5 * MB_IN_BYTES;
	public const UPLOAD_TYPES = array( 'image/jpeg', 'image/png', 'image/webp' );

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Adds the page for users linked to a restaurant.
	 *
	 * @return void
	 */
	public static function menu(): void {
		if ( ! current_user_can( Roles::OWNER ) || ! Roles::owned_restaurants( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'My restaurant', 'guam-restaurants-core' ), __( 'My restaurant', 'guam-restaurants-core' ), Roles::OWNER, self::PAGE, array( self::class, 'screen' ), 'dashicons-store', 3 );
	}

	/**
	 * Listing and ad numbers for a restaurant over a Guam date range.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $today         Y-m-d (Guam).
	 * @return array{current: array<string, int>, previous: array<string, int>, ads: array<int, array<string, mixed>>, from: string, to: string}
	 */
	public static function stats( int $restaurant_id, string $today ): array {
		$to        = $today;
		$from      = ( new \DateTimeImmutable( $today ) )->modify( '-' . ( self::DAYS - 1 ) . ' days' )->format( 'Y-m-d' );
		$prev_to   = ( new \DateTimeImmutable( $from ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$prev_from = ( new \DateTimeImmutable( $prev_to ) )->modify( '-' . ( self::DAYS - 1 ) . ' days' )->format( 'Y-m-d' );

		$ad_ids = array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => Ads::POST_TYPE,
					'post_status'      => array( 'publish', 'private', 'draft' ),
					'fields'           => 'ids',
					'posts_per_page'   => 50,
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => Ads::META_RESTAURANT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $restaurant_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
		$ads    = array();
		foreach ( $ad_ids as $ad_id ) {
			$ad = Ads::get( $ad_id );
			if ( null !== $ad ) {
				$ads[ $ad_id ] = array(
					'ad'          => $ad,
					'impressions' => 0,
					'clicks'      => 0,
				);
			}
		}
		if ( $ads ) {
			foreach ( Stats::totals( $from, $to ) as $row ) {
				if ( isset( $ads[ $row['ad_id'] ] ) ) {
					$ads[ $row['ad_id'] ]['impressions'] += $row['impressions'];
					$ads[ $row['ad_id'] ]['clicks']      += $row['clicks'];
				}
			}
		}
		return array(
			'current'  => Listing_Stats::totals( $restaurant_id, $from, $to ),
			'previous' => Listing_Stats::totals( $restaurant_id, $prev_from, $prev_to ),
			'ads'      => array_values( $ads ),
			'from'     => $from,
			'to'       => $to,
		);
	}

	/**
	 * Saves a form section.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );
		$user_id    = get_current_user_id();
		$restaurant = isset( $_POST['restaurant'] ) ? absint( $_POST['restaurant'] ) : 0;
		$section    = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';
		if ( ! Roles::can_speak_for( $user_id, $restaurant ) ) {
			wp_die( esc_html__( 'Only the verified owner of this restaurant can change it.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Owner_Content sanitizes each field.
		$input = array();
		switch ( $section ) {
			case 'hours':
				$input['hours'] = isset( $_POST['hours'] ) && is_array( $_POST['hours'] ) ? wp_unslash( $_POST['hours'] ) : array();
				break;
			case 'menu_links':
				$input['menu_links'] = isset( $_POST['menu_links'] ) && is_array( $_POST['menu_links'] ) ? wp_unslash( $_POST['menu_links'] ) : array();
				break;
			case 'specials':
				$input['specials'] = isset( $_POST['specials'] ) && is_array( $_POST['specials'] ) ? wp_unslash( $_POST['specials'] ) : array();
				break;
			case 'cta':
				$input['cta'] = array(
					'action' => isset( $_POST['cta_action'] ) ? sanitize_key( wp_unslash( $_POST['cta_action'] ) ) : '',
					'url'    => isset( $_POST['cta_url'] ) ? sanitize_text_field( wp_unslash( $_POST['cta_url'] ) ) : '',
				);
				break;
			case 'photos':
				$keep = isset( $_POST['keep'] ) && is_array( $_POST['keep'] ) ? array_map( 'absint', $_POST['keep'] ) : array();
				$new  = self::upload( $restaurant, $user_id, isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '' );
				if ( is_wp_error( $new ) ) {
					self::back( $restaurant, array( 'error', $new->get_error_message() ) );
				}
				$input['photos'] = $new ? array_merge( $keep, array( $new ) ) : $keep;
				break;
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = Owner_Content::apply( $user_id, $restaurant, $input );
		self::back(
			$restaurant,
			is_wp_error( $result )
				? array( 'error', implode( ' ', $result->get_error_messages() ) )
				: array( 'success', $result['changed'] ? __( 'Saved. The change is live on your listing.', 'guam-restaurants-core' ) : __( 'Nothing changed.', 'guam-restaurants-core' ) )
		);
	}

	/**
	 * Saves an uploaded photo as an attachment of the restaurant.
	 *
	 * @param int    $restaurant Restaurant.
	 * @param int    $user_id    Owner.
	 * @param string $alt        Description.
	 * @return int|\WP_Error|null Attachment ID; null when nothing was uploaded.
	 */
	private static function upload( int $restaurant, int $user_id, string $alt ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle().
		if ( empty( $_FILES['photo']['name'] ) || ! isset( $_FILES['photo']['size'], $_FILES['photo']['tmp_name'] ) ) {
			return null;
		}
		if ( count( Owner_Content::get( $restaurant )['photos'] ) >= Premium::max_photos( $restaurant ) ) {
			/* translators: %d: maximum. */
			return new \WP_Error( 'gr_owner_photos', sprintf( __( 'You can have up to %d photos. Remove one first.', 'guam-restaurants-core' ), Premium::max_photos( $restaurant ) ) );
		}
		if ( (int) $_FILES['photo']['size'] > self::MAX_UPLOAD ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return new \WP_Error( 'gr_owner_photo_size', __( 'Photos must be 5 MB or smaller.', 'guam-restaurants-core' ) );
		}
		$type = wp_check_filetype_and_ext( (string) $_FILES['photo']['tmp_name'], sanitize_file_name( wp_unslash( (string) $_FILES['photo']['name'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! in_array( $type['type'], self::UPLOAD_TYPES, true ) ) {
			return new \WP_Error( 'gr_owner_photo_type', __( 'Upload a JPEG, PNG or WebP image.', 'guam-restaurants-core' ) );
		}
		if ( '' === $alt ) {
			return new \WP_Error( 'gr_owner_photo_alt', __( 'Describe the photo in a few words, for people who cannot see it.', 'guam-restaurants-core' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload(
			'photo',
			$restaurant,
			array( 'post_author' => $user_id ),
			array(
				'test_form' => false,
				'mimes'     => array(
					'jpg|jpeg|jpe' => 'image/jpeg',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( (int) $id, '_wp_attachment_image_alt', mb_substr( $alt, 0, 150 ) );
		return (int) $id;
	}

	/**
	 * The page.
	 *
	 * @return void
	 */
	public static function screen(): void {
		$user_id = get_current_user_id();
		$owned   = Roles::owned_restaurants( $user_id );
		if ( ! current_user_can( Roles::OWNER ) || ! $owned ) {
			wp_die( esc_html__( 'No restaurant is linked to your account yet.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		$restaurant = isset( $_GET['restaurant'] ) ? absint( $_GET['restaurant'] ) : $owned[0]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		if ( ! in_array( $restaurant, $owned, true ) ) {
			$restaurant = $owned[0];
		}

		echo '<div class="wrap"><h1>' . esc_html( get_the_title( $restaurant ) ) . '</h1>';
		self::notice( $user_id );
		if ( count( $owned ) > 1 ) {
			echo '<p>';
			foreach ( $owned as $id ) {
				printf( '<a class="button%1$s" href="%2$s">%3$s</a> ', $id === $restaurant ? ' button-primary' : '', esc_url( add_query_arg( 'restaurant', $id, admin_url( 'admin.php?page=' . self::PAGE ) ) ), esc_html( get_the_title( $id ) ) );
			}
			echo '</p>';
		}
		printf( '<p><a href="%1$s">%2$s</a> · <a href="%3$s">%4$s</a></p>', esc_url( (string) get_permalink( $restaurant ) ), esc_html__( 'View your listing', 'guam-restaurants-core' ), esc_url( admin_url( 'admin.php?page=' . Submit_Screen::PAGE ) ), esc_html__( 'Reply to reviews or add an owner quote', 'guam-restaurants-core' ) );

		self::render_stats( $restaurant );
		self::render_premium( $restaurant );
		self::render_hours_form( $restaurant );
		self::render_links_form( $restaurant );
		self::render_specials_form( $restaurant );
		self::render_photos_form( $restaurant, $user_id );

		echo '<p class="description">' . esc_html__( 'You can change your hours, menu links, specials and photos here. Ratings, awards, the listing\'s open or closed history and reviews are not editable by owners; to report a mistake in them, contact us.', 'guam-restaurants-core' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Numbers.
	 *
	 * @param int $restaurant Restaurant.
	 * @return void
	 */
	private static function render_stats( int $restaurant ): void {
		$stats = self::stats( $restaurant, Server::today() );
		/* translators: 1: start date, 2: end date. */
		echo '<h2>' . esc_html( sprintf( __( 'Last 30 days (%1$s to %2$s, Guam time)', 'guam-restaurants-core' ), $stats['from'], $stats['to'] ) ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:40rem"><thead><tr><th></th><th>' . esc_html__( 'Last 30 days', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'The 30 days before', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		foreach ( array(
			'views'          => __( 'Listing views', 'guam-restaurants-core' ),
			'website_clicks' => __( 'Clicks to your website', 'guam-restaurants-core' ),
			'phone_clicks'   => __( 'Taps on your phone number', 'guam-restaurants-core' ),
		) as $key => $label ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td></tr>', esc_html( $label ), esc_html( number_format_i18n( $stats['current'][ $key ] ) ), esc_html( number_format_i18n( $stats['previous'][ $key ] ) ) );
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Counted in visitors\' browsers; bots are left out and nothing about visitors is stored. Counting started when this feature was installed.', 'guam-restaurants-core' ) . '</p>';

		echo '<h2>' . esc_html__( 'Your Sponsored placements', 'guam-restaurants-core' ) . '</h2>';
		if ( ! $stats['ads'] ) {
			printf( '<p>%s</p>', esc_html__( 'No Sponsored placements are linked to this restaurant.', 'guam-restaurants-core' ) );
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Placement', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Runs', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Impressions (30 days)', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Clicks (30 days)', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Click-through rate', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		foreach ( $stats['ads'] as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( wp_strip_all_tags( (string) ( Ads::products()[ $row['ad']['product'] ]['label'] ?? $row['ad']['product'] ) ) ),
				esc_html( $row['ad']['start'] . ' – ' . ( '' === $row['ad']['end'] ? __( 'open-ended', 'guam-restaurants-core' ) : $row['ad']['end'] ) ),
				esc_html( number_format_i18n( $row['impressions'] ) ),
				esc_html( number_format_i18n( $row['clicks'] ) ),
				esc_html( Report::ctr( $row['impressions'], $row['clicks'] ) )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Premium profile status, plans and the call-to-action form.
	 *
	 * @param int $restaurant Restaurant.
	 * @return void
	 */
	private static function render_premium( int $restaurant ): void {
		echo '<h2>' . esc_html__( 'Premium profile', 'guam-restaurants-core' ) . '</h2>';
		$until = Premium::until( $restaurant );
		if ( Premium::is_active( $restaurant ) ) {
			/* translators: %s: date. */
			echo '<p><strong>' . esc_html( sprintf( __( 'Active until %s.', 'guam-restaurants-core' ), wp_date( get_option( 'date_format' ), (int) strtotime( $until . ' 12:00:00' ) ) ) ) . '</strong> ' . esc_html__( 'It does not renew by itself; we email you 14 and 3 days before it ends.', 'guam-restaurants-core' ) . '</p>';
		} else {
			/* translators: %d: number of photos. */
			echo '<p>' . esc_html( sprintf( __( 'A premium profile adds a call-to-action button (such as "Order online" or "Reserve a table"), up to %d photos, and keeps other restaurants\' Sponsored placements off your page. It does not change ratings, awards or where your restaurant appears in lists.', 'guam-restaurants-core' ), Premium::MAX_PHOTOS ) ) . '</p>';
		}
		$products = Woo_Checkout::products();
		if ( $products ) {
			echo '<p>';
			foreach ( $products as $product ) {
				printf(
					'<a class="button button-primary" href="%1$s">%2$s</a> <span>%3$s</span> &nbsp; ',
					esc_url( Woo_Checkout::buy_url( $product, $restaurant ) ),
					/* translators: %s: product name. */
					esc_html( sprintf( Premium::is_active( $restaurant ) ? __( 'Add time: %s', 'guam-restaurants-core' ) : __( 'Buy: %s', 'guam-restaurants-core' ), $product->get_name() ) ),
					wp_kses_post( $product->get_price_html() )
				);
			}
			echo '</p><p class="description">' . esc_html__( 'You pay on the checkout page. New time starts the day after your current end date.', 'guam-restaurants-core' );
			if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
				printf( ' <a href="%1$s">%2$s</a>', esc_url( wc_get_account_endpoint_url( 'orders' ) ), esc_html__( 'Your orders and receipts', 'guam-restaurants-core' ) );
			}
			echo '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Premium profiles are not on sale online yet. Reply to any email from us to ask about one.', 'guam-restaurants-core' ) . '</p>';
		}
		if ( ! Premium::is_active( $restaurant ) ) {
			return;
		}
		$cta = Premium::cta( $restaurant );
		self::form_open( $restaurant, 'cta', __( 'Call-to-action button', 'guam-restaurants-core' ) );
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="gr-cta-action">' . esc_html__( 'Button', 'guam-restaurants-core' ) . '</label></th><td><select id="gr-cta-action" name="cta_action"><option value="">' . esc_html__( 'No button', 'guam-restaurants-core' ) . '</option>';
		foreach ( Premium::cta_actions() as $key => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $key, $cta['action'] ?? '', false ), esc_html( $label ) );
		}
		printf( '</select></td></tr><tr><th scope="row"><label for="gr-cta-url">%1$s</label></th><td><input type="url" class="regular-text" id="gr-cta-url" name="cta_url" placeholder="https://" value="%2$s"></td></tr></tbody></table>', esc_html__( 'Link', 'guam-restaurants-core' ), esc_attr( (string) ( $cta['url'] ?? '' ) ) );
		self::form_close( __( 'Save button', 'guam-restaurants-core' ) );
	}

	/**
	 * Hours form.
	 *
	 * @param int $restaurant Restaurant.
	 * @return void
	 */
	private static function render_hours_form( int $restaurant ): void {
		$hours = get_post_meta( $restaurant, Restaurant_Meta::HOURS, true );
		$hours = is_array( $hours ) ? $hours : array();
		$days  = array_fill_keys( Vocabulary::DAYS, array() );
		foreach ( (array) ( $hours['weekly'] ?? array() ) as $interval ) {
			$days[ $interval['day'] ][] = $interval['open'] . '-' . $interval['close'];
		}
		self::form_open( $restaurant, 'hours', __( 'Opening hours', 'guam-restaurants-core' ) );
		echo '<p class="description">' . esc_html__( '24-hour clock, Guam time. Separate split shifts with a comma, e.g. 11:00-14:00, 17:00-21:00. Leave a day empty when closed.', 'guam-restaurants-core' ) . '</p><table class="form-table" role="presentation"><tbody>';
		foreach ( Restaurant_View::day_names() as $day => $name ) {
			printf( '<tr><th scope="row"><label for="gr-hours-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="gr-hours-%1$s" name="hours[%1$s]" value="%3$s"></td></tr>', esc_attr( $day ), esc_html( $name ), esc_attr( implode( ', ', $days[ $day ] ?? array() ) ) );
		}
		printf( '<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="hours[always_open]" value="1"%2$s> %3$s</label></td></tr>', esc_html__( 'Open 24 hours', 'guam-restaurants-core' ), checked( ! empty( $hours['always_open'] ), true, false ), esc_html__( 'Open every day, all day', 'guam-restaurants-core' ) );
		printf( '<tr><th scope="row"><label for="gr-hours-note">%1$s</label></th><td><input type="text" class="regular-text" id="gr-hours-note" name="hours[note]" maxlength="200" value="%2$s"></td></tr>', esc_html__( 'Note (e.g. holiday hours)', 'guam-restaurants-core' ), esc_attr( (string) ( $hours['note'] ?? '' ) ) );
		echo '</tbody></table>';
		self::form_close( __( 'Save hours', 'guam-restaurants-core' ) );
	}

	/**
	 * Menu links form.
	 *
	 * @param int $restaurant Restaurant.
	 * @return void
	 */
	private static function render_links_form( int $restaurant ): void {
		$links = Owner_Content::get( $restaurant )['menu_links'];
		self::form_open( $restaurant, 'menu_links', __( 'Menu links', 'guam-restaurants-core' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		for ( $i = 0; $i < Owner_Content::MAX_LINKS; $i++ ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" name="menu_links[%2$d][label]" placeholder="%3$s" maxlength="%4$d" value="%5$s"> <input type="url" class="regular-text" name="menu_links[%2$d][url]" placeholder="https://" value="%6$s"></td></tr>',
				/* translators: %d: row number. */
				esc_html( sprintf( __( 'Link %d', 'guam-restaurants-core' ), $i + 1 ) ),
				(int) $i,
				esc_attr__( 'Label, e.g. Lunch menu', 'guam-restaurants-core' ),
				(int) Owner_Content::MAX_TITLE,
				esc_attr( (string) ( $links[ $i ]['label'] ?? '' ) ),
				esc_attr( (string) ( $links[ $i ]['url'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
		self::form_close( __( 'Save menu links', 'guam-restaurants-core' ) );
	}

	/**
	 * Specials form.
	 *
	 * @param int $restaurant Restaurant.
	 * @return void
	 */
	private static function render_specials_form( int $restaurant ): void {
		$specials = Owner_Content::get( $restaurant )['specials'];
		self::form_open( $restaurant, 'specials', __( 'Specials', 'guam-restaurants-core' ) );
		echo '<p class="description">' . esc_html__( 'Shown on your listing as "From the owner" until the end date. Do not mention awards or call anything "Featured", "Top pick" or "Recommended".', 'guam-restaurants-core' ) . '</p><table class="form-table" role="presentation"><tbody>';
		for ( $i = 0; $i < Owner_Content::MAX_SPECIALS; $i++ ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" class="regular-text" name="specials[%2$d][title]" placeholder="%3$s" maxlength="%4$d" value="%5$s"><br><textarea class="large-text" rows="2" name="specials[%2$d][details]" maxlength="%6$d" placeholder="%7$s">%8$s</textarea><br><label>%9$s <input type="date" name="specials[%2$d][ends]" value="%10$s"></label></td></tr>',
				/* translators: %d: row number. */
				esc_html( sprintf( __( 'Special %d', 'guam-restaurants-core' ), $i + 1 ) ),
				(int) $i,
				esc_attr__( 'Title, e.g. Weekday lunch set', 'guam-restaurants-core' ),
				(int) Owner_Content::MAX_TITLE,
				esc_attr( (string) ( $specials[ $i ]['title'] ?? '' ) ),
				(int) Owner_Content::MAX_DETAILS,
				esc_attr__( 'Details (optional)', 'guam-restaurants-core' ),
				esc_textarea( (string) ( $specials[ $i ]['details'] ?? '' ) ),
				esc_html__( 'Ends (optional)', 'guam-restaurants-core' ),
				esc_attr( (string) ( $specials[ $i ]['ends'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
		self::form_close( __( 'Save specials', 'guam-restaurants-core' ) );
	}

	/**
	 * Photos form.
	 *
	 * @param int $restaurant Restaurant.
	 * @param int $user_id    Owner.
	 * @return void
	 */
	private static function render_photos_form( int $restaurant, int $user_id ): void {
		unset( $user_id );
		$photos = Owner_Content::get( $restaurant )['photos'];
		self::form_open( $restaurant, 'photos', __( 'Photos', 'guam-restaurants-core' ), true );
		echo '<div style="display:flex;flex-wrap:wrap;gap:1rem">';
		foreach ( $photos as $photo ) {
			printf( '<label style="text-align:center">%1$s<br><input type="checkbox" name="keep[]" value="%2$d" checked> %3$s</label>', wp_get_attachment_image( $photo, 'thumbnail' ), (int) $photo, esc_html__( 'Keep', 'guam-restaurants-core' ) );
		}
		echo '</div>';
		if ( count( $photos ) < Premium::max_photos( $restaurant ) ) {
			printf(
				'<p><label for="gr-owner-photo">%1$s</label><br><input type="file" id="gr-owner-photo" name="photo" accept="image/jpeg,image/png,image/webp"></p><p><label for="gr-owner-alt">%2$s</label><br><input type="text" class="regular-text" id="gr-owner-alt" name="alt" maxlength="150"></p>',
				/* translators: %d: maximum photos. */
				esc_html( sprintf( __( 'Add a photo (JPEG, PNG or WebP, up to 5 MB; %d photos at most). Use only photos you took or have the right to use.', 'guam-restaurants-core' ), Premium::max_photos( $restaurant ) ) ),
				esc_html__( 'Describe the photo (alt text)', 'guam-restaurants-core' )
			);
		}
		self::form_close( __( 'Save photos', 'guam-restaurants-core' ) );
	}

	/**
	 * Opens a section form.
	 *
	 * @param int    $restaurant Restaurant.
	 * @param string $section    Section.
	 * @param string $title      Heading.
	 * @param bool   $files      Whether the form uploads files.
	 * @return void
	 */
	private static function form_open( int $restaurant, string $section, string $title, bool $files = false ): void {
		printf( '<h2>%1$s</h2><form method="post" action="%2$s"%3$s>', esc_html( $title ), esc_url( admin_url( 'admin-post.php' ) ), $files ? ' enctype="multipart/form-data"' : '' );
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%1$s"><input type="hidden" name="restaurant" value="%2$d"><input type="hidden" name="section" value="%3$s">', esc_attr( self::ACTION ), (int) $restaurant, esc_attr( $section ) );
	}

	/**
	 * Closes a section form.
	 *
	 * @param string $label Button label.
	 * @return void
	 */
	private static function form_close( string $label ): void {
		submit_button( $label, 'secondary' );
		echo '</form>';
	}

	/**
	 * Shows the message from the last save.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private static function notice( int $user_id ): void {
		$message = get_transient( self::NOTICE . $user_id );
		if ( ! is_array( $message ) || 2 !== count( $message ) ) {
			return;
		}
		delete_transient( self::NOTICE . $user_id );
		printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', 'error' === $message[0] ? 'error' : 'success', esc_html( (string) $message[1] ) );
	}

	/**
	 * Returns to the dashboard with a message.
	 *
	 * @param int      $restaurant Restaurant.
	 * @param string[] $message    Type and text.
	 * @return void
	 */
	private static function back( int $restaurant, array $message ): void {
		set_transient( self::NOTICE . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'restaurant', $restaurant, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}
}
