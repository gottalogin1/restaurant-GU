<?php
/**
 * Routes for the pages that change by themselves: New on Guam and This week.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Three pages served by the plugin at fixed URLs, like the dish pages:
 *
 * - /new/ : New on Guam, every month with new places (New_Places).
 * - /new/{YYYY-MM}/ : the places new in one month, as a list and a map.
 * - /this-week/ : the next seven days of markets, truck stops, village
 *   fiestas and holiday hours (This_Week).
 *
 * The /ja/ and /ko/ copies come from the language router as for every
 * other page.
 */
final class Fresh_Pages {

	public const QUERY_VAR       = 'gr_fresh';
	public const MONTH_QUERY_VAR = 'gr_month';

	/**
	 * Bump when the rewrite rules change so they are flushed once.
	 */
	public const ROUTES_VERSION = '1';
	public const ROUTES_OPTION  = 'guam_restaurants_fresh_routes';

	public const PAGES = array( 'new', 'new-month', 'week' );

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
		add_rewrite_rule( '^new/?$', 'index.php?' . self::QUERY_VAR . '=new', 'top' );
		add_rewrite_rule( '^new/(\d{4}-\d{2})/?$', 'index.php?' . self::QUERY_VAR . '=new-month&' . self::MONTH_QUERY_VAR . '=$matches[1]', 'top' );
		add_rewrite_rule( '^this-week/?$', 'index.php?' . self::QUERY_VAR . '=week', 'top' );
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
		$vars[] = self::MONTH_QUERY_VAR;
		return $vars;
	}

	/**
	 * URL of a page: `new` (optionally with a month) or `week`.
	 *
	 * @param string $page  Page.
	 * @param string $month YYYY-MM, for a month of New on Guam.
	 * @return string
	 */
	public static function url( string $page, string $month = '' ): string {
		if ( get_option( 'permalink_structure' ) ) {
			if ( 'week' === $page ) {
				return home_url( '/this-week/' );
			}
			return home_url( '' === $month ? '/new/' : '/new/' . $month . '/' );
		}
		if ( 'week' === $page ) {
			return add_query_arg( self::QUERY_VAR, 'week', home_url( '/' ) );
		}
		return add_query_arg(
			'' === $month ? array( self::QUERY_VAR => 'new' ) : array(
				self::QUERY_VAR       => 'new-month',
				self::MONTH_QUERY_VAR => $month,
			),
			home_url( '/' )
		);
	}

	/**
	 * The page requested, or ''.
	 *
	 * @return string
	 */
	public static function current_page(): string {
		$page = (string) get_query_var( self::QUERY_VAR );
		return in_array( $page, self::PAGES, true ) ? $page : '';
	}

	/**
	 * The month requested on a New on Guam month page, or ''.
	 *
	 * @return string YYYY-MM.
	 */
	public static function current_month(): string {
		$month = (string) get_query_var( self::MONTH_QUERY_VAR );
		return 'new-month' === self::current_page() && 1 === preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ? $month : '';
	}

	/**
	 * Serves the URLs as pages; a month with no new places is a real 404.
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
		if ( 'new-month' === $page && ( '' === self::current_month() || ! New_Places::in_month( self::current_month() ) ) ) {
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
		return GUAM_RESTAURANTS_CORE_DIR . 'templates/fresh-page.php';
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
			$parts['title'] = self::title();
		}
		return $parts;
	}

	/**
	 * Title of the current page.
	 *
	 * @return string
	 */
	public static function title(): string {
		return 'week' === self::current_page() ? This_Week::title() : New_Places::title( self::current_month() );
	}

	/**
	 * Meta description of the current page.
	 *
	 * @return string
	 */
	public static function description(): string {
		return 'week' === self::current_page() ? This_Week::description() : New_Places::description( self::current_month() );
	}

	/**
	 * The current page's address, for the canonical link.
	 *
	 * @return string
	 */
	public static function canonical(): string {
		return 'week' === self::current_page() ? self::url( 'week' ) : self::url( 'new', self::current_month() );
	}

	/**
	 * Whether search engines should index the current page: a month needs
	 * Cuisine_Villages::MIN_PLACES places, as cuisine-in-village pages do.
	 *
	 * @return bool
	 */
	public static function indexable(): bool {
		return 'new-month' !== self::current_page() || count( New_Places::in_month( self::current_month() ) ) >= Cuisine_Villages::MIN_PLACES;
	}

	/**
	 * The page body.
	 *
	 * @return string
	 */
	public static function render_current(): string {
		switch ( self::current_page() ) {
			case 'week':
				return This_Week::render();
			case 'new-month':
				return New_Places::render_month( self::current_month() );
			default:
				return New_Places::render_index();
		}
	}

	/**
	 * Pages for the sitemap: This week, New on Guam and its indexable months.
	 *
	 * @return array<int, array{loc: string}>
	 */
	public static function sitemap_pages(): array {
		$list   = array( array( 'loc' => self::url( 'week' ) ) );
		$months = New_Places::months();
		if ( $months ) {
			$list[] = array( 'loc' => self::url( 'new' ) );
		}
		foreach ( $months as $month => $ids ) {
			if ( count( $ids ) >= Cuisine_Villages::MIN_PLACES ) {
				$list[] = array( 'loc' => self::url( 'new', (string) $month ) );
			}
		}
		return $list;
	}
}
