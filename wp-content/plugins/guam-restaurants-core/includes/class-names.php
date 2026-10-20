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
	 * Village names, Chamorro names and aliases as keys, for spotting a
	 * branch suffix ("McDonald's, Mangilao").
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $village_keys = null;

	/**
	 * Lowercases, removes accents, apostrophes and punctuation, turns "&"
	 * into "and" and drops a leading "the", so "The Kracked Egg" and
	 * "kracked egg" compare equal. A trailing ", <village>" added to tell
	 * branches apart is dropped too, so "McDonald's, Mangilao" compares
	 * equal to "McDonald's" (Tagging\Branch_Names).
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function key( string $name ): string {
		return self::normalize( self::split_village( $name )[0] );
	}

	/**
	 * Splits a trailing ", <village>" off a name. Only a known village name,
	 * Chamorro name or alias counts, so "Pizza, Pasta & More" is left whole.
	 *
	 * @param string $name Name, as stored.
	 * @return array{0: string, 1: string} Name without the suffix, and the suffix's village text ('' when none).
	 */
	public static function split_village( string $name ): array {
		if ( preg_match( '/^(.*\S)\s*,\s*([^,]+?)\s*$/u', $name, $m ) && isset( self::village_keys()[ self::normalize( $m[2] ) ] ) ) {
			return array( $m[1], $m[2] );
		}
		return array( $name, '' );
	}

	/**
	 * The comparable form of a name.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function normalize( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = strtolower( remove_accents( $name ) );
		$name = str_replace( '&', ' and ', $name );
		$name = (string) preg_replace( "/['’`]/u", '', $name );
		$name = (string) preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$name = (string) preg_replace( '/^the /', '', trim( $name ) );
		return trim( $name );
	}

	/**
	 * Keys of every village name, Chamorro name and alias (from the seed data).
	 *
	 * @return array<string, true>
	 */
	private static function village_keys(): array {
		if ( null === self::$village_keys ) {
			self::$village_keys = array();
			foreach ( Seed_Data::villages() as $village ) {
				foreach ( array_merge( array( $village['name'], $village['chamorro'] ), $village['aliases'] ) as $name ) {
					if ( '' !== $name ) {
						self::$village_keys[ self::normalize( $name ) ] = true;
					}
				}
			}
		}
		return self::$village_keys;
	}
}
