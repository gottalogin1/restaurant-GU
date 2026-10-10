<?php
/**
 * Plain facts about a restaurant, formatted for display.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Formatting shared by cards, the restaurant page and the map. Every value
 * comes from our own stored facts (OSM, owners, editors); nothing from
 * Google or Yelp is read here.
 */
final class Restaurant_View {

	/**
	 * Terms of one taxonomy on a restaurant, from the object term cache.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $taxonomy      Taxonomy.
	 * @return \WP_Term[]
	 */
	public static function terms( int $restaurant_id, string $taxonomy ): array {
		$terms = get_the_terms( $restaurant_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		if ( Content_Types::TAX_MALL === $taxonomy ) {
			// get_the_terms() does not pass through get_terms_args, so hide unverified malls here.
			$terms = array_filter(
				$terms,
				static function ( \WP_Term $term ): bool {
					return \GuamRestaurants\Core\Mall_Verification::is_verified( (int) $term->term_id ) || \GuamRestaurants\Core\Mall_Verification::can_see_unverified();
				}
			);
		}
		return array_values( $terms );
	}

	/**
	 * Term names joined with commas.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $taxonomy      Taxonomy.
	 * @return string Plain text.
	 */
	public static function term_names( int $restaurant_id, string $taxonomy ): string {
		return implode( ', ', wp_list_pluck( self::terms( $restaurant_id, $taxonomy ), 'name' ) );
	}

	/**
	 * Term links joined with commas.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $taxonomy      Taxonomy.
	 * @return string HTML.
	 */
	public static function term_links( int $restaurant_id, string $taxonomy ): string {
		$links = array();
		foreach ( self::terms( $restaurant_id, $taxonomy ) as $term ) {
			$url     = get_term_link( $term );
			$links[] = is_wp_error( $url ) ? esc_html( $term->name ) : sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $term->name ) );
		}
		return implode( ', ', $links );
	}

	/**
	 * Price level term (the first, if several), or null.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return \WP_Term|null
	 */
	public static function price( int $restaurant_id ): ?\WP_Term {
		$terms = self::terms( $restaurant_id, Content_Types::TAX_PRICE );
		return $terms ? $terms[0] : null;
	}

	/**
	 * Price level as visible symbols plus an accessible description,
	 * for example "$$" read as "Price level 2 of 4".
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string HTML, or '' without a price level.
	 */
	public static function price_html( int $restaurant_id ): string {
		$term = self::price( $restaurant_id );
		if ( null === $term ) {
			return '';
		}
		$rank  = (int) get_term_meta( (int) $term->term_id, Term_Meta::RANK, true );
		$label = $rank > 0
			/* translators: 1: price level, 2: highest level. */
			? sprintf( __( 'Price level %1$d of %2$d', 'guam-restaurants-core' ), $rank, 4 )
			: $term->name;
		return sprintf( '<span class="gr-price" aria-hidden="true">%1$s</span><span class="screen-reader-text">%2$s</span>', esc_html( $term->name ), esc_html( $label ) );
	}

	/**
	 * Street address.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string Plain text.
	 */
	public static function address( int $restaurant_id ): string {
		return (string) get_post_meta( $restaurant_id, Restaurant_Meta::ADDRESS, true );
	}

	/**
	 * Coordinates, or null when not set (0 means unset; Guam is nowhere near 0,0).
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{lat: float, lng: float}|null
	 */
	public static function coordinates( int $restaurant_id ): ?array {
		$lat = get_post_meta( $restaurant_id, Restaurant_Meta::LATITUDE, true );
		$lng = get_post_meta( $restaurant_id, Restaurant_Meta::LONGITUDE, true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || ( 0.0 === (float) $lat && 0.0 === (float) $lng ) ) {
			return null;
		}
		return array(
			'lat' => (float) $lat,
			'lng' => (float) $lng,
		);
	}

	/**
	 * Phone number for display: "(671) 555-0123" from "+16715550123".
	 *
	 * @param string $e164 E.164 number.
	 * @return string
	 */
	public static function format_phone( string $e164 ): string {
		if ( 1 === preg_match( '/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m ) ) {
			return sprintf( '(%1$s) %2$s-%3$s', $m[1], $m[2], $m[3] );
		}
		return $e164;
	}

	/**
	 * Host name of a website for display, without "www.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function website_label( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return '' === $host ? $url : (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Status notice for anything but open or unknown, as a plain fact.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string Plain text, or ''.
	 */
	public static function status_label( int $restaurant_id ): string {
		switch ( (string) get_post_meta( $restaurant_id, Restaurant_Meta::STATUS, true ) ) {
			case 'temporarily_closed':
				return __( 'Temporarily closed', 'guam-restaurants-core' );
			case 'permanently_closed':
				$closed = self::partial_date( (string) get_post_meta( $restaurant_id, Restaurant_Meta::CLOSED_ON, true ) );
				/* translators: %s: date, for example "March 2024". */
				return '' === $closed ? __( 'Closed', 'guam-restaurants-core' ) : sprintf( __( 'Closed in %s', 'guam-restaurants-core' ), $closed );
			default:
				return '';
		}
	}

	/**
	 * A YYYY, YYYY-MM or YYYY-MM-DD date in words.
	 *
	 * @param string $date Partial date.
	 * @return string
	 */
	public static function partial_date( string $date ): string {
		if ( 1 === preg_match( '/^\d{4}$/', $date ) ) {
			return $date;
		}
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})$/', $date, $m ) ) {
			return wp_date( 'F Y', (int) gmmktime( 12, 0, 0, (int) $m[2], 1, (int) $m[1] ), new \DateTimeZone( 'UTC' ) );
		}
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return wp_date( (string) get_option( 'date_format', 'F j, Y' ), (int) gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ), new \DateTimeZone( 'UTC' ) );
		}
		return '';
	}

	/**
	 * Opening hours by day, Monday first. Null when no hours are known.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{always_open: bool, days: array<string, string[]>, note: string}|null Day key => "11:00–21:00" ranges.
	 */
	public static function hours_by_day( int $restaurant_id ): ?array {
		$hours = get_post_meta( $restaurant_id, Restaurant_Meta::HOURS, true );
		if ( ! is_array( $hours ) || ( empty( $hours['weekly'] ) && empty( $hours['always_open'] ) ) ) {
			return null;
		}
		$days = array_fill_keys( Vocabulary::DAYS, array() );
		foreach ( (array) ( $hours['weekly'] ?? array() ) as $interval ) {
			$day = (string) ( $interval['day'] ?? '' );
			if ( isset( $days[ $day ] ) ) {
				$days[ $day ][] = self::time_label( (string) $interval['open'] ) . '–' . self::time_label( (string) $interval['close'] );
			}
		}
		return array(
			'always_open' => ! empty( $hours['always_open'] ),
			'days'        => $days,
			'note'        => (string) ( $hours['note'] ?? '' ),
		);
	}

	/**
	 * Day names, Monday first.
	 *
	 * @return array<string, string>
	 */
	public static function day_names(): array {
		return array(
			'mo' => __( 'Monday', 'guam-restaurants-core' ),
			'tu' => __( 'Tuesday', 'guam-restaurants-core' ),
			'we' => __( 'Wednesday', 'guam-restaurants-core' ),
			'th' => __( 'Thursday', 'guam-restaurants-core' ),
			'fr' => __( 'Friday', 'guam-restaurants-core' ),
			'sa' => __( 'Saturday', 'guam-restaurants-core' ),
			'su' => __( 'Sunday', 'guam-restaurants-core' ),
		);
	}

	/**
	 * "13:30" as "1:30 PM" in the site's time format.
	 *
	 * @param string $time HH:MM.
	 * @return string
	 */
	public static function time_label( string $time ): string {
		if ( '24:00' === $time ) {
			$time = '00:00';
		}
		if ( 1 !== preg_match( '/^(\d{2}):(\d{2})$/', $time, $m ) ) {
			return $time;
		}
		return wp_date( (string) get_option( 'time_format', 'g:i a' ), (int) gmmktime( (int) $m[1], (int) $m[2], 0, 1, 1, 2024 ), new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * A directory card.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $heading_level Heading level for the name.
	 * @return string HTML.
	 */
	public static function card( int $restaurant_id, int $heading_level = 3 ): string {
		$heading_level = max( 2, min( 6, $heading_level ) );
		$meta          = array_filter(
			array(
				esc_html( self::term_names( $restaurant_id, Content_Types::TAX_CUISINE ) ),
				esc_html( implode( ', ', array_filter( array( self::term_names( $restaurant_id, Content_Types::TAX_VILLAGE ), self::term_names( $restaurant_id, Content_Types::TAX_MALL ) ) ) ) ),
				self::price_html( $restaurant_id ),
			)
		);
		$hours         = Open_Hours::compact( $restaurant_id );
		$status        = self::status_label( $restaurant_id );

		return sprintf(
			'<article class="gr-card"><h%1$d class="gr-card__name"><a href="%2$s">%3$s</a></h%1$d>%4$s%5$s%6$s</article>',
			$heading_level,
			esc_url( (string) get_permalink( $restaurant_id ) ),
			esc_html( get_the_title( $restaurant_id ) ),
			$meta ? '<p class="gr-card__meta">' . implode( ' <span aria-hidden="true">·</span> ', $meta ) . '</p>' : '',
			'' !== self::address( $restaurant_id ) ? '<p class="gr-card__address">' . esc_html( self::address( $restaurant_id ) ) . '</p>' : '',
			'' !== $status
				? '<p class="gr-card__status">' . esc_html( $status ) . '</p>'
				: ( '' !== $hours ? '<p class="gr-card__open" data-gr-hours="' . esc_attr( $hours ) . '"></p>' : '' )
		);
	}
}
