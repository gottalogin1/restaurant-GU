<?php
/**
 * The claim form block on the "Claim this listing" page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Claims;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Owners\Dashboard;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * `guam-restaurants/claim-form`: the claim steps for the restaurant in the
 * page's `?restaurant=` parameter (restaurant pages link here).
 *
 * Logged-out visitors see links to log in or register (WordPress's own
 * screens, which LoginPress can style). Logged-in users see, in turn: the
 * request form, the code form, "waiting for review", or a link to their
 * owner dashboard. Forms post to admin-post.php with a nonce and come back
 * here with a one-time message.
 */
final class Claim_Form {

	public const BLOCK_FOLDER   = 'blocks/claim-form';
	public const ACTION_REQUEST = 'gr_claim_request';
	public const ACTION_VERIFY  = 'gr_claim_verify';
	public const MESSAGE        = 'gr_claim_msg_';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
		add_action( 'admin_post_' . self::ACTION_REQUEST, array( self::class, 'handle_request' ) );
		add_action( 'admin_post_' . self::ACTION_VERIFY, array( self::class, 'handle_verify' ) );
	}

	/**
	 * Registers the block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type( GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER, array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * Handles the request form.
	 *
	 * @return void
	 */
	public static function handle_request(): void {
		check_admin_referer( self::ACTION_REQUEST );
		$restaurant = isset( $_POST['restaurant'] ) ? absint( $_POST['restaurant'] ) : 0;
		$method     = isset( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$result     = Claims::request( get_current_user_id(), $restaurant, $method, $email );
		self::back(
			$restaurant,
			is_wp_error( $result )
				? array( 'error', $result->get_error_message() )
				: array( 'success', Claims::METHOD_EMAIL === $method ? __( 'We emailed you a code. Enter it below.', 'guam-restaurants-core' ) : __( 'We texted a code to the restaurant\'s listed phone. Enter it below.', 'guam-restaurants-core' ) )
		);
	}

	/**
	 * Handles the code form.
	 *
	 * @return void
	 */
	public static function handle_verify(): void {
		check_admin_referer( self::ACTION_VERIFY );
		$claim_id = isset( $_POST['claim'] ) ? absint( $_POST['claim'] ) : 0;
		$code     = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$claim    = Claims::get( $claim_id );
		$result   = Claims::verify( $claim_id, get_current_user_id(), $code );
		self::back(
			null === $claim ? 0 : (int) $claim['restaurant_id'],
			is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', __( 'Thanks, that code is right. We will review your claim and email you when it is approved.', 'guam-restaurants-core' ) )
		);
	}

	/**
	 * Renders the block.
	 *
	 * @return string
	 */
	public static function render(): string {
		Assets::enqueue();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page cache convention.
		}
		$restaurant = isset( $_GET['restaurant'] ) ? absint( $_GET['restaurant'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		if ( $restaurant && ( Content_Types::POST_TYPE !== get_post_type( $restaurant ) || 'publish' !== get_post_status( $restaurant ) ) ) {
			$restaurant = 0;
		}
		$user_id = get_current_user_id();
		$out     = self::message( $user_id );

		if ( ! $user_id ) {
			$here  = $restaurant ? add_query_arg( 'restaurant', $restaurant, Pages::url( 'claim' ) ) : Pages::url( 'claim' );
			$links = sprintf( '<a href="%1$s">%2$s</a>', esc_url( wp_login_url( $here ) ), esc_html__( 'Log in', 'guam-restaurants-core' ) );
			if ( get_option( 'users_can_register' ) ) {
				$links .= ' ' . esc_html__( 'or', 'guam-restaurants-core' ) . ' ' . sprintf( '<a href="%1$s">%2$s</a>', esc_url( add_query_arg( 'redirect_to', rawurlencode( $here ), wp_registration_url() ) ), esc_html__( 'create an account', 'guam-restaurants-core' ) );
			}
			/* translators: %s: log in / register links. */
			return self::wrap( $out . '<p>' . sprintf( esc_html__( '%s to claim this listing. The account you use will be the one that manages the listing.', 'guam-restaurants-core' ), $links ) . '</p>' );
		}

		if ( ! $restaurant ) {
			return self::wrap( $out . '<p>' . esc_html__( 'To claim a restaurant, open its page in the directory and follow "Claim this listing".', 'guam-restaurants-core' ) . '</p>' . self::my_claims( $user_id ) );
		}

		$owner = (int) get_post_meta( $restaurant, Restaurant_Meta::CLAIMED_BY_USER_ID, true );
		if ( $owner === $user_id ) {
			return self::wrap( $out . '<p>' . sprintf( '%1$s <a href="%2$s">%3$s</a>', esc_html__( 'This listing is linked to your account.', 'guam-restaurants-core' ), esc_url( admin_url( 'admin.php?page=' . Dashboard::PAGE ) ), esc_html__( 'Open your owner dashboard', 'guam-restaurants-core' ) ) . '</p>' );
		}
		if ( $owner > 0 ) {
			return self::wrap( $out . '<p>' . esc_html__( 'This listing has already been claimed. If you manage this restaurant, contact us and we will look into it.', 'guam-restaurants-core' ) . '</p>' );
		}

		$latest = null;
		foreach ( Claims::for_user( $user_id, Claims::OPEN_STATUSES ) as $claim ) {
			if ( (int) $claim['restaurant_id'] === $restaurant ) {
				$latest = $claim;
				break;
			}
		}
		if ( null !== $latest && Claims::VERIFIED === $latest['status'] ) {
			return self::wrap( $out . '<p>' . esc_html__( 'Your claim is confirmed and waiting for review. We will email you when it is approved.', 'guam-restaurants-core' ) . '</p>' );
		}
		if ( null !== $latest ) {
			$out .= self::code_form( $latest );
		}
		return self::wrap( $out . self::request_form( $restaurant, null !== $latest ) );
	}

	/**
	 * The request form.
	 *
	 * @param int  $restaurant Restaurant.
	 * @param bool $again      Whether a code was already sent.
	 * @return string
	 */
	private static function request_form( int $restaurant, bool $again ): string {
		$methods = Claims::methods( $restaurant );
		if ( ! $methods ) {
			return '<p>' . esc_html__( 'This listing has no website domain or phone we can send a code to. Contact us and we will confirm your claim another way.', 'guam-restaurants-core' ) . '</p>';
		}
		$domain = Claims::claim_domain( $restaurant );
		$html   = '<form class="gr-claim__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html  .= wp_nonce_field( self::ACTION_REQUEST, '_wpnonce', true, false );
		$html  .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_REQUEST ) . '"><input type="hidden" name="restaurant" value="' . (int) $restaurant . '">';
		$html  .= '<fieldset><legend>' . esc_html( $again ? __( 'Need a new code?', 'guam-restaurants-core' ) : __( 'How should we confirm you manage this restaurant?', 'guam-restaurants-core' ) ) . '</legend>';
		foreach ( $methods as $i => $method ) {
			$label = Claims::METHOD_EMAIL === $method
				/* translators: %s: domain. */
				? sprintf( __( 'Email a code to my address at %s', 'guam-restaurants-core' ), $domain )
				: __( 'Text a code to the phone number on this listing', 'guam-restaurants-core' );
			$html .= sprintf( '<p><label><input type="radio" name="method" value="%1$s"%2$s> %3$s</label></p>', esc_attr( $method ), 0 === $i ? ' checked' : '', esc_html( $label ) );
		}
		if ( in_array( Claims::METHOD_EMAIL, $methods, true ) ) {
			/* translators: %s: domain. */
			$html .= '<p><label for="gr-claim-email">' . esc_html( sprintf( __( 'Your email at %s', 'guam-restaurants-core' ), $domain ) ) . '</label><br><input type="email" id="gr-claim-email" name="email" autocomplete="email" placeholder="' . esc_attr( 'name@' . $domain ) . '"></p>';
		}
		$html .= '</fieldset><p><button type="submit">' . esc_html__( 'Send code', 'guam-restaurants-core' ) . '</button></p></form>';
		return $html;
	}

	/**
	 * The code form.
	 *
	 * @param array<string, mixed> $claim Pending claim.
	 * @return string
	 */
	private static function code_form( array $claim ): string {
		$html  = '<form class="gr-claim__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= wp_nonce_field( self::ACTION_VERIFY, '_wpnonce', true, false );
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_VERIFY ) . '"><input type="hidden" name="claim" value="' . (int) $claim['id'] . '">';
		$html .= '<p><label for="gr-claim-code">' . esc_html__( 'Six-digit code', 'guam-restaurants-core' ) . '</label><br><input type="text" id="gr-claim-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></p>';
		$html .= '<p><button type="submit">' . esc_html__( 'Confirm', 'guam-restaurants-core' ) . '</button></p></form>';
		return $html;
	}

	/**
	 * The user's claims.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function my_claims( int $user_id ): string {
		$claims = Claims::for_user( $user_id );
		if ( ! $claims ) {
			return '';
		}
		$labels = array(
			Claims::PENDING   => __( 'Waiting for your code', 'guam-restaurants-core' ),
			Claims::VERIFIED  => __( 'Waiting for review', 'guam-restaurants-core' ),
			Claims::APPROVED  => __( 'Approved', 'guam-restaurants-core' ),
			Claims::REJECTED  => __( 'Not approved', 'guam-restaurants-core' ),
			Claims::CANCELLED => __( 'Closed', 'guam-restaurants-core' ),
		);
		$items  = '';
		foreach ( array_slice( $claims, 0, 10 ) as $claim ) {
			$items .= sprintf( '<li>%1$s: %2$s</li>', esc_html( get_the_title( (int) $claim['restaurant_id'] ) ), esc_html( $labels[ $claim['status'] ] ?? $claim['status'] ) );
		}
		return '<h3>' . esc_html__( 'Your claims', 'guam-restaurants-core' ) . '</h3><ul>' . $items . '</ul>';
	}

	/**
	 * The one-time message from the last form post.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function message( int $user_id ): string {
		if ( ! $user_id ) {
			return '';
		}
		$message = get_transient( self::MESSAGE . $user_id );
		if ( ! is_array( $message ) || 2 !== count( $message ) ) {
			return '';
		}
		delete_transient( self::MESSAGE . $user_id );
		return sprintf( '<p class="gr-claim__message gr-claim__message--%1$s" role="status">%2$s</p>', esc_attr( 'error' === $message[0] ? 'error' : 'success' ), esc_html( (string) $message[1] ) );
	}

	/**
	 * Saves the message and returns to the claim page.
	 *
	 * @param int      $restaurant Restaurant.
	 * @param string[] $message    Type and text.
	 * @return void
	 */
	private static function back( int $restaurant, array $message ): void {
		set_transient( self::MESSAGE . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		$url = Pages::url( 'claim' );
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		wp_safe_redirect( $restaurant ? add_query_arg( 'restaurant', $restaurant, $url ) : $url );
		exit;
	}

	/**
	 * Wraps output.
	 *
	 * @param string $inner HTML.
	 * @return string
	 */
	private static function wrap( string $inner ): string {
		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes( array( 'class' => 'gr-claim' ) ), $inner );
	}
}
