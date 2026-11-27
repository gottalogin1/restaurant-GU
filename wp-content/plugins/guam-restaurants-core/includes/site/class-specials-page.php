<?php
/**
 * Specials and happy hours on Guam: the page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Owners\Specials;
use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * /specials/ (Fresh_Pages) lists what verified owners have posted
 * (Owners\Specials), by restaurant:
 *
 * - `when`: `today` (the default), `now` (a timed special running now) or
 *   `week` (any of the next seven days);
 * - `type`: '' for both, `happy-hour` or `special`;
 * - `village`: a village slug.
 *
 * The plain page is indexed once enough restaurants post specials
 * (indexable()); a filtered view never is (Seo, `filtered`).
 * "On now" changes by the minute, so the page tells page caches not to
 * keep it (DONOTCACHEPAGE; docs/performance.md §2).
 */
final class Specials_Page {

	public const WHEN    = 'when';
	public const TYPE    = 'type';
	public const VILLAGE = 'village';

	public const VIEWS = array( 'today', 'now', 'week' );

	/**
	 * URL type value => Specials kind.
	 */
	public const TYPES = array(
		'happy-hour' => Specials::KIND_HAPPY_HOUR,
		'special'    => Specials::KIND_SPECIAL,
	);

	/**
	 * The filters asked for, cleaned.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @return array{when: string, type: string, village: string}
	 */
	public static function filters( array $params ): array {
		$when    = sanitize_key( (string) ( $params[ self::WHEN ] ?? '' ) );
		$type    = sanitize_key( (string) ( $params[ self::TYPE ] ?? '' ) );
		$village = sanitize_title( (string) ( $params[ self::VILLAGE ] ?? '' ) );
		return array(
			'when'    => in_array( $when, self::VIEWS, true ) ? $when : 'today',
			'type'    => isset( self::TYPES[ $type ] ) ? $type : '',
			'village' => '' !== $village && term_exists( $village, Content_Types::TAX_VILLAGE ) ? $village : '',
		);
	}

	/**
	 * The filters on this request.
	 *
	 * @return array{when: string, type: string, village: string}
	 */
	public static function current_filters(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return self::filters( wp_unslash( $_GET ) );
	}

	/**
	 * Whether the request has any filter in it.
	 *
	 * @return bool
	 */
	public static function is_filtered(): bool {
		return array( 'today', '', '' ) !== array_values( self::current_filters() );
	}

