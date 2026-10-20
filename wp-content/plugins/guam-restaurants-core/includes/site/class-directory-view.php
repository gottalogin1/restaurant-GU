<?php
/**
 * Filter form, results and pagination for the directory and the map.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Village_Aliases;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered markup. The form is a plain GET form, so filtering works
 * without JavaScript; assets/site/directory.js submits it to the REST
 * endpoint instead and swaps in the results, keeping the URL shareable.
 */
final class Directory_View {

	public const RESULTS_ID = 'gr-directory-results';

	/**
	 * Cuisines with their own quick-filter chip.
	 */
	public const CUISINE_CHIPS = 5;

	/**
	 * Context string for a locked facet, for example "village:dededo".
	 *
	 * @param array<string, string> $locked Facet => slug.
	 * @return string
	 */
	public static function context_string( array $locked ): string {
		foreach ( $locked as $facet => $slug ) {
			return $facet . ':' . $slug;
		}
		return '';
	}

	/**
	 * Locked facet from a context string; unknown facets and terms give array().
	 *
	 * @param string $context Context string.
	 * @return array<string, string>
	 */
	public static function parse_context( string $context ): array {
		if ( 1 !== preg_match( '/^([a-z]+):([a-z0-9\-%]+)$/', $context, $m ) || ! isset( Filters::FACETS[ $m[1] ] ) ) {
			return array();
		}
		$term = get_term_by( 'slug', sanitize_title( $m[2] ), Filters::FACETS[ $m[1] ] );
		return $term instanceof \WP_Term ? array( $m[1] => $term->slug ) : array();
	}

	/**
	 * Base URL for a listing: the archive, or the locked term's page.
	 *
	 * @param array<string, string> $locked Facet => slug.
	 * @return string
	 */
	public static function base_url( array $locked ): string {
		foreach ( $locked as $facet => $slug ) {
			$link = get_term_link( $slug, Filters::FACETS[ $facet ] );
			if ( ! is_wp_error( $link ) ) {
				return (string) $link;
			}
		}
		return (string) get_post_type_archive_link( Content_Types::POST_TYPE );
	}

	/**
	 * URL of a results page with these filters.
	 *
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug.
	 * @param int                   $page    Page.
	 * @return string
	 */
	public static function page_url( array $filters, array $locked, int $page = 1 ): string {
		$base = self::base_url( $locked );
		if ( $page > 1 ) {
			$base = get_option( 'permalink_structure' )
				? trailingslashit( $base ) . user_trailingslashit( 'page/' . $page, 'paged' )
				: add_query_arg( 'paged', $page, $base );
		}
		$params = Filters::to_params( $filters, $locked );
		return $params ? add_query_arg( array_map( array( self::class, 'encode' ), $params ), $base ) : $base;
	}

