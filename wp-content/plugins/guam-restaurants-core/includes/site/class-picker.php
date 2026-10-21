<?php
/**
 * Random place picker: "What should I eat?"
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Ads\Context as Ad_Context;
use GuamRestaurants\Core\Ads\Slot;
use GuamRestaurants\Core\Ads\Tracking;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Picks one restaurant at random from those matching the directory's
 * filters (village, mall, cuisine, price, open now, award winners).
 *
 * - Every match has the same chance. Sponsored placements, premium
 *   profiles and third-party ratings play no part in the pick (CLAUDE.md
 *   rule 5).
 * - Above the pick sits one sponsored slot (ad product `picker_sponsor`),
 *   in its own box labeled "Sponsored" and targeted by the visitor's
 *   village, mall and food type. While no ad is running there, the box
 *   invites businesses to advertise instead.
 * - Only published restaurants that are not closed, permanently or
 *   temporarily, are picked.
 * - Places already shown are passed back as `not` so "Pick again" does not
 *   repeat them; once every match has been shown, the round starts over.
 * - Nothing about the visitor is stored. Their choices live only in the
 *   page's URL, so a pick can be shared.
 *
 * The form is a plain GET form to the page it sits on, so it works without
 * JavaScript; assets/site/picker.js asks the REST endpoint instead.
 */
final class Picker {

	/**
	 * URL parameter that asks the page for a pick.
	 */
	public const PICK = 'pick';

	/**
	 * URL parameter listing restaurants already shown.
	 */
	public const NOT = 'not';

	/**
	 * Most restaurants remembered as already shown.
	 */
	public const MAX_NOT = 50;

