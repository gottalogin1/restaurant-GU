<?php
/**
 * The photo upload agreement.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Text lives in data/legal/photo-upload-agreement.md (published by
 * Legal\Pages at /photo-upload-agreement/). The company's formal name is
 * not decided yet: [guam_legal_name] shows GUAM_RESTAURANTS_LEGAL_NAME,
 * or the site name until it is set, and the Photo submissions screen says
 * the agreement still needs the formal name and counsel's review.
 */
final class Agreement {

	public const VERSION   = '1';
	public const SLUG      = 'photo-upload-agreement';
	public const SHORTCODE = 'guam_legal_name';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/**
	 * Whether the formal name has been set.
	 *
	 * @return bool
	 */
	public static function has_legal_name(): bool {
		return '' !== trim( Config::get( Config::LEGAL_NAME ) );
	}

	/**
	 * Formal name, or the site name until it is set.
	 *
	 * @return string
	 */
	public static function legal_name(): string {
		$name = trim( Config::get( Config::LEGAL_NAME ) );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		return esc_html( self::legal_name() );
	}

	/**
	 * URL of the published agreement, or ''.
	 *
	 * @return string
	 */
	public static function url(): string {
		$page = get_page_by_path( self::SLUG, OBJECT, 'page' );
		return $page instanceof \WP_Post && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
	}
}
