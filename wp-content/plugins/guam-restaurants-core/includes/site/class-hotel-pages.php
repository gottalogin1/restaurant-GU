<?php
/**
 * "Restaurants near your hotel" pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * Two pages served by the plugin at fixed URLs, like the dish pages
 * (English audience idea E6, comparison addition 21):
 *
 * - /restaurants-near/ : every hotel with a page, by village.
 * - /restaurants-near/{hotel}/ : places within walking distance of the
 *   hotel, then places a short drive away, nearest first, with a map.
 *
 * A hotel needs Cuisine_Villages::MIN_PLACES places within a short drive
 * for a page; otherwise its URL is a real 404. The /ja/ and /ko/ copies come
 * from the language router as for every other page.
 */
final class Hotel_Pages {

	public const QUERY_VAR       = 'gr_near';
	public const HOTEL_QUERY_VAR = 'gr_hotel';
	public const BASE            = 'restaurants-near';

	/**
	 * Bump when the rewrite rules change so they are flushed once.
	 */
	public const ROUTES_VERSION = '1';
	public const ROUTES_OPTION  = 'guam_restaurants_hotel_routes';

	public const PAGES = array( 'index', 'hotel' );

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
	 * These URLs match no other query, so WordPress would treat them as the
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
		add_rewrite_rule( '^' . self::BASE . '/?$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule( '^' . self::BASE . '/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=hotel&' . self::HOTEL_QUERY_VAR . '=$matches[1]', 'top' );
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
		$vars[] = self::HOTEL_QUERY_VAR;
		return $vars;
	}

