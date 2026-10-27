<?php
/**
 * Holiday and special hours: dated changes to the weekly hours.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Ads\Guard;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Vocabulary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The verified owner (or an editor) sets the hours for a date, such as
 * Christmas, Liberation Day or the village fiesta: closed all day, or other
 * hours (comparison addition 23). Stored in gr_special_hours as
 * `{date, closed, ranges, note}`.
 *
 * A date's hours replace the weekly hours that start on that day; hours
 * that started the night before and run past midnight still count. "Open
 * now", "open late" and "open at" use them (open_sql()), and so does the
 * open-now label in the browser (attribute(), hours.js). Only hours from
 * the owner or our editors are used; nothing is imported.
 */
final class Special_Hours {

	public const META = 'gr_special_hours';

	public const MAX        = 20;
	public const MAX_NOTE   = 60;
	public const DAYS_AHEAD = 366;

	/**
	 * Days ahead the browser is told about (pages may be cached for hours).
	 */
	public const BROWSER_DAYS = 31;

	/**
	 * Date => restaurant IDs with special hours that day, from today on.
	 */
	public const INDEX_OPTION = 'guam_restaurants_special_hours_index';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'added_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'trashed_post', array( self::class, 'rebuild_index' ) );
		add_action( 'untrashed_post', array( self::class, 'rebuild_index' ) );
	}

	/**
	 * Rebuilds the index when a restaurant's special hours change.
	 *
	 * @param int|int[] $meta_id  Meta ID (unused).
	 * @param int       $post_id  Post (unused).
	 * @param string    $meta_key Key.
	 * @return void
	 */
	public static function meta_changed( $meta_id, $post_id, $meta_key ): void {
		if ( self::META === $meta_key ) {
			self::rebuild_index();
		}
	}

	/**
	 * Rebuilds the date index from every restaurant's special hours.
	 *
	 * @return array<string, int[]>
	 */
	public static function rebuild_index(): array {
		$ids   = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only restaurants that have special hours.
			)
		);
		$from  = self::shift( Clock::today(), -1 );
		$index = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			foreach ( self::stored( $id ) as $entry ) {
				if ( $entry['date'] >= $from ) {
					$index[ $entry['date'] ][] = $id;
				}
			}
		}
		ksort( $index );
		update_option( self::INDEX_OPTION, $index, false );
		return $index;
	}

	/**
	 * Restaurant IDs with special hours on a date.
	 *
	 * @param string $date Y-m-d.
	 * @return int[]
	 */
	public static function restaurants_on( string $date ): array {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) && isset( $index[ $date ] ) ? array_map( 'intval', (array) $index[ $date ] ) : array();
	}

	/**
	 * Special hours from a form: rows of `date` (Y-m-d), `closed`, `hours`
	 * ("11:00-14:00, 17:00-21:00") and `note`. Rows without a date are
	 * dropped; past dates are dropped; a date listed twice keeps the last row.
	 *
	 * @param array<int, mixed> $rows  Rows.
	 * @param string|null       $today Y-m-d, Guam.
	 * @return array<int, array{date: string, closed: bool, ranges: array<int, array{open: string, close: string}>, note: string}>|WP_Error
	 */
	public static function from_input( array $rows, ?string $today = null ) {
		$today  = $today ?? Clock::today();
		$last   = self::shift( $today, self::DAYS_AHEAD );
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
				$errors->add( 'gr_special_hours_date', sprintf( __( 'Use a valid date within the next %d days for each change of hours.', 'guam-restaurants-core' ), self::DAYS_AHEAD ) );
				continue;
			}
			if ( $date < $today ) {
				continue;
			}
			$closed = ! empty( $row['closed'] );
			$text   = trim( (string) ( $row['hours'] ?? '' ) );
			$ranges = $closed || '' === $text ? array() : Owner_Content::ranges_from_text( $text );
			if ( null === $ranges || ( ! $closed && ! $ranges ) ) {
				$errors->add( 'gr_special_hours_time', __( 'For each date, tick Closed or write the hours like 11:00-14:00, 17:00-21:00 (24-hour clock).', 'guam-restaurants-core' ) );
				continue;
			}
			$note = mb_substr( sanitize_text_field( (string) ( $row['note'] ?? '' ) ), 0, self::MAX_NOTE );
			foreach ( Guard::creative_problems( array( 'headline' => $note ) ) as $problem ) {
				$errors->add( 'gr_owner_words', $problem );
			}
			$out[ $date ] = array(
				'date'   => $date,
				'closed' => $closed,
				'ranges' => $closed ? array() : $ranges,
				'note'   => $note,
			);
		}
		if ( count( $out ) > self::MAX ) {
			/* translators: %d: maximum. */
			$errors->add( 'gr_special_hours_max', sprintf( __( 'Add at most %d dates.', 'guam-restaurants-core' ), self::MAX ) );
		}
		ksort( $out );
		return $errors->has_errors() ? $errors : array_values( $out );
	}

	/**
	 * Every stored entry, oldest first.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array{date: string, closed: bool, ranges: array<int, array{open: string, close: string}>, note: string}>
	 */
	public static function stored( int $restaurant_id ): array {
		$value = get_post_meta( $restaurant_id, self::META, true );
		$out   = array();
		foreach ( is_array( $value ) ? $value : array() as $entry ) {
			if ( is_array( $entry ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $entry['date'] ?? '' ) ) ) {
				$out[ (string) $entry['date'] ] = array(
					'date'   => (string) $entry['date'],
					'closed' => ! empty( $entry['closed'] ),
					'ranges' => array_values( array_filter( (array) ( $entry['ranges'] ?? array() ), 'is_array' ) ),
					'note'   => (string) ( $entry['note'] ?? '' ),
				);
			}
		}
		ksort( $out );
		return array_values( $out );
	}

	/**
	 * Entries from today on (Guam), for the hours table and forms.
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d.
	 * @return array<int, array{date: string, closed: bool, ranges: array<int, array{open: string, close: string}>, note: string}>
	 */
	public static function upcoming( int $restaurant_id, ?string $today = null ): array {
		$today = $today ?? Clock::today();
		return array_values(
			array_filter(
				self::stored( $restaurant_id ),
				static function ( array $entry ) use ( $today ): bool {
					return $entry['date'] >= $today;
				}
			)
		);
	}

	/**
	 * The entry for one date, or null.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $date          Y-m-d.
	 * @return array{date: string, closed: bool, ranges: array<int, array{open: string, close: string}>, note: string}|null
	 */
	public static function on( int $restaurant_id, string $date ): ?array {
		foreach ( self::stored( $restaurant_id ) as $entry ) {
			if ( $entry['date'] === $date ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Opening intervals that start on a date, in minutes from that day's
	 * midnight; one running past midnight ends after 1440. From the date's
	 * special hours, else from the weekly hours.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $date          Y-m-d.
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function starting( int $restaurant_id, string $date ): array {
		$entry = self::on( $restaurant_id, $date );
		if ( null !== $entry ) {
			return self::to_minutes( $entry['ranges'] );
		}
		$hours = get_post_meta( $restaurant_id, Restaurant_Meta::HOURS, true );
		$hours = is_array( $hours ) ? $hours : array();
		if ( ! empty( $hours['always_open'] ) ) {
			return array( array( 0, Clock::MINUTES_PER_DAY ) );
		}
		$day    = Vocabulary::DAYS[ (int) ( new \DateTimeImmutable( $date, Clock::zone() ) )->format( 'N' ) - 1 ];
		$ranges = array();
		foreach ( (array) ( $hours['weekly'] ?? array() ) as $interval ) {
			if ( is_array( $interval ) && ( $interval['day'] ?? '' ) === $day ) {
				$ranges[] = $interval;
			}
		}
		return self::to_minutes( $ranges );
	}

	/**
	 * Every interval during a date: those starting that day, then the part
	 * after midnight of those that started the day before. Minutes from the
	 * date's midnight, sorted.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $date          Y-m-d.
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function day( int $restaurant_id, string $date ): array {
		$out = self::starting( $restaurant_id, $date );
		foreach ( self::starting( $restaurant_id, self::shift( $date, -1 ) ) as $interval ) {
			if ( $interval[1] > Clock::MINUTES_PER_DAY ) {
				$out[] = array( 0, $interval[1] - Clock::MINUTES_PER_DAY );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Whether the restaurant is open at a moment, by its weekly and special hours.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $timestamp     Unix time.
	 * @return bool
	 */
	public static function is_open( int $restaurant_id, int $timestamp ): bool {
		$local  = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( Clock::zone() );
		$minute = (int) $local->format( 'G' ) * 60 + (int) $local->format( 'i' );
		foreach ( self::day( $restaurant_id, $local->format( 'Y-m-d' ) ) as $interval ) {
			if ( $interval[0] <= $minute && $minute < $interval[1] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * SQL condition for "open at a moment": the weekly-hours condition for
	 * restaurants without special hours that day or the day before, and the
	 * answer worked out here for the few that have them.
	 *
	 * @param int    $timestamp   Unix time.
	 * @param string $posts_alias Posts table name or alias in the outer query.
	 * @param string $weekly_sql  Open_Hours::open_sql() for the same minute.
	 * @return string
	 */
	public static function open_sql( int $timestamp, string $posts_alias, string $weekly_sql ): string {
		$date     = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( Clock::zone() )->format( 'Y-m-d' );
		$affected = array_values( array_unique( array_merge( self::restaurants_on( $date ), self::restaurants_on( self::shift( $date, -1 ) ) ) ) );
		if ( ! $affected ) {
			return $weekly_sql;
		}
		$open = array();
		foreach ( $affected as $id ) {
			if ( self::is_open( $id, $timestamp ) ) {
				$open[] = $id;
			}
		}
		$sql = "(({$posts_alias}.ID NOT IN (" . implode( ',', array_map( 'intval', $affected ) ) . ")) AND {$weekly_sql})";
		return $open ? "({$sql} OR {$posts_alias}.ID IN (" . implode( ',', array_map( 'intval', $open ) ) . '))' : $sql;
	}

	/**
	 * The days the browser's open-now label must work out from these
	 * hours instead of the weekly ones: each special date and the day after
	 * it, from yesterday to BROWSER_DAYS ahead. "Y-m-d:start-end,start-end"
	 * joined by ";" (minutes from that day's midnight; nothing after the
	 * colon means closed all day).
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d.
	 * @return string '' when there are none.
	 */
	public static function browser_days( int $restaurant_id, ?string $today = null ): string {
		$today = $today ?? Clock::today();
		$from  = self::shift( $today, -1 );
		$to    = self::shift( $today, self::BROWSER_DAYS );
		$dates = array();
		foreach ( self::stored( $restaurant_id ) as $entry ) {
			foreach ( array( $entry['date'], self::shift( $entry['date'], 1 ) ) as $date ) {
				if ( $date >= $from && $date <= $to ) {
					$dates[ $date ] = true;
				}
			}
		}
		ksort( $dates );
		$parts = array();
		foreach ( array_keys( $dates ) as $date ) {
			$parts[] = $date . ':' . implode(
				',',
				array_map(
					static function ( array $interval ): string {
						return $interval[0] . '-' . $interval[1];
					},
					self::day( $restaurant_id, $date )
				)
			);
		}
		return implode( ';', $parts );
	}

	/**
	 * The data-gr-special attribute for an open-now label, with a leading space.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string '' when there are no special hours.
	 */
	public static function attribute( int $restaurant_id ): string {
		$days = self::browser_days( $restaurant_id );
		return '' === $days ? '' : ' data-gr-special="' . esc_attr( $days ) . '"';
	}

	/**
	 * One entry in words: "Closed" or "11:00 am–2:00 pm, 5:00 pm–9:00 pm".
	 *
	 * @param array{closed: bool, ranges: array<int, array{open: string, close: string}>} $entry Entry.
	 * @return string
	 */
	public static function hours_label( array $entry ): string {
		if ( $entry['closed'] || ! $entry['ranges'] ) {
			return _x( 'Closed', 'closed all day on a date', 'guam-restaurants-core' );
		}
		return implode(
			', ',
			array_map(
				static function ( array $range ): string {
					return Restaurant_View::time_label( (string) $range['open'] ) . '–' . Restaurant_View::time_label( (string) $range['close'] );
				},
				$entry['ranges']
			)
		);
	}

	/**
	 * A date in words: "December 25, 2026", in the order of the page's language.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public static function date_label( string $date ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Clock::zone() );
		/* translators: date format with the year. See https://www.php.net/manual/datetime.format.php */
		return $day ? wp_date( __( 'F j, Y', 'guam-restaurants-core' ), $day->getTimestamp() + 12 * HOUR_IN_SECONDS, Clock::zone() ) : $date;
	}

	/**
	 * The "Holiday and special hours" list under the weekly hours.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string '' when there are none.
	 */
	public static function render( int $restaurant_id ): string {
		$entries = self::upcoming( $restaurant_id );
		if ( ! $entries ) {
			return '';
		}
		$items = '';
		foreach ( $entries as $entry ) {
			$items .= sprintf(
				'<li><span class="gr-special-hours__date">%1$s</span>%2$s: %3$s</li>',
				esc_html( self::date_label( $entry['date'] ) ),
				'' !== $entry['note'] ? ' (' . esc_html( $entry['note'] ) . ')' : '',
				esc_html( self::hours_label( $entry ) )
			);
		}
		return '<div class="gr-special-hours"><h4 class="gr-special-hours__title">' . esc_html__( 'Holiday and special hours', 'guam-restaurants-core' ) . '</h4><ul>' . $items . '</ul><p class="gr-special-hours__source">' . esc_html__( 'Set by the owner or our editors. These replace the weekly hours on those dates.', 'guam-restaurants-core' ) . '</p></div>';
	}

	/**
	 * Form rows for the owner dashboard and the editor's box: the dates
	 * still to come, then empty rows.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $name          Field name, for example "special_hours".
	 * @return string Table rows.
	 */
	public static function form_rows( int $restaurant_id, string $name ): string {
		$entries = self::upcoming( $restaurant_id );
		$rows    = '';
		$count   = min( self::MAX, count( $entries ) + 3 );
		for ( $i = 0; $i < $count; $i++ ) {
			$entry  = $entries[ $i ] ?? array(
				'date'   => '',
				'closed' => false,
				'ranges' => array(),
				'note'   => '',
			);
			$ranges = implode(
				', ',
				array_map(
					static function ( array $range ): string {
						return $range['open'] . '-' . $range['close'];
					},
					$entry['ranges']
				)
			);
			$rows  .= sprintf(
				'<tr><td><input type="date" name="%1$s[%2$d][date]" value="%3$s" aria-label="%4$s"></td><td><label><input type="checkbox" name="%1$s[%2$d][closed]" value="1"%5$s> %6$s</label></td><td><input type="text" name="%1$s[%2$d][hours]" value="%7$s" placeholder="11:00-14:00, 17:00-21:00" aria-label="%8$s"></td><td><input type="text" name="%1$s[%2$d][note]" value="%9$s" maxlength="%10$d" placeholder="%11$s" aria-label="%12$s"></td></tr>',
				esc_attr( $name ),
				(int) $i,
				esc_attr( $entry['date'] ),
				esc_attr__( 'Date', 'guam-restaurants-core' ),
				checked( $entry['closed'], true, false ),
				esc_html__( 'Closed', 'guam-restaurants-core' ),
				esc_attr( $ranges ),
				esc_attr__( 'Hours that day', 'guam-restaurants-core' ),
				esc_attr( $entry['note'] ),
				(int) self::MAX_NOTE,
				esc_attr__( 'e.g. Christmas Day', 'guam-restaurants-core' ),
				esc_attr__( 'What the day is (optional)', 'guam-restaurants-core' )
			);
		}
		return sprintf(
			'<table class="widefat striped"><thead><tr><th>%1$s</th><th>%2$s</th><th>%3$s</th><th>%4$s</th></tr></thead><tbody>%5$s</tbody></table>',
			esc_html__( 'Date', 'guam-restaurants-core' ),
			esc_html__( 'Closed all day', 'guam-restaurants-core' ),
			esc_html__( 'Or open these hours', 'guam-restaurants-core' ),
			esc_html__( 'What the day is (optional)', 'guam-restaurants-core' ),
			$rows
		);
	}

	/**
	 * Ranges as minutes from midnight; a close at or before the open runs
	 * past midnight.
	 *
	 * @param array<int, mixed> $ranges Rows with `open` and `close` (HH:MM).
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function to_minutes( array $ranges ): array {
		$out = array();
		foreach ( $ranges as $range ) {
			$open  = Open_Hours::minutes( (string) ( $range['open'] ?? '' ) );
			$close = Open_Hours::minutes( (string) ( $range['close'] ?? '' ) );
			if ( null === $open || null === $close ) {
				continue;
			}
			$out[] = array( $open, $close <= $open ? $close + Clock::MINUTES_PER_DAY : $close );
		}
		sort( $out );
		return $out;
	}

	/**
	 * A date some days away.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add (negative to go back).
	 * @return string Y-m-d.
	 */
	public static function shift( string $date, int $days ): string {
		return ( new \DateTimeImmutable( $date . ' 12:00:00', Clock::zone() ) )->modify( ( $days >= 0 ? '+' : '' ) . $days . ' days' )->format( 'Y-m-d' );
	}
}
