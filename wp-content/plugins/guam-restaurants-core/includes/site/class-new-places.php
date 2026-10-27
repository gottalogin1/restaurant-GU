<?php
/**
 * New on Guam: the places that opened or were first listed each month.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Monthly pages built from data we already keep (comparison addition 15):
 *
 * - a restaurant's opening date, when it is known to the month
 *   (gr_opened_on, YYYY-MM or YYYY-MM-DD), or else
 * - the day an import first found it, when that was not the source's first
 *   run (Monitoring\Queries::recent_openings(): the starting inventory is
 *   not "new") and the place is not known to have opened in an earlier year.
 *
 * Published restaurants only, permanently closed ones left out. Newest
 * first, then by name: nothing paid changes the order (CLAUDE.md rule 5).
 */
final class New_Places {

	/**
	 * How far back first-listed dates are read, in days.
	 */
	public const HISTORY_DAYS = 3 * 365;

	/**
	 * Months with new places, newest first.
	 *
	 * @return array<string, array<int, array{id: int, date: string, opened: bool}>> YYYY-MM => places, newest first.
	 */
	public static function months(): array {
		return (array) Cache::remember( 'new-places', array(), array( self::class, 'build' ), HOUR_IN_SECONDS );
	}

	/**
	 * The places new in a month.
	 *
	 * @param string $month YYYY-MM.
	 * @return array<int, array{id: int, date: string, opened: bool}>
	 */
	public static function in_month( string $month ): array {
		return self::months()[ $month ] ?? array();
	}

