<?php
/**
 * Buffets and all-you-can-eat: the fact, the listing section and the page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Ads\Guard;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Translation\Languages;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a place serves a buffet or all-you-can-eat, and when (English
 * audience idea E22). Stored in gr_buffet as:
 *
 * Kind     buffet | ayce (all you can eat, for example Korean BBQ)
 * sessions up to MAX_SESSIONS rows of
 *          {meal, days[], from, to, price (US cents per adult, 0 = not given)}
 * note     a short line in the owner's or editor's words ("Kids 5 to 11 half price")
 * source   web address the facts came from ('' when the owner set them)
 * by       owner | editor
 * checked  Y-m-d the facts were set or confirmed
 *
 * Owners set it from their dashboard (Owners\Owner_Content), editors on
 * the edit screen (Admin\Buffet_Box), and a first hand-researched list
 * ships in data/buffets.php (apply_research()). It is a fact, never
 * ranked by: the Buffets page sorts by price or by village and nothing
 * paid or rated changes the order (CLAUDE.md rule 5).
 */
final class Buffets {

	public const META = 'gr_buffet';

	public const KINDS = array( 'buffet', 'ayce' );
	public const MEALS = array( 'breakfast', 'brunch', 'lunch', 'dinner', 'all_day' );

	public const MAX_SESSIONS = 8;
	public const MAX_NOTE     = 150;
	public const MAX_PRICE    = 50000;

	public const SORTS = array( 'price', 'village' );

