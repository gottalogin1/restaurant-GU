<?php
/**
 * Weekly food markets and village fiestas.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Two lists of facts, each with its source link (CLAUDE.md rule 3):
 *
 * - Markets (comparison addition 22): name, village, weekdays, hours,
 *   place, source and the date someone checked it. Shipped in
 *   data/markets.php.
 * - Fiestas (addition 26): date, village, the community within it, patron
 *   saint and source. Shipped in data/fiestas.php from Guampedia's calendar.
 *
 * Once an administrator saves Restaurants > Markets and fiestas
 * (Admin\Local_Events_Screen), the saved list is used instead of the
 * shipped one, so their edits are kept on updates.
 */
final class Local_Events {

	public const MARKETS_OPTION = 'guam_restaurants_markets';
	public const FIESTAS_OPTION = 'guam_restaurants_fiestas';

	/**
	 * Markets, shipped or saved.
	 *
	 * @return array<int, array{name: string, village: string, days: string[], opens: string, closes: string, place: string, source: string, checked: string}>
	 */
	public static function markets(): array {
		$saved = get_option( self::MARKETS_OPTION, null );
		$rows  = is_array( $saved ) ? $saved : (array) include GUAM_RESTAURANTS_CORE_DIR . 'data/markets.php';
		return array_values( array_filter( array_map( array( self::class, 'clean_market' ), $rows ) ) );
	}

	/**
	 * Fiestas, shipped or saved, by date.
	 *
	 * @return array<int, array{date: string, village: string, place: string, name: string, source: string}>
	 */
	public static function fiestas(): array {
		$saved = get_option( self::FIESTAS_OPTION, null );
		$rows  = is_array( $saved ) ? $saved : (array) include GUAM_RESTAURANTS_CORE_DIR . 'data/fiestas.php';
		$rows  = array_values( array_filter( array_map( array( self::class, 'clean_fiesta' ), $rows ) ) );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		return $rows;
	}

