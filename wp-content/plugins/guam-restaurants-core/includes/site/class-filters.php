<?php
/**
 * Directory filters from shareable URL parameters.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the directory's URL parameters, for example
 * `/restaurants/?cuisine=japanese,korean&village=tamuning&price=2&open=now&sort=price_asc`.
 *
 * - Facets (`village`, `mall`, `cuisine`, `price`) take term slugs,
 *   comma-separated or as an array (`cuisine[]=a&cuisine[]=b`, which is
 *   what the form sends without JavaScript). Several values in one facet
 *   match any of them; different facets must all match. Unknown slugs and
 *   unverified malls are dropped.
 * - `award=winner` keeps restaurants with at least one recorded win.
 * - `open=now` keeps restaurants open at this minute, Guam time.
 * - `late=tonight` keeps restaurants open at 10 pm tonight, Guam time
 *   (Clock::late_minute()).
 * - `at=sa-0730` keeps restaurants open at that day and time, Guam time.
 *   The day is a Vocabulary::DAYS key, `today` or `tomorrow`. Without
 *   JavaScript the form sends the two halves as `at_day` and `at_time`.
 * - `meal=breakfast` keeps restaurants open by 8 am on at least one day,
 *   going by their listed hours (BREAKFAST_MINUTE).
 * - `q` is a plain-language search: Query_Parser turns the phrases it
 *   knows ("cheap Korean open late in Tumon") into the filters above and
 *   leaves the other words as a keyword search. What it read is kept in
 *   `read` for the "We read your search as" line; it is not a parameter.
 * - `sort` is one of SORTS. Sponsored placements never change it
 *   (CLAUDE.md rule 5).
 *
 * The names avoid WordPress's public query vars (`s`, `year`, `order`...).
 */
final class Filters {

	public const SEARCH = 'q';
	public const AWARD  = 'award';
	public const OPEN   = 'open';
	public const LATE   = 'late';
	public const AT     = 'at';
	public const MEAL   = 'meal';
	public const SORT   = 'sort';
	public const WHERE  = 'where';

	public const AT_DAY  = 'at_day';
	public const AT_TIME = 'at_time';

	public const AWARD_WINNER   = 'winner';
	public const OPEN_NOW       = 'now';
	public const LATE_TONIGHT   = 'tonight';
	public const MEAL_BREAKFAST = 'breakfast';

	/**
	 * "Breakfast" means open at this minute of the day (8:00 am) on at least one day.
	 */
	public const BREAKFAST_MINUTE = 480;

	/**
	 * Days `at` accepts besides Vocabulary::DAYS; these depend on today's date.
	 */
	public const RELATIVE_DAYS = array( 'today', 'tomorrow' );

	/**
	 * URL parameter => taxonomy.
	 */
	public const FACETS = array(
		'village' => Content_Types::TAX_VILLAGE,
		'mall'    => Content_Types::TAX_MALL,
		'cuisine' => Content_Types::TAX_CUISINE,
		'price'   => Content_Types::TAX_PRICE,
	);

	public const SORTS = array( 'relevance', 'name', 'newest', 'price_asc', 'price_desc' );

	public const MAX_TERMS_PER_FACET = 20;
	public const MAX_SEARCH_LENGTH   = 100;

	/**
	 * Empty filters.
	 *
	 * @return array{q: string, facets: array<string, string[]>, award: bool, open: bool, late: bool, at: string, breakfast: bool, sort: string, page: int, read: array{text: string, matches: array<int, array{type: string, value: string, phrase: string}>}|null}
	 */
	public static function defaults(): array {
		return array(
			'q'         => '',
			'facets'    => array_fill_keys( array_keys( self::FACETS ), array() ),
			'award'     => false,
			'open'      => false,
			'late'      => false,
			'at'        => '',
			'breakfast' => false,
			'sort'      => '',
			'page'      => 1,
			'read'      => null,
		);
	}

