<?php
/**
 * This week on Guam: markets, food trucks, village fiestas and holiday hours.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * The next seven days, Guam time, starting today (comparison additions 22,
 * 23 and 26). Each day lists, in this order:
 *
 * - village fiestas (Local_Events, with the calendar's link);
 * - weekly food markets (Local_Events, each with its source and the date
 *   it was checked);
 * - food trucks and pop-ups, by start time (Truck_Stops, from owners);
 * - restaurants with holiday or special hours that day (Special_Hours).
 *
 * Nothing on the page is ranked, and nothing paid changes the order.
 */
final class This_Week {

	public const DAYS = 7;

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title(): string {
		return __( 'This week on Guam: night markets, food trucks and fiestas', 'guam-restaurants-core' );
	}

	/**
	 * Meta description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'The next seven days on Guam: night markets, where food trucks will be, village fiestas, and restaurants with holiday hours.', 'guam-restaurants-core' );
	}

	/**
	 * The days, from today.
	 *
	 * @param string $today Y-m-d; defaults to today in Guam.
	 * @return array<string, array{fiestas: array<int, array<string, string>>, markets: array<int, array<string, mixed>>, stops: array<int, array<string, mixed>>, hours: array<int, array<string, mixed>>}> Y-m-d => what is on.
	 */
	public static function days( string $today = '' ): array {
		$today = '' === $today ? Clock::today() : $today;
		$last  = Special_Hours::shift( $today, self::DAYS - 1 );
		$days  = array();
		for ( $i = 0; $i < self::DAYS; $i++ ) {
			$date          = Special_Hours::shift( $today, $i );
			$days[ $date ] = array(
				'fiestas' => array(),
				'markets' => Local_Events::markets_on( $date ),
				'stops'   => array(),
				'hours'   => array(),
			);
			foreach ( Special_Hours::restaurants_on( $date ) as $id ) {
				$entry = Special_Hours::on( $id, $date );
				if ( null !== $entry && 'publish' === get_post_status( $id ) ) {
					$days[ $date ]['hours'][] = array( 'restaurant_id' => $id ) + $entry;
				}
			}
			usort(
				$days[ $date ]['hours'],
				static function ( array $a, array $b ): int {
					return strcasecmp( get_the_title( $a['restaurant_id'] ), get_the_title( $b['restaurant_id'] ) );
				}
			);
		}
		foreach ( Local_Events::fiestas_between( $today, $last ) as $fiesta ) {
			$days[ $fiesta['date'] ]['fiestas'][] = $fiesta;
		}
		foreach ( Truck_Stops::between( $today, $last ) as $stop ) {
			$days[ $stop['date'] ]['stops'][] = $stop;
		}
		return $days;
	}

	/**
	 * The page.
	 *
	 * @return string
	 */
	public static function render(): string {
		Assets::enqueue();
		$today = Clock::today();
		$body  = '';
		foreach ( self::days( $today ) as $date => $day ) {
			$body .= '<section class="gr-week__day" aria-labelledby="gr-week-' . esc_attr( $date ) . '"><h2 class="gr-section-title" id="gr-week-' . esc_attr( $date ) . '">' . esc_html( self::day_label( $date, $today ) ) . '</h2>';
			$parts = self::fiestas_html( $day['fiestas'] ) . self::markets_html( $day['markets'] ) . self::stops_html( $day['stops'] ) . self::hours_html( $day['hours'] );
			$body .= '' === $parts ? '<p class="gr-week__empty">' . esc_html__( 'Nothing listed for this day yet.', 'guam-restaurants-core' ) . '</p>' : $parts;
			$body .= '</section>';
		}
		$claim = Pages::url( 'claim' );
		return sprintf(
			'<div class="gr-fresh-page gr-week alignwide"><h1 class="gr-fresh-page__title">%1$s</h1><p class="gr-fresh-page__intro">%2$s</p>%3$s<p class="gr-week__owners">%4$s</p></div>',
			esc_html__( 'This week on Guam', 'guam-restaurants-core' ),
			esc_html__( 'Night markets, food trucks, village fiestas and holiday hours for the next seven days, Guam time. Market times and fiesta dates come from the pages linked beside them; truck stops and holiday hours come from the owners. Plans change, so check before you go.', 'guam-restaurants-core' ),
			$body,
			'' === $claim
				? esc_html__( 'Own a food truck or restaurant? Verified owners can list their stops and holiday hours from their dashboard.', 'guam-restaurants-core' )
				: sprintf(
					/* translators: %s: "claim your listing", linked. */
					esc_html__( 'Own a food truck or restaurant? %s to list your stops and holiday hours here.', 'guam-restaurants-core' ),
					'<a href="' . esc_url( $claim ) . '">' . esc_html__( 'Claim your listing', 'guam-restaurants-core' ) . '</a>'
				)
		);
	}

