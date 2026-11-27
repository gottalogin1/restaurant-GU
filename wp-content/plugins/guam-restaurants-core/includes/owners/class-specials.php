<?php
/**
 * Specials and happy hours that verified owners post.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Owners;

use GuamRestaurants\Core\Ads\Guard;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Cache;
use GuamRestaurants\Core\Site\Clock;
use GuamRestaurants\Core\Site\Restaurant_View;
use GuamRestaurants\Core\Site\Special_Hours;
use GuamRestaurants\Core\Vocabulary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A special is stored in the restaurant's gr_specials meta (Owner_Content::
 * SPECIALS) as:
 *
 * - `kind`: `special` or `happy_hour`;
 * - `title` and `details` in the owner's words (a happy hour may leave the
 *   title empty; it is then shown as "Happy hour");
 * - `days`: day keys (Vocabulary::DAYS); empty means every day;
 * - `from` and `to`: HH:MM, Guam time, both empty for all day. A `to` at
 *   or before `from` runs past midnight. A happy hour needs times;
 * - `starts` and `ends`: Y-m-d, either may be empty.
 *
 * Specials saved before kinds, days and times existed have only title,
 * details and ends; normalize() reads them as an all-day special on every
 * day.
 *
 * Specials are owner content. They are shown with "From the owner" and on
 * the Specials page (Site\Specials_Page), ordered by time and name only;
 * a premium profile or an ad never moves them (CLAUDE.md rule 5).
 */
final class Specials {

	public const KIND_SPECIAL    = 'special';
	public const KIND_HAPPY_HOUR = 'happy_hour';
	public const KINDS           = array( self::KIND_SPECIAL, self::KIND_HAPPY_HOUR );

	public const MAX         = 8;
	public const MAX_TITLE   = 80;
	public const MAX_DETAILS = 200;

	/**
	 * How far ahead a special may start, in days.
	 */
	public const MAX_DAYS_AHEAD = 366;