	/**
	 * Filters from request parameters.
	 *
	 * @param array<string, mixed>  $params Raw parameters (for example $_GET, unslashed).
	 * @param array<string, string> $locked Facet => slug fixed by the page (a village's own page).
	 * @return array<string, mixed> See defaults().
	 */
	public static function from_params( array $params, array $locked = array() ): array {
		$filters = self::defaults();

		$q            = isset( $params[ self::SEARCH ] ) && is_scalar( $params[ self::SEARCH ] ) ? sanitize_text_field( (string) $params[ self::SEARCH ] ) : '';
		$filters['q'] = mb_substr( trim( $q ), 0, self::MAX_SEARCH_LENGTH );

		// The home page search sends one place as "where": village:<slug> or mall:<slug>.
		$where = isset( $params[ self::WHERE ] ) && is_scalar( $params[ self::WHERE ] ) ? (string) $params[ self::WHERE ] : '';
		if ( 1 === preg_match( '/^(village|mall):([a-z0-9\-]+)$/', $where, $m ) && empty( $params[ $m[1] ] ) ) {
			$params[ $m[1] ] = $m[2];
		}

		$parsed = null;
		if ( '' !== $filters['q'] && ! Query_Parser::is_literal( $filters['q'] ) ) {
			$parsed = Query_Parser::parse( $filters['q'] );
			if ( $parsed['matches'] ) {
				foreach ( $parsed['facets'] as $param => $slugs ) {
					if ( $slugs ) {
						$params[ $param ] = array_merge( self::slugs( $params[ $param ] ?? array() ), $slugs );
					}
				}
				$filters['read'] = array(
					'text'    => $filters['q'],
					'matches' => $parsed['matches'],
				);
				$filters['q']    = $parsed['search'];
			} else {
				$parsed = null;
			}
		}

		foreach ( self::FACETS as $param => $taxonomy ) {
			$slugs = self::slugs( $params[ $param ] ?? array() );
			if ( isset( $locked[ $param ] ) ) {
				$slugs = array( sanitize_title( $locked[ $param ] ) );
			}
			$filters['facets'][ $param ] = self::known_slugs( $taxonomy, $slugs );
		}

		$filters['award'] = self::AWARD_WINNER === ( $params[ self::AWARD ] ?? '' ) || '1' === ( $params[ self::AWARD ] ?? '' );
		$filters['open']  = self::OPEN_NOW === ( $params[ self::OPEN ] ?? '' ) || '1' === ( $params[ self::OPEN ] ?? '' );
		$filters['late']  = self::LATE_TONIGHT === ( $params[ self::LATE ] ?? '' ) || '1' === ( $params[ self::LATE ] ?? '' );
		if ( $parsed ) {
			foreach ( array( 'award', 'open', 'late', 'breakfast' ) as $flag ) {
				$filters[ $flag ] = $filters[ $flag ] || $parsed[ $flag ];
			}
		}
		$filters['breakfast'] = $filters['breakfast'] || self::MEAL_BREAKFAST === ( $params[ self::MEAL ] ?? '' );

		$at = isset( $params[ self::AT ] ) && is_scalar( $params[ self::AT ] ) ? (string) $params[ self::AT ] : '';
		if ( '' === $at && isset( $params[ self::AT_DAY ], $params[ self::AT_TIME ] ) && is_scalar( $params[ self::AT_DAY ] ) && is_scalar( $params[ self::AT_TIME ] ) ) {
			$at = $params[ self::AT_DAY ] . '-' . $params[ self::AT_TIME ];
		}
		$filters['at'] = null === self::at_parts( $at ) ? '' : $at;

		$sort            = isset( $params[ self::SORT ] ) && is_scalar( $params[ self::SORT ] ) ? sanitize_key( (string) $params[ self::SORT ] ) : '';
		$filters['sort'] = in_array( $sort, self::SORTS, true ) ? $sort : '';
		if ( 'relevance' === $filters['sort'] && '' === $filters['q'] ) {
			$filters['sort'] = '';
		}

		$filters['page'] = max( 1, isset( $params['page'] ) && is_scalar( $params['page'] ) ? absint( $params['page'] ) : 1 );
		return $filters;
	}

	/**
	 * The sort in effect: the chosen one, else relevance for a search, else name.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	public static function effective_sort( array $filters ): string {
		if ( '' !== (string) ( $filters['sort'] ?? '' ) ) {
			return (string) $filters['sort'];
		}
		return '' !== (string) ( $filters['q'] ?? '' ) ? 'relevance' : 'name';
	}

	/**
	 * Canonical URL parameters, leaving out empty values, the page number and
	 * any facet the page itself fixes.
	 *
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug fixed by the page.
	 * @return array<string, string>
	 */
	public static function to_params( array $filters, array $locked = array() ): array {
		$params = array();
		if ( '' !== (string) $filters['q'] ) {
			$params[ self::SEARCH ] = (string) $filters['q'];
		}
		foreach ( array_keys( self::FACETS ) as $param ) {
			if ( isset( $locked[ $param ] ) || empty( $filters['facets'][ $param ] ) ) {
				continue;
			}
			$params[ $param ] = implode( ',', $filters['facets'][ $param ] );
		}
		if ( ! empty( $filters['award'] ) ) {
			$params[ self::AWARD ] = self::AWARD_WINNER;
		}
		if ( ! empty( $filters['open'] ) ) {
			$params[ self::OPEN ] = self::OPEN_NOW;
		}
		if ( ! empty( $filters['late'] ) ) {
			$params[ self::LATE ] = self::LATE_TONIGHT;
		}
		if ( '' !== (string) ( $filters['at'] ?? '' ) ) {
			$params[ self::AT ] = (string) $filters['at'];
		}
		if ( ! empty( $filters['breakfast'] ) ) {
			$params[ self::MEAL ] = self::MEAL_BREAKFAST;
		}
		if ( '' !== (string) $filters['sort'] ) {
			$params[ self::SORT ] = (string) $filters['sort'];
		}
		return $params;
	}