	/**
	 * "Today, Tuesday, October 27" or "Thursday, October 29".
	 *
	 * @param string $date  Y-m-d.
	 * @param string $today Y-m-d.
	 * @return string
	 */
	private static function day_label( string $date, string $today ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Clock::zone() );
		/* translators: weekday, month and day format. See https://www.php.net/manual/datetime.format.php */
		$label = $day ? wp_date( __( 'l, F j', 'guam-restaurants-core' ), $day->getTimestamp() + 12 * HOUR_IN_SECONDS, Clock::zone() ) : $date;
		if ( $date === $today ) {
			/* translators: %s: weekday and date. */
			return sprintf( __( 'Today, %s', 'guam-restaurants-core' ), $label );
		}
		if ( Special_Hours::shift( $today, 1 ) === $date ) {
			/* translators: %s: weekday and date. */
			return sprintf( __( 'Tomorrow, %s', 'guam-restaurants-core' ), $label );
		}
		return $label;
	}

	/**
	 * Fiestas that day.
	 *
	 * @param array<int, array<string, string>> $fiestas Fiestas.
	 * @return string
	 */
	private static function fiestas_html( array $fiestas ): string {
		if ( ! $fiestas ) {
			return '';
		}
		$items = '';
		foreach ( $fiestas as $fiesta ) {
			$village = get_term_by( 'slug', $fiesta['village'], Content_Types::TAX_VILLAGE );
			$where   = $village instanceof \WP_Term ? '<a href="' . esc_url( (string) get_term_link( $village ) ) . '">' . Languages::keep( esc_html( $village->name ) ) . '</a>' : '';
			$items  .= sprintf(
				'<li><strong>%1$s</strong>%2$s%3$s%4$s</li>',
				esc_html( $fiesta['name'] ),
				'' !== $fiesta['place'] ? ', ' . Languages::keep( esc_html( $fiesta['place'] ) ) : '',
				'' !== $where ? ', ' . $where : '',
				'' !== $fiesta['source'] ? ' <a class="gr-week__source" href="' . esc_url( $fiesta['source'] ) . '" rel="noopener">' . esc_html__( 'Source', 'guam-restaurants-core' ) . '</a>' : ''
			);
		}
		return '<h3 class="gr-week__kind">' . esc_html__( 'Village fiestas', 'guam-restaurants-core' ) . '</h3><ul class="gr-week__list">' . $items . '</ul>';
	}

	/**
	 * Markets that day.
	 *
	 * @param array<int, array<string, mixed>> $markets Markets.
	 * @return string
	 */
	private static function markets_html( array $markets ): string {
		if ( ! $markets ) {
			return '';
		}
		$items = '';
		foreach ( $markets as $market ) {
			$village = '' === $market['village'] ? null : get_term_by( 'slug', (string) $market['village'], Content_Types::TAX_VILLAGE );
			$place   = implode( ', ', array_filter( array( (string) $market['place'], $village instanceof \WP_Term ? $village->name : '' ) ) );
			$checked = '' === $market['checked'] ? '' : sprintf(
				/* translators: %s: date. */
				__( 'checked %s', 'guam-restaurants-core' ),
				Special_Hours::date_label( (string) $market['checked'] )
			);
			$items .= sprintf(
				'<li><strong>%1$s</strong>, %2$s%3$s%4$s</li>',
				Languages::keep( esc_html( (string) $market['name'] ) ),
				esc_html( Local_Events::market_hours( $market ) ),
				'' !== $place ? '. ' . Languages::keep( esc_html( $place ) ) : '',
				'' !== $market['source'] ? ' <a class="gr-week__source" href="' . esc_url( (string) $market['source'] ) . '" rel="noopener">' . esc_html__( 'Source', 'guam-restaurants-core' ) . '</a>' . ( '' !== $checked ? ' <span class="gr-week__checked">(' . esc_html( $checked ) . ')</span>' : '' ) : ''
			);
		}
		return '<h3 class="gr-week__kind">' . esc_html__( 'Markets', 'guam-restaurants-core' ) . '</h3><ul class="gr-week__list">' . $items . '</ul>';
	}

	/**
	 * Truck and pop-up stops that day.
	 *
	 * @param array<int, array<string, mixed>> $stops Stops.
	 * @return string
	 */
	private static function stops_html( array $stops ): string {
		if ( ! $stops ) {
			return '';
		}
		$items = '';
		foreach ( $stops as $stop ) {
			$items .= sprintf(
				'<li><span class="gr-week__time">%1$s–%2$s</span> <a href="%3$s">%4$s</a>, %5$s</li>',
				esc_html( Restaurant_View::time_label( (string) $stop['from'] ) ),
				esc_html( Restaurant_View::time_label( (string) $stop['to'] ) ),
				esc_url( (string) get_permalink( (int) $stop['restaurant_id'] ) ),
				Languages::keep( esc_html( get_the_title( (int) $stop['restaurant_id'] ) ) ),
				Languages::keep( esc_html( Truck_Stops::place_label( $stop ) ) )
			);
		}
		return '<h3 class="gr-week__kind">' . esc_html__( 'Food trucks and pop-ups', 'guam-restaurants-core' ) . '</h3><ul class="gr-week__list">' . $items . '</ul><p class="gr-week__note">' . esc_html__( 'From the owners, by start time.', 'guam-restaurants-core' ) . '</p>';
	}

	/**
	 * Holiday and special hours that day.
	 *
	 * @param array<int, array<string, mixed>> $hours Entries with restaurant_id.
	 * @return string
	 */
	private static function hours_html( array $hours ): string {
		if ( ! $hours ) {
			return '';
		}
		$items = '';
		foreach ( $hours as $entry ) {
			$items .= sprintf(
				'<li><a href="%1$s">%2$s</a>: %3$s%4$s</li>',
				esc_url( (string) get_permalink( (int) $entry['restaurant_id'] ) ),
				Languages::keep( esc_html( get_the_title( (int) $entry['restaurant_id'] ) ) ),
				esc_html( Special_Hours::hours_label( $entry ) ),
				'' !== $entry['note'] ? ' (' . esc_html( (string) $entry['note'] ) . ')' : ''
			);
		}
		return '<h3 class="gr-week__kind">' . esc_html__( 'Holiday and special hours', 'guam-restaurants-core' ) . '</h3><ul class="gr-week__list">' . $items . '</ul>';
	}
}
