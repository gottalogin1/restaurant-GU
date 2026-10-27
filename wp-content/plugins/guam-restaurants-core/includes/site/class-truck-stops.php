<?php
/**
 * Where food trucks and pop-ups will be, from their owners.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Ads\Guard;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Translation\Languages;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A verified owner (or an editor) lists dated stops: date, from, to, the
 * place in their own words and the village (comparison addition 22).
 * Stored in gr_truck_stops as `{date, from, to, place, village}`. A stop
 * can repeat weekly for up to MAX_REPEAT weeks; each week is stored as its
 * own date, so a past stop simply stops showing.
 *
 * Stops are owner content: shown with "From the owner", never ranked, and
 * listed on the This week page by time, not by who pays.
 */
final class Truck_Stops {

	public const META = 'gr_truck_stops';

	public const MAX        = 30;
	public const MAX_PLACE  = 80;
	public const MAX_REPEAT = 8;
	public const DAYS_AHEAD = 60;

	/**
	 * Stops from a form: rows of `date`, `from`, `to` (HH:MM), `place`,
	 * `village` (slug) and `repeat` (weeks, 1 for once). Rows without a
	 * date are dropped, and so are past dates.
	 *
	 * @param array<int, mixed> $rows  Rows.
	 * @param string|null       $today Y-m-d, Guam.
	 * @return array<int, array{date: string, from: string, to: string, place: string, village: string}>|WP_Error
	 */
	public static function from_input( array $rows, ?string $today = null ) {
		$today  = $today ?? Clock::today();
		$last   = Special_Hours::shift( $today, self::DAYS_AHEAD );
		$out    = array();
		$errors = new WP_Error();
		foreach ( $rows as $row ) {
			$row  = (array) $row;
			$date = trim( (string) ( $row['date'] ?? '' ) );
			if ( '' === $date ) {
				continue;
			}
			$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
			if ( ! $day || $day->format( 'Y-m-d' ) !== $date || $date > $last ) {
				/* translators: %d: number of days. */
				$errors->add( 'gr_truck_stop_date', sprintf( __( 'Use a valid date within the next %d days for each stop.', 'guam-restaurants-core' ), self::DAYS_AHEAD ) );
				continue;
			}
			$from = self::time( (string) ( $row['from'] ?? '' ) );
			$to   = self::time( (string) ( $row['to'] ?? '' ) );
			if ( null === $from || null === $to ) {
				$errors->add( 'gr_truck_stop_time', __( 'Give each stop a start and end time, like 17:00 and 21:00 (24-hour clock).', 'guam-restaurants-core' ) );
				continue;
			}
			$place = mb_substr( sanitize_text_field( (string) ( $row['place'] ?? '' ) ), 0, self::MAX_PLACE );
			if ( '' === $place ) {
				$errors->add( 'gr_truck_stop_place', __( 'Say where each stop is, for example "Chamorro Village parking lot".', 'guam-restaurants-core' ) );
				continue;
			}
			foreach ( Guard::creative_problems( array( 'headline' => $place ) ) as $problem ) {
				$errors->add( 'gr_owner_words', $problem );
			}
			$village = sanitize_title( (string) ( $row['village'] ?? '' ) );
			if ( '' !== $village && ! term_exists( $village, Content_Types::TAX_VILLAGE ) ) {
				$village = '';
			}
			$repeat = max( 1, min( self::MAX_REPEAT, (int) ( $row['repeat'] ?? 1 ) ) );
			for ( $week = 0; $week < $repeat; $week++ ) {
				$when = Special_Hours::shift( $date, 7 * $week );
				if ( $when < $today || $when > $last ) {
					continue;
				}
				$out[ $when . ' ' . $from . ' ' . $place ] = array(
					'date'    => $when,
					'from'    => $from,
					'to'      => $to,
					'place'   => $place,
					'village' => $village,
				);
			}
		}
		if ( count( $out ) > self::MAX ) {
			/* translators: %d: maximum. */
			$errors->add( 'gr_truck_stops_max', sprintf( __( 'Add at most %d stops, counting each repeated week.', 'guam-restaurants-core' ), self::MAX ) );
		}
		ksort( $out );
		return $errors->has_errors() ? $errors : array_values( $out );
	}

	/**
	 * A restaurant's stops from today on, soonest first.
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d.
	 * @return array<int, array{date: string, from: string, to: string, place: string, village: string}>
	 */
	public static function upcoming( int $restaurant_id, ?string $today = null ): array {
		$today = $today ?? Clock::today();
		$value = get_post_meta( $restaurant_id, self::META, true );
		$out   = array();
		foreach ( is_array( $value ) ? $value : array() as $stop ) {
			if ( is_array( $stop ) && (string) ( $stop['date'] ?? '' ) >= $today ) {
				$out[] = array(
					'date'    => (string) $stop['date'],
					'from'    => (string) ( $stop['from'] ?? '' ),
					'to'      => (string) ( $stop['to'] ?? '' ),
					'place'   => (string) ( $stop['place'] ?? '' ),
					'village' => (string) ( $stop['village'] ?? '' ),
				);
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( $a['date'] . $a['from'], $b['date'] . $b['from'] );
			}
		);
		return $out;
	}