	/**
	 * Facets the picker offers, in form order.
	 */
	public const FACETS = array( 'village', 'mall', 'cuisine', 'price' );

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'no_page_cache_for_picks' ) );
		add_filter( 'wp_robots', array( self::class, 'robots' ) );
	}

	/**
	 * Filters for a pick: the directory's, without the keyword search, "open
	 * late", "open at" or sort.
	 *
	 * @param array<string, mixed> $params Raw parameters.
	 * @return array<string, mixed>
	 */
	public static function filters( array $params ): array {
		unset( $params[ Filters::SEARCH ], $params[ Filters::LATE ], $params[ Filters::AT ], $params[ Filters::AT_DAY ], $params[ Filters::AT_TIME ], $params[ Filters::SORT ], $params[ Filters::WHERE ], $params['page'] );
		$filters = Filters::from_params( $params );
		foreach ( array_keys( Filters::FACETS ) as $facet ) {
			if ( ! in_array( $facet, self::FACETS, true ) ) {
				$filters['facets'][ $facet ] = array();
			}
		}
		return $filters;
	}

	/**
	 * Restaurant IDs already shown, from a comma-separated list or an array.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public static function seen( $value ): array {
		if ( is_scalar( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( is_scalar( $id ) && 1 === preg_match( '/^\s*[1-9][0-9]*\s*$/', (string) $id ) ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}
		$ids = array_values( $ids );
		return array_slice( $ids, -self::MAX_NOT );
	}

	/**
	 * IDs of every restaurant that can be picked with these filters.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int[]
	 */
	public static function candidates( array $filters ): array {
		$filters['page'] = 1;
		$filters['sort'] = '';
		$key             = array( Filters::to_params( $filters ), ! empty( $filters['open'] ) ? Clock::minute_of_week() : null );
		return (array) Cache::remember(
			'pick',
			$key,
			static function () use ( $filters ): array {
				$query = Directory_Query::query(
					$filters,
					-1,
					array(
						'fields'        => 'ids',
						'no_found_rows' => true,
						'orderby'       => 'ID',
						'order'         => 'ASC',
						// Permanently closed ones are already left out by Directory_Query.
						'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- postmeta (post_id, meta_key) key.
							'relation' => 'OR',
							array(
								'key'     => Restaurant_Meta::STATUS,
								'compare' => 'NOT EXISTS',
							),
							array(
								'key'     => Restaurant_Meta::STATUS,
								'value'   => 'temporarily_closed',
								'compare' => '!=',
							),
						),
					)
				);
				return array_map( 'intval', $query->posts );
			},
			! empty( $filters['open'] ) ? 60 : Cache::TTL
		);
	}

	/**
	 * Picks one restaurant.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param int[]                $seen    Restaurants already shown.
	 * @return array{id: int, total: int, left: int, seen: int[]} id 0 when nothing matches. left counts matches not yet shown after this one; seen is the list to send with the next pick.
	 */
	public static function pick( array $filters, array $seen = array() ): array {
		$all   = self::candidates( $filters );
		$total = count( $all );
		if ( 0 === $total ) {
			return array(
				'id'    => 0,
				'total' => 0,
				'left'  => 0,
				'seen'  => array(),
			);
		}
		$seen = array_values( array_intersect( $seen, $all ) );
		$pool = array_values( array_diff( $all, $seen ) );
		if ( ! $pool ) {
			// Every match has been shown: start a new round, but not with the last one.
			$last = (int) end( $seen );
			$seen = array();
			$pool = $total > 1 ? array_values( array_diff( $all, array( $last ) ) ) : $all;
		}
		$id     = $pool[ random_int( 0, count( $pool ) - 1 ) ];
		$seen[] = $id;
		return array(
			'id'    => $id,
			'total' => $total,
			'left'  => count( $pool ) - 1,
			'seen'  => array_slice( $seen, -self::MAX_NOT ),
		);
	}

	/**
	 * Whether this request asks for a pick.
	 *
	 * @return bool
	 */
	public static function is_pick_request(): bool {
		return isset( $_GET[ self::PICK ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
	}

	/**
	 * A pick changes on every load, so keep it out of page caches.
	 *
	 * @return void
	 */
	public static function no_page_cache_for_picks(): void {
		if ( self::is_pick_request() && is_singular() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constant read by page cache plugins.
		}
	}

	/**
	 * Pick results are not pages for search engines.
	 *
	 * @param array<string, bool|string> $robots Directives.
	 * @return array<string, bool|string>
	 */
	public static function robots( $robots ): array {
		$robots = (array) $robots;
		if ( self::is_pick_request() && is_singular() ) {
			unset( $robots['index'], $robots['max-image-preview'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * The whole picker: form, plus a pick when the URL asks for one.
	 *
	 * @param array<string, mixed> $params Raw parameters (for example $_GET, unslashed).
	 * @param string               $action URL of the page the picker is on.
	 * @return string
	 */
	public static function render( array $params, string $action ): string {
		Assets::enqueue( 'picker' );
		// Picks arriving over REST can carry an ad; the beacon counts it.
		wp_enqueue_script( Tracking::SCRIPT );
		$filters = self::filters( $params );
		$result  = '';
		if ( isset( $params[ self::PICK ] ) ) {
			$result = self::result( self::pick( $filters, self::seen( $params[ self::NOT ] ?? array() ) ), $filters, $action );
		}
		return sprintf(
			'<div class="gr-picker">%1$s<div class="gr-picker__result" data-gr-picker-result aria-live="polite" tabindex="-1">%2$s</div></div>',
			self::form( $filters, $action ),
			$result
		);
	}

	/**
	 * The choices form.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param string               $action  Form action.
	 * @return string
	 */
	public static function form( array $filters, string $action ): string {
		$id     = wp_unique_id( 'gr-picker-' );
		$fields = self::select( $id . '-village', 'village', __( 'Village', 'guam-restaurants-core' ), __( 'Anywhere on Guam', 'guam-restaurants-core' ), Content_Types::TAX_VILLAGE, $filters )
			. self::select( $id . '-mall', 'mall', __( 'Mall', 'guam-restaurants-core' ), __( 'Any, in a mall or not', 'guam-restaurants-core' ), Content_Types::TAX_MALL, $filters );

		$prices = '';
		foreach ( Directory_View::facet_terms( Content_Types::TAX_PRICE ) as $term ) {
			$rank    = (int) get_term_meta( (int) $term->term_id, Term_Meta::RANK, true );
			$prices .= self::chip(
				'price[]',
				$term->slug,
				in_array( $term->slug, (array) $filters['facets']['price'], true ),
				/* translators: 1: price level, 2: highest level. */
				'<span aria-hidden="true">' . esc_html( $term->name ) . '</span><span class="screen-reader-text">' . esc_html( $rank > 0 ? sprintf( __( 'Price level %1$d of %2$d', 'guam-restaurants-core' ), $rank, 4 ) : $term->name ) . '</span>'
			);
		}
		if ( '' !== $prices ) {
			$fields .= sprintf(
				'<fieldset class="gr-picker__field gr-picker__chips"><legend>%1$s</legend><div class="gr-picker__options">%2$s</div></fieldset>',
				esc_html__( 'Price (any you tick)', 'guam-restaurants-core' ),
				$prices
			);
		}

		$cuisines = Directory_View::facet_terms( Content_Types::TAX_CUISINE );
		if ( $cuisines ) {
			$boxes = '';
			foreach ( $cuisines as $term ) {
				$boxes .= sprintf(
					'<label><input type="checkbox" name="cuisine[]" value="%1$s"%2$s> %3$s</label>',
					esc_attr( $term->slug ),
					checked( in_array( $term->slug, (array) $filters['facets']['cuisine'], true ), true, false ),
					esc_html( $term->name )
				);
			}
			$count   = count( (array) $filters['facets']['cuisine'] );
			$fields .= sprintf(
				'<details class="gr-facet gr-picker__field" data-gr-facet="cuisine"%1$s><summary>%2$s<span class="gr-facet__count">%3$s</span></summary><fieldset><legend class="screen-reader-text">%2$s</legend><div class="gr-facet__options">%4$s</div></fieldset></details>',
				$count ? ' open' : '',
				esc_html__( 'Food type (any you tick)', 'guam-restaurants-core' ),
				/* translators: %d: number of selected options. */
				$count ? esc_html( sprintf( _n( ' (%d selected)', ' (%d selected)', $count, 'guam-restaurants-core' ), $count ) ) : '',
				$boxes
			);
		}

		$fields .= sprintf(
			'<fieldset class="gr-picker__field gr-picker__chips"><legend class="screen-reader-text">%1$s</legend><div class="gr-picker__options">%2$s%3$s</div></fieldset>',
			esc_html__( 'More choices', 'guam-restaurants-core' ),
			self::chip( Filters::OPEN, Filters::OPEN_NOW, ! empty( $filters['open'] ), esc_html__( 'Open now', 'guam-restaurants-core' ) ),
			self::chip( Filters::AWARD, Filters::AWARD_WINNER, ! empty( $filters['award'] ), esc_html__( 'Award winners only', 'guam-restaurants-core' ) )
		);

		return sprintf(
			'<form class="gr-picker__form" method="get" action="%1$s" data-gr-picker aria-label="%2$s"><input type="hidden" name="%3$s" value="1">%4$s<div class="gr-picker__actions"><button type="submit" class="wp-element-button gr-picker__go">%5$s</button> <a class="gr-picker__clear" href="%1$s">%6$s</a></div></form>',
			esc_url( $action ),
			esc_attr__( 'Choices for a random pick', 'guam-restaurants-core' ),
			esc_attr( self::PICK ),
			$fields,
			esc_html__( 'Pick a place for me', 'guam-restaurants-core' ),
			esc_html__( 'Clear choices', 'guam-restaurants-core' )
		);
	}

	/**
	 * The picked restaurant, how many matched, and the next steps.
	 *
	 * @param array{id: int, total: int, left: int, seen: int[]} $pick    Pick.
	 * @param array<string, mixed>                               $filters Filters.
	 * @param string                                             $action  URL of the picker page.
	 * @return string
	 */
	public static function result( array $pick, array $filters, string $action ): string {
		if ( 0 === $pick['id'] ) {
			return sprintf(
				'<p class="gr-picker__summary">%1$s</p><p><a href="%2$s">%3$s</a></p>',
				esc_html__( 'Nothing matches all of these choices. Try fewer of them.', 'guam-restaurants-core' ),
				esc_url( $action ),
				esc_html__( 'Clear choices', 'guam-restaurants-core' )
			);
		}

		Assets::enqueue( 'hours' );
		if ( 1 === $pick['total'] ) {
			$summary = __( 'Only one place matches your choices:', 'guam-restaurants-core' );
		} else {
			/* translators: %s: number of matching restaurants. */
			$summary = sprintf( _n( 'Picked at random from %s place that matches:', 'Picked at random from %s places that match:', $pick['total'], 'guam-restaurants-core' ), number_format_i18n( $pick['total'] ) );
		}

		$params              = array_map( array( Directory_View::class, 'encode' ), Filters::to_params( $filters ) );
		$again               = $params;
		$again[ self::PICK ] = '1';
		$again[ self::NOT ]  = implode( ',', $pick['seen'] );
		$again_url           = add_query_arg( $again, $action );
		$all_url             = Directory_View::page_url( $filters, array() );
		$again_label         = 1 === $pick['total'] ? '' : esc_html__( 'Pick again', 'guam-restaurants-core' );
		$note                = '';
		if ( $pick['total'] > 1 && 0 === $pick['left'] ) {
			$note = '<p class="gr-picker__note">' . esc_html__( 'That was the last one you had not seen. Picking again starts over.', 'guam-restaurants-core' ) . '</p>';
		}
		if ( ! empty( $filters['open'] ) ) {
			$note .= '<p class="gr-picker__note">' . esc_html__( 'Open now uses Guam time and counts only restaurants whose hours we have.', 'guam-restaurants-core' ) . '</p>';
		}

		return sprintf(
			'%8$s<p class="gr-picker__summary">%1$s</p><div class="gr-picker__card" data-gr-picked="%2$d">%3$s</div>%4$s<div class="gr-picker__next">%5$s<a class="gr-picker__all" href="%6$s">%7$s</a></div>',
			esc_html( $summary ),
			(int) $pick['id'],
			Restaurant_View::card( (int) $pick['id'], 2 ),
			$note,
			'' === $again_label ? '' : sprintf( '<a class="wp-element-button gr-picker__again" href="%1$s" rel="nofollow" data-gr-not="%2$s">%3$s</a> ', esc_url( $again_url ), esc_attr( implode( ',', $pick['seen'] ) ), $again_label ),
			esc_url( $all_url ),
			/* translators: %s: number of matching restaurants. */
			esc_html( sprintf( _n( 'See the %s match', 'See all %s matches', $pick['total'], 'guam-restaurants-core' ), number_format_i18n( $pick['total'] ) ) ),
			self::sponsor( $filters )
		);
	}

	/**
	 * The sponsored slot above a pick, or, with no ad running, an
	 * invitation to advertise there.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return string
	 */
	public static function sponsor( array $filters ): string {
		$ad = Slot::render( 'picker_sponsor', 1, Ad_Context::for_picker( (array) $filters['facets'] ) );
		if ( '' !== $ad ) {
			return '<div class="gr-picker__sponsor">' . $ad . '</div>';
		}
		$advertise = Pages::url( 'advertise' );
		if ( '' === $advertise ) {
			return '';
		}
		return sprintf(
			'<div class="gr-picker__sponsor"><aside class="gr-sponsored gr-picker__open-slot" aria-label="%1$s"><p class="gr-sponsored__label">%2$s</p><p class="gr-ad__headline"><a href="%3$s">%4$s</a></p><p class="gr-ad__text">%5$s</p></aside></div>',
			esc_attr__( 'Advertising space', 'guam-restaurants-core' ),
			esc_html__( 'Advertise here', 'guam-restaurants-core' ),
			esc_url( $advertise ),
			esc_html__( 'Your restaurant could be here, above every pick', 'guam-restaurants-core' ),
			esc_html__( 'People use this page when they are deciding where to eat right now. Put your restaurant in front of them with a sponsored spot, shown by village or food type.', 'guam-restaurants-core' )
		);
	}

	/**
	 * A one-choice dropdown for a facet. Village options show their aliases
	 * ("Tamuning (Tumon, Harmon)").
	 *
	 * @param string               $id       Element ID.
	 * @param string               $param    URL parameter.
	 * @param string               $label    Label.
	 * @param string               $any      Label of the empty option.
	 * @param string               $taxonomy Taxonomy.
	 * @param array<string, mixed> $filters  Filters.
	 * @return string
	 */
	private static function select( string $id, string $param, string $label, string $any, string $taxonomy, array $filters ): string {
		$terms = Directory_View::facet_terms( $taxonomy );
		if ( ! $terms ) {
			return '';
		}
		$current = (array) $filters['facets'][ $param ];
		$options = '';
		foreach ( $terms as $term ) {
			$name = $term->name;
			if ( Content_Types::TAX_VILLAGE === $taxonomy ) {
				$aliases = array_filter( array_map( 'strval', (array) get_term_meta( (int) $term->term_id, Term_Meta::ALIASES, true ) ) );
				$aliases = array_slice( array_values( array_diff( $aliases, array( $term->name ) ) ), 0, 3 );
				if ( $aliases ) {
					$name .= ' (' . implode( ', ', $aliases ) . ')';
				}
			}
			$options .= sprintf( '<option value="%1$s"%2$s %4$s>%3$s</option>', esc_attr( $term->slug ), selected( in_array( $term->slug, $current, true ), true, false ), esc_html( $name ), Languages::keep_attributes() );
		}
		return sprintf(
			'<div class="gr-picker__field gr-picker__select"><label for="%1$s">%2$s</label><select id="%1$s" name="%3$s"><option value="">%4$s</option>%5$s</select></div>',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $param ),
			esc_html( $any ),
			$options
		);
	}

	/**
	 * A checkbox shown as a button, like the directory's quick filters.
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
}
