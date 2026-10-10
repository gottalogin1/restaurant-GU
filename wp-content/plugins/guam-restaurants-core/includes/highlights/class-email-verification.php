<?php
/**
 * One-time email confirmation links.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

defined( 'ABSPATH' ) || exit;

/**
 * A confirmation link carries a random token; the server keeps only a hash
 * of the token and what it confirms, in a transient that expires after a
 * day and is deleted when used. Email addresses are never stored: once the
 * link is sent the address is forgotten.
 */
final class Email_Verification {

	public const TTL    = DAY_IN_SECONDS;
	public const PREFIX = 'gr_hl_confirm_';

	/**
	 * Stores a payload and returns its token.
	 *
	 * @param array<string, mixed> $payload What the link confirms.
	 * @return string
	 */
	public static function create( array $payload ): string {
		$token = wp_generate_password( 32, false, false );
		set_transient( self::key( $token ), $payload, self::TTL );
		return $token;
	}

	/**
	 * Returns a token's payload once, then forgets it.
	 *
	 * @param string $token Token from the link.
	 * @return array<string, mixed>|null Null when unknown, used or expired.
	 */
	public static function take( string $token ): ?array {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
		if ( 32 !== strlen( (string) $token ) ) {
			return null;
		}
		$key     = self::key( (string) $token );
		$payload = get_transient( $key );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		delete_transient( $key );
		return $payload;
	}

	/**
	 * Emails the link.
	 *
	 * @param string $email         Address.
	 * @param string $url           Confirmation URL.
	 * @param int    $restaurant_id Restaurant reviewed.
	 * @return bool
	 */
	public static function send( string $email, string $url, int $restaurant_id ): bool {
		return wp_mail(
			$email,
			/* translators: %s: restaurant name. */
			sprintf( __( 'Confirm your review of %s', 'guam-restaurants-core' ), get_the_title( $restaurant_id ) ),
			sprintf(
				/* translators: 1: confirmation URL, 2: hours the link works. */
				__( "Please confirm your email address by opening this link:\n\n%1\$s\n\nThe link works once, for %2\$d hours. After you confirm, a moderator checks your review against our published rules before it appears. Your review will show a \"Verified email\" badge, which means only that you confirmed this address.\n\nWe do not keep your email address. If you did not write a review, ignore this message.", 'guam-restaurants-core' ),
				$url,
				(int) ( self::TTL / HOUR_IN_SECONDS )
			)
		);
	}

	/**
	 * Hash for an email address, for rate limiting only.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function email_key( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), wp_salt( 'nonce' ) );
	}

	/**
	 * Transient name for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function key( string $token ): string {
		return self::PREFIX . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