	/**
	 * Whether the results change with the clock ("open now", "open late",
	 * "open today at..."), so they may be cached for a minute only. A fixed
	 * day and time ("open Saturday at 7:30 am") is not timed.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return bool
	 */
	public static function is_timed( array $filters ): bool {
		$at = self::at_parts( (string) ( $filters['at'] ?? '' ) );
		return ! empty( $filters['open'] ) || ! empty( $filters['late'] ) || ( null !== $at && in_array( $at['day'], self::RELATIVE_DAYS, true ) );
	}

	/**
	 * Day and minute of an `at` value such as "sa-0730" or "today-2000".
	 *
	 * @param string $at Value.
	 * @return array{day: string, minute: int}|null Null when it is not valid.
	 */
	public static function at_parts( string $at ): ?array {
		$days = implode( '|', array_merge( Vocabulary::DAYS, self::RELATIVE_DAYS ) );
		if ( 1 !== preg_match( '/^(' . $days . ')-([01]\d|2[0-3])([0-5]\d)$/', $at, $m ) ) {
			return null;
		}
		return array(
			'day'    => $m[1],
			'minute' => (int) $m[2] * 60 + (int) $m[3],
		);
	}

	/**
	 * Minute of the week an `at` value stands for, Guam time.
	 *
	 * @param string   $at        Value.
	 * @param int|null $timestamp Now, for today and tomorrow; defaults to Clock::now().
	 * @return int|null Null when it is not valid.
	 */
	public static function at_minute( string $at, ?int $timestamp = null ): ?int {
		$parts = self::at_parts( $at );
		if ( null === $parts ) {
			return null;
		}
		$day = array_search( $parts['day'], Vocabulary::DAYS, true );
		if ( false === $day ) {
			$day = intdiv( Clock::minute_of_week( $timestamp ), Clock::MINUTES_PER_DAY ) + ( 'tomorrow' === $parts['day'] ? 1 : 0 );
		}
		return ( (int) $day % 7 ) * Clock::MINUTES_PER_DAY + $parts['minute'];
	}

	/**
	 * An `at` value in words: "today at 8:00 pm", "Saturday at 7:30 am".
	 *
	 * @param string $at Value.
	 * @return string '' when it is not valid.
	 */
	public static function at_label( string $at ): string {
		$parts = self::at_parts( $at );
		if ( null === $parts ) {
			return '';
		}
		$days = array(
			'today'    => __( 'today', 'guam-restaurants-core' ),
			'tomorrow' => __( 'tomorrow', 'guam-restaurants-core' ),
		) + Restaurant_View::day_names();
		$time = Restaurant_View::time_label( sprintf( '%02d:%02d', intdiv( $parts['minute'], 60 ), $parts['minute'] % 60 ) );
		/* translators: 1: day, for example "Saturday" or "today", 2: time, for example "7:30 am". */
		return sprintf( __( '%1$s at %2$s', 'guam-restaurants-core' ), $days[ $parts['day'] ], $time );
	}

	/**
	 * Whether any filter beyond the page's own fixed facet is chosen.
	 *
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug fixed by the page.
	 * @return bool
	 */
	public static function is_filtered( array $filters, array $locked = array() ): bool {
		return array() !== self::to_params( $filters, $locked );
	}

	/**
	 * Slugs from a comma-separated string or an array.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function slugs( $value ): array {
		if ( is_scalar( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$slugs = array();
		foreach ( $value as $slug ) {
			if ( is_scalar( $slug ) ) {
				$slug = sanitize_title( (string) $slug );
				if ( '' !== $slug ) {
					$slugs[ $slug ] = $slug;
				}
			}
		}
		return array_slice( array_values( $slugs ), 0, self::MAX_TERMS_PER_FACET );
	}

	/**
	 * Keeps slugs of terms that exist and are visible (unverified malls are
	 * hidden by Mall_Verification), in a stable order.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param string[] $slugs    Slugs.
	 * @return string[]
	 */
	private static function known_slugs( string $taxonomy, array $slugs ): array {
		if ( ! $slugs ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => $slugs,
				'hide_empty' => false,
				'fields'     => 'id=>slug',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$known = array_values( array_intersect( $slugs, array_values( $terms ) ) );
		sort( $known );
		return $known;
	}
}
