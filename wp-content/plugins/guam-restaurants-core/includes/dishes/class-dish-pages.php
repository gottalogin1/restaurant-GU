<?php
/**
 * "Where to get it" dish pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Dishes;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Directory_View;
use GuamRestaurants\Core\Site\Restaurant_View;

defined( 'ABSPATH' ) || exit;

/**
 * Two pages, served by the plugin at fixed URLs (comparison addition 12):
 *
 * - /dishes/ : every dish with a page, Guam favorites first.
 * - /dishes/{slug}/ : every listed restaurant serving that dish, as a
 *   list and a map.
 *
 * A dish page is ordered by how many different visitors named or
 * photographed the dish at each place, and says so. It is never called
 * "best", owners and connected people are never counted, and nothing paid
 * changes the order (CLAUDE.md rule 5).
 */
final class Dish_Pages {

	public const QUERY_VAR      = 'gr_dishes';
	public const DISH_QUERY_VAR = 'gr_dish';

	/**
	 * Bump when the rewrite rules change so they are flushed once.
	 */
	public const ROUTES_VERSION = '1';
	public const ROUTES_OPTION  = 'guam_restaurants_dish_routes';

	public const PAGES = array( 'index', 'dish' );

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'init', array( self::class, 'maybe_flush_rules' ), 99 );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'pre_handle_404', array( self::class, 'handle_404' ), 10, 2 );
		add_filter( 'template_include', array( self::class, 'template_include' ), 99 );
		add_filter( 'document_title_parts', array( self::class, 'document_title' ) );
		add_action( 'parse_query', array( self::class, 'not_home' ) );
	}

	/**
	 * Dish URLs match no other query, so WordPress would treat them as the
	 * blog home. They are not.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function not_home( $query ): void {
		if ( $query instanceof \WP_Query && $query->is_main_query() && in_array( (string) $query->get( self::QUERY_VAR ), self::PAGES, true ) ) {
			$query->is_home = false;
		}
	}

	/**
	 * Adds the rewrite rules.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule( '^dishes/?$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule( '^dishes/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=dish&' . self::DISH_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Flushes rewrite rules once after the routes change.
	 *
	 * @return void
	 */
	public static function maybe_flush_rules(): void {
		if ( get_option( self::ROUTES_OPTION ) !== self::ROUTES_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::ROUTES_OPTION, self::ROUTES_VERSION );
		}
	}

	/**
	 * Registers the query vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::QUERY_VAR;
		$vars[] = self::DISH_QUERY_VAR;
		return $vars;
	}

	/**
	 * URL of the dish list, or of one dish.
	 *
	 * @param string $slug Dish slug; '' for the list.
	 * @return string
	 */
	public static function url( string $slug = '' ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '' === $slug ? '/dishes/' : '/dishes/' . rawurlencode( $slug ) . '/' );
		}
		return add_query_arg(
			'' === $slug ? array( self::QUERY_VAR => 'index' ) : array(
				self::QUERY_VAR      => 'dish',
				self::DISH_QUERY_VAR => $slug,
			),
			home_url( '/' )
		);
	}

	/**
	 * The dish page requested (index or dish), or ''.
	 *
	 * @return string
	 */
	public static function current_page(): string {
		$page = (string) get_query_var( self::QUERY_VAR );
		return in_array( $page, self::PAGES, true ) ? $page : '';
	}

	/**
	 * The requested dish's page, or null.
	 *
	 * @return array{slug: string, key: string, name: string, places: int, seed: bool}|null
	 */
	public static function current_dish(): ?array {
		if ( 'dish' !== self::current_page() ) {
			return null;
		}
		$slug  = sanitize_title( (string) get_query_var( self::DISH_QUERY_VAR ) );
		$pages = Dish_Index::get()['pages'];
		return isset( $pages[ $slug ] ) ? array( 'slug' => $slug ) + $pages[ $slug ] : null;
	}

	/**
	 * Serves dish URLs as pages; an unknown dish is a real 404.
	 *
	 * @param bool      $preempt  Whether to skip WordPress's 404 handling.
	 * @param \WP_Query $wp_query Main query.
	 * @return bool
	 */
	public static function handle_404( $preempt, $wp_query ) {
		$page = self::current_page();
		if ( '' === $page ) {
			return $preempt;
		}
		if ( 'dish' === $page && null === self::current_dish() ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return true;
		}
		status_header( 200 );
		return true;
	}

	/**
	 * Uses the plugin's page template for dish URLs.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function template_include( $template ): string {
		if ( '' === self::current_page() || is_404() ) {
			return (string) $template;
		}
		return GUAM_RESTAURANTS_CORE_DIR . 'templates/dish-page.php';
	}

	/**
	 * Sets the document title on dish pages.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( $parts ): array {
		$parts = (array) $parts;
		if ( '' !== self::current_page() && ! is_404() ) {
			$parts['title'] = self::title( self::current_dish() );
		}
		return $parts;
	}

	/**
	 * Page title.
	 *
	 * @param array<string, mixed>|null $dish Dish page, or null for the list.
	 * @return string
	 */
	public static function title( ?array $dish ): string {
		/* translators: %s: dish name, for example "Kelaguen". */
		return null === $dish ? __( 'Where to get Guam dishes', 'guam-restaurants-core' ) : sprintf( __( 'Where to get %s on Guam', 'guam-restaurants-core' ), (string) $dish['name'] );
	}

	/**
	 * Meta description.
	 *
	 * @return string
	 */
	public static function description(): string {
		$dish = self::current_dish();
		if ( null === $dish ) {
			return __( 'Guam dishes and the restaurants that serve them, from what visitors photographed and named in their reviews.', 'guam-restaurants-core' );
		}
		return sprintf(
			/* translators: 1: number of places, 2: dish name. */
			_n( '%1$s place on Guam serves %2$s, with a map. Listed by how many visitors named it there, not by who makes it best.', '%1$s places on Guam serve %2$s, with a map. Listed by how many visitors named it there, not by who makes it best.', (int) $dish['places'], 'guam-restaurants-core' ),
			number_format_i18n( (int) $dish['places'] ),
			(string) $dish['name']
		);
	}

	/**
	 * The page body for the current request.
	 *
	 * @return string
	 */
	public static function render_current(): string {
		$dish = self::current_dish();
		return null === $dish ? self::render_index() : self::render_dish( $dish );
	}

	/**
	 * The dish list.
	 *
	 * @return string
	 */
	public static function render_index(): string {
		Assets::enqueue();
		$pages     = Dish_Index::get()['pages'];
		$favorites = array();
		foreach ( array_keys( Dish_Index::SEEDS ) as $key ) {
			$slug = sanitize_title( $key );
			if ( isset( $pages[ $slug ] ) ) {
				$favorites[] = self::index_item( $slug, $pages[ $slug ] );
			}
		}
		$all = array();
		foreach ( $pages as $slug => $page ) {
			$all[] = self::index_item( (string) $slug, $page );
		}

		$body = $all
			? ( $favorites ? '<h2 class="gr-section-title">' . esc_html__( 'Guam favorites', 'guam-restaurants-core' ) . '</h2><ul class="gr-dish-index gr-dish-index--favorites" role="list">' . implode( '', $favorites ) . '</ul>' : '' )
				. '<h2 class="gr-section-title">' . esc_html__( 'All dishes, A to Z', 'guam-restaurants-core' ) . '</h2><ul class="gr-dish-index" role="list">' . implode( '', $all ) . '</ul>'
			: '<p class="gr-dish-page__empty">' . esc_html__( 'No dishes yet. When visitors add dish photos or name what they ordered in a review, the dishes appear here.', 'guam-restaurants-core' ) . '</p>';

		return sprintf(
			'<div class="gr-dish-page alignwide"><h1 class="gr-dish-page__title">%1$s</h1><p class="gr-dish-page__intro">%2$s</p>%3$s</div>',
			esc_html( self::title( null ) ),
			esc_html__( 'Dishes visitors photographed or named in their reviews, and dishes owners list on their menus, with every listed restaurant that serves each one.', 'guam-restaurants-core' ),
			$body
		);
	}

	/**
	 * One dish page.
	 *
	 * @param array{slug: string, key: string, name: string, places: int, seed: bool} $dish Dish page.
	 * @return string
	 */
	public static function render_dish( array $dish ): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );

		$index  = Dish_Index::get();
		$titles = array();
		_prime_post_caches( array_keys( $index['places'] ), false, false );
		foreach ( array_keys( $index['places'] ) as $restaurant_id ) {
			$titles[ $restaurant_id ] = (string) get_the_title( $restaurant_id );
		}
		$places = array_filter(
			Dish_Index::places( $index['places'], $dish['key'], $titles ),
			static function ( $restaurant_id ): bool {
				return Content_Types::POST_TYPE === get_post_type( $restaurant_id ) && 'publish' === get_post_status( $restaurant_id );
			},
			ARRAY_FILTER_USE_KEY
		);

		$items = '';
		$pin   = 0;
		Restaurant_View::prime( array_keys( $places ) );
		foreach ( $places as $restaurant_id => $why ) {
			// The reason goes inside the card body, under the opening hours.
			$card   = Restaurant_View::card( $restaurant_id, 2 );
			$end    = strrpos( $card, '</div></article>' );
			$card   = false === $end ? $card . self::why( $why, $dish['name'] ) : substr_replace( $card, self::why( $why, $dish['name'] ), $end, 0 );
			$point  = Restaurant_View::coordinates( $restaurant_id );
			$items .= null === $point
				? '<li>' . $card . '</li>'
				: sprintf( '<li data-gr-pin="%1$d" data-gr-point="%2$s,%3$s">%4$s</li>', ++$pin, esc_attr( (string) $point['lng'] ), esc_attr( (string) $point['lat'] ), $card );
		}
		$count   = count( $places );
		$results = '<p class="gr-directory__summary" role="status">' . esc_html(
			/* translators: %s: number of restaurants. */
			sprintf( _n( '%s place serves it', '%s places serve it', $count, 'guam-restaurants-core' ), number_format_i18n( $count ) )
		) . '</p><ul class="gr-cards" role="list">' . $items . '</ul>';

		return sprintf(
			'<div class="gr-dish-page alignwide"><p class="gr-dish-page__back"><a href="%1$s">%2$s</a></p><h1 class="gr-dish-page__title">%3$s</h1><p class="gr-dish-page__intro">%4$s</p>%5$s<p class="gr-dish-page__add">%6$s</p></div>',
			esc_url( self::url() ),
			esc_html__( 'All dishes', 'guam-restaurants-core' ),
			esc_html( self::title( $dish ) ),
			esc_html__( 'Listed by how many different visitors photographed this dish or named it in an approved review at each place, counting each person once. Places where only the owner lists it come next. This is not a ranking of who makes it best: owners and staff are never counted, and sponsors cannot change the order.', 'guam-restaurants-core' ),
			sprintf(
				'<div class="gr-directory gr-dish-page__list"><div class="gr-directory__views" role="group" aria-label="%1$s" hidden><button type="button" class="gr-directory__view" data-gr-view="list" aria-pressed="true">%2$s</button><button type="button" class="gr-directory__view" data-gr-view="map" aria-pressed="false">%3$s</button></div><div class="gr-directory__body"><div class="gr-directory__results" id="%4$s" tabindex="-1">%5$s</div><div class="gr-directory__map" data-gr-results-map role="region" aria-label="%6$s"></div></div></div>',
				esc_attr__( 'Show the list as', 'guam-restaurants-core' ),
				esc_html__( 'List', 'guam-restaurants-core' ),
				esc_html__( 'Map', 'guam-restaurants-core' ),
				esc_attr( Directory_View::RESULTS_ID ),
				$results,
				esc_attr__( 'Map of these restaurants', 'guam-restaurants-core' )
			),
			esc_html__( 'Had it somewhere else? Add a dish photo or name it in a review on that restaurant\'s page, and the place appears here once it is approved.', 'guam-restaurants-core' )
		);
	}

	/**
	 * Why a restaurant is listed: visitor count, the owner's menu, and the
	 * dish names it goes by there when they differ from the page's.
	 *
	 * @param array{visitors: int, owner: bool, names: string[]} $why  From Dish_Index::places().
	 * @param string                                             $name Page's dish name.
	 * @return string
	 */
	private static function why( array $why, string $name ): string {
		$parts = array();
		if ( $why['visitors'] > 0 ) {
			/* translators: %s: number of visitors. */
			$parts[] = sprintf( _n( 'Named by %s visitor', 'Named by %s visitors', $why['visitors'], 'guam-restaurants-core' ), number_format_i18n( $why['visitors'] ) );
		}
		if ( $why['owner'] ) {
			$parts[] = __( 'On the owner\'s menu', 'guam-restaurants-core' );
		}
		$names = array_values(
			array_filter(
				array_unique( $why['names'] ),
				static function ( string $item ) use ( $name ): bool {
					return Popular_Dishes::key( $item ) !== Popular_Dishes::key( $name );
				}
			)
		);
		if ( $names ) {
			/* translators: %s: dish names, for example "Chicken kelaguen, Beef kelaguen". */
			$parts[] = sprintf( __( 'As %s', 'guam-restaurants-core' ), implode( ', ', array_slice( $names, 0, 3 ) ) );
		}
		return '<p class="gr-dish-page__why">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
	}

	/**
	 * One dish in the list.
	 *
	 * @param string                                             $slug Slug.
	 * @param array{name: string, places: int, seed: bool}|array $page Dish page.
	 * @return string
	 */
	private static function index_item( string $slug, array $page ): string {
		return sprintf(
			'<li><a href="%1$s">%2$s</a> <span class="gr-dish-index__count">%3$s</span></li>',
			esc_url( self::url( $slug ) ),
			esc_html( (string) $page['name'] ),
			esc_html(
				/* translators: %s: number of places. */
				sprintf( _n( '%s place', '%s places', (int) $page['places'], 'guam-restaurants-core' ), number_format_i18n( (int) $page['places'] ) )
			)
		);
	}
}
