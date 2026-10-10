<?php
/**
 * Name normalization for matching.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Turns business and mall names into comparable keys.
 */
final class Names {

	/**
	 * Lowercases, removes accents, apostrophes and punctuation, turns "&"
	 * into "and" and drops a leading "the", so "The Kracked Egg" and
	 * "kracked egg" compare equal.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function key( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = strtolower( remove_accents( $name ) );
		$name = str_replace( '&', ' and ', $name );
		$name = (string) preg_replace( "/['’`]/u", '', $name );
		$name = (string) preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$name = (string) preg_replace( '/^the /', '', trim( $name ) );
		return trim( $name );
	}
}