	/**
	 * Kind labels.
	 *
	 * @return array<string, string>
	 */
	public static function kind_labels(): array {
		return array(
			self::KIND_SPECIAL    => __( 'Special', 'guam-restaurants-core' ),
			self::KIND_HAPPY_HOUR => __( 'Happy hour', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Specials from the owner form. A row is dropped when it has no title
	 * and no times; a happy hour without a title keeps an empty title.
	 *
	 * @param array<int, mixed> $rows  Rows of kind, title, details, days[], from, to, starts, ends.
	 * @param string|null       $today Y-m-d, Guam.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function from_input( array $rows, ?string $today = null ) {
		$today  = $today ?? Clock::today();
		$out    = array();
		$errors = new WP_Error();
		foreach ( $rows as $row ) {
			$row     = (array) $row;
			$kind    = in_array( (string) ( $row['kind'] ?? '' ), self::KINDS, true ) ? (string) $row['kind'] : self::KIND_SPECIAL;
			$title   = mb_substr( sanitize_text_field( (string) ( $row['title'] ?? '' ) ), 0, self::MAX_TITLE );
			$details = mb_substr( sanitize_textarea_field( (string) ( $row['details'] ?? '' ) ), 0, self::MAX_DETAILS );
			$from    = trim( (string) ( $row['from'] ?? '' ) );
			$to      = trim( (string) ( $row['to'] ?? '' ) );
			if ( '' === $title && ( '' === $from || self::KIND_SPECIAL === $kind ) ) {
				continue;
			}
			$label = '' === $title ? self::kind_labels()[ $kind ] : $title;

			$from_time = self::time( $from );
			$to_time   = self::time( $to );
			if ( ( '' !== $from && null === $from_time ) || ( '' !== $to && null === $to_time ) || ( '' === $from ) !== ( '' === $to ) || ( '' !== $from && $from_time === $to_time ) ) {
				/* translators: %s: the special's title. */
				$errors->add( 'gr_special_time', sprintf( __( '"%s": give both a start and an end time, like 16:00 and 19:00 (24-hour clock), or leave both empty for all day.', 'guam-restaurants-core' ), $label ) );
				continue;
			}
			if ( self::KIND_HAPPY_HOUR === $kind && '' === $from ) {
				/* translators: %s: the special's title. */
				$errors->add( 'gr_special_time', sprintf( __( '"%s": a happy hour needs a start and an end time.', 'guam-restaurants-core' ), $label ) );
				continue;
			}

			$starts = self::date( (string) ( $row['starts'] ?? '' ) );
			$ends   = self::date( (string) ( $row['ends'] ?? '' ) );
			if ( null === $starts || null === $ends ) {
				/* translators: %s: the special's title. */
				$errors->add( 'gr_owner_special_date', sprintf( __( '"%s": use valid dates, or leave them empty.', 'guam-restaurants-core' ), $label ) );
				continue;
			}
			if ( '' !== $starts && '' !== $ends && $ends < $starts ) {
				/* translators: %s: the special's title. */
				$errors->add( 'gr_owner_special_date', sprintf( __( '"%s": the end date is before the start date.', 'guam-restaurants-core' ), $label ) );
				continue;
			}
			if ( '' !== $starts && $starts > Special_Hours::shift( $today, self::MAX_DAYS_AHEAD ) ) {
				/* translators: %s: the special's title. */
				$errors->add( 'gr_owner_special_date', sprintf( __( '"%s": start it within the next year.', 'guam-restaurants-core' ), $label ) );
				continue;
			}

			$days = array_values( array_intersect( Vocabulary::DAYS, array_map( 'strval', (array) ( $row['days'] ?? array() ) ) ) );
			if ( count( $days ) === count( Vocabulary::DAYS ) ) {
				$days = array();
			}

			foreach ( Guard::creative_problems(
				array(
					'headline' => $title,
					'text'     => $details,
				)
			) as $problem ) {
				$errors->add( 'gr_owner_words', $problem );
			}
			$out[] = array(
				'kind'    => $kind,
				'title'   => $title,
				'details' => $details,
				'days'    => $days,
				'from'    => (string) $from_time,
				'to'      => (string) $to_time,
				'starts'  => $starts,
				'ends'    => $ends,
			);
		}
		if ( count( $out ) > self::MAX ) {
			/* translators: %d: maximum. */
			$errors->add( 'gr_owner_specials', sprintf( __( 'Add at most %d specials and happy hours.', 'guam-restaurants-core' ), self::MAX ) );
		}
		return $errors->has_errors() ? $errors : $out;
	}

	/**
	 * A stored special with every key, or null when it is not one.
	 *
	 * @param mixed $raw Stored value.
	 * @return array{kind: string, title: string, details: string, days: string[], from: string, to: string, starts: string, ends: string}|null
	 */
	public static function normalize( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$kind = in_array( (string) ( $raw['kind'] ?? '' ), self::KINDS, true ) ? (string) $raw['kind'] : self::KIND_SPECIAL;
		$from = self::time( (string) ( $raw['from'] ?? '' ) );
		$to   = self::time( (string) ( $raw['to'] ?? '' ) );
		if ( null === $from || null === $to || $from === $to ) {
			$from = '';
			$to   = '';
		}
		$special = array(
			'kind'    => $kind,
			'title'   => (string) ( $raw['title'] ?? '' ),
			'details' => (string) ( $raw['details'] ?? '' ),
			'days'    => array_values( array_intersect( Vocabulary::DAYS, array_map( 'strval', (array) ( $raw['days'] ?? array() ) ) ) ),
			'from'    => (string) $from,
			'to'      => (string) $to,
			'starts'  => (string) self::date( (string) ( $raw['starts'] ?? '' ) ),
			'ends'    => (string) self::date( (string) ( $raw['ends'] ?? '' ) ),
		);
		return '' === $special['title'] && self::KIND_SPECIAL === $kind ? null : $special;
	}

	/**
	 * A restaurant's specials, every key filled.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( int $restaurant_id ): array {
		$value = get_post_meta( $restaurant_id, Owner_Content::SPECIALS, true );
		return array_values( array_filter( array_map( array( self::class, 'normalize' ), is_array( $value ) ? $value : array() ) ) );
	}

	/**
	 * Specials not past their end date: the ones running now or later.
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d, Guam.
	 * @return array<int, array<string, mixed>>
	 */
	public static function current( int $restaurant_id, ?string $today = null ): array {
		$today = $today ?? Clock::today();
		return array_values(
			array_filter(
				self::all( $restaurant_id ),
				static function ( array $special ) use ( $today ): bool {
					return '' === $special['ends'] || $special['ends'] >= $today;
				}
			)
		);
	}

	/**
	 * Whether a special runs on a date (its start, if timed).
	 *
	 * @param array<string, mixed> $special Normalized special.
	 * @param string               $date    Y-m-d.
	 * @return bool
	 */
	public static function on_date( array $special, string $date ): bool {
		if ( ( '' !== $special['starts'] && $date < $special['starts'] ) || ( '' !== $special['ends'] && $date > $special['ends'] ) ) {
			return false;
		}
		if ( ! $special['days'] ) {
			return true;
		}
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, Clock::zone() );
		return $day && in_array( Vocabulary::DAYS[ (int) $day->format( 'N' ) - 1 ], $special['days'], true );
	}

	/**
	 * Whether a timed special is running at a moment (Guam time). An
	 * all-day special is never "on now"; it is simply on today.
	 *
	 * @param array<string, mixed> $special   Normalized special.
	 * @param int|null             $timestamp Unix time; defaults to now.
	 * @return bool
	 */
	public static function running_at( array $special, ?int $timestamp = null ): bool {
		if ( '' === $special['from'] ) {
			return false;
		}
		$timestamp = $timestamp ?? Clock::now();
		$today     = Clock::today( $timestamp );
		$now       = Clock::minute_of_week( $timestamp ) % Clock::MINUTES_PER_DAY;
		$from      = self::minutes( $special['from'] );
		$to        = self::minutes( $special['to'] );
		$overnight = $to <= $from;
		if ( $now >= $from && ( $overnight || $now < $to ) && self::on_date( $special, $today ) ) {
			return true;
		}
		return $overnight && $now < $to && self::on_date( $special, Special_Hours::shift( $today, -1 ) );
	}

	/**
	 * Whether a special runs on any of the days from $from to $to.
	 *
	 * @param array<string, mixed> $special Normalized special.
	 * @param string               $from    Y-m-d.
	 * @param string               $to      Y-m-d.
	 * @return bool
	 */
	public static function on_any_day( array $special, string $from, string $to ): bool {
		for ( $date = $from; $date <= $to; $date = Special_Hours::shift( $date, 1 ) ) {
			if ( self::on_date( $special, $date ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The title to show: the owner's, or "Happy hour".
	 *
	 * @param array<string, mixed> $special Normalized special.
	 * @return string
	 */
	public static function title( array $special ): string {
		return '' === $special['title'] ? self::kind_labels()[ $special['kind'] ] : $special['title'];
	}

	/**
	 * "Monday to Friday, 4:00 pm–7:00 pm", "Every day, all day".
	 *
	 * @param array<string, mixed> $special Normalized special.
	 * @return string
	 */
	public static function when_label( array $special ): string {
		$days = self::days_label( $special['days'] );
		if ( '' === $special['from'] ) {
			/* translators: %s: days, for example "Every day" or "Saturday and Sunday". */
			return sprintf( __( '%s, all day', 'guam-restaurants-core' ), $days );
		}
		/* translators: 1: days, 2: start time, 3: end time. */
		return sprintf( __( '%1$s, %2$s–%3$s', 'guam-restaurants-core' ), $days, Restaurant_View::time_label( $special['from'] ), Restaurant_View::time_label( $special['to'] ) );
	}

	/**
	 * Days in words: runs of three or more as "Monday to Friday", others
	 * listed.
	 *
	 * @param string[] $days Day keys; empty for every day.
	 * @return string
	 */
	public static function days_label( array $days ): string {
		$names = Restaurant_View::day_names();
		$days  = array_values( array_intersect( Vocabulary::DAYS, $days ) );
		if ( ! $days || count( $days ) === count( Vocabulary::DAYS ) ) {
			return __( 'Every day', 'guam-restaurants-core' );
		}
		$index = array_flip( Vocabulary::DAYS );
		$runs  = array();
		foreach ( $days as $day ) {
			$last = count( $runs ) - 1;
			if ( $last >= 0 && $index[ end( $runs[ $last ] ) ] + 1 === $index[ $day ] ) {
				$runs[ $last ][] = $day;
			} else {
				$runs[] = array( $day );
			}
		}
		$parts = array();
		foreach ( $runs as $run ) {
			if ( count( $run ) >= 3 ) {
				/* translators: 1: first day, 2: last day, for example "Monday to Friday". */
				$parts[] = sprintf( __( '%1$s to %2$s', 'guam-restaurants-core' ), $names[ $run[0] ], $names[ end( $run ) ] );
			} else {
				foreach ( $run as $day ) {
					$parts[] = $names[ $day ];
				}
			}
		}
		return wp_sprintf( '%l', $parts );
	}

	/**
	 * Dates in words: "Until December 31, 2026", "From December 1, 2026",
	 * "December 1, 2026 to December 31, 2026", or ''.
	 *
	 * @param array<string, mixed> $special Normalized special.
	 * @param string|null          $today   Y-m-d; a start date already past is not shown.
	 * @return string
	 */
	public static function dates_label( array $special, ?string $today = null ): string {
		$today  = $today ?? Clock::today();
		$starts = '' !== $special['starts'] && $special['starts'] > $today ? $special['starts'] : '';
		if ( '' !== $starts && '' !== $special['ends'] ) {
			/* translators: 1: start date, 2: end date. */
			return sprintf( __( '%1$s to %2$s', 'guam-restaurants-core' ), Special_Hours::date_label( $starts ), Special_Hours::date_label( $special['ends'] ) );
		}
		if ( '' !== $starts ) {
			/* translators: %s: date. */
			return sprintf( __( 'From %s', 'guam-restaurants-core' ), Special_Hours::date_label( $starts ) );
		}
		if ( '' !== $special['ends'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'Until %s', 'guam-restaurants-core' ), Special_Hours::date_label( $special['ends'] ) );
		}
		return '';
	}

	/**
	 * Every published, claimed, not closed restaurant with specials still
	 * to come: restaurant ID => normalized specials. Cached until a post or
	 * its meta changes.
	 *
	 * @param string|null $today Y-m-d.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public static function collect( ?string $today = null ): array {
		$today = $today ?? Clock::today();
		return Cache::remember(
			'specials',
			$today,
			static function () use ( $today ): array {
				$ids = get_posts(
					array(
						'post_type'      => Content_Types::POST_TYPE,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => Owner_Content::SPECIALS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only restaurants with specials.
					)
				);
				$out = array();
				foreach ( array_map( 'intval', $ids ) as $id ) {
					if ( (int) get_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) <= 0 || 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
						continue;
					}
					$specials = self::current( $id, $today );
					if ( $specials ) {
						$out[ $id ] = $specials;
					}
				}
				return $out;
			}
		);
	}

	/**
	 * Specials for the Specials page, by restaurant.
	 *
	 * Views: `today` (runs today), `now` (a timed special running now) and
	 * `week` (runs on any of the next seven days). Kinds: '' for both, or
	 * a KINDS value. Village: a village slug or ''.
	 *
	 * Order: restaurants with something on now first, then by the earliest
	 * start time (all day first), then by name. Nothing paid changes it.
	 *
	 * @param string   $view      View.
	 * @param string   $kind      Kind or ''.
	 * @param string   $village   Village slug or ''.
	 * @param int|null $timestamp Unix time; defaults to now.
	 * @return array<int, array{restaurant_id: int, specials: array<int, array<string, mixed>>, on_now: bool}>
	 */
	public static function find( string $view, string $kind = '', string $village = '', ?int $timestamp = null ): array {
		$timestamp = $timestamp ?? Clock::now();
		$today     = Clock::today( $timestamp );
		$last      = Special_Hours::shift( $today, 6 );
		$out       = array();
		foreach ( self::collect( $today ) as $id => $specials ) {
			if ( '' !== $village && ! has_term( $village, Content_Types::TAX_VILLAGE, $id ) ) {
				continue;
			}
			$keep = array();
			foreach ( $specials as $special ) {
				if ( '' !== $kind && $kind !== $special['kind'] ) {
					continue;
				}
				$running = self::running_at( $special, $timestamp );
				if ( 'now' === $view ? $running : ( 'week' === $view ? self::on_any_day( $special, $today, $last ) : ( $running || self::on_date( $special, $today ) ) ) ) {
					$keep[] = $special + array( 'on_now' => $running );
				}
			}
			if ( ! $keep ) {
				continue;
			}
			usort( $keep, array( self::class, 'compare' ) );
			$out[] = array(
				'restaurant_id' => (int) $id,
				'specials'      => $keep,
				'on_now'        => (bool) array_filter( wp_list_pluck( $keep, 'on_now' ) ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ): int {
				if ( $a['on_now'] !== $b['on_now'] ) {
					return $a['on_now'] ? -1 : 1;
				}
				$by_time = strcmp( $a['specials'][0]['from'], $b['specials'][0]['from'] );
				return 0 !== $by_time ? $by_time : strcasecmp( get_the_title( $a['restaurant_id'] ), get_the_title( $b['restaurant_id'] ) );
			}
		);
		return $out;
	}

	/**
	 * Within a restaurant: on now first, then all day, then by start time.
	 *
	 * @param array<string, mixed> $a Special.
	 * @param array<string, mixed> $b Special.
	 * @return int
	 */
	public static function compare( array $a, array $b ): int {
		if ( ! empty( $a['on_now'] ) !== ! empty( $b['on_now'] ) ) {
			return ! empty( $a['on_now'] ) ? -1 : 1;
		}
		return strcmp( $a['from'] . self::title( $a ), $b['from'] . self::title( $b ) );
	}

	/**
	 * Villages that have at least one restaurant with specials, by name.
	 *
	 * @return \WP_Term[]
	 */
	public static function villages(): array {
		$ids = array_keys( self::collect() );
		if ( ! $ids ) {
			return array();
		}
		$terms = wp_get_object_terms(
			$ids,
			Content_Types::TAX_VILLAGE,
			array(
				'orderby' => 'name',
				'fields'  => 'all',
			)
		);
		return is_array( $terms ) ? array_values( $terms ) : array();
	}

	/**
	 * Minutes since midnight for HH:MM.
	 *
	 * @param string $time HH:MM.
	 * @return int
	 */
	private static function minutes( string $time ): int {
		return (int) substr( $time, 0, 2 ) * 60 + (int) substr( $time, 3, 2 );
	}

	/**
	 * "16:00" from a time input or "4:00"; '' for empty; null when not a time.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	private static function time( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 !== preg_match( '/^(\d{1,2}):([0-5]\d)$/', $value, $m ) || (int) $m[1] > 23 ) {
			return null;
		}
		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}

	/**
	 * A Y-m-d date; '' for empty; null when not a date.
	 *
	 * @param string $value Value.
	 * @return string|null
	 */
	private static function date( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
	}
}