	/**
	 * One market row, cleaned; null when it has no name or no day.
	 *
	 * @param mixed $row Row.
	 * @return array{name: string, village: string, days: string[], opens: string, closes: string, place: string, source: string, checked: string}|null
	 */
	public static function clean_market( $row ): ?array {
		$row  = (array) $row;
		$name = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 100 );
		$days = array_values( array_intersect( Vocabulary::DAYS, array_map( 'strval', (array) ( $row['days'] ?? array() ) ) ) );
		if ( '' === $name || ! $days ) {
			return null;
		}
		return array(
			'name'    => $name,
			'village' => self::village_slug( (string) ( $row['village'] ?? '' ) ),
			'days'    => $days,
			'opens'   => self::time( (string) ( $row['opens'] ?? '' ) ),
			'closes'  => self::time( (string) ( $row['closes'] ?? '' ) ),
			'place'   => mb_substr( sanitize_text_field( (string) ( $row['place'] ?? '' ) ), 0, 120 ),
			'source'  => esc_url_raw( (string) ( $row['source'] ?? '' ), array( 'http', 'https' ) ),
			'checked' => self::date( (string) ( $row['checked'] ?? '' ) ),
		);
	}

	/**
	 * One fiesta row, cleaned; null without a valid date or a village.
	 *
	 * @param mixed $row Row.
	 * @return array{date: string, village: string, place: string, name: string, source: string}|null
	 */
	public static function clean_fiesta( $row ): ?array {
		$row     = (array) $row;
		$date    = self::date( (string) ( $row['date'] ?? '' ) );
		$village = self::village_slug( (string) ( $row['village'] ?? '' ) );
		$name    = mb_substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 120 );
		if ( '' === $date || '' === $village || '' === $name ) {
			return null;
		}
		return array(
			'date'    => $date,
			'village' => $village,
			'place'   => mb_substr( sanitize_text_field( (string) ( $row['place'] ?? '' ) ), 0, 60 ),
			'name'    => $name,
			'source'  => esc_url_raw( (string) ( $row['source'] ?? '' ), array( 'http', 'https' ) ),
		);
	}

	/**
	 * Markets held on a date.
	 *
	 * @param string $date Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public static function markets_on( string $date ): array {
		$day = Vocabulary::DAYS[ (int) ( new \DateTimeImmutable( $date . ' 12:00:00', Clock::zone() ) )->format( 'N' ) - 1 ];
		$out = array_values(
			array_filter(
				self::markets(),
				static function ( array $market ) use ( $day ): bool {
					return in_array( $day, $market['days'], true );
				}
			)
		);
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( ( '' === $a['opens'] ? '99' : $a['opens'] ) . $a['name'], ( '' === $b['opens'] ? '99' : $b['opens'] ) . $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Fiestas between two dates.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int, array{date: string, village: string, place: string, name: string, source: string}>
	 */
	public static function fiestas_between( string $from, string $to ): array {
		return array_values(
			array_filter(
				self::fiestas(),
				static function ( array $fiesta ) use ( $from, $to ): bool {
					return $fiesta['date'] >= $from && $fiesta['date'] <= $to;
				}
			)
		);
	}

	/**
	 * A village's fiestas in the latest year listed for it.
	 *
	 * @param string $village Village slug.
	 * @return array<int, array{date: string, village: string, place: string, name: string, source: string}>
	 */
	public static function village_fiestas( string $village ): array {
		$rows = array_values(
			array_filter(
				self::fiestas(),
				static function ( array $fiesta ) use ( $village ): bool {
					return $fiesta['village'] === $village;
				}
			)
		);
		if ( ! $rows ) {
			return array();
		}
		$year = substr( (string) end( $rows )['date'], 0, 4 );
		return array_values(
			array_filter(
				$rows,
				static function ( array $fiesta ) use ( $year ): bool {
					return 0 === strpos( $fiesta['date'], $year );
				}
			)
		);
	}

	/**
	 * The fiesta line on a village page: this year's fiestas, the next one
	 * first, with the source link.
	 *
	 * @param \WP_Term $village Village.
	 * @param string   $today   Y-m-d; defaults to today in Guam.
	 * @return string '' when none is listed.
	 */
	public static function village_line( \WP_Term $village, string $today = '' ): string {
		$fiestas = self::village_fiestas( $village->slug );
		if ( ! $fiestas ) {
			return '';
		}
		$today = '' === $today ? Clock::today() : $today;
		$next  = null;
		$items = array();
		foreach ( $fiestas as $fiesta ) {
			$label = self::fiesta_label( $fiesta );
			if ( null === $next && $fiesta['date'] >= $today ) {
				$next = $fiesta;
				continue;
			}
			$items[] = $label;
		}
		$source = (string) $fiestas[0]['source'];
		$year   = substr( $fiestas[0]['date'], 0, 4 );
		$html   = '<div class="gr-fiestas">';
		if ( null !== $next ) {
			$html .= '<p class="gr-fiestas__next"><strong>' . esc_html__( 'Next village fiesta:', 'guam-restaurants-core' ) . '</strong> ' . esc_html( self::fiesta_label( $next ) ) . '</p>';
		}
		if ( $items ) {
			$html .= '<p class="gr-fiestas__all">' . esc_html(
				/* translators: 1: year, 2: list of fiestas. */
				sprintf( null !== $next ? __( 'Other %1$s fiestas: %2$s', 'guam-restaurants-core' ) : __( '%1$s fiestas: %2$s', 'guam-restaurants-core' ), $year, implode( '; ', $items ) )
			) . '</p>';
		}
		if ( '' !== $source ) {
			$html .= '<p class="gr-fiestas__source">' . sprintf(
				/* translators: %s: source name, linked. */
				esc_html__( 'Dates from the %s. The Church sets each year\'s dates, so check with the parish.', 'guam-restaurants-core' ),
				'<a href="' . esc_url( $source ) . '" rel="noopener">' . esc_html__( 'Guampedia fiesta calendar', 'guam-restaurants-core' ) . '</a>'
			) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * "Santo Niño, January 4" or "San Isidro (Malojloj), May 16".
	 *
	 * @param array{date: string, place: string, name: string} $fiesta Fiesta.
	 * @return string
	 */
	public static function fiesta_label( array $fiesta ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fiesta['date'], Clock::zone() );
		/* translators: date format for a fiesta, without the year. See https://www.php.net/manual/datetime.format.php */
		$date = $day ? wp_date( __( 'F j', 'guam-restaurants-core' ), $day->getTimestamp() + 12 * HOUR_IN_SECONDS, Clock::zone() ) : $fiesta['date'];
		return $fiesta['name'] . ( '' !== $fiesta['place'] ? ' (' . $fiesta['place'] . ')' : '' ) . ', ' . $date;
	}

	/**
	 * A market's hours in words: "5:30 pm–9:30 pm", "from 5:00 pm", or "evening".
	 *
	 * @param array{opens: string, closes: string} $market Market.
	 * @return string
	 */
	public static function market_hours( array $market ): string {
		if ( '' !== $market['opens'] && '' !== $market['closes'] ) {
			return Restaurant_View::time_label( $market['opens'] ) . '–' . Restaurant_View::time_label( $market['closes'] );
		}
		if ( '' !== $market['opens'] ) {
			/* translators: %s: time. */
			return sprintf( __( 'from %s', 'guam-restaurants-core' ), Restaurant_View::time_label( $market['opens'] ) );
		}
		return __( 'hours not published', 'guam-restaurants-core' );
	}

	/**
	 * A village slug (the screen offers only seeded villages).
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function village_slug( string $slug ): string {
		return sanitize_title( $slug );
	}

	/**
	 * HH:MM or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function time( string $value ): string {
		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', trim( $value ) ) ? trim( $value ) : '';
	}

	/**
	 * Y-m-d or ''.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function date( string $value ): string {
		$value = trim( $value );
		$day   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $day && $day->format( 'Y-m-d' ) === $value ? $value : '';
	}
}
