<?php
/**
 * Server-rendered blocks for the public site.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Ads\Slot;
use GuamRestaurants\Core\Appearance\Color_Scheme;
use GuamRestaurants\Core\Awards\Display as Awards_Display;
use GuamRestaurants\Core\Awards\Pages as Awards_Pages;
use GuamRestaurants\Core\Awards\Policy as Awards_Policy;
use GuamRestaurants\Core\Awards\Repository as Awards;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Owners\Listing_Stats;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Saved\View as Saved_View;
use GuamRestaurants\Core\Translation\Integration as Translation;
use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the blocks in blocks/ and renders them. The theme only places
 * them in templates (CLAUDE.md rule 6). All output is server-rendered HTML;
 * scripts only enhance it.
 */
final class Blocks {

	public const EDITOR_SCRIPT = 'guam-restaurants-site-blocks-editor';

	/**
	 * Block folder => render method.
	 */
	public const BLOCKS = array(
		'directory'           => 'directory',
		'search-form'         => 'search_form',
		'term-links'          => 'term_links',
		'restaurant-row'      => 'restaurant_row',
		'restaurant-hero'     => 'restaurant_hero',
		'recent-changes'      => 'recent_changes',
		'awards-feature'      => 'awards_feature',
		'sponsored-slot'      => 'sponsored_slot',
		'term-intro'          => 'term_intro',
		'restaurant-details'  => 'restaurant_details',
		'restaurant-map'      => 'restaurant_map',
		'change-history'      => 'change_history',
		'listing-actions'     => 'listing_actions',
		'listing-context'     => 'listing_context',
		'map-explorer'        => 'map_explorer',
		'data-attributions'   => 'data_attributions',
		'policy-links'        => 'policy_links',
		'color-scheme-toggle' => 'color_scheme_toggle',
		'random-picker'       => 'random_picker',
		'saved-list'          => 'saved_list',
		'account-links'       => 'account_links',
		'language-switcher'   => 'language_switcher',
	);

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_blocks' ) );
	}

	/**
	 * Registers the editor script and every block.
	 *
	 * @return void
	 */
	public static function register_blocks(): void {
		$asset = require GUAM_RESTAURANTS_CORE_DIR . 'blocks/site-editor/editor.asset.php';
		wp_register_script( self::EDITOR_SCRIPT, plugins_url( 'blocks/site-editor/editor.js', GUAM_RESTAURANTS_CORE_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'guam-restaurants-core' );

		foreach ( self::BLOCKS as $folder => $method ) {
			register_block_type(
				GUAM_RESTAURANTS_CORE_DIR . 'blocks/' . $folder,
				array( 'render_callback' => array( self::class, $method ) )
			);
		}
	}

	/**
	 * The restaurant a block is about: its context post, else the queried one.
	 *
	 * @param \WP_Block|null $block Block.
	 * @return int 0 when not a restaurant.
	 */
	private static function restaurant_id( $block ): int {
		$id = $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_queried_object_id();
		return Content_Types::POST_TYPE === get_post_type( $id ) ? $id : 0;
	}

	/**
	 * Wraps output in the block wrapper attributes.
	 *
	 * @param string               $tag     Tag.
	 * @param string               $inner   Inner HTML.
	 * @param array<string, mixed> $extra   Extra wrapper attributes.
	 * @return string
	 */
	private static function wrap( string $tag, string $inner, array $extra = array() ): string {
		return sprintf( '<%1$s %2$s>%3$s</%1$s>', $tag, get_block_wrapper_attributes( $extra ), $inner );
	}

	/**
	 * Directory: the main query on directory pages, else a query from the URL.
	 *
	 * @return string
	 */
	public static function directory(): string {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query && Directory_Query::is_directory( $wp_query ) ) {
			$locked  = Directory_Query::locked( $wp_query );
			$filters = Directory_Query::current_filters( $wp_query );
			$query   = $wp_query;
		} else {
			$locked  = array();
			$filters = Directory_Query::current_filters( null );
			$query   = Directory_Query::query( $filters );
		}
		return self::wrap( 'div', Directory_View::render( $query, $filters, $locked ) );
	}

	/**
	 * Search box.
	 *
	 * @return string
	 */
	public static function search_form(): string {
		Assets::enqueue();
		$id     = wp_unique_id( 'gr-search-' );
		$groups = '';
		foreach ( array(
			'village' => array( Content_Types::TAX_VILLAGE, __( 'Villages', 'guam-restaurants-core' ) ),
			'mall'    => array( Content_Types::TAX_MALL, __( 'Malls', 'guam-restaurants-core' ) ),
		) as $facet => $group ) {
			$options = '';
			foreach ( Directory_View::facet_terms( $group[0] ) as $term ) {
				$options .= sprintf( '<option value="%1$s" %3$s>%2$s</option>', esc_attr( $facet . ':' . $term->slug ), esc_html( $term->name ), Languages::keep_attributes() );
			}
			$groups .= '' === $options ? '' : sprintf( '<optgroup label="%1$s">%2$s</optgroup>', esc_attr( $group[1] ), $options );
		}
		return self::wrap(
			'form',
			sprintf(
				'<span class="gr-search__field gr-search__what"><label for="%1$s">%2$s</label><input type="search" id="%1$s" name="%3$s" maxlength="%4$d" placeholder="%5$s"></span>%7$s<button type="submit" class="wp-element-button">%6$s</button>',
				esc_attr( $id ),
				esc_html__( 'What', 'guam-restaurants-core' ),
				esc_attr( Filters::SEARCH ),
				Filters::MAX_SEARCH_LENGTH,
				esc_attr__( 'Name, dish, or “cheap Korean in Tumon”', 'guam-restaurants-core' ),
				esc_html__( 'Search', 'guam-restaurants-core' ),
				'' === $groups ? '' : sprintf(
					'<span class="gr-search__field gr-search__where"><label for="%1$s-where">%2$s</label><select id="%1$s-where" name="%3$s"><option value="">%4$s</option>%5$s</select></span>',
					esc_attr( $id ),
					esc_html__( 'Where', 'guam-restaurants-core' ),
					esc_attr( Filters::WHERE ),
					esc_html__( 'Anywhere on Guam', 'guam-restaurants-core' ),
					$groups
				)
			),
			array(
				'class'  => 'gr-search',
				'role'   => 'search',
				'method' => 'get',
				'action' => esc_url( (string) get_post_type_archive_link( Content_Types::POST_TYPE ) ),
			)
		);
	}

	/**
	 * Random place picker.
	 *
	 * @return string
	 */
	public static function random_picker(): string {
		// Read-only choices on a public page; no state changes, so no nonce.
		$params = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Picker sanitizes each value.
		$action = is_singular() ? (string) get_permalink( get_queried_object_id() ) : home_url( '/' );
		return self::wrap( 'div', Picker::render( is_array( $params ) ? $params : array(), $action ) );
	}

	/**
	 * The Saved page: the visitor's list, or a shared one, with the map.
	 *
	 * @return string
	 */
	public static function saved_list(): string {
		return Saved_View::render_page();
	}

	/**
	 * Header links to the Saved page and to sign in or the account.
	 *
	 * @return string
	 */
	public static function account_links(): string {
		return Saved_View::account_links();
	}

	/**
	 * Header language links (English, 日本語, 한국어), shown once a translation plugin publishes them.
	 *
	 * @return string
	 */
	public static function language_switcher(): string {
		return Translation::switcher();
	}

	/**
	 * Term chips.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function term_links( $attributes ): string {
		$taxonomy = (string) ( $attributes['taxonomy'] ?? Content_Types::TAX_VILLAGE );
		if ( ! in_array( $taxonomy, Filters::FACETS, true ) ) {
			return '';
		}
		$terms = Directory_View::facet_terms( $taxonomy );
		$limit = (int) ( $attributes['limit'] ?? 0 );
		if ( $limit > 0 ) {
			usort(
				$terms,
				static function ( \WP_Term $a, \WP_Term $b ): int {
					return $b->count === $a->count ? strcmp( $a->name, $b->name ) : $b->count <=> $a->count;
				}
			);
			$terms = array_slice( $terms, 0, $limit );
			usort(
				$terms,
				static function ( \WP_Term $a, \WP_Term $b ): int {
					return strcmp( $a->name, $b->name );
				}
			);
		}
		if ( ! $terms ) {
			return '';
		}
		Assets::enqueue();
		$counts = ! empty( $attributes['showCounts'] );
		$items  = '';
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$items .= sprintf(
				'<li><a class="gr-chip" href="%1$s">%2$s%3$s</a></li>',
				esc_url( $link ),
				Restaurant_View::is_place_taxonomy( $taxonomy ) ? Languages::keep( esc_html( $term->name ) ) : esc_html( $term->name ),
				/* translators: %s: number of restaurants. */
				$counts ? ' <span class="gr-chip__count"><span class="screen-reader-text">' . esc_html( sprintf( _n( '%s restaurant', '%s restaurants', (int) $term->count, 'guam-restaurants-core' ), number_format_i18n( (int) $term->count ) ) ) . '</span><span aria-hidden="true">' . esc_html( number_format_i18n( (int) $term->count ) ) . '</span></span>' : ''
			);
		}
		$list    = 'tiles' === ( $attributes['style'] ?? 'chips' ) ? 'gr-chips gr-chips--tiles' : 'gr-chips';
		$heading = trim( (string) ( $attributes['heading'] ?? '' ) );
		if ( '' === $heading ) {
			return self::wrap( 'ul', $items, array( 'class' => $list ) );
		}
		// The heading lives in the block so a taxonomy with no listed terms
		// (for example, before any mall is verified) leaves no empty section.
		return self::wrap( 'section', '<h2 class="wp-block-heading">' . esc_html( $heading ) . '</h2><ul class="' . esc_attr( $list ) . '">' . $items . '</ul>', array( 'class' => 'gr-term-links' ) );
	}

	/**
	 * A row of restaurant cards with a link to the full list.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function restaurant_row( $attributes ): string {
		$limit = max( 1, min( 24, (int) ( $attributes['limit'] ?? 8 ) ) );
		if ( 'new' === ( $attributes['source'] ?? 'filters' ) ) {
			$ids = wp_list_pluck(
				(array) Cache::remember(
					'recent',
					array( 'openings', Restaurant_View::NEW_DAYS, $limit ),
					static function () use ( $limit ) {
						return Queries::recent_openings( Restaurant_View::NEW_DAYS, $limit );
					},
					600
				),
				'restaurant_id'
			);
			$all = Pages::url( 'recent' );
		} else {
			wp_parse_str( (string) ( $attributes['query'] ?? '' ), $params );
			$filters = Filters::from_params( $params );
			$ids     = Directory_Query::query(
				$filters,
				$limit,
				array(
					'fields'        => 'ids',
					'no_found_rows' => true,
				)
			)->posts;
			$all     = Directory_View::page_url( $filters, array() );
		}
		if ( ! $ids ) {
			return '';
		}
		Assets::enqueue( 'hours' );
		$heading = trim( (string) ( $attributes['heading'] ?? '' ) );
		$cards   = '';
		foreach ( $ids as $id ) {
			$cards .= '<li>' . Restaurant_View::card( (int) $id, '' === $heading ? 2 : 3 ) . '</li>';
		}
		return self::wrap(
			'section',
			sprintf(
				'<div class="gr-row__head">%1$s%2$s</div><ul class="gr-cards gr-row__list" role="list">%3$s</ul>',
				'' === $heading ? '' : '<h2 class="wp-block-heading">' . esc_html( $heading ) . '</h2>',
				'' === $all ? '' : sprintf( '<a class="gr-row__all" href="%1$s">%2$s</a>', esc_url( $all ), esc_html__( 'See all', 'guam-restaurants-core' ) ),
				$cards
			),
			array( 'class' => 'gr-row' )
		);
	}

	/**
	 * Recent openings, reopenings or closures.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function recent_changes( $attributes ): string {
		$kind   = (string) ( $attributes['kind'] ?? 'openings' );
		$days   = max( 1, min( 3650, (int) ( $attributes['days'] ?? 90 ) ) );
		$limit  = max( 1, min( 200, (int) ( $attributes['limit'] ?? 8 ) ) );
		$layout = 'list' === ( $attributes['layout'] ?? 'strip' ) ? 'list' : 'strip';

		$rows = Cache::remember(
			'recent',
			array( $kind, $days, $limit ),
			static function () use ( $kind, $days, $limit ) {
				switch ( $kind ) {
					case 'closures':
						return Queries::recent_closures( $days, $limit );
					case 'reopenings':
						return Queries::recent_reopenings( $days, $limit );
					default:
						return Queries::recent_openings( $days, $limit );
				}
			},
			600
		);
		$rows = (array) $rows;

		Assets::enqueue( 'hours' );
		if ( ! $rows ) {
			$empty = array(
				/* translators: %d: number of days. */
				'openings'   => _n( 'No new restaurants recorded in the last %d day.', 'No new restaurants recorded in the last %d days.', $days, 'guam-restaurants-core' ),
				/* translators: %d: number of days. */
				'reopenings' => _n( 'No reopenings recorded in the last %d day.', 'No reopenings recorded in the last %d days.', $days, 'guam-restaurants-core' ),
				/* translators: %d: number of days. */
				'closures'   => _n( 'No closures recorded in the last %d day.', 'No closures recorded in the last %d days.', $days, 'guam-restaurants-core' ),
			);
			return self::wrap( 'div', '<p class="gr-empty">' . esc_html( sprintf( $empty[ $kind ] ?? $empty['openings'], $days ) ) . '</p>', array( 'class' => 'gr-recent gr-recent--empty' ) );
		}

		$date_key = array(
			'closures'   => 'closed_at',
			'reopenings' => 'reopened_at',
		)[ $kind ] ?? 'first_seen';
		$items    = '';
		foreach ( $rows as $row ) {
			$id     = (int) $row['restaurant_id'];
			$name   = '' !== $row['url'] ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $row['url'] ), Languages::keep( esc_html( $row['name'] ) ) ) : Languages::keep( esc_html( $row['name'] ) );
			$date   = 'closures' === $kind && '' !== (string) ( $row['closed_on'] ?? '' ) ? Restaurant_View::partial_date( (string) $row['closed_on'] ) : Clock::local_date( (string) $row[ $date_key ] );
			$meta   = array_filter(
				array(
					esc_html( Restaurant_View::term_names( $id, Content_Types::TAX_CUISINE ) ),
					Restaurant_View::place_html( $id ),
				)
			);
			$items .= sprintf(
				'<li class="gr-recent__item"><h3 class="gr-recent__name">%1$s</h3>%2$s<p class="gr-recent__date"><time>%3$s</time></p></li>',
				$name,
				$meta ? '<p class="gr-recent__meta">' . implode( ' · ', $meta ) . '</p>' : '',
				esc_html( $date )
			);
		}
		return self::wrap( 'ul', $items, array( 'class' => 'gr-recent gr-recent--' . $layout ) );
	}

	/**
	 * Latest recorded award winners.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function awards_feature( $attributes ): string {
		$limit = max( 1, min( 24, (int) ( $attributes['limit'] ?? 6 ) ) );
		Assets::enqueue();

		$rows = Cache::remember(
			'awards-feature',
			array( $limit ),
			static function () use ( $limit ) {
				$years = Awards::years();
				if ( ! $years ) {
					return array();
				}
				$winners = array_filter(
					Awards::query( array( 'year' => $years[0] ) ),
					static function ( array $row ): bool {
						return 'winner' === $row['placement'] && 'publish' === $row['restaurant_status'];
					}
				);
				return array_slice( array_values( $winners ), 0, $limit );
			}
		);

		$more = sprintf( '<p class="gr-awards-feature__more"><a href="%1$s">%2$s</a></p>', esc_url( Awards_Pages::url( 'archive' ) ), esc_html__( 'All award results', 'guam-restaurants-core' ) );
		if ( ! $rows ) {
			return self::wrap( 'div', '<p class="gr-empty">' . esc_html__( 'Award results have not been added yet. When they are, winners appear here with a link to where each result was published.', 'guam-restaurants-core' ) . '</p>' . $more, array( 'class' => 'gr-awards-feature gr-awards-feature--empty' ) );
		}
		$items = '';
		foreach ( $rows as $row ) {
			$items .= sprintf(
				'<li><h3 class="gr-awards-feature__name">%1$s</h3><p>%2$s</p></li>',
				Awards_Display::restaurant_link( (int) $row['restaurant_id'], (string) $row['restaurant_name'], (string) $row['restaurant_status'] ),
				Awards_Display::line( $row )
			);
		}
		return self::wrap( 'div', '<ul class="gr-awards-feature__list" role="list">' . $items . '</ul>' . $more . Awards_Display::notice(), array( 'class' => 'gr-awards-feature' ) );
	}

	/**
	 * Sponsored slot: ads of one product that target this page (Ads\Slot).
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function sponsored_slot( $attributes ): string {
		$html = Slot::render( (string) ( $attributes['product'] ?? 'banner' ), (int) ( $attributes['count'] ?? 1 ) );
		return '' === $html ? '' : self::wrap( 'div', $html, array( 'class' => 'gr-sponsored-slot' ) );
	}

	/**
	 * Term introduction.
	 *
	 * @return string
	 */
	public static function term_intro(): string {
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, Filters::FACETS, true ) ) {
			return '';
		}
		Assets::enqueue();
		return self::wrap( 'div', Intro::render( $term ) );
	}

	/**
	 * Top of a restaurant page: photo, badges, name (the page's h1), summary and action buttons, led by the owner's premium Order or Reserve button.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function restaurant_hero( $attributes, $content = '', $block = null ): string {
		$id = self::restaurant_id( $block );
		if ( ! $id ) {
			return '';
		}
		Assets::enqueue( 'hours' );
		wp_enqueue_script( Assets::SCRIPTS['share'] );
		wp_enqueue_script( Listing_Stats::SCRIPT );

		$meta   = array_filter(
			array(
				esc_html( Restaurant_View::term_names( $id, Content_Types::TAX_CUISINE ) ),
				Restaurant_View::place_html( $id, Content_Types::TAX_MALL ),
				Restaurant_View::price_html( $id ),
			)
		);
		$status = Restaurant_View::status_label( $id );
		$hours  = Open_Hours::compact( $id );

		$actions = array();
		$claimed = (int) get_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) > 0;
		// The owner's Order or Reserve button is part of a premium profile, as in Owner_Content::render_block().
		$cta = $claimed && Premium::is_active( $id ) ? Premium::cta( $id ) : null;
		if ( null !== $cta ) {
			$actions[] = sprintf( '<a class="gr-action" href="%1$s" rel="noopener nofollow">%2$s</a>', esc_url( $cta['url'] ), esc_html( Premium::cta_actions()[ $cta['action'] ] ) );
		}
		$phone = (string) get_post_meta( $id, Restaurant_Meta::PHONE, true );
		if ( '' !== $phone ) {
			$actions[] = sprintf( '<a class="gr-action" href="tel:%1$s" data-gr-listing-click="phone">%2$s</a>', esc_attr( $phone ), esc_html__( 'Call', 'guam-restaurants-core' ) );
		}
		$directions = Maps::directions_url( $id );
		if ( '' !== $directions ) {
			$actions[] = sprintf( '<a class="gr-action" href="%1$s" rel="noopener">%2$s</a>', esc_url( $directions ), esc_html__( 'Directions', 'guam-restaurants-core' ) );
		}
		$website = (string) get_post_meta( $id, Restaurant_Meta::WEBSITE, true );
		if ( '' !== $website ) {
			$actions[] = sprintf( '<a class="gr-action" href="%1$s" rel="noopener" data-gr-listing-click="website">%2$s</a>', esc_url( $website ), esc_html__( 'Website', 'guam-restaurants-core' ) );
		}
		// Menu links come from the verified owner, so they show only while the listing is claimed (as in Owner_Content::render_block()).
		$menus = $claimed ? Owner_Content::get( $id )['menu_links'] : array();
		if ( $menus && 'menu' !== ( $cta['action'] ?? '' ) ) {
			$actions[] = sprintf( '<a class="gr-action" href="%1$s" rel="noopener nofollow">%2$s</a>', esc_url( $menus[0]['url'] ), esc_html__( 'Menu', 'guam-restaurants-core' ) );
		}
		$actions[] = Saved_View::action_button( $id );
		$actions[] = sprintf(
			'<button type="button" class="gr-action" data-gr-share data-gr-share-url="%1$s" data-gr-share-title="%2$s" data-gr-copied="%3$s" hidden>%4$s</button>',
			esc_url( (string) get_permalink( $id ) ),
			esc_attr( wp_strip_all_tags( get_the_title( $id ) ) ),
			esc_attr__( 'Link copied', 'guam-restaurants-core' ),
			esc_html__( 'Share', 'guam-restaurants-core' )
		);

		return self::wrap(
			'section',
			sprintf(
				'%1$s<div class="gr-hero__summary">%2$s<h1 class="gr-hero__name">%7$s</h1>%3$s%4$s<nav class="gr-hero__actions" aria-label="%5$s">%6$s</nav></div>',
				Restaurant_View::media( $id, 'large', 'gr-hero__media', true ),
				Restaurant_View::badges( $id ),
				$meta ? '<p class="gr-hero__meta">' . implode( ' <span aria-hidden="true">·</span> ', $meta ) . '</p>' : '',
				'' !== $status
					? '<p class="gr-hero__status">' . esc_html( $status ) . '</p>'
					: ( '' !== $hours ? '<p class="gr-card__open gr-hero__open" data-gr-hours="' . esc_attr( $hours ) . '"></p>' : '' ),
				esc_attr__( 'Restaurant actions', 'guam-restaurants-core' ),
				implode( '', $actions ),
				Languages::keep( esc_html( get_the_title( $id ) ) )
			),
			array(
				'class'           => 'gr-hero',
				'data-gr-listing' => (string) $id,
			)
		);
	}

	/**
	 * Address, contact, price and hours.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function restaurant_details( $attributes, $content = '', $block = null ): string {
		$id = self::restaurant_id( $block );
		if ( ! $id ) {
			return '';
		}
		Assets::enqueue( 'hours' );
		wp_enqueue_script( Listing_Stats::SCRIPT );

		$rows   = array();
		$place  = array_filter( array( Restaurant_View::term_links( $id, Content_Types::TAX_MALL ), Restaurant_View::term_links( $id, Content_Types::TAX_VILLAGE ) ) );
		$street = Restaurant_View::address( $id );
		if ( '' !== $street || $place ) {
			$rows[ __( 'Address', 'guam-restaurants-core' ) ] = implode( '<br>', array_filter( array( Languages::keep( esc_html( $street ) ), implode( ', ', $place ) ) ) );
		}
		$cuisine = Restaurant_View::term_links( $id, Content_Types::TAX_CUISINE );
		if ( '' !== $cuisine ) {
			$rows[ __( 'Cuisine', 'guam-restaurants-core' ) ] = $cuisine;
		}
		$price = Restaurant_View::price( $id );
		if ( null !== $price ) {
			$link = get_term_link( $price );
			$rows[ __( 'Price', 'guam-restaurants-core' ) ] = ( is_wp_error( $link ) ? '' : '<a href="' . esc_url( $link ) . '">' ) . Restaurant_View::price_html( $id ) . ( is_wp_error( $link ) ? '' : '</a>' ) . ( '' !== $price->description ? ' <span class="gr-details__hint">' . esc_html( $price->description ) . '</span>' : '' );
		}
		$phone = (string) get_post_meta( $id, Restaurant_Meta::PHONE, true );
		if ( '' !== $phone ) {
			$rows[ __( 'Phone', 'guam-restaurants-core' ) ] = sprintf( '<a href="tel:%1$s" data-gr-listing-click="phone">%2$s</a>', esc_attr( $phone ), esc_html( Restaurant_View::format_phone( $phone ) ) );
		}
		$website = (string) get_post_meta( $id, Restaurant_Meta::WEBSITE, true );
		if ( '' !== $website ) {
			$rows[ __( 'Website', 'guam-restaurants-core' ) ] = sprintf( '<a href="%1$s" rel="noopener" data-gr-listing-click="website">%2$s</a>', esc_url( $website ), esc_html( Restaurant_View::website_label( $website ) ) );
		}
		foreach ( array(
			Restaurant_Meta::FACEBOOK  => __( 'Facebook', 'guam-restaurants-core' ),
			Restaurant_Meta::INSTAGRAM => __( 'Instagram', 'guam-restaurants-core' ),
		) as $key => $label ) {
			$url = (string) get_post_meta( $id, $key, true );
			if ( '' !== $url && $url !== $website ) {
				$rows[ $label ] = sprintf( '<a href="%1$s" rel="noopener">%2$s</a>', esc_url( $url ), esc_html( (string) preg_replace( '#^https?://(www\.)?#i', '', untrailingslashit( $url ) ) ) );
			}
		}
		$directions = Maps::directions_url( $id );
		if ( '' !== $directions ) {
			/* translators: %s: restaurant name. */
			$rows[ __( 'Directions', 'guam-restaurants-core' ) ] = sprintf( '<a href="%1$s" rel="noopener">%2$s</a>', esc_url( $directions ), esc_html__( 'Get directions in Google Maps', 'guam-restaurants-core' ) );
		}

		$list = '';
		foreach ( $rows as $label => $value ) {
			$list .= '<div class="gr-details__row"><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
		}

		$status = Restaurant_View::status_label( $id );
		$out    = '' !== $status ? '<p class="gr-details__status">' . esc_html( $status ) . '</p>' : '';
		$out   .= '' !== $list ? '<dl class="gr-details__list">' . $list . '</dl>' : '';
		$out   .= self::hours_table( $id, '' === $status );

		return self::wrap(
			'section',
			'<h2 class="gr-section-title">' . esc_html__( 'Details', 'guam-restaurants-core' ) . '</h2>' . $out,
			array(
				'class'           => 'gr-details',
				'data-gr-listing' => (string) $id,
			)
		);
	}

	/**
	 * Opening hours table.
	 *
	 * @param int  $id        Restaurant.
	 * @param bool $open_info Whether to show the live open-now label.
	 * @return string
	 */
	private static function hours_table( int $id, bool $open_info ): string {
		$hours  = Restaurant_View::hours_by_day( $id );
		$report = Pages::url( 'correction' );
		$report = '' === $report ? '' : add_query_arg(
			array(
				'restaurant' => $id,
				'topic'      => 'hours',
			),
			$report
		);
		if ( null === $hours ) {
			return '<p class="gr-details__hours-none">' . esc_html__( 'Opening hours are not listed yet. Please check with the restaurant.', 'guam-restaurants-core' )
				. ( '' !== $report ? ' <a href="' . esc_url( $report ) . '">' . esc_html__( 'Know the hours? Tell us', 'guam-restaurants-core' ) . '</a>' : '' ) . '</p>';
		}
		$body = '';
		foreach ( Restaurant_View::day_names() as $day => $name ) {
			$ranges = $hours['always_open'] ? array( __( 'Open 24 hours', 'guam-restaurants-core' ) ) : $hours['days'][ $day ];
			$body  .= sprintf( '<tr data-gr-day="%1$s"><th scope="row">%2$s</th><td>%3$s</td></tr>', esc_attr( $day ), esc_html( $name ), $ranges ? esc_html( implode( ', ', $ranges ) ) : esc_html__( 'Closed', 'guam-restaurants-core' ) );
		}
		$compact = Open_Hours::compact( $id );
		return sprintf(
			'<div class="gr-hours"%1$s><h3 class="gr-hours__title">%2$s</h3>%3$s<table class="gr-hours__table"><caption class="screen-reader-text">%4$s</caption><tbody>%5$s</tbody></table>%6$s%7$s</div>',
			$open_info && '' !== $compact ? ' data-gr-hours="' . esc_attr( $compact ) . '"' : '',
			esc_html__( 'Opening hours', 'guam-restaurants-core' ),
			$open_info && '' !== $compact ? '<p class="gr-card__open gr-hours__now" aria-live="polite"></p>' : '',
			esc_html__( 'Opening hours, Guam time', 'guam-restaurants-core' ),
			$body,
			'' !== $hours['note'] ? '<p class="gr-hours__note">' . esc_html( $hours['note'] ) . '</p>' : '',
			self::hours_check( $id, $report )
		);
	}

	/**
	 * When the hours were last checked, and "Are these hours right?". "No"
	 * opens the correction form about this restaurant's hours, so a change
	 * goes through the corrections queue and never straight onto the page.
	 * "Yes" (shown by hours.js) only thanks the visitor; nothing is stored.
	 *
	 * @param int    $id     Restaurant.
	 * @param string $report Correction form URL for these hours, or ''.
	 * @return string
	 */
	private static function hours_check( int $id, string $report ): string {
		$checked = Restaurant_View::hours_checked( $id );
		$ask     = '' === $report ? '' : sprintf(
			'<p class="gr-hours__ask" data-gr-hours-ask><span>%1$s</span> <button type="button" class="gr-hours__yes" data-gr-hours-yes hidden>%2$s</button> <a class="gr-hours__no" href="%3$s">%4$s</a></p><p class="gr-hours__thanks" role="status" hidden>%5$s</p>',
			esc_html__( 'Are these hours right?', 'guam-restaurants-core' ),
			esc_html__( 'Yes', 'guam-restaurants-core' ),
			esc_url( $report ),
			esc_html__( 'No, report a change', 'guam-restaurants-core' ),
			esc_html__( 'Thanks for checking.', 'guam-restaurants-core' )
		);
		if ( '' === $checked && '' === $ask ) {
			return '';
		}
		return '<div class="gr-hours__check">' . ( '' !== $checked ? '<p class="gr-hours__checked">' . esc_html( $checked ) . '</p>' : '' ) . $ask . '</div>';
	}

	/**
	 * Restaurant map.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function restaurant_map( $attributes, $content = '', $block = null ): string {
		$id = self::restaurant_id( $block );
		if ( ! $id ) {
			return '';
		}
		$kind = Maps::restaurant_map_kind( $id );
		if ( 'none' === $kind ) {
			return '';
		}
		/* translators: %s: restaurant name. */
		$label = sprintf( __( 'Map showing %s', 'guam-restaurants-core' ), wp_strip_all_tags( get_the_title( $id ) ) );
		if ( 'google' === $kind ) {
			Assets::enqueue();
			return self::wrap(
				'div',
				sprintf( '<iframe class="gr-map__frame" src="%1$s" title="%2$s" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>', esc_url( Maps::google_embed_url( $id ) ), esc_attr( $label ) ),
				array( 'class' => 'gr-map gr-map--google' )
			);
		}
		Assets::enqueue( 'map' );
		$point = Restaurant_View::coordinates( $id );
		return self::wrap(
			'div',
			sprintf(
				'<div class="gr-map__canvas" role="region" aria-label="%1$s" data-gr-map-point="%2$s,%3$s" data-gr-map-name="%4$s"></div><noscript><p>%5$s</p></noscript>',
				esc_attr( $label ),
				esc_attr( (string) $point['lng'] ),
				esc_attr( (string) $point['lat'] ),
				esc_attr( wp_strip_all_tags( get_the_title( $id ) ) ),
				esc_html__( 'The map needs JavaScript. Use the directions link above instead.', 'guam-restaurants-core' )
			),
			array( 'class' => 'gr-map' )
		);
	}

	/**
	 * Opened, first listed and reopened dates.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function change_history( $attributes, $content = '', $block = null ): string {
		$id = self::restaurant_id( $block );
		if ( ! $id ) {
			return '';
		}
		$events = History::events( $id );
		if ( ! $events ) {
			return '';
		}
		Assets::enqueue();
		$items = '';
		foreach ( $events as $event ) {
			$items .= sprintf( '<li><span class="gr-history__label">%1$s</span> <time>%2$s</time></li>', esc_html( $event['label'] ), esc_html( $event['date'] ) );
		}
		return self::wrap( 'section', '<h2 class="gr-section-title">' . esc_html__( 'History', 'guam-restaurants-core' ) . '</h2><ul class="gr-history">' . $items . '</ul>', array( 'class' => 'gr-history-section' ) );
	}

	/**
	 * Claim, correction and contact links.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function listing_actions( $attributes, $content = '', $block = null ): string {
		$id = self::restaurant_id( $block );
		if ( ! $id ) {
			return '';
		}
		$claimed = (int) get_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) > 0;
		$links   = array();
		foreach ( array(
			'claim'      => $claimed ? '' : __( 'Own this restaurant? Claim this listing', 'guam-restaurants-core' ),
			'correction' => __( 'Suggest a correction', 'guam-restaurants-core' ),
			'contact'    => $claimed ? __( 'Contact the owner', 'guam-restaurants-core' ) : '',
			'advertise'  => __( 'Advertise on this page', 'guam-restaurants-core' ),
		) as $role => $text ) {
			$url = '' === $text ? '' : Pages::url( $role );
			if ( '' !== $url ) {
				$links[] = sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( add_query_arg( 'restaurant', $id, $url ) ), esc_html( $text ) );
			}
		}
		if ( ! $links ) {
			return '';
		}
		Assets::enqueue();
		return self::wrap(
			'nav',
			'<ul>' . implode( '', $links ) . '</ul>',
			array(
				'class'      => 'gr-actions',
				'aria-label' => esc_attr__( 'About this listing', 'guam-restaurants-core' ),
			)
		);
	}

	/**
	 * Which restaurant a claim, correction or contact page is about.
	 *
	 * @return string
	 */
	public static function listing_context(): string {
		$id = isset( $_GET['restaurant'] ) ? absint( $_GET['restaurant'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		if ( ! $id || Content_Types::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		Assets::enqueue();
		$place = Restaurant_View::term_names( $id, Content_Types::TAX_VILLAGE );
		return self::wrap(
			'p',
			sprintf(
				/* translators: %s: restaurant name, linked. */
				esc_html__( 'Listing: %s', 'guam-restaurants-core' ),
				sprintf( '<a href="%1$s">%2$s</a>%3$s', esc_url( (string) get_permalink( $id ) ), Languages::keep( esc_html( get_the_title( $id ) ) ), '' !== $place ? ' (' . Languages::keep( esc_html( $place ) ) . ')' : '' )
			),
			array( 'class' => 'gr-listing-context' )
		);
	}

	/**
	 * Map with filters.
	 *
	 * @return string
	 */
	public static function map_explorer(): string {
		Assets::enqueue( 'map' );
		wp_enqueue_script( Assets::SCRIPTS['directory'] );
		$filters = Directory_Query::current_filters( null );
		$action  = (string) get_permalink();
		$list    = Directory_View::page_url( $filters, array() );
		return self::wrap(
			'div',
			Directory_View::form( $filters, array(), 'map', $action )
			. sprintf(
				'<p class="gr-map-explorer__status" role="status" data-gr-map-status>%1$s</p><div class="gr-map-explorer__canvas" role="region" aria-label="%2$s" data-gr-map-explorer></div><noscript><p>%3$s</p></noscript><p class="gr-map-explorer__list"><a href="%4$s" data-gr-map-list>%5$s</a></p>',
				esc_html__( 'Loading the map…', 'guam-restaurants-core' ),
				esc_attr__( 'Map of restaurants', 'guam-restaurants-core' ),
				esc_html__( 'The map needs JavaScript. The list view shows the same restaurants.', 'guam-restaurants-core' ),
				esc_url( $list ),
				esc_html__( 'Show these restaurants as a list', 'guam-restaurants-core' )
			),
			array( 'class' => 'gr-map-explorer' )
		);
	}

	/**
	 * Required data credits.
	 *
	 * @return string
	 */
	public static function data_attributions(): string {
		$items = array(
			sprintf(
				/* translators: 1: "© OpenStreetMap contributors" link, 2: licence link. */
				__( 'Restaurant details include data %1$s, available under the %2$s.', 'guam-restaurants-core' ),
				'<a href="https://www.openstreetmap.org/copyright">' . esc_html__( '© OpenStreetMap contributors', 'guam-restaurants-core' ) . '</a>',
				'<a href="https://opendatacommons.org/licenses/odbl/">' . esc_html__( 'Open Database License', 'guam-restaurants-core' ) . '</a>'
			),
			Maps::DEFAULT_STYLE_URL === Maps::style_url()
				? sprintf(
					/* translators: 1: OpenFreeMap link, 2: OpenMapTiles link. */
					__( 'Maps: %1$s, %2$s, with OpenStreetMap data.', 'guam-restaurants-core' ),
					'<a href="https://openfreemap.org/">OpenFreeMap</a>',
					'<a href="https://www.openmaptiles.org/">© OpenMapTiles</a>'
				)
				: '',
			sprintf(
				/* translators: %s: Census link. */
				__( 'Village boundaries: %s.', 'guam-restaurants-core' ),
				'<a href="https://www.census.gov/geographies/mapping-files/time-series/geo/tiger-line-file.html">' . esc_html__( 'U.S. Census Bureau TIGER/Line', 'guam-restaurants-core' ) . '</a>'
			),
			Ingestion_Runs::has_previous( 'fsq_os' )
				? sprintf(
					/* translators: %s: Foursquare Open Source Places link. */
					__( 'Some business details: %s, © Foursquare Labs, Inc., Apache License 2.0.', 'guam-restaurants-core' ),
					'<a href="https://docs.foursquare.com/data-products/docs/fsq-places-open-source">Foursquare Open Source Places</a>'
				)
				: '',
			esc_html__( 'Ratings from other sites are shown as each site provides them, with its own credit.', 'guam-restaurants-core' ),
		);
		/**
		 * Filters the data credits in the footer (HTML, one item each).
		 *
		 * @param string[] $items Credits.
		 */
		$items = array_filter( (array) apply_filters( 'guam_restaurants_data_attributions', $items ) );
		if ( ! $items ) {
			return '';
		}
		Assets::enqueue();
		return self::wrap( 'ul', '<li>' . implode( '</li><li>', array_map( 'wp_kses_post', $items ) ) . '</li>', array( 'class' => 'gr-attributions' ) );
	}

	/**
	 * Policy links.
	 *
	 * @return string
	 */
	public static function policy_links(): string {
		$policy = get_page_by_path( Policy_Page::SLUG, OBJECT, 'page' );
		$links  = array(
			__( 'Editorial policy', 'guam-restaurants-core' ) => $policy instanceof \WP_Post && 'publish' === $policy->post_status ? (string) get_permalink( $policy ) : '',
			__( 'Privacy policy', 'guam-restaurants-core' ) => (string) get_privacy_policy_url(),
			__( 'Terms of use', 'guam-restaurants-core' ) => Pages::url( 'terms' ),
			__( 'Advertising disclosure', 'guam-restaurants-core' ) => Legal_Pages::url( 'disclosure' ),
			__( 'Data sources', 'guam-restaurants-core' ) => Legal_Pages::url( 'sources' ),
			__( 'Photo upload agreement', 'guam-restaurants-core' ) => Legal_Pages::url( 'photos' ),
			__( 'Advertise with us', 'guam-restaurants-core' ) => Pages::url( 'advertise' ),
		);
		$items  = '';
		foreach ( $links as $text => $url ) {
			if ( '' !== $url ) {
				$items .= sprintf( '<li><a href="%1$s">%2$s</a></li>', esc_url( $url ), esc_html( $text ) );
			}
		}
		if ( '' === $items ) {
			return '';
		}
		Assets::enqueue();
		return self::wrap( 'ul', $items, array( 'class' => 'gr-policy-links' ) );
	}

	/**
	 * Light / dark / system switch. Hidden until color-scheme.js wires it up.
	 *
	 * @return string
	 */
	public static function color_scheme_toggle(): string {
		return self::wrap(
			'div',
			Color_Scheme::buttons(),
			array(
				'class'      => 'gr-scheme',
				'role'       => 'group',
				'aria-label' => __( 'Color theme', 'guam-restaurants-core' ),
				'hidden'     => 'hidden',
			)
		);
	}
}
