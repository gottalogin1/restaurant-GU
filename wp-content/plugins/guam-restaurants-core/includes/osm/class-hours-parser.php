<?php
/**
 * Parser for the common forms of the OSM opening_hours tag.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Turns `opening_hours` values such as "Mo-Fr 10:00-21:00; Sa,Su 08:00-22:00"
 * into the gr_hours structure.
 *
 * Only plain weekly schedules are parsed: day lists and ranges, time
 * ranges (several per day, past midnight allowed), "off"/"closed" and
 * "24/7". Anything else (public holidays, months, sunrise, comments,
 * open ends) returns null, and the importer keeps just the raw value so an
 * editor or a later parser can handle it.
 */
final class Hours_Parser {

	/**
	 * Parses a value.
	 *
	 * @param string $value opening_hours tag.
	 * @return array{weekly?: array<int, array{day: string, open: string, close: string}>, always_open?: bool}|null
	 */
	public static function parse( string $value ): ?array {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( '24/7' === $value ) {
			return array(
				'weekly'      => array(),
				'always_open' => true,
			);
		}

		// "Mo-Fr 09:00-17:00, Sa 10:00-14:00": a comma before a day selector also separates rules.
		$rules = preg_split( '/\s*;\s*|(?<=\d)\s*,\s*(?=(?:Mo|Tu|We|Th|Fr|Sa|Su)\b)/', $value );
		$days  = array_fill_keys( Vocabulary::DAYS, array() );

		foreach ( $rules as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( ! preg_match( '/^(?:([A-Za-z,\- ]+?)\s+)?(off|closed|[0-9:,\- ]+)$/', $rule, $m ) ) {
				return null;
			}

			$selected = '' === $m[1] ? Vocabulary::DAYS : self::days( $m[1] );
			if ( null === $selected ) {
				return null;
			}

			$intervals = array();
			if ( ! in_array( $m[2], array( 'off', 'closed' ), true ) ) {
				$intervals = self::times( $m[2] );
				if ( null === $intervals ) {
					return null;
				}
			}

			// A later rule replaces earlier ones for the same days, as in OSM.
			foreach ( $selected as $day ) {
				$days[ $day ] = $intervals;
			}
		}

		$weekly = array();
		foreach ( $days as $day => $intervals ) {
			foreach ( $intervals as $interval ) {
				$weekly[] = array( 'day' => $day ) + $interval;
			}
		}

		return array( 'weekly' => $weekly );
	}

	/**
	 * Parses a day selector such as "Mo-Fr", "Sa,Su" or "Fr-Mo".
	 *
	 * @param string $selector Selector.
	 * @return string[]|null Day codes, or null when not understood.
	 */
	private static function days( string $selector ): ?array {
		$all  = Vocabulary::DAYS;
		$days = array();

		foreach ( explode( ',', str_replace( ' ', '', $selector ) ) as $part ) {
			if ( ! preg_match( '/^(Mo|Tu|We|Th|Fr|Sa|Su)(?:-(Mo|Tu|We|Th|Fr|Sa|Su))?$/', $part, $m ) ) {
				return null;
			}
			$from = array_search( strtolower( $m[1] ), $all, true );
			$to   = isset( $m[2] ) ? array_search( strtolower( $m[2] ), $all, true ) : $from;
			// Ranges may wrap past Sunday, for example Fr-Mo.
			$span = ( $to - $from + 7 ) % 7;
			for ( $n = 0; $n <= $span; $n++ ) {
				$days[] = $all[ ( $from + $n ) % 7 ];
			}
		}

		return array_values( array_unique( $days ) );
	}

	/**
	 * Parses "10:00-14:00,17:00-02:00".
	 *
	 * @param string $times Times.
	 * @return array<int, array{open: string, close: string}>|null
	 */
	private static function times( string $times ): ?array {
		$out = array();
		foreach ( explode( ',', str_replace( ' ', '', $times ) ) as $range ) {
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $range, $m ) ) {
				return null;
			}
			$open_h  = (int) $m[1];
			$close_h = (int) $m[3];
			if ( $open_h > 23 || (int) $m[2] > 59 || (int) $m[4] > 59 || $close_h > 47 ) {
				return null;
			}
			// OSM writes past-midnight closing as 26:00; gr_hours uses a close time before the open time.
			if ( $close_h > 24 || ( 24 === $close_h && '00' !== $m[4] ) ) {
				$close_h -= 24;
			}
			$out[] = array(
				'open'  => sprintf( '%02d:%s', $open_h, $m[2] ),
				'close' => sprintf( '%02d:%s', $close_h, $m[4] ),
			);
		}
		return $out;
	}
}