	/**
	 * Whether the plain page is worth indexing: Cuisine_Villages::MIN_PLACES
	 * restaurants with specials still to come, as for other list pages.
	 *
	 * @return bool
	 */
	public static function indexable(): bool {
		return count( Specials::collect() ) >= Cuisine_Villages::MIN_PLACES;
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title(): string {
		return __( 'Specials and happy hours on Guam', 'guam-restaurants-core' );
	}

	/**
	 * Meta description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'Today\'s food specials and happy hours at Guam restaurants, posted by the owners themselves. See what is on now, today or this week, by village.', 'guam-restaurants-core' );
	}

	/**
	 * URL with filters.
	 *
	 * @param array<string, string> $filters when, type, village.
	 * @return string
	 */
	public static function url( array $filters ): string {
		$args = array_filter(
			array(
				self::WHEN    => 'today' === ( $filters['when'] ?? 'today' ) ? '' : (string) $filters['when'],
				self::TYPE    => (string) ( $filters['type'] ?? '' ),
				self::VILLAGE => (string) ( $filters['village'] ?? '' ),
			)
		);
		return $args ? add_query_arg( $args, Fresh_Pages::url( 'specials' ) ) : Fresh_Pages::url( 'specials' );
	}

	/**
	 * The page.
	 *
	 * @return string
	 */
	public static function render(): string {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- page cache convention.
		}
		Assets::enqueue();
		$filters = self::current_filters();
		$results = Specials::find( $filters['when'], '' === $filters['type'] ? '' : self::TYPES[ $filters['type'] ], $filters['village'] );
		$body    = '';
		foreach ( $results as $result ) {
			$body .= self::place_html( $result['restaurant_id'], $result['specials'] );
		}
		if ( '' === $body ) {
			$body = '<p class="gr-fresh-page__empty">' . esc_html( self::empty_text( $filters ) ) . '</p>';
		}
		$claim = Pages::url( 'claim' );
		return sprintf(
			'<div class="gr-fresh-page gr-specials alignwide"><h1 class="gr-fresh-page__title">%1$s</h1><p class="gr-fresh-page__intro">%2$s</p>%3$s<p class="gr-specials__count" role="status">%4$s</p><div class="gr-specials__places">%5$s</div><p class="gr-week__note">%6$s</p><p class="gr-week__owners">%7$s</p></div>',
			esc_html( self::title() ),
			esc_html__( 'Food specials and happy hours posted by the owners of Guam restaurants. Times are Guam time. Places are listed by what is on now, then by start time and name; nobody pays to be listed higher. Owners can change plans, so check with the restaurant before you go.', 'guam-restaurants-core' ),
			self::filters_html( $filters ),
			esc_html(
				/* translators: %s: number of restaurants. */
				sprintf( _n( '%s restaurant', '%s restaurants', count( $results ), 'guam-restaurants-core' ), number_format_i18n( count( $results ) ) )
			),
			$body,
			sprintf(
				/* translators: %s: "This week on Guam", linked. */
				esc_html__( 'Looking for night markets, food trucks and fiestas? See %s.', 'guam-restaurants-core' ),
				'<a href="' . esc_url( Fresh_Pages::url( 'week' ) ) . '">' . esc_html__( 'This week on Guam', 'guam-restaurants-core' ) . '</a>'
			),
			'' === $claim
				? esc_html__( 'Own a restaurant? Verified owners can post specials and happy hours from their dashboard, free.', 'guam-restaurants-core' )
				: sprintf(
					/* translators: %s: "Claim your listing", linked. */
					esc_html__( 'Own a restaurant? %s to post your specials and happy hours here, free.', 'guam-restaurants-core' ),
					'<a href="' . esc_url( $claim ) . '">' . esc_html__( 'Claim your listing', 'guam-restaurants-core' ) . '</a>'
				)
		);
	}

	/**
	 * One special as a list item. Used here and in the owner block.
	 *
	 * @param array<string, mixed> $special Normalized special, with `on_now`.
	 * @return string
	 */
	public static function special_html( array $special ): string {
		$kind  = (string) $special['kind'];
		$badge = '' === $special['title'] ? '' : '<span class="gr-special__kind gr-special__kind--' . esc_attr( str_replace( '_', '-', $kind ) ) . '">' . esc_html( Specials::kind_labels()[ $kind ] ) . '</span> ';
		$dates = Specials::dates_label( $special );
		return sprintf(
			'<li class="gr-special%1$s">%2$s<strong class="gr-special__title">%3$s</strong>%4$s<br><span class="gr-special__when">%5$s</span>%6$s%7$s</li>',
			empty( $special['on_now'] ) ? '' : ' gr-special--on-now',
			$badge,
			esc_html( Specials::title( $special ) ),
			empty( $special['on_now'] ) ? '' : ' <span class="gr-special__on-now">' . esc_html__( 'On now', 'guam-restaurants-core' ) . '</span>',
			esc_html( Specials::when_label( $special ) ),
			'' === $dates ? '' : ' <span class="gr-special__dates"><span aria-hidden="true">· </span>' . esc_html( $dates ) . '</span>',
			'' === $special['details'] ? '' : '<span class="gr-special__details">' . esc_html( (string) $special['details'] ) . '</span>'
		);
	}

	/**
	 * A restaurant and its specials.
	 *
	 * @param int                              $restaurant_id Restaurant.
	 * @param array<int, array<string, mixed>> $specials      Specials with `on_now`.
	 * @return string
	 */
	private static function place_html( int $restaurant_id, array $specials ): string {
		$items = '';
		foreach ( $specials as $special ) {
			$items .= self::special_html( $special );
		}
		$villages = get_the_terms( $restaurant_id, Content_Types::TAX_VILLAGE );
		$where    = is_array( $villages ) && $villages ? implode( ', ', wp_list_pluck( $villages, 'name' ) ) : '';
		return sprintf(
			'<article class="gr-specials__place"><h2 class="gr-specials__name"><a href="%1$s">%2$s</a></h2>%3$s<ul class="gr-specials__list">%4$s</ul></article>',
			esc_url( (string) get_permalink( $restaurant_id ) ),
			Languages::keep( esc_html( get_the_title( $restaurant_id ) ) ),
			'' === $where ? '' : '<p class="gr-specials__where">' . Languages::keep( esc_html( $where ) ) . '</p>',
			$items
		);
	}