	/**
	 * URL of the hotel list, or of one hotel's page.
	 *
	 * @param string $slug Hotel slug; '' for the list.
	 * @return string
	 */
	public static function url( string $slug = '' ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::BASE . '/' . ( '' === $slug ? '' : rawurlencode( $slug ) . '/' ) );
		}
		return add_query_arg(
			'' === $slug ? array( self::QUERY_VAR => 'index' ) : array(
				self::QUERY_VAR       => 'hotel',
				self::HOTEL_QUERY_VAR => $slug,
			),
			home_url( '/' )
		);
	}

	/**
	 * The page requested (index or hotel), or ''.
	 *
	 * @return string
	 */
	public static function current_page(): string {
		$page = (string) get_query_var( self::QUERY_VAR );
		return in_array( $page, self::PAGES, true ) ? $page : '';
	}

	/**
	 * The requested hotel when it has a page, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function current_hotel(): ?array {
		if ( 'hotel' !== self::current_page() ) {
			return null;
		}
		$hotel = Hotels::get( sanitize_title( (string) get_query_var( self::HOTEL_QUERY_VAR ) ) );
		return null !== $hotel && Hotels::has_page( $hotel ) ? $hotel : null;
	}

	/**
	 * Serves the URLs as pages; a hotel without a page is a real 404.
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
		if ( 'hotel' === $page && null === self::current_hotel() ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return true;
		}
		status_header( 200 );
		return true;
	}

	/**
	 * Uses the plugin's page template.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function template_include( $template ): string {
		if ( '' === self::current_page() || is_404() ) {
			return (string) $template;
		}
		return GUAM_RESTAURANTS_CORE_DIR . 'templates/hotel-page.php';
	}

	/**
	 * Sets the document title.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( $parts ): array {
		$parts = (array) $parts;
		if ( '' !== self::current_page() && ! is_404() ) {
			$parts['title'] = self::title( self::current_hotel() );
		}
		return $parts;
	}

	/**
	 * Page title, as plain text.
	 *
	 * @param array<string, mixed>|null $hotel Hotel, or null for the list.
	 * @return string
	 */
	public static function title( ?array $hotel ): string {
		/* translators: %s: hotel name, for example "Hotel Nikko Guam". */
		return null === $hotel ? __( 'Restaurants near your hotel on Guam', 'guam-restaurants-core' ) : sprintf( __( 'Restaurants near %s', 'guam-restaurants-core' ), (string) $hotel['name'] );
	}

	/**
	 * Meta description.
	 *
	 * @return string
	 */
	public static function description(): string {
		$hotel = self::current_hotel();
		if ( null === $hotel ) {
			return __( 'Pick your hotel to see the places to eat within walking distance and a short drive away, nearest first, with a map.', 'guam-restaurants-core' );
		}
		$places = Hotels::places( $hotel );
		return sprintf(
			/* translators: 1: number of places within walking distance, 2: hotel name, 3: number of places a short drive away. */
			__( '%1$s places to eat within walking distance of %2$s and %3$s more a short drive away, nearest first, with a map.', 'guam-restaurants-core' ),
			number_format_i18n( count( $places['walk'] ) ),
			(string) $hotel['name'],
			number_format_i18n( count( $places['drive'] ) )
		);
	}

	/**
	 * The current page's address, for the canonical link.
	 *
	 * @return string
	 */
	public static function canonical(): string {
		$hotel = self::current_hotel();
		return self::url( null === $hotel ? '' : (string) $hotel['slug'] );
	}

	/**
	 * Restaurants on the current hotel page, nearest first (structured data).
	 *
	 * @return int[]
	 */
	public static function current_ids(): array {
		$hotel = self::current_hotel();
		if ( null === $hotel ) {
			return array();
		}
		$places = Hotels::places( $hotel );
		return array_merge( array_keys( $places['walk'] ), array_keys( $places['drive'] ) );
	}

	/**
	 * The page body for the current request.
	 *
	 * @return string
	 */
	public static function render_current(): string {
		$hotel = self::current_hotel();
		return null === $hotel ? self::render_index() : self::render_hotel( $hotel );
	}

	/**
	 * The hotel list, by village.
	 *
	 * @return string
	 */
	public static function render_index(): string {
		Assets::enqueue();
		$groups = array();
		foreach ( Hotels::with_pages() as $slug => $hotel ) {
			$village              = Hotels::village_name( $hotel );
			$groups[ $village ][] = sprintf(
				'<li><a href="%1$s">%2$s</a> <span class="gr-dish-index__count">%3$s</span></li>',
				esc_url( self::url( (string) $slug ) ),
				Languages::keep( esc_html( (string) $hotel['name'] ) ),
				esc_html(
					/* translators: %s: number of places. */
					sprintf( _n( '%s place within walking distance', '%s places within walking distance', count( Hotels::places( $hotel )['walk'] ), 'guam-restaurants-core' ), number_format_i18n( count( Hotels::places( $hotel )['walk'] ) ) )
				)
			);
		}
		uksort(
			$groups,
			static function ( $a, $b ): int {
				// Hotels outside every village go last.
				$order = ( '' === (string) $a ) <=> ( '' === (string) $b );
				return 0 !== $order ? $order : strcasecmp( (string) $a, (string) $b );
			}
		);
		$body = '';
		foreach ( $groups as $village => $items ) {
			$body .= '<h2 class="gr-section-title">' . esc_html( '' === (string) $village ? __( 'Elsewhere on Guam', 'guam-restaurants-core' ) : (string) $village ) . '</h2><ul class="gr-dish-index gr-hotel-index" role="list">' . implode( '', $items ) . '</ul>';
		}
		if ( '' === $body ) {
			$body = '<p class="gr-dish-page__empty">' . esc_html__( 'No hotels yet. They appear here once hotel locations are loaded.', 'guam-restaurants-core' ) . '</p>';
		}

		return sprintf(
			'<div class="gr-dish-page gr-hotel-page alignwide"><h1 class="gr-dish-page__title">%1$s</h1><p class="gr-dish-page__intro">%2$s</p>%3$s%4$s</div>',
			esc_html( self::title( null ) ),
			esc_html__( 'Staying on Guam? Pick your hotel to see the places to eat you can walk to, then the ones a short drive away, nearest first.', 'guam-restaurants-core' ),
			$body,
			self::credit()
		);
	}

	/**
	 * One hotel's page.
	 *
	 * @param array<string, mixed> $hotel Hotel, with its slug.
	 * @return string
	 */
	public static function render_hotel( array $hotel ): string {
		Assets::enqueue( 'directory' );
		wp_enqueue_script( Assets::SCRIPTS['map'] );

		$places = Hotels::places( $hotel );
		Restaurant_View::prime( array_merge( array_keys( $places['walk'] ), array_keys( $places['drive'] ) ) );
		$pin  = 0;
		$walk = self::cards( $places['walk'], true, $pin );
		$near = self::cards( $places['drive'], false, $pin );

		$results = '';
		if ( '' !== $walk ) {
			$results .= sprintf(
				'<h2 class="gr-section-title">%1$s</h2><p class="gr-directory__summary">%2$s</p><ul class="gr-cards" role="list">%3$s</ul>',
				esc_html__( 'Within walking distance', 'guam-restaurants-core' ),
				esc_html(
					/* translators: 1: number of places, 2: walking minutes. */
					sprintf( _n( '%1$s place within about %2$s minutes on foot', '%1$s places within about %2$s minutes on foot', count( $places['walk'] ), 'guam-restaurants-core' ), number_format_i18n( count( $places['walk'] ) ), number_format_i18n( Hotels::walk_minutes( Hotels::WALK_M ) ) )
				),
				$walk
			);
		}
		if ( '' !== $near ) {
			$results .= sprintf(
				'<h2 class="gr-section-title">%1$s</h2><p class="gr-directory__summary">%2$s</p><ul class="gr-cards" role="list">%3$s</ul>',
				esc_html__( 'A short drive away', 'guam-restaurants-core' ),
				esc_html(
					/* translators: 1: number of places, 2: distance in kilometres. */
					sprintf( _n( '%1$s place within %2$s km', '%1$s places within %2$s km', count( $places['drive'] ), 'guam-restaurants-core' ), number_format_i18n( count( $places['drive'] ) ), number_format_i18n( Hotels::DRIVE_M / 1000 ) )
				),
				$near
			);
		}

		$name = Languages::keep( esc_html( (string) $hotel['name'] ) );
		return sprintf(
			'<div class="gr-dish-page gr-hotel-page alignwide"><p class="gr-dish-page__back"><a href="%1$s">%2$s</a></p><h1 class="gr-dish-page__title">%3$s</h1><p class="gr-dish-page__intro">%4$s</p>%5$s%6$s%7$s</div>',
			esc_url( self::url() ),
			esc_html__( 'All hotels', 'guam-restaurants-core' ),
			/* translators: %s: hotel name. */
			sprintf( esc_html__( 'Restaurants near %s', 'guam-restaurants-core' ), $name ),
			esc_html__( 'Nearest first, by straight-line distance from the hotel. Walking times are estimates, so check the route before you set out. Sponsors cannot change the order.', 'guam-restaurants-core' ),
			sprintf(
				'<div class="gr-directory gr-dish-page__list"><div class="gr-directory__views" role="group" aria-label="%1$s" hidden><button type="button" class="gr-directory__view" data-gr-view="list" aria-pressed="true">%2$s</button><button type="button" class="gr-directory__view" data-gr-view="map" aria-pressed="false">%3$s</button></div><div class="gr-directory__body"><div class="gr-directory__results" id="%4$s" tabindex="-1">%5$s</div><div class="gr-directory__map" data-gr-results-map data-gr-origin="%6$s,%7$s" data-gr-origin-label="%8$s" role="region" aria-label="%9$s"></div></div></div>',
				esc_attr__( 'Show the list as', 'guam-restaurants-core' ),
				esc_html__( 'List', 'guam-restaurants-core' ),
				esc_html__( 'Map', 'guam-restaurants-core' ),
				esc_attr( Directory_View::RESULTS_ID ),
				$results,
				esc_attr( (string) $hotel['lng'] ),
				esc_attr( (string) $hotel['lat'] ),
				esc_attr( (string) $hotel['name'] ),
				esc_attr__( 'Map of the hotel and these restaurants', 'guam-restaurants-core' )
			),
			self::neighbours( $hotel ),
			self::credit()
		);
	}

	/**
	 * Cards with the distance under each one's opening hours.
	 *
	 * @param array<int, float> $places Metres by restaurant ID.
	 * @param bool              $walk   Whether to add the walking time.
	 * @param int               $pin    Last map pin number; advanced.
	 * @return string
	 */
	private static function cards( array $places, bool $walk, int &$pin ): string {
		$items = '';
		foreach ( $places as $restaurant_id => $metres ) {
			$line = '<p class="gr-dish-page__why gr-hotel-page__distance">' . esc_html(
				$walk
					/* translators: 1: distance, for example "350 m", 2: walking minutes. */
					? sprintf( _n( '%1$s away · about %2$s minute walk', '%1$s away · about %2$s minute walk', Hotels::walk_minutes( $metres ), 'guam-restaurants-core' ), Hotels::distance_label( $metres ), number_format_i18n( Hotels::walk_minutes( $metres ) ) )
					/* translators: %s: distance, for example "2.1 km". */
					: sprintf( __( '%s away', 'guam-restaurants-core' ), Hotels::distance_label( $metres ) )
			) . '</p>';
			$card   = Restaurant_View::card( (int) $restaurant_id, 3 );
			$end    = strrpos( $card, '</div></article>' );
			$card   = false === $end ? $card . $line : substr_replace( $card, $line, $end, 0 );
			$point  = Restaurant_View::coordinates( (int) $restaurant_id );
			$items .= null === $point
				? '<li>' . $card . '</li>'
				: sprintf( '<li data-gr-pin="%1$d" data-gr-point="%2$s,%3$s">%4$s</li>', ++$pin, esc_attr( (string) $point['lng'] ), esc_attr( (string) $point['lat'] ), $card );
		}
		return $items;
	}

	/**
	 * Links to other hotels within walking distance.
	 *
	 * @param array<string, mixed> $hotel Hotel.
	 * @return string
	 */
	private static function neighbours( array $hotel ): string {
		$near = Hotels::hotels_near( (float) $hotel['lat'], (float) $hotel['lng'], (string) $hotel['slug'] );
		if ( ! $near ) {
			return '';
		}
		$visible = Hotels::visible();
		$items   = '';
		foreach ( $near as $slug => $metres ) {
			$items .= sprintf(
				'<li><a class="gr-chip" href="%1$s">%2$s <span class="gr-hotel-page__chip-distance">%3$s</span></a></li>',
				esc_url( self::url( (string) $slug ) ),
				Languages::keep( esc_html( (string) $visible[ $slug ]['name'] ) ),
				esc_html( Hotels::distance_label( $metres ) )
			);
		}
		return sprintf(
			'<nav class="gr-related gr-hotel-page__neighbours" aria-label="%1$s"><div class="gr-related__group"><h2 class="gr-related__title">%1$s</h2><ul class="gr-chips">%2$s</ul></div></nav>',
			esc_html__( 'Other hotels nearby', 'guam-restaurants-core' ),
			$items
		);
	}

	/**
	 * Where the hotel locations come from (ODbL attribution).
	 *
	 * @return string
	 */
	private static function credit(): string {
		return sprintf(
			'<p class="gr-dish-page__add">%1$s <a href="https://www.openstreetmap.org/copyright" rel="noopener">%2$s</a></p>',
			esc_html__( 'Hotel names and locations:', 'guam-restaurants-core' ),
			esc_html__( '© OpenStreetMap contributors', 'guam-restaurants-core' )
		);
	}

	/**
	 * Pages for the sitemap: the list and every hotel with a page.
	 *
	 * @return array<int, array{loc: string}>
	 */
	public static function sitemap_pages(): array {
		$hotels = Hotels::with_pages();
		if ( ! $hotels ) {
			return array();
		}
		$list = array( array( 'loc' => self::url() ) );
		foreach ( array_keys( $hotels ) as $slug ) {
			$list[] = array( 'loc' => self::url( (string) $slug ) );
		}
		return $list;
	}
}