	/**
	 * Published restaurants' stops between two dates, soonest first.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int, array{restaurant_id: int, date: string, from: string, to: string, place: string, village: string}>
	 */
	public static function between( string $from, string $to ): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only restaurants that list stops.
			)
		);
		$out = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
				continue;
			}
			foreach ( self::upcoming( $id, $from ) as $stop ) {
				if ( $stop['date'] <= $to ) {
					$out[] = array( 'restaurant_id' => $id ) + $stop;
				}
			}
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return strcmp( $a['date'] . $a['from'], $b['date'] . $b['from'] );
			}
		);
		return $out;
	}

	/**
	 * A stop's place in words: "Chamorro Village parking lot, Hagåtña".
	 *
	 * @param array{place: string, village: string} $stop Stop.
	 * @return string
	 */
	public static function place_label( array $stop ): string {
		$village = '' === $stop['village'] ? null : get_term_by( 'slug', $stop['village'], Content_Types::TAX_VILLAGE );
		return $village instanceof \WP_Term ? $stop['place'] . ', ' . $village->name : $stop['place'];
	}

	/**
	 * "Where to find us" under the restaurant's hours: the next two weeks.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string '' when no stops are listed.
	 */
	public static function render( int $restaurant_id ): string {
		$today = Clock::today();
		$until = Special_Hours::shift( $today, 13 );
		$items = '';
		foreach ( self::upcoming( $restaurant_id, $today ) as $stop ) {
			if ( $stop['date'] > $until ) {
				break;
			}
			$items .= sprintf(
				'<li><span class="gr-truck-stops__when">%1$s, %2$s–%3$s</span> %4$s</li>',
				esc_html( Special_Hours::date_label( $stop['date'] ) ),
				esc_html( Restaurant_View::time_label( $stop['from'] ) ),
				esc_html( Restaurant_View::time_label( $stop['to'] ) ),
				Languages::keep( esc_html( self::place_label( $stop ) ) )
			);
		}
		if ( '' === $items ) {
			return '';
		}
		return '<div class="gr-truck-stops"><h4 class="gr-truck-stops__title">' . esc_html__( 'Where to find us', 'guam-restaurants-core' ) . '</h4><ul>' . $items . '</ul><p class="gr-truck-stops__source">' . esc_html__( 'Stops listed by the owner. Plans can change, so check their own pages on the day.', 'guam-restaurants-core' ) . '</p></div>';
	}

	/**
	 * Form rows for the owner dashboard and the editor's box: the stops
	 * still to come, then empty rows.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $name          Field name, for example "truck_stops".
	 * @return string Table.
	 */
	public static function form_rows( int $restaurant_id, string $name ): string {
		$stops    = self::upcoming( $restaurant_id );
		$villages = get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_VILLAGE,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$rows     = '';
		$count    = min( self::MAX, count( $stops ) + 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$stop    = $stops[ $i ] ?? array(
				'date'    => '',
				'from'    => '',
				'to'      => '',
				'place'   => '',
				'village' => '',
			);
			$options = '<option value="">' . esc_html__( 'Village', 'guam-restaurants-core' ) . '</option>';
			foreach ( is_array( $villages ) ? $villages : array() as $village ) {
				$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $village->slug ), selected( $stop['village'], $village->slug, false ), esc_html( $village->name ) );
			}
			$repeat = '';
			for ( $weeks = 1; $weeks <= self::MAX_REPEAT; $weeks++ ) {
				$repeat .= sprintf(
					'<option value="%1$d">%2$s</option>',
					$weeks,
					esc_html(
						1 === $weeks ? __( 'Once', 'guam-restaurants-core' )
						/* translators: %d: number of weeks. */
						: sprintf( _n( 'Weekly for %d week', 'Weekly for %d weeks', $weeks, 'guam-restaurants-core' ), $weeks )
					)
				);
			}
			$rows .= sprintf(
				'<tr><td><input type="date" name="%1$s[%2$d][date]" value="%3$s" aria-label="%4$s"></td><td><input type="time" name="%1$s[%2$d][from]" value="%5$s" aria-label="%6$s"> – <input type="time" name="%1$s[%2$d][to]" value="%7$s" aria-label="%8$s"></td><td><input type="text" name="%1$s[%2$d][place]" value="%9$s" maxlength="%10$d" placeholder="%11$s" aria-label="%12$s"> <select name="%1$s[%2$d][village]" aria-label="%13$s">%14$s</select></td><td><select name="%1$s[%2$d][repeat]" aria-label="%15$s">%16$s</select></td></tr>',
				esc_attr( $name ),
				(int) $i,
				esc_attr( $stop['date'] ),
				esc_attr__( 'Date', 'guam-restaurants-core' ),
				esc_attr( $stop['from'] ),
				esc_attr__( 'From', 'guam-restaurants-core' ),
				esc_attr( $stop['to'] ),
				esc_attr__( 'To', 'guam-restaurants-core' ),
				esc_attr( $stop['place'] ),
				(int) self::MAX_PLACE,
				esc_attr__( 'e.g. Chamorro Village parking lot', 'guam-restaurants-core' ),
				esc_attr__( 'Where', 'guam-restaurants-core' ),
				esc_attr__( 'Village', 'guam-restaurants-core' ),
				$options,
				esc_attr__( 'Repeat', 'guam-restaurants-core' ),
				$repeat
			);
		}
		return sprintf(
			'<table class="widefat striped"><thead><tr><th>%1$s</th><th>%2$s</th><th>%3$s</th><th>%4$s</th></tr></thead><tbody>%5$s</tbody></table>',
			esc_html__( 'Date', 'guam-restaurants-core' ),
			esc_html__( 'Time', 'guam-restaurants-core' ),
			esc_html__( 'Where', 'guam-restaurants-core' ),
			esc_html__( 'Repeat', 'guam-restaurants-core' ),
			$rows
		);
	}

	/**
	 * "17:00" from a time input or "5:00"; null when not a time.
	 *
	 * @param string $value Value.
	 * @return string|null HH:MM.
	 */
	private static function time( string $value ): ?string {
		if ( 1 !== preg_match( '/^(\d{1,2}):([0-5]\d)$/', trim( $value ), $m ) || (int) $m[1] > 23 ) {
			return null;
		}
		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}
}