	/**
	 * URL-encodes a parameter value, keeping commas readable.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function encode( string $value ): string {
		return str_replace( '%2C', ',', rawurlencode( $value ) );
	}

	/**
	 * The whole directory: form plus results.
	 *
	 * @param \WP_Query             $query   Results.
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug.
	 * @return string
	 */
	public static function render( \WP_Query $query, array $filters, array $locked ): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );
		return sprintf(
			'<div class="gr-directory">%1$s<div class="gr-directory__views" role="group" aria-label="%4$s" hidden><button type="button" class="gr-directory__view" data-gr-view="list" aria-pressed="true">%5$s</button><button type="button" class="gr-directory__view" data-gr-view="map" aria-pressed="false">%6$s</button></div><div class="gr-directory__body"><div class="gr-directory__results" id="%2$s" tabindex="-1">%3$s</div><div class="gr-directory__map" data-gr-results-map role="region" aria-label="%7$s"></div></div></div>',
			self::form( $filters, $locked, 'list' ),
			esc_attr( self::RESULTS_ID ),
			self::results( $query, $filters, $locked ),
			esc_attr__( 'Show results as', 'guam-restaurants-core' ),
			esc_html__( 'List', 'guam-restaurants-core' ),
			esc_html__( 'Map', 'guam-restaurants-core' ),
			esc_attr__( 'Map of these results', 'guam-restaurants-core' )
		);
	}

	/**
	 * Result count, cards and pagination.
	 *
	 * @param \WP_Query             $query   Results.
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug.
	 * @return string
	 */
	public static function results( \WP_Query $query, array $filters, array $locked ): string {
		$total = (int) $query->found_posts;
		$page  = max( 1, (int) $query->get( 'paged' ) );
		$pages = (int) $query->max_num_pages;

		if ( 0 === $total ) {
			$summary = esc_html__( 'No restaurants match these filters.', 'guam-restaurants-core' );
		} elseif ( $pages > 1 ) {
			$first   = ( $page - 1 ) * (int) $query->get( 'posts_per_page' ) + 1;
			$last    = min( $total, $first + count( $query->posts ) - 1 );
			$summary = esc_html(
				sprintf(
					/* translators: 1: first result number, 2: last result number, 3: total. */
					_n( 'Showing %1$s–%2$s of %3$s restaurant', 'Showing %1$s–%2$s of %3$s restaurants', $total, 'guam-restaurants-core' ),
					number_format_i18n( $first ),
					number_format_i18n( $last ),
					number_format_i18n( $total )
				)
			);
		} else {
			/* translators: %s: number of restaurants. */
			$summary = esc_html( sprintf( _n( '%s restaurant', '%s restaurants', $total, 'guam-restaurants-core' ), number_format_i18n( $total ) ) );
		}

		$cards = '';
		$pin   = 0;
		foreach ( $query->posts as $post ) {
			$id    = (int) ( is_object( $post ) ? $post->ID : $post );
			$point = Restaurant_View::coordinates( $id );
			// Numbered like the map pins, so a card and its pin can be matched.
			$cards .= null === $point
				? '<li>' . Restaurant_View::card( $id, 2 ) . '</li>'
				: sprintf( '<li data-gr-pin="%1$d" data-gr-point="%2$s,%3$s">%4$s</li>', ++$pin, esc_attr( (string) $point['lng'] ), esc_attr( (string) $point['lat'] ), Restaurant_View::card( $id, 2 ) );
		}

		$active = self::active( $filters, $locked );
		$out    = $active . '<p class="gr-directory__summary" role="status">' . $summary . '</p>';
		if ( ! empty( $filters['open'] ) ) {
			$out .= '<p class="gr-directory__note">' . esc_html__( 'Open now uses Guam time and lists only restaurants whose hours we have.', 'guam-restaurants-core' ) . '</p>';
		}
		if ( ! empty( $filters['late'] ) ) {
			$out .= '<p class="gr-directory__note">' . esc_html__( 'Open late means open at 10 pm tonight, Guam time, and lists only restaurants whose hours we have.', 'guam-restaurants-core' ) . '</p>';
		}
		if ( '' !== (string) ( $filters['at'] ?? '' ) ) {
			$out .= '<p class="gr-directory__note">' . esc_html(
				sprintf(
					/* translators: %s: day and time, for example "Saturday at 7:30 am". */
					__( 'Open %s uses Guam time and lists only restaurants whose hours we have. Holiday hours may differ.', 'guam-restaurants-core' ),
					Filters::at_label( (string) $filters['at'] )
				)
			) . '</p>';
		}
		if ( ! empty( $filters['breakfast'] ) ) {
			$out .= '<p class="gr-directory__note">' . esc_html__( 'Breakfast lists restaurants open by 8 am on at least one day, going by their listed hours.', 'guam-restaurants-core' ) . '</p>';
		}
		if ( '' !== $cards ) {
			$out .= '<ul class="gr-cards" role="list">' . $cards . '</ul>';
		} elseif ( '' === $active && Filters::is_filtered( $filters, $locked ) ) {
			$out .= sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( self::page_url( Filters::defaults(), $locked ) ), esc_html__( 'Clear all filters', 'guam-restaurants-core' ) );
		}
		return $out . self::pagination( $page, $pages, $filters, $locked );
	}

	/**
	 * The filters in effect as chips that each remove one, led by how a
	 * typed search was read ("We read “cheap Korean in Tumon” as").
	 *
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug fixed by the page.
	 * @return string Empty when nothing beyond the page's own facet is chosen.
	 */
	public static function active( array $filters, array $locked ): string {
		$read    = is_array( $filters['read'] ?? null ) ? $filters['read'] : null;
		$typed   = array();
		$filters = array_merge( Filters::defaults(), $filters, array( 'read' => null ) );
		foreach ( $read['matches'] ?? array() as $match ) {
			$typed[ $match['type'] . ':' . $match['value'] ] = (string) $match['phrase'];
		}

		$chips = array();
		$add   = static function ( string $label, array $without ) use ( &$chips, $locked ): void {
			$chips[] = sprintf(
				'<li><a class="gr-chip gr-chip--remove" href="%1$s" data-gr-remove>%2$s<span aria-hidden="true">×</span><span class="screen-reader-text">%3$s</span></a></li>',
				esc_url( self::page_url( $without, $locked ) ),
				$label,
				esc_html__( '(remove)', 'guam-restaurants-core' )
			);
		};
		foreach ( Filters::FACETS as $param => $taxonomy ) {
			if ( isset( $locked[ $param ] ) ) {
				continue;
			}
			foreach ( (array) $filters['facets'][ $param ] as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					continue;
				}
				$label = esc_html( $term->name );
				if ( 'price' === $param && '' !== $term->description ) {
					$label .= ' <span class="gr-chip__note">' . esc_html( $term->description ) . '</span>';
				}
				$phrase = $typed[ $param . ':' . $slug ] ?? '';
				if ( '' !== $phrase && 'price' !== $param && Village_Aliases::normalize( $phrase ) !== Village_Aliases::normalize( $term->name ) ) {
					/* translators: %s: the words the visitor typed, for example "Tumon". */
					$label .= ' <span class="gr-chip__note">' . esc_html( sprintf( __( '(%s)', 'guam-restaurants-core' ), $phrase ) ) . '</span>';
				}
				$without                     = $filters;
				$without['facets'][ $param ] = array_values( array_diff( (array) $filters['facets'][ $param ], array( $slug ) ) );
				$add( $label, $without );
			}
		}
		$flags = array(
			'open'      => __( 'Open now', 'guam-restaurants-core' ),
			'late'      => __( 'Open late', 'guam-restaurants-core' ),
			'breakfast' => __( 'Breakfast', 'guam-restaurants-core' ),
			'award'     => __( 'Award winners', 'guam-restaurants-core' ),
		);
		foreach ( $flags as $flag => $label ) {
			if ( ! empty( $filters[ $flag ] ) ) {
				$add( esc_html( $label ), array_merge( $filters, array( $flag => false ) ) );
			}
		}
		if ( '' !== (string) $filters['at'] ) {
			/* translators: %s: day and time, for example "Saturday at 7:30 am". */
			$add( esc_html( sprintf( __( 'Open %s', 'guam-restaurants-core' ), Filters::at_label( (string) $filters['at'] ) ) ), array_merge( $filters, array( 'at' => '' ) ) );
		}
		if ( '' !== (string) $filters['q'] ) {
			/* translators: %s: keywords searched for. */
			$add( esc_html( sprintf( __( 'Words: “%s”', 'guam-restaurants-core' ), (string) $filters['q'] ) ), array_merge( $filters, array( 'q' => '' ) ) );
		}
		if ( ! $chips ) {
			return '';
		}

		$lead  = '';
		$exact = '';
		if ( $read ) {
			/* translators: %s: what the visitor typed. */
			$lead  = esc_html( sprintf( __( 'We read “%s” as:', 'guam-restaurants-core' ), (string) $read['text'] ) );
			$exact = sprintf(
				' <a class="gr-active__exact" href="%1$s">%2$s</a>',
				esc_url( self::page_url( array_merge( Filters::defaults(), array( 'q' => '"' . str_replace( '"', '', (string) $read['text'] ) . '"' ) ), $locked ) ),
				esc_html__( 'Search the exact words instead', 'guam-restaurants-core' )
			);
		} else {
			$lead = esc_html__( 'Filters:', 'guam-restaurants-core' );
		}
		return sprintf(
			'<div class="gr-active" role="group" aria-label="%1$s"><p class="gr-active__lead">%2$s</p><ul class="gr-chips gr-active__chips" role="list">%3$s</ul><p class="gr-active__links">%4$s <a class="gr-active__clear" href="%5$s" data-gr-remove>%6$s</a></p></div>',
			esc_attr__( 'Filters in use', 'guam-restaurants-core' ),
			$lead,
			implode( '', $chips ),
			$exact,
			esc_url( self::page_url( Filters::defaults(), $locked ) ),
			esc_html__( 'Clear all', 'guam-restaurants-core' )
		);
	}

	/**
	 * Previous, numbered and next links.
	 *
	 * @param int                   $page    Current page.
	 * @param int                   $pages   Page count.
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug.
	 * @return string
	 */
	public static function pagination( int $page, int $pages, array $filters, array $locked ): string {
		if ( $pages <= 1 ) {
			return '';
		}
		$items = array();
		if ( $page > 1 ) {
			$items[] = sprintf( '<a class="gr-pagination__prev" rel="prev" href="%1$s">%2$s</a>', esc_url( self::page_url( $filters, $locked, $page - 1 ) ), esc_html__( 'Previous', 'guam-restaurants-core' ) );
		}
		$shown = array_unique( array_filter( array( 1, $page - 1, $page, $page + 1, $pages ), static fn( $n ) => $n >= 1 && $n <= $pages ) );
		sort( $shown );
		$previous = 0;
		foreach ( $shown as $number ) {
			if ( $number - $previous > 1 ) {
				$items[] = '<span class="gr-pagination__gap" aria-hidden="true">…</span>';
			}
			$items[] = $number === $page
				? sprintf( '<a aria-current="page" href="%1$s">%2$s</a>', esc_url( self::page_url( $filters, $locked, $number ) ), esc_html( number_format_i18n( $number ) ) )
				/* translators: %s: page number. */
				: sprintf( '<a href="%1$s" aria-label="%3$s">%2$s</a>', esc_url( self::page_url( $filters, $locked, $number ) ), esc_html( number_format_i18n( $number ) ), esc_attr( sprintf( __( 'Page %s', 'guam-restaurants-core' ), number_format_i18n( $number ) ) ) );
			$previous = $number;
		}
		if ( $page < $pages ) {
			$items[] = sprintf( '<a class="gr-pagination__next" rel="next" href="%1$s">%2$s</a>', esc_url( self::page_url( $filters, $locked, $page + 1 ) ), esc_html__( 'Next', 'guam-restaurants-core' ) );
		}
		return sprintf( '<nav class="gr-pagination" aria-label="%1$s">%2$s</nav>', esc_attr__( 'Results pages', 'guam-restaurants-core' ), implode( '', $items ) );
	}

	/**
	 * The filter form.
	 *
	 * @param array<string, mixed>  $filters Filters.
	 * @param array<string, string> $locked  Facet => slug fixed by the page.
	 * @param string                $mode    list (directory) or map.
	 * @param string                $action  Form action; defaults to the listing's URL.
	 * @return string
	 */
	public static function form( array $filters, array $locked, string $mode = 'list', string $action = '' ): string {
		$action = '' === $action ? self::base_url( $locked ) : $action;
		$labels = array(
			'village' => __( 'Village', 'guam-restaurants-core' ),
			'mall'    => __( 'Mall', 'guam-restaurants-core' ),
			'cuisine' => __( 'Cuisine', 'guam-restaurants-core' ),
			'price'   => __( 'Price', 'guam-restaurants-core' ),
		);

		$facets = '';
		$more   = 0;
		foreach ( Filters::FACETS as $param => $taxonomy ) {
			if ( isset( $locked[ $param ] ) || 'price' === $param ) {
				continue;
			}
			$facets .= self::facet( $param, $taxonomy, $labels[ $param ], (array) $filters['facets'][ $param ] );
			$more   += count( (array) $filters['facets'][ $param ] );
		}
		$facets .= self::open_at( (string) $filters['at'] );
		$more   += '' !== (string) $filters['at'] ? 1 : 0;
		$top     = array();
		$chips   = self::chip( Filters::OPEN, Filters::OPEN_NOW, ! empty( $filters['open'] ), esc_html__( 'Open now', 'guam-restaurants-core' ) )
			. self::chip( Filters::LATE, Filters::LATE_TONIGHT, ! empty( $filters['late'] ), esc_html__( 'Open late', 'guam-restaurants-core' ) )
			. self::chip( Filters::MEAL, Filters::MEAL_BREAKFAST, ! empty( $filters['breakfast'] ), esc_html__( 'Breakfast', 'guam-restaurants-core' ) )
			. self::chip( Filters::AWARD, Filters::AWARD_WINNER, ! empty( $filters['award'] ), esc_html__( 'Award winners', 'guam-restaurants-core' ) );
		if ( ! isset( $locked['price'] ) ) {
			foreach ( self::facet_terms( Content_Types::TAX_PRICE ) as $term ) {
				$rank   = (int) get_term_meta( (int) $term->term_id, Term_Meta::RANK, true );
				$chips .= self::chip(
					'price[]',
					$term->slug,
					in_array( $term->slug, (array) $filters['facets']['price'], true ),
					/* translators: 1: price level, 2: highest level. */
					'<span aria-hidden="true">' . esc_html( $term->name ) . '</span><span class="screen-reader-text">' . esc_html( $rank > 0 ? sprintf( __( 'Price level %1$d of %2$d', 'guam-restaurants-core' ), $rank, 4 ) : $term->name ) . '</span>'
				);
			}
		}
		if ( ! isset( $locked['cuisine'] ) ) {
			// The busiest cuisines also have a chip; it mirrors the same box in More filters (directory.js keeps both in step).
			$cuisines = self::facet_terms( Content_Types::TAX_CUISINE );
			usort(
				$cuisines,
				static function ( \WP_Term $a, \WP_Term $b ): int {
					return $b->count <=> $a->count;
				}
			);
			foreach ( array_slice( $cuisines, 0, self::CUISINE_CHIPS ) as $term ) {
				$top[]  = $term->slug;
				$chips .= self::chip( 'cuisine[]', $term->slug, in_array( $term->slug, (array) $filters['facets']['cuisine'], true ), esc_html( $term->name ) );
			}
		}
		// More filters opens only for choices that have no chip.
		$more -= count( array_intersect( (array) $filters['facets']['cuisine'], $top ) );

		$sort = '';
		if ( 'list' === $mode ) {
			$sorts = array(
				'name'       => __( 'Name (A to Z)', 'guam-restaurants-core' ),
				'newest'     => __( 'Newest listings', 'guam-restaurants-core' ),
				'price_asc'  => __( 'Price (low to high)', 'guam-restaurants-core' ),
				'price_desc' => __( 'Price (high to low)', 'guam-restaurants-core' ),
			);
			if ( '' !== (string) $filters['q'] ) {
				$sorts = array( 'relevance' => __( 'Best match', 'guam-restaurants-core' ) ) + $sorts;
			}
			$current = Filters::effective_sort( $filters );
			$options = '';
			foreach ( $sorts as $value => $label ) {
				$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $current, false ), esc_html( $label ) );
			}
			$sort = sprintf(
				'<div class="gr-filters__sort"><label for="gr-sort">%1$s</label> <select id="gr-sort" name="%2$s">%3$s</select></div>',
				esc_html__( 'Sort by', 'guam-restaurants-core' ),
				esc_attr( Filters::SORT ),
				$options
			);
		}

		return sprintf(
			'<form class="gr-filters" role="search" method="get" action="%1$s" data-gr-filters="%2$s" data-gr-context="%3$s" aria-label="%4$s">
				<div class="gr-filters__search"><label for="gr-q">%5$s</label><input type="search" id="gr-q" name="%6$s" value="%7$s" maxlength="%8$d" autocomplete="off" placeholder="%16$s"></div>
				<div class="gr-filters__quick" role="group" aria-label="%10$s">%11$s</div>
				%9$s
				%12$s
				<div class="gr-filters__actions"><button type="submit" class="wp-element-button">%13$s</button> <a class="gr-filters__clear" href="%14$s">%15$s</a></div>
			</form>',
			esc_url( $action ),
			esc_attr( $mode ),
			esc_attr( self::context_string( $locked ) ),
			esc_attr__( 'Filter restaurants', 'guam-restaurants-core' ),
			esc_html__( 'Search by name, dish or what you feel like', 'guam-restaurants-core' ),
			esc_attr( Filters::SEARCH ),
			esc_attr( (string) $filters['q'] ),
			Filters::MAX_SEARCH_LENGTH,
			'' === $facets ? '' : sprintf(
				'<details class="gr-filters__more"%1$s><summary>%2$s</summary><div class="gr-filters__facets">%3$s</div></details>',
				$more ? ' open' : '',
				esc_html__( 'More filters', 'guam-restaurants-core' ),
				$facets
			),
			esc_attr__( 'Quick filters', 'guam-restaurants-core' ),
			$chips,
			$sort,
			esc_html__( 'Show results', 'guam-restaurants-core' ),
			esc_url( $action ),
			esc_html__( 'Clear filters', 'guam-restaurants-core' ),
			esc_attr__( 'Try “cheap Korean open late in Tumon”', 'guam-restaurants-core' )
		);
	}

	/**
	 * One quick-filter chip: a checkbox styled as a button.
	 *
	 * @param string $name    Input name.
	 * @param string $value   Input value.
	 * @param bool   $checked Checked.
	 * @param string $label   Escaped label HTML.
	 * @return string
	 */
	private static function chip( string $name, string $value, bool $checked, string $label ): string {
		return sprintf(
			'<label class="gr-chip gr-chip--toggle"><input type="checkbox" name="%1$s" value="%2$s"%3$s> %4$s</label>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( $checked, true, false ),
			$label
		);
	}

	/**
	 * "Open at": a day and a time, sent as at_day and at_time (directory.js
	 * joins them into one `at` parameter).
	 *
	 * @param string $at Current `at` value, or ''.
	 * @return string
	 */
	private static function open_at( string $at ): string {
		$parts  = Filters::at_parts( $at );
		$chosen = null === $parts ? '' : sprintf( '%02d%02d', intdiv( $parts['minute'], 60 ), $parts['minute'] % 60 );
		$days   = array(
			''         => __( 'Choose a day', 'guam-restaurants-core' ),
			'today'    => __( 'Today', 'guam-restaurants-core' ),
			'tomorrow' => __( 'Tomorrow', 'guam-restaurants-core' ),
		) + Restaurant_View::day_names();
		$times  = array( '' => __( 'Choose a time', 'guam-restaurants-core' ) );
		for ( $minute = 0; $minute < Clock::MINUTES_PER_DAY; $minute += 30 ) {
			$time                                   = sprintf( '%02d:%02d', intdiv( $minute, 60 ), $minute % 60 );
			$times[ str_replace( ':', '', $time ) ] = Restaurant_View::time_label( $time );
		}
		if ( '' !== $chosen && ! isset( $times[ $chosen ] ) ) {
			$times[ $chosen ] = Restaurant_View::time_label( substr( $chosen, 0, 2 ) . ':' . substr( $chosen, 2 ) );
		}
		$options = static function ( array $choices, string $selected ): string {
			$html = '';
			foreach ( $choices as $value => $label ) {
				$html .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $value ), selected( (string) $value, $selected, false ), esc_html( $label ) );
			}
			return $html;
		};
		return sprintf(
			'<fieldset class="gr-open-at"><legend>%1$s</legend><div class="gr-open-at__fields"><label>%2$s <select name="%3$s">%4$s</select></label> <label>%5$s <select name="%6$s">%7$s</select></label></div><p class="gr-open-at__hint">%8$s</p></fieldset>',
			esc_html__( 'Open at', 'guam-restaurants-core' ),
			esc_html__( 'Day', 'guam-restaurants-core' ),
			esc_attr( Filters::AT_DAY ),
			$options( $days, null === $parts ? '' : $parts['day'] ),
			esc_html__( 'Time', 'guam-restaurants-core' ),
			esc_attr( Filters::AT_TIME ),
			$options( $times, $chosen ),
			esc_html__( 'Guam time, by listed hours. Choose both a day and a time.', 'guam-restaurants-core' )
		);
	}

	/**
	 * One facet: a disclosure with a checkbox per term.
	 *
	 * @param string   $param    URL parameter.
	 * @param string   $taxonomy Taxonomy.
	 * @param string   $label    Label.
	 * @param string[] $selected Selected slugs.
	 * @return string
	 */
	private static function facet( string $param, string $taxonomy, string $label, array $selected ): string {
		$terms = self::facet_terms( $taxonomy );
		if ( ! $terms ) {
			return '';
		}
		$boxes = '';
		foreach ( $terms as $term ) {
			$boxes .= sprintf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( $param ),
				esc_attr( $term->slug ),
				checked( in_array( $term->slug, $selected, true ), true, false ),
				esc_html( $term->name )
			);
		}
		$count = count( $selected );
		return sprintf(
			'<details class="gr-facet" data-gr-facet="%1$s"%2$s><summary>%3$s<span class="gr-facet__count">%4$s</span></summary><fieldset><legend class="screen-reader-text">%3$s</legend><div class="gr-facet__options">%5$s</div></fieldset></details>',
			esc_attr( $param ),
			$count ? ' open' : '',
			esc_html( $label ),
			/* translators: %d: number of selected options. */
			$count ? esc_html( sprintf( _n( ' (%d selected)', ' (%d selected)', $count, 'guam-restaurants-core' ), $count ) ) : '',
			$boxes
		);
	}

	/**
	 * Terms offered in a facet: those with listings, prices by rank, others by name.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return \WP_Term[]
	 */
	public static function facet_terms( string $taxonomy ): array {
		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
			'orderby'    => 'name',
		);
		if ( Content_Types::TAX_PRICE === $taxonomy ) {
			$args['meta_key'] = Term_Meta::RANK; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- four terms.
			$args['orderby']  = 'meta_value_num';
		}
		$terms = get_terms( $args );
		return is_array( $terms ) ? $terms : array();
	}
}