	/**
	 * The filter links and the village picker.
	 *
	 * @param array{when: string, type: string, village: string} $filters Filters.
	 * @return string
	 */
	private static function filters_html( array $filters ): string {
		$group    = static function ( string $key, array $options, string $label ) use ( $filters ): string {
			$links = '';
			foreach ( $options as $value => $text ) {
				$current = $filters[ $key ] === $value;
				$links  .= sprintf(
					'<li><a class="gr-chip%1$s" href="%2$s"%3$s>%4$s</a></li>',
					$current ? ' gr-chip--current' : '',
					esc_url( self::url( array( $key => $value ) + $filters ) ),
					$current ? ' aria-current="page"' : '',
					esc_html( $text )
				);
			}
			return '<ul class="gr-specials__chips" aria-label="' . esc_attr( $label ) . '">' . $links . '</ul>';
		};
		$html     = $group(
			'when',
			array(
				'today' => __( 'Today', 'guam-restaurants-core' ),
				'now'   => __( 'On now', 'guam-restaurants-core' ),
				'week'  => __( 'Next 7 days', 'guam-restaurants-core' ),
			),
			__( 'When', 'guam-restaurants-core' )
		);
		$html    .= $group(
			'type',
			array(
				''           => __( 'Specials and happy hours', 'guam-restaurants-core' ),
				'happy-hour' => __( 'Happy hours', 'guam-restaurants-core' ),
				'special'    => __( 'Food specials', 'guam-restaurants-core' ),
			),
			__( 'Type', 'guam-restaurants-core' )
		);
		$villages = Specials::villages();
		if ( count( $villages ) > 1 || '' !== $filters['village'] ) {
			$options = '<option value="">' . esc_html__( 'All villages', 'guam-restaurants-core' ) . '</option>';
			foreach ( $villages as $village ) {
				$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $village->slug ), selected( $filters['village'], $village->slug, false ), Languages::keep( esc_html( $village->name ) ) );
			}
			$hidden = '';
			foreach ( array(
				self::WHEN => 'today' === $filters['when'] ? '' : $filters['when'],
				self::TYPE => $filters['type'],
			) as $name => $value ) {
				if ( '' !== $value ) {
					$hidden .= sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( $value ) );
				}
			}
			$html .= sprintf(
				'<form class="gr-specials__village" method="get" action="%1$s">%2$s<label for="gr-specials-village">%3$s</label> <select id="gr-specials-village" name="%4$s">%5$s</select> <button type="submit" class="wp-element-button">%6$s</button></form>',
				esc_url( Fresh_Pages::url( 'specials' ) ),
				$hidden,
				esc_html__( 'Village', 'guam-restaurants-core' ),
				esc_attr( self::VILLAGE ),
				$options,
				esc_html__( 'Show', 'guam-restaurants-core' )
			);
		}
		return '<nav class="gr-specials__filters" aria-label="' . esc_attr__( 'Filter specials', 'guam-restaurants-core' ) . '">' . $html . '</nav>';
	}

	/**
	 * What to say when nothing matches.
	 *
	 * @param array{when: string, type: string, village: string} $filters Filters.
	 * @return string
	 */
	private static function empty_text( array $filters ): string {
		if ( 'now' === $filters['when'] ) {
			return __( 'Nothing with set hours is on right now. Try Today or Next 7 days.', 'guam-restaurants-core' );
		}
		if ( 'week' === $filters['when'] ) {
			return __( 'No specials or happy hours are listed for the next seven days yet.', 'guam-restaurants-core' );
		}
		return __( 'No specials or happy hours are listed for today yet. Try Next 7 days.', 'guam-restaurants-core' );
	}
}