	/**
	 * Kind names.
	 *
	 * @return array<string, string>
	 */
	public static function kind_names(): array {
		return array(
			'buffet' => __( 'Buffet', 'guam-restaurants-core' ),
			'ayce'   => __( 'All you can eat', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Meal names.
	 *
	 * @return array<string, string>
	 */
	public static function meal_names(): array {
		return array(
			'breakfast' => __( 'Breakfast', 'guam-restaurants-core' ),
			'brunch'    => __( 'Brunch', 'guam-restaurants-core' ),
			'lunch'     => __( 'Lunch', 'guam-restaurants-core' ),
			'dinner'    => __( 'Dinner', 'guam-restaurants-core' ),
			'all_day'   => __( 'All day', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Short day names, Monday first.
	 *
	 * @return array<string, string>
	 */
	public static function short_days(): array {
		return array(
			'mo' => _x( 'Mon', 'short day name', 'guam-restaurants-core' ),
			'tu' => _x( 'Tue', 'short day name', 'guam-restaurants-core' ),
			'we' => _x( 'Wed', 'short day name', 'guam-restaurants-core' ),
			'th' => _x( 'Thu', 'short day name', 'guam-restaurants-core' ),
			'fr' => _x( 'Fri', 'short day name', 'guam-restaurants-core' ),
			'sa' => _x( 'Sat', 'short day name', 'guam-restaurants-core' ),
			'su' => _x( 'Sun', 'short day name', 'guam-restaurants-core' ),
		);
	}

	/**
	 * The fact from a form or the research list. An empty kind clears it.
	 *
	 * @param array<string, mixed> $input  kind, note, source and sessions (rows of meal, days[], from, to, price in dollars).
	 * @param string               $by     owner or editor.
	 * @param string|null          $today  Y-m-d; defaults to today in Guam.
	 * @return array<string, mixed>|WP_Error An empty array when cleared.
	 */
	public static function from_input( array $input, string $by, ?string $today = null ) {
		$kind = sanitize_key( (string) ( $input['kind'] ?? '' ) );
		if ( '' === $kind ) {
			return array();
		}
		$errors = new WP_Error();
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$errors->add( 'gr_buffet_kind', __( 'Choose Buffet or All you can eat.', 'guam-restaurants-core' ) );
		}
		$sessions = array();
		foreach ( (array) ( $input['sessions'] ?? array() ) as $row ) {
			$row  = (array) $row;
			$meal = sanitize_key( (string) ( $row['meal'] ?? '' ) );
			$days = array_values( array_intersect( \GuamRestaurants\Core\Vocabulary::DAYS, array_map( 'sanitize_key', array_map( 'strval', (array) ( $row['days'] ?? array() ) ) ) ) );
			$from = trim( (string) ( $row['from'] ?? '' ) );
			$to   = trim( (string) ( $row['to'] ?? '' ) );
			$cost = trim( (string) ( $row['price'] ?? '' ) );
			if ( '' === $meal && ! $days && '' === $from && '' === $to && '' === $cost ) {
				continue;
			}
			if ( ! in_array( $meal, self::MEALS, true ) ) {
				$errors->add( 'gr_buffet_meal', __( 'Choose the meal for each buffet row.', 'guam-restaurants-core' ) );
				continue;
			}
			if ( ! $days ) {
				$errors->add( 'gr_buffet_days', __( 'Tick at least one day for each buffet row.', 'guam-restaurants-core' ) );
				continue;
			}
			$from = '' === $from ? '' : self::time( $from );
			$to   = '' === $to ? '' : self::time( $to );
			if ( null === $from || null === $to || ( '' === $from ) !== ( '' === $to ) ) {
				$errors->add( 'gr_buffet_time', __( 'Give both a start and an end time, like 17:00 and 21:00 (24-hour clock), or leave both empty.', 'guam-restaurants-core' ) );
				continue;
			}
			$price = self::cents( $cost );
			if ( null === $price ) {
				$errors->add( 'gr_buffet_price', __( 'Write the adult price per person as a number, like 45 or 45.50, or leave it empty.', 'guam-restaurants-core' ) );
				continue;
			}
			$sessions[] = array(
				'meal'  => $meal,
				'days'  => $days,
				'from'  => $from,
				'to'    => $to,
				'price' => $price,
			);
		}
		if ( count( $sessions ) > self::MAX_SESSIONS ) {
			/* translators: %d: maximum. */
			$errors->add( 'gr_buffet_max', sprintf( __( 'List at most %d buffet rows.', 'guam-restaurants-core' ), self::MAX_SESSIONS ) );
		}
		$note = mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, self::MAX_NOTE );
		foreach ( Guard::creative_problems( array( 'headline' => $note ) ) as $problem ) {
			$errors->add( 'gr_owner_words', $problem );
		}
		$source = 'owner' === $by ? '' : esc_url_raw( trim( (string) ( $input['source'] ?? '' ) ), array( 'http', 'https' ) );
		if ( $errors->has_errors() ) {
			return $errors;
		}
		usort(
			$sessions,
			static function ( array $a, array $b ): int {
				$order = array_search( $a['meal'], self::MEALS, true ) <=> array_search( $b['meal'], self::MEALS, true );
				if ( 0 !== $order ) {
					return $order;
				}
				return array_search( $a['days'][0], \GuamRestaurants\Core\Vocabulary::DAYS, true ) <=> array_search( $b['days'][0], \GuamRestaurants\Core\Vocabulary::DAYS, true );
			}
		);
		return array(
			'kind'     => $kind,
			'sessions' => $sessions,
			'note'     => $note,
			'source'   => $source,
			'by'       => 'owner' === $by ? 'owner' : 'editor',
			'checked'  => $today ?? Clock::today(),
		);
	}

	/**
	 * A restaurant's fact, or null.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{kind: string, sessions: array<int, array{meal: string, days: string[], from: string, to: string, price: int}>, note: string, source: string, by: string, checked: string}|null
	 */
	public static function get( int $restaurant_id ): ?array {
		$value = get_post_meta( $restaurant_id, self::META, true );
		if ( ! is_array( $value ) || ! in_array( $value['kind'] ?? '', self::KINDS, true ) ) {
			return null;
		}
		$sessions = array();
		foreach ( (array) ( $value['sessions'] ?? array() ) as $row ) {
			if ( is_array( $row ) && in_array( $row['meal'] ?? '', self::MEALS, true ) ) {
				$sessions[] = array(
					'meal'  => (string) $row['meal'],
					'days'  => array_values( array_map( 'strval', (array) ( $row['days'] ?? array() ) ) ),
					'from'  => (string) ( $row['from'] ?? '' ),
					'to'    => (string) ( $row['to'] ?? '' ),
					'price' => max( 0, (int) ( $row['price'] ?? 0 ) ),
				);
			}
		}
		return array(
			'kind'     => (string) $value['kind'],
			'sessions' => $sessions,
			'note'     => (string) ( $value['note'] ?? '' ),
			'source'   => (string) ( $value['source'] ?? '' ),
			'by'       => 'owner' === ( $value['by'] ?? '' ) ? 'owner' : 'editor',
			'checked'  => (string) ( $value['checked'] ?? '' ),
		);
	}

	/**
	 * Saves a fact (or clears it with an empty array) and logs the change.
	 *
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $value         from_input() result.
	 * @param string               $source        Change log source: owner or editor.
	 * @return bool Whether anything changed.
	 */
	public static function save( int $restaurant_id, array $value, string $source ): bool {
		$old = get_post_meta( $restaurant_id, self::META, true );
		$old = is_array( $old ) ? $old : array();
		// A save with the same facts only moves the checked date; that is not a change.
		if ( $old && $value && array_diff_key( $old, array( 'checked' => 1 ) ) == array_diff_key( $value, array( 'checked' => 1 ) ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same keys and values, any order.
			update_post_meta( $restaurant_id, self::META, $value );
			return false;
		}
		if ( $old === $value ) {
			return false;
		}
		Change_Tracker::suppress(
			static function () use ( $restaurant_id, $value, $old, $source ) {
				if ( $value ) {
					update_post_meta( $restaurant_id, self::META, $value );
				} else {
					delete_post_meta( $restaurant_id, self::META );
				}
				Change_Log::record( $restaurant_id, self::META, $old ? $old : null, $value ? $value : null, $source );
			}
		);
		Cache::flush();
		return true;
	}

	/**
	 * Lowest adult price of a fact, in cents; 0 when no price is given.
	 *
	 * @param array{sessions: array<int, array{price: int}>} $fact Fact.
	 * @return int
	 */
	public static function lowest_price( array $fact ): int {
		$prices = array_filter( wp_list_pluck( $fact['sessions'], 'price' ) );
		return $prices ? (int) min( $prices ) : 0;
	}

	/**
	 * "$45" or "$45.50".
	 *
	 * @param int $cents Cents.
	 * @return string
	 */
	public static function price_label( int $cents ): string {
		return '$' . number_format_i18n( $cents / 100, 0 === $cents % 100 ? 0 : 2 );
	}

	/**
	 * Days in words: "Daily", "Mon–Fri", "Fri, Sat", "Mon, Tue, Thu–Sun".
	 *
	 * @param string[] $days Day keys.
	 * @return string
	 */
	public static function days_label( array $days ): string {
		$order = \GuamRestaurants\Core\Vocabulary::DAYS;
		$days  = array_values( array_intersect( $order, $days ) );
		if ( 7 === count( $days ) ) {
			return __( 'Daily', 'guam-restaurants-core' );
		}
		$names = self::short_days();
		$runs  = array();
		foreach ( $days as $day ) {
			$index = (int) array_search( $day, $order, true );
			$last  = count( $runs ) - 1;
			if ( $last >= 0 && end( $runs[ $last ] ) === $index - 1 ) {
				$runs[ $last ][] = $index;
			} else {
				$runs[] = array( $index );
			}
		}
		$parts = array();
		foreach ( $runs as $run ) {
			if ( count( $run ) >= 3 ) {
				$parts[] = $names[ $order[ $run[0] ] ] . '–' . $names[ $order[ end( $run ) ] ];
			} else {
				foreach ( $run as $index ) {
					$parts[] = $names[ $order[ $index ] ];
				}
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * One session in words: "Dinner · Fri, Sat · 5:00 pm–9:00 pm · $55".
	 *
	 * @param array{meal: string, days: string[], from: string, to: string, price: int} $session Session.
	 * @return string Escaped HTML.
	 */
	public static function session_html( array $session ): string {
		$parts = array(
			'<span class="gr-buffet__meal">' . esc_html( self::meal_names()[ $session['meal'] ] ?? $session['meal'] ) . '</span>',
			esc_html( self::days_label( $session['days'] ) ),
		);
		if ( '' !== $session['from'] ) {
			$parts[] = esc_html( Restaurant_View::time_label( $session['from'] ) . '–' . Restaurant_View::time_label( $session['to'] ) );
		}
		$parts[] = $session['price'] > 0
			/* translators: %s: price, for example "$45". */
			? '<span class="gr-buffet__price">' . esc_html( sprintf( __( '%s per adult', 'guam-restaurants-core' ), self::price_label( $session['price'] ) ) ) . '</span>'
			: esc_html__( 'price not listed', 'guam-restaurants-core' );
		return implode( ' · ', $parts );
	}

	/**
	 * Where the facts came from, and when.
	 *
	 * @param array{source: string, by: string, checked: string} $fact Fact.
	 * @return string Escaped HTML.
	 */
	public static function source_html( array $fact ): string {
		$date = '' === $fact['checked'] ? '' : Special_Hours::date_label( $fact['checked'] );
		if ( 'owner' === $fact['by'] ) {
			/* translators: %s: date. */
			$text = '' === $date ? esc_html__( 'From the owner.', 'guam-restaurants-core' ) : esc_html( sprintf( __( 'From the owner, updated %s.', 'guam-restaurants-core' ), $date ) );
		} elseif ( '' !== $fact['source'] ) {
			$host = (string) wp_parse_url( $fact['source'], PHP_URL_HOST );
			$link = '<a href="' . esc_url( $fact['source'] ) . '" rel="nofollow noopener" target="_blank">' . Languages::keep( esc_html( preg_replace( '/^www\./', '', $host ) ) ) . '</a>';
			/* translators: 1: website the facts came from, linked, for example "hiltonguamresort.com", 2: date. */
			$text = '' === $date ? sprintf( esc_html__( 'From %1$s.', 'guam-restaurants-core' ), $link ) : sprintf( esc_html__( 'From %1$s, checked %2$s.', 'guam-restaurants-core' ), $link, esc_html( $date ) );
		} else {
			/* translators: %s: date. */
			$text = '' === $date ? esc_html__( 'Checked by our editors.', 'guam-restaurants-core' ) : esc_html( sprintf( __( 'Checked by our editors %s.', 'guam-restaurants-core' ), $date ) );
		}
		return $text . ' ' . esc_html__( 'Prices and days change, so check before you go.', 'guam-restaurants-core' );
	}

	/**
	 * The section on a restaurant's page, under its hours.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string '' when the place has no buffet.
	 */
	public static function render( int $restaurant_id ): string {
		$fact = self::get( $restaurant_id );
		if ( null === $fact ) {
			return '';
		}
		$items = '';
		foreach ( $fact['sessions'] as $session ) {
			$items .= '<li>' . self::session_html( $session ) . '</li>';
		}
		return sprintf(
			'<div class="gr-buffet"><h4 class="gr-buffet__title">%1$s</h4>%2$s%3$s<p class="gr-buffet__source">%4$s</p><p class="gr-buffet__more"><a href="%5$s">%6$s</a></p></div>',
			esc_html( self::kind_names()[ $fact['kind'] ] ),
			'' !== $items ? '<ul class="gr-buffet__sessions">' . $items . '</ul>' : '',
			'' !== $fact['note'] ? '<p class="gr-buffet__note">' . Languages::keep( esc_html( $fact['note'] ) ) . '</p>' : '',
			self::source_html( $fact ),
			esc_url( Fresh_Pages::url( 'buffets' ) ),
			esc_html__( 'More buffets on Guam', 'guam-restaurants-core' )
		);
	}

	/**
	 * Published, open restaurants with the fact.
	 *
	 * @return array<int, array{id: int, fact: array<string, mixed>, price: int, village: string}> Unsorted.
	 */
	public static function places(): array {
		return (array) Cache::remember( 'buffets', array(), array( self::class, 'build' ) );
	}

	/**
	 * Builds places().
	 *
	 * @return array<int, array{id: int, fact: array<string, mixed>, price: int, village: string}>
	 */
	public static function build(): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- only places with the fact.
			)
		);
		$out = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$fact = self::get( $id );
			if ( null === $fact || 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
				continue;
			}
			$out[] = array(
				'id'      => $id,
				'fact'    => $fact,
				'price'   => self::lowest_price( $fact ),
				'village' => Restaurant_View::term_names( $id, Content_Types::TAX_VILLAGE ),
			);
		}
		return $out;
	}

	/**
	 * Places in page order. By price: lowest adult price first, places
	 * without a price last, then by name. By village: village name, then
	 * price as above. Never by rating, and nothing paid takes part.
	 *
	 * @param string $sort price or village.
	 * @return array<int, array{id: int, fact: array<string, mixed>, price: int, village: string}>
	 */
	public static function sorted( string $sort = 'price' ): array {
		$places = self::places();
		$titles = array();
		foreach ( $places as $place ) {
			$titles[ $place['id'] ] = (string) get_the_title( $place['id'] );
		}
		usort(
			$places,
			static function ( array $a, array $b ) use ( $sort, $titles ): int {
				if ( 'village' === $sort ) {
					$order = ( '' === $a['village'] ) <=> ( '' === $b['village'] );
					if ( 0 === $order ) {
						$order = strcasecmp( remove_accents( $a['village'] ), remove_accents( $b['village'] ) );
					}
					if ( 0 !== $order ) {
						return $order;
					}
				}
				foreach ( array( ( 0 === $a['price'] ) <=> ( 0 === $b['price'] ), $a['price'] <=> $b['price'] ) as $order ) {
					if ( 0 !== $order ) {
						return $order;
					}
				}
				return strcasecmp( $titles[ $a['id'] ], $titles[ $b['id'] ] );
			}
		);
		return $places;
	}

	/**
	 * Places with at least one price.
	 *
	 * @return int
	 */
	public static function priced_count(): int {
		return count( array_filter( wp_list_pluck( self::places(), 'price' ) ) );
	}

	/**
	 * The sort asked for on the page.
	 *
	 * @return string price or village.
	 */
	public static function current_sort(): string {
		$sort = isset( $_GET[ Filters::SORT ] ) ? sanitize_key( wp_unslash( $_GET[ Filters::SORT ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing order.
		return in_array( $sort, self::SORTS, true ) ? $sort : 'price';
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title(): string {
		return __( 'Buffets and all-you-can-eat on Guam', 'guam-restaurants-core' );
	}

	/**
	 * Meta description.
	 *
	 * @return string
	 */
	public static function description(): string {
		$count = count( self::places() );
		return sprintf(
			/* translators: %s: number of places. */
			_n( '%s Guam place with a buffet or all-you-can-eat, from hotel breakfasts to Sunday brunch, with the price per person and the days served.', '%s Guam places with a buffet or all-you-can-eat, from hotel breakfasts to Sunday brunch, with the price per person and the days served.', $count, 'guam-restaurants-core' ),
			number_format_i18n( $count )
		);
	}

	/**
	 * The page body.
	 *
	 * @return string
	 */
	public static function render_page(): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );
		$sort   = self::current_sort();
		$places = self::sorted( $sort );
		$intro  = '<p class="gr-fresh-page__intro">' . esc_html__( 'Hotel buffets, Sunday brunches, Korean BBQ and other places where you pay once per person and eat as much as you like. Prices are for one adult, as the owner or the restaurant\'s published page gives them; most places add a service charge. Sorted by price or by village, never by rating; nothing paid changes the order.', 'guam-restaurants-core' ) . '</p>';
		$own    = '<p class="gr-fresh-page__also">' . esc_html__( 'Run a buffet that is missing or out of date? Verified owners can set their buffet days and prices from their dashboard, or tell us through Suggest a correction.', 'guam-restaurants-core' ) . '</p>';
		if ( ! $places ) {
			return '<div class="gr-fresh-page alignwide"><h1 class="gr-fresh-page__title">' . esc_html( self::title() ) . '</h1>' . $intro . '<p class="gr-fresh-page__empty">' . esc_html__( 'No buffets recorded yet.', 'guam-restaurants-core' ) . '</p>' . $own . '</div>';
		}
		$links = array();
		foreach ( array(
			'price'   => __( 'Price (low to high)', 'guam-restaurants-core' ),
			'village' => __( 'Village', 'guam-restaurants-core' ),
		) as $value => $label ) {
			$url     = 'price' === $value ? Fresh_Pages::url( 'buffets' ) : add_query_arg( Filters::SORT, $value, Fresh_Pages::url( 'buffets' ) );
			$links[] = $value === $sort
				? '<a aria-current="true" class="gr-chip is-active" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'
				: '<a class="gr-chip" href="' . esc_url( $url ) . '"' . ( 'village' === $value ? ' rel="nofollow"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$sorter = '<p class="gr-buffets__sort"><span>' . esc_html__( 'Sort by', 'guam-restaurants-core' ) . '</span> ' . implode( ' ', $links ) . '</p>';
		return '<div class="gr-fresh-page alignwide"><h1 class="gr-fresh-page__title">' . esc_html( self::title() ) . '</h1>' . $intro . $sorter . self::results( $places, $sort ) . $own . '</div>';
	}

	/**
	 * Cards with a map, each with its buffet times and prices.
	 *
	 * @param array<int, array{id: int, fact: array<string, mixed>, price: int, village: string}> $places Places, in order.
	 * @param string                                                                              $sort   price or village.
	 * @return string
	 */
	private static function results( array $places, string $sort ): string {
		Restaurant_View::prime( wp_list_pluck( $places, 'id' ) );
		$groups = array();
		$pin    = 0;
		foreach ( $places as $place ) {
			$fact  = $place['fact'];
			$lines = '';
			foreach ( $fact['sessions'] as $session ) {
				$lines .= '<li>' . self::session_html( $session ) . '</li>';
			}
			$lead = $place['price'] > 0
				/* translators: 1: Buffet or All you can eat, 2: price, for example "$30". */
				? sprintf( __( '%1$s from %2$s per adult', 'guam-restaurants-core' ), self::kind_names()[ $fact['kind'] ], self::price_label( $place['price'] ) )
				: self::kind_names()[ $fact['kind'] ];
			$why              = '<div class="gr-buffet gr-buffet--card"><p class="gr-buffet__lead">' . esc_html( $lead ) . '</p>' . ( '' !== $lines ? '<ul class="gr-buffet__sessions">' . $lines . '</ul>' : '' ) . '</div>';
			$card             = Restaurant_View::card( $place['id'], 'village' === $sort ? 3 : 2 );
			$end              = strrpos( $card, '</div></article>' );
			$card             = false === $end ? $card . $why : substr_replace( $card, $why, $end, 0 );
			$point            = Restaurant_View::coordinates( $place['id'] );
			$item             = null === $point
				? '<li>' . $card . '</li>'
				: sprintf( '<li data-gr-pin="%1$d" data-gr-point="%2$s,%3$s">%4$s</li>', ++$pin, esc_attr( (string) $point['lng'] ), esc_attr( (string) $point['lat'] ), $card );
			$group            = 'village' === $sort ? ( '' === $place['village'] ? __( 'Village not known', 'guam-restaurants-core' ) : $place['village'] ) : '';
			$groups[ $group ] = ( $groups[ $group ] ?? '' ) . $item;
		}
		$count = count( $places );
		$body  = '<p class="gr-directory__summary" role="status">' . esc_html(
			/* translators: %s: number of places. */
			sprintf( _n( '%s place', '%s places', $count, 'guam-restaurants-core' ), number_format_i18n( $count ) )
		) . '</p>';
		foreach ( $groups as $group => $items ) {
			$body .= ( '' === $group ? '' : '<h2 class="gr-section-title">' . Languages::keep( esc_html( (string) $group ) ) . '</h2>' ) . '<ul class="gr-cards" role="list">' . $items . '</ul>';
		}
		return sprintf(
			'<div class="gr-directory gr-dish-page__list"><div class="gr-directory__views" role="group" aria-label="%1$s" hidden><button type="button" class="gr-directory__view" data-gr-view="list" aria-pressed="true">%2$s</button><button type="button" class="gr-directory__view" data-gr-view="map" aria-pressed="false">%3$s</button></div><div class="gr-directory__body"><div class="gr-directory__results" id="%4$s" tabindex="-1">%5$s</div><div class="gr-directory__map" data-gr-results-map role="region" aria-label="%6$s"></div></div></div>',
			esc_attr__( 'Show the list as', 'guam-restaurants-core' ),
			esc_html__( 'List', 'guam-restaurants-core' ),
			esc_html__( 'Map', 'guam-restaurants-core' ),
			esc_attr( Directory_View::RESULTS_ID ),
			$body,
			esc_attr__( 'Map of these places', 'guam-restaurants-core' )
		);
	}

	/**
	 * Form fields for the owner dashboard and the editor's box.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $name          Field name, for example "buffet".
	 * @param bool   $with_source   Whether to ask for the source address (editors).
	 * @return string
	 */
	public static function form_fields( int $restaurant_id, string $name, bool $with_source = false ): string {
		$fact  = self::get( $restaurant_id ) ?? array(
			'kind'     => '',
			'sessions' => array(),
			'note'     => '',
			'source'   => '',
			'by'       => '',
			'checked'  => '',
		);
		$kinds = '<option value="">' . esc_html__( 'No buffet', 'guam-restaurants-core' ) . '</option>';
		foreach ( self::kind_names() as $value => $label ) {
			$kinds .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $fact['kind'], $value, false ), esc_html( $label ) );
		}
		$out = sprintf(
			'<p><label>%1$s <select name="%2$s[kind]">%3$s</select></label></p>',
			esc_html__( 'Serves', 'guam-restaurants-core' ),
			esc_attr( $name ),
			$kinds
		);

		$rows  = '';
		$count = min( self::MAX_SESSIONS, count( $fact['sessions'] ) + 2 );
		for ( $i = 0; $i < $count; $i++ ) {
			$session = $fact['sessions'][ $i ] ?? array(
				'meal'  => '',
				'days'  => array(),
				'from'  => '',
				'to'    => '',
				'price' => 0,
			);
			$meals   = '<option value="">' . esc_html__( 'Meal', 'guam-restaurants-core' ) . '</option>';
			foreach ( self::meal_names() as $value => $label ) {
				$meals .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $session['meal'], $value, false ), esc_html( $label ) );
			}
			$days = '';
			foreach ( self::short_days() as $day => $label ) {
				$days .= sprintf( '<label style="margin-right:.4em"><input type="checkbox" name="%1$s[sessions][%2$d][days][]" value="%3$s"%4$s> %5$s</label>', esc_attr( $name ), (int) $i, esc_attr( $day ), checked( in_array( $day, $session['days'], true ), true, false ), esc_html( $label ) );
			}
			$rows .= sprintf(
				'<tr><td><select name="%1$s[sessions][%2$d][meal]" aria-label="%3$s">%4$s</select></td><td>%5$s</td><td><input type="time" name="%1$s[sessions][%2$d][from]" value="%6$s" aria-label="%7$s"> – <input type="time" name="%1$s[sessions][%2$d][to]" value="%8$s" aria-label="%9$s"></td><td>$<input type="text" inputmode="decimal" size="7" name="%1$s[sessions][%2$d][price]" value="%10$s" aria-label="%11$s"></td></tr>',
				esc_attr( $name ),
				(int) $i,
				esc_attr__( 'Meal', 'guam-restaurants-core' ),
				$meals,
				$days,
				esc_attr( $session['from'] ),
				esc_attr__( 'From', 'guam-restaurants-core' ),
				esc_attr( $session['to'] ),
				esc_attr__( 'To', 'guam-restaurants-core' ),
				esc_attr( $session['price'] > 0 ? number_format( $session['price'] / 100, 0 === $session['price'] % 100 ? 0 : 2, '.', '' ) : '' ),
				esc_attr__( 'Adult price per person, in dollars', 'guam-restaurants-core' )
			);
		}
		$out .= sprintf(
			'<table class="widefat striped"><thead><tr><th>%1$s</th><th>%2$s</th><th>%3$s</th><th>%4$s</th></tr></thead><tbody>%5$s</tbody></table>',
			esc_html__( 'Meal', 'guam-restaurants-core' ),
			esc_html__( 'Days', 'guam-restaurants-core' ),
			esc_html__( 'Time', 'guam-restaurants-core' ),
			esc_html__( 'Adult price per person', 'guam-restaurants-core' ),
			$rows
		);
		$out .= sprintf(
			'<p><label>%1$s<br><input type="text" class="regular-text" name="%2$s[note]" value="%3$s" maxlength="%4$d" placeholder="%5$s"></label></p>',
			esc_html__( 'Note (optional)', 'guam-restaurants-core' ),
			esc_attr( $name ),
			esc_attr( $fact['note'] ),
			(int) self::MAX_NOTE,
			esc_attr__( 'e.g. Kids 5 to 11 half price. 10% service charge.', 'guam-restaurants-core' )
		);
		if ( $with_source ) {
			$out .= sprintf(
				'<p><label>%1$s<br><input type="url" class="regular-text" name="%2$s[source]" value="%3$s" placeholder="https://"></label></p>',
				esc_html__( 'Where these facts come from (the restaurant\'s own page; leave empty if the owner told you)', 'guam-restaurants-core' ),
				esc_attr( $name ),
				esc_attr( $fact['source'] )
			);
		}
		if ( '' !== $fact['checked'] ) {
			/* translators: %s: date. */
			$out .= '<p class="description">' . esc_html( sprintf( __( 'Last saved %s. Saving again marks the facts as checked today.', 'guam-restaurants-core' ), Special_Hours::date_label( $fact['checked'] ) ) ) . '</p>';
		}
		return $out;
	}

	/**
	 * Applies the hand-researched list (data/buffets.php) to the
	 * restaurants it names. A place is matched by name (Names::key) within
	 * its village. Facts an owner or editor already set are kept unless
	 * $overwrite; research rows are replaced when the list changes.
	 *
	 * @param array<int, array<string, mixed>>|null $rows      Rows; defaults to the shipped list.
	 * @param bool                                  $overwrite Replace owner and editor facts too.
	 * @return array{created: int, updated: int, unchanged: int, failed: int, missing: string[]}
	 */
	public static function apply_research( ?array $rows = null, bool $overwrite = false ): array {
		$rows   = $rows ?? self::research();
		$counts = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'missing'   => array(),
		);
		foreach ( $rows as $row ) {
			$id = self::match( (string) ( $row['name'] ?? '' ), (string) ( $row['village'] ?? '' ), (array) ( $row['aliases'] ?? array() ) );
			if ( 0 === $id ) {
				$counts['missing'][] = (string) ( $row['name'] ?? '' );
				continue;
			}
			$value = self::from_input( $row, 'editor', (string) ( $row['checked'] ?? '' ) );
			if ( is_wp_error( $value ) || ! $value ) {
				++$counts['failed'];
				continue;
			}
			$value['research'] = 1;
			$old               = get_post_meta( $id, self::META, true );
			if ( is_array( $old ) && $old && empty( $old['research'] ) && ! $overwrite ) {
				++$counts['unchanged'];
				continue;
			}
			if ( self::save( $id, $value, 'editor' ) ) {
				++$counts[ is_array( $old ) && $old ? 'updated' : 'created' ];
			} else {
				++$counts['unchanged'];
			}
		}
		return $counts;
	}

	/**
	 * The shipped research list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function research(): array {
		$rows = include GUAM_RESTAURANTS_CORE_DIR . 'data/buffets.php';
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A published restaurant by name in a village; 0 when none or more than one match.
	 *
	 * @param string   $name    Name.
	 * @param string   $village Village slug ('' for any).
	 * @param string[] $aliases Other names it may be listed under.
	 * @return int
	 */
	public static function match( string $name, string $village, array $aliases = array() ): int {
		$keys = array_filter( array_map( array( Names::class, 'key' ), array_merge( array( $name ), $aliases ) ) );
		if ( ! $keys ) {
			return 0;
		}
		$args = array(
			'post_type'      => Content_Types::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		);
		if ( '' !== $village ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off research run.
				array(
					'taxonomy' => Content_Types::TAX_VILLAGE,
					'field'    => 'slug',
					'terms'    => $village,
				),
			);
		}
		$found = array();
		foreach ( array_map( 'intval', get_posts( $args ) ) as $id ) {
			if ( in_array( Names::key( (string) get_post_field( 'post_title', $id, 'raw' ) ), $keys, true ) ) {
				$found[] = $id;
			}
		}
		return 1 === count( $found ) ? $found[0] : 0;
	}

	/**
	 * "17:00" from a time input or "5:00"; null when not a time.
	 *
	 * @param string $value Value.
	 * @return string|null HH:MM.
	 */
	private static function time( string $value ): ?string {
		if ( 1 !== preg_match( '/^(\d{1,2}):([0-5]\d)$/', trim( $value ), $m ) || (int) $m[1] > 24 || ( 24 === (int) $m[1] && '00' !== $m[2] ) ) {
			return null;
		}
		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}

	/**
	 * Cents from "45", "45.5", "$45.50"; 0 for empty; null when not a price.
	 *
	 * @param string $value Value.
	 * @return int|null
	 */
	private static function cents( string $value ): ?int {
		$value = trim( str_replace( array( '$', ',', ' ' ), '', $value ) );
		if ( '' === $value ) {
			return 0;
		}
		if ( 1 !== preg_match( '/^(\d{1,3})(?:\.(\d{1,2}))?$/', $value, $m ) ) {
			return null;
		}
		$cents = (int) $m[1] * 100 + (int) str_pad( $m[2] ?? '0', 2, '0' );
		return $cents > 0 && $cents <= self::MAX_PRICE ? $cents : null;
	}
}