	/**
	 * Builds the month lists.
	 *
	 * @return array<string, array<int, array{id: int, date: string, opened: bool}>>
	 */
	public static function build(): array {
		$places = array();

		$opened = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one query, cached for an hour.
					array(
						'key'     => Restaurant_Meta::OPENED_ON,
						'value'   => '^[0-9]{4}-[0-9]{2}',
						'compare' => 'REGEXP',
					),
				),
			)
		);
		foreach ( array_map( 'intval', $opened ) as $id ) {
			$date          = (string) get_post_meta( $id, Restaurant_Meta::OPENED_ON, true );
			$places[ $id ] = array(
				'id'     => $id,
				'date'   => strlen( $date ) >= 10 ? substr( $date, 0, 10 ) : substr( $date, 0, 7 ),
				'opened' => true,
			);
		}

		foreach ( Queries::recent_openings( self::HISTORY_DAYS ) as $row ) {
			$id = (int) $row['restaurant_id'];
			if ( isset( $places[ $id ] ) || '' === $row['url'] ) {
				continue;
			}
			$date = Clock::local_date( (string) $row['first_seen'], 'Y-m-d' );
			// Opened in an earlier year (known only to the year): found late, not new.
			$year = substr( (string) get_post_meta( $id, Restaurant_Meta::OPENED_ON, true ), 0, 4 );
			if ( '' !== $year && $year < substr( $date, 0, 4 ) ) {
				continue;
			}
			$places[ $id ] = array(
				'id'     => $id,
				'date'   => $date,
				'opened' => false,
			);
		}

		$months = array();
		foreach ( $places as $id => $place ) {
			if ( 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) || 'publish' !== get_post_status( $id ) || ! preg_match( '/^\d{4}-\d{2}/', $place['date'] ) ) {
				continue;
			}
			$months[ substr( $place['date'], 0, 7 ) ][] = $place;
		}
		krsort( $months );
		foreach ( $months as $month => $list ) {
			usort(
				$list,
				static function ( array $a, array $b ): int {
					$order = strcmp( $b['date'], $a['date'] );
					return 0 !== $order ? $order : strcasecmp( get_the_title( $a['id'] ), get_the_title( $b['id'] ) );
				}
			);
			$months[ $month ] = $list;
		}
		return $months;
	}

	/**
	 * "October 2026".
	 *
	 * @param string $month YYYY-MM.
	 * @return string
	 */
	public static function month_label( string $month ): string {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $month . '-15', Clock::zone() );
		/* translators: month and year format. See https://www.php.net/manual/datetime.format.php */
		return $day ? wp_date( __( 'F Y', 'guam-restaurants-core' ), $day->getTimestamp(), Clock::zone() ) : $month;
	}

	/**
	 * Page title.
	 *
	 * @param string $month YYYY-MM, or '' for the list of months.
	 * @return string
	 */
	public static function title( string $month = '' ): string {
		/* translators: %s: month and year, for example "October 2026". */
		return '' === $month ? __( 'New restaurants on Guam', 'guam-restaurants-core' ) : sprintf( __( 'New restaurants on Guam, %s', 'guam-restaurants-core' ), self::month_label( $month ) );
	}

	/**
	 * Meta description.
	 *
	 * @param string $month YYYY-MM, or ''.
	 * @return string
	 */
	public static function description( string $month = '' ): string {
		if ( '' === $month ) {
			return __( 'Restaurants, cafes and food spots that opened on Guam or joined this directory, month by month, with a map.', 'guam-restaurants-core' );
		}
		$count = count( self::in_month( $month ) );
		return sprintf(
			/* translators: 1: number of places, 2: month and year. */
			_n( '%1$s place opened on Guam or joined this directory in %2$s, with a map and opening hours.', '%1$s places opened on Guam or joined this directory in %2$s, with a map and opening hours.', $count, 'guam-restaurants-core' ),
			number_format_i18n( $count ),
			self::month_label( $month )
		);
	}

	/**
	 * The list of months, with the newest month's places.
	 *
	 * @return string
	 */
	public static function render_index(): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );
		$months = self::months();
		$intro  = '<p class="gr-fresh-page__intro">' . esc_html__( 'Places that opened on Guam, or that this directory first found, month by month. Newest first; nothing paid changes the order. Opened a place we missed? Tell us through Suggest a correction.', 'guam-restaurants-core' ) . '</p>';
		$recent = Pages::url( 'recent' );
		if ( '' !== $recent ) {
			$intro .= '<p class="gr-fresh-page__also">' . sprintf(
				/* translators: %s: "Recently opened and closed", linked. */
				esc_html__( 'Closures and reopenings are on %s.', 'guam-restaurants-core' ),
				'<a href="' . esc_url( $recent ) . '">' . esc_html__( 'Recently opened and closed', 'guam-restaurants-core' ) . '</a>'
			) . '</p>';
		}
		if ( ! $months ) {
			return '<div class="gr-fresh-page alignwide"><h1 class="gr-fresh-page__title">' . esc_html( self::title() ) . '</h1>' . $intro . '<p class="gr-fresh-page__empty">' . esc_html__( 'No new places recorded yet.', 'guam-restaurants-core' ) . '</p></div>';
		}
		$latest = (string) array_key_first( $months );
		$list   = '';
		foreach ( $months as $month => $places ) {
			$list .= sprintf(
				'<li><a href="%1$s">%2$s</a> <span class="gr-dish-index__count">%3$s</span></li>',
				esc_url( Fresh_Pages::url( 'new', (string) $month ) ),
				esc_html( self::month_label( (string) $month ) ),
				esc_html(
					/* translators: %s: number of places. */
					sprintf( _n( '%s place', '%s places', count( $places ), 'guam-restaurants-core' ), number_format_i18n( count( $places ) ) )
				)
			);
		}
		return '<div class="gr-fresh-page alignwide"><h1 class="gr-fresh-page__title">' . esc_html( self::title() ) . '</h1>' . $intro
			. '<h2 class="gr-section-title">' . esc_html( self::month_label( $latest ) ) . '</h2>' . self::results( $months[ $latest ] )
			. '<h2 class="gr-section-title">' . esc_html__( 'Every month', 'guam-restaurants-core' ) . '</h2><ul class="gr-dish-index" role="list">' . $list . '</ul></div>';
	}

	/**
	 * One month.
	 *
	 * @param string $month YYYY-MM.
	 * @return string
	 */
	public static function render_month( string $month ): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );
		$months = array_keys( self::months() );
		$at     = array_search( $month, $months, true );
		$links  = array( '<a href="' . esc_url( Fresh_Pages::url( 'new' ) ) . '">' . esc_html__( 'Every month', 'guam-restaurants-core' ) . '</a>' );
		if ( false !== $at && isset( $months[ $at + 1 ] ) ) {
			/* translators: %s: month and year. */
			$links[] = '<a href="' . esc_url( Fresh_Pages::url( 'new', $months[ $at + 1 ] ) ) . '" rel="prev">' . esc_html( sprintf( __( 'Earlier: %s', 'guam-restaurants-core' ), self::month_label( $months[ $at + 1 ] ) ) ) . '</a>';
		}
		if ( false !== $at && $at > 0 ) {
			/* translators: %s: month and year. */
			$links[] = '<a href="' . esc_url( Fresh_Pages::url( 'new', $months[ $at - 1 ] ) ) . '" rel="next">' . esc_html( sprintf( __( 'Later: %s', 'guam-restaurants-core' ), self::month_label( $months[ $at - 1 ] ) ) ) . '</a>';
		}
		return sprintf(
			'<div class="gr-fresh-page alignwide"><p class="gr-dish-page__back">%1$s</p><h1 class="gr-fresh-page__title">%2$s</h1><p class="gr-fresh-page__intro">%3$s</p>%4$s</div>',
			implode( ' · ', $links ),
			esc_html( self::title( $month ) ),
			esc_html__( 'Each place is listed in the month it opened, when we know it, or else the month this directory first found it. Newest first; nothing paid changes the order.', 'guam-restaurants-core' ),
			self::results( self::in_month( $month ) )
		);
	}

	/**
	 * Cards with a map, each saying why it is new.
	 *
	 * @param array<int, array{id: int, date: string, opened: bool}> $places Places.
	 * @return string
	 */
	private static function results( array $places ): string {
		Restaurant_View::prime( wp_list_pluck( $places, 'id' ) );
		$items = '';
		$pin   = 0;
		foreach ( $places as $place ) {
			$card   = Restaurant_View::card( $place['id'], 3 );
			$why    = '<p class="gr-dish-page__why">' . esc_html( self::why( $place ) ) . '</p>';
			$end    = strrpos( $card, '</div></article>' );
			$card   = false === $end ? $card . $why : substr_replace( $card, $why, $end, 0 );
			$point  = Restaurant_View::coordinates( $place['id'] );
			$items .= null === $point
				? '<li>' . $card . '</li>'
				: sprintf( '<li data-gr-pin="%1$d" data-gr-point="%2$s,%3$s">%4$s</li>', ++$pin, esc_attr( (string) $point['lng'] ), esc_attr( (string) $point['lat'] ), $card );
		}
		$count   = count( $places );
		$results = '<p class="gr-directory__summary" role="status">' . esc_html(
			/* translators: %s: number of places. */
			sprintf( _n( '%s new place', '%s new places', $count, 'guam-restaurants-core' ), number_format_i18n( $count ) )
		) . '</p><ul class="gr-cards" role="list">' . $items . '</ul>';
		return sprintf(
			'<div class="gr-directory gr-dish-page__list"><div class="gr-directory__views" role="group" aria-label="%1$s" hidden><button type="button" class="gr-directory__view" data-gr-view="list" aria-pressed="true">%2$s</button><button type="button" class="gr-directory__view" data-gr-view="map" aria-pressed="false">%3$s</button></div><div class="gr-directory__body"><div class="gr-directory__results" id="%4$s" tabindex="-1">%5$s</div><div class="gr-directory__map" data-gr-results-map role="region" aria-label="%6$s"></div></div></div>',
			esc_attr__( 'Show the list as', 'guam-restaurants-core' ),
			esc_html__( 'List', 'guam-restaurants-core' ),
			esc_html__( 'Map', 'guam-restaurants-core' ),
			esc_attr( Directory_View::RESULTS_ID ),
			$results,
			esc_attr__( 'Map of these restaurants', 'guam-restaurants-core' )
		);
	}

	/**
	 * "Opened October 2026" or "First listed here October 12, 2026".
	 *
	 * @param array{date: string, opened: bool} $place Place.
	 * @return string
	 */
	private static function why( array $place ): string {
		if ( $place['opened'] ) {
			$label = 10 === strlen( $place['date'] ) ? Special_Hours::date_label( $place['date'] ) : self::month_label( $place['date'] );
			/* translators: %s: date. */
			return sprintf( __( 'Opened %s', 'guam-restaurants-core' ), $label );
		}
		/* translators: %s: date. */
		return sprintf( __( 'First listed here %s', 'guam-restaurants-core' ), Special_Hours::date_label( $place['date'] ) );
	}
}
