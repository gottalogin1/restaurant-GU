<?php
/**
 * Awards archive, per-category and most-awarded pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

defined( 'ABSPATH' ) || exit;

/**
 * Three pages, served by the plugin at fixed URLs:
 *
 * - /awards/ : results for a year, with year, category and (when there is
 *   more than one) program selectors.
 * - /awards/category/{slug}/ : one category's results across all years.
 * - /awards/most-awarded/ : restaurants ranked by results in our table,
 *   overall or for one category.
 *
 * Page titles describe the content in our words and never use a program's
 * name (the name appears only in table cells as a factual citation). Every
 * page carries the "not affiliated" notice.
 */
final class Pages {

	public const QUERY_VAR          = 'gr_awards';
	public const CATEGORY_QUERY_VAR = 'gr_award_category';

	/**
	 * Bump when the rewrite rules change so they are flushed once.
	 */
	public const ROUTES_VERSION = '1';
	public const ROUTES_OPTION  = 'guam_restaurants_awards_routes';

	/**
	 * Selector form fields. Prefixed because `year` is a WordPress query var
	 * (a date archive), which would redirect the request.
	 */
	public const FIELD_YEAR     = 'award_year';
	public const FIELD_CATEGORY = 'award_category';
	public const FIELD_PROGRAM  = 'award_program';

	public const PAGES = array( 'archive', 'category', 'ranking' );

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
	 * Awards URLs match no other query, so WordPress would treat them as the
	 * blog home (body class "home", home-page SEO). They are not.
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
		add_rewrite_rule( '^awards/?$', 'index.php?' . self::QUERY_VAR . '=archive', 'top' );
		add_rewrite_rule( '^awards/most-awarded/?$', 'index.php?' . self::QUERY_VAR . '=ranking', 'top' );
		add_rewrite_rule( '^awards/category/([^/]+)/?$', 'index.php?' . self::QUERY_VAR . '=category&' . self::CATEGORY_QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Flushes rewrite rules once after the routes change, so existing sites pick them up.
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
		$vars[] = self::CATEGORY_QUERY_VAR;
		return $vars;
	}

	/**
	 * URL of an awards page.
	 *
	 * @param string $page          archive, category or ranking.
	 * @param string $category_slug Category slug, for the category page.
	 * @return string
	 */
	public static function url( string $page, string $category_slug = '' ): string {
		if ( get_option( 'permalink_structure' ) ) {
			switch ( $page ) {
				case 'ranking':
					return home_url( '/awards/most-awarded/' );
				case 'category':
					return home_url( '/awards/category/' . rawurlencode( $category_slug ) . '/' );
				default:
					return home_url( '/awards/' );
			}
		}
		$args = array( self::QUERY_VAR => in_array( $page, self::PAGES, true ) ? $page : 'archive' );
		if ( 'category' === $page ) {
			$args[ self::CATEGORY_QUERY_VAR ] = $category_slug;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * The awards page requested, or ''.
	 *
	 * @return string
	 */
	public static function current_page(): string {
		$page = (string) get_query_var( self::QUERY_VAR );
		return in_array( $page, self::PAGES, true ) ? $page : '';
	}

	/**
	 * Serves awards URLs as pages instead of 404s; an unknown category is a real 404.
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
		if ( 'category' === $page && null === Repository::category_by_slug( sanitize_title( (string) get_query_var( self::CATEGORY_QUERY_VAR ) ) ) ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return true;
		}
		status_header( 200 );
		return true;
	}

	/**
	 * Uses the plugin's page template for awards URLs.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function template_include( $template ): string {
		if ( '' === self::current_page() || is_404() ) {
			return (string) $template;
		}
		return GUAM_RESTAURANTS_CORE_DIR . 'templates/awards-page.php';
	}

	/**
	 * Sets the document title on awards pages.
	 *
	 * @param array<string, string> $parts Title parts.
	 * @return array<string, string>
	 */
	public static function document_title( $parts ): array {
		$parts = (array) $parts;
		if ( '' !== self::current_page() && ! is_404() ) {
			$parts['title'] = self::title( self::current_page(), self::request() );
		}
		return $parts;
	}

	/**
	 * Filters from the current request (query vars and the selector form).
	 *
	 * @return array{year: int, category: string, program: string}
	 */
	public static function request(): array {
		// Read-only filters for a public page; no state changes, so no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$category = 'category' === self::current_page()
			? (string) get_query_var( self::CATEGORY_QUERY_VAR )
			: ( isset( $_GET[ self::FIELD_CATEGORY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FIELD_CATEGORY ] ) ) : '' );
		$request  = array(
			'year'     => isset( $_GET[ self::FIELD_YEAR ] ) ? absint( $_GET[ self::FIELD_YEAR ] ) : 0,
			'category' => sanitize_title( $category ),
			'program'  => isset( $_GET[ self::FIELD_PROGRAM ] ) ? Csv_Reader::clean_label( wp_unslash( $_GET[ self::FIELD_PROGRAM ] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_label() runs sanitize_text_field().
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $request;
	}

	/**
	 * Page title, in our own words.
	 *
	 * @param string                                                 $page    archive, category or ranking.
	 * @param array{year?: int, category?: string, program?: string} $request Filters.
	 * @return string
	 */
	public static function title( string $page, array $request = array() ): string {
		$category = isset( $request['category'] ) && '' !== $request['category'] ? Repository::category_by_slug( $request['category'] ) : null;
		switch ( $page ) {
			case 'category':
				/* translators: %s: category name, for example "Best Burger". */
				return null === $category ? __( 'Award category', 'guam-restaurants-core' ) : sprintf( __( '%s: results by year', 'guam-restaurants-core' ), $category );
			case 'ranking':
				/* translators: %s: category name. */
				return null === $category ? __( 'Most-awarded restaurants', 'guam-restaurants-core' ) : sprintf( __( 'Most-awarded restaurants: %s', 'guam-restaurants-core' ), $category );
			default:
				return __( 'Restaurant awards', 'guam-restaurants-core' );
		}
	}

	/**
	 * The page body for the current request.
	 *
	 * @return string
	 */
	public static function render_current(): string {
		$request = self::request();
		switch ( self::current_page() ) {
			case 'category':
				return self::render_category( $request['category'], $request['program'] );
			case 'ranking':
				return self::render_ranking( $request['category'] );
			default:
				return self::render_archive( $request['year'], $request['category'], $request['program'] );
		}
	}

	/**
	 * Archive: one year's results (the latest by default), filterable by category and program.
	 *
	 * @param int    $year          Year, or 0 for the latest (all years when a category is chosen).
	 * @param string $category_slug Category slug, or ''.
	 * @param string $program       Program, or ''.
	 * @return string
	 */
	public static function render_archive( int $year = 0, string $category_slug = '', string $program = '' ): string {
		wp_enqueue_style( Display::STYLE );

		$programs = Repository::programs();
		$program  = in_array( $program, $programs, true ) ? $program : '';
		$years    = Repository::years( $program );
		$category = '' === $category_slug ? null : Repository::category_by_slug( $category_slug );

		$out = self::heading( 'archive' ) . self::nav();
		if ( empty( $years ) ) {
			return $out . '<p>' . esc_html__( 'No award results are recorded yet.', 'guam-restaurants-core' ) . '</p>' . Display::notice();
		}
		if ( ! in_array( $year, $years, true ) ) {
			$year = null === $category ? $years[0] : 0;
		}

		$out .= self::selector_form( $programs, $program, $years, $year, Repository::categories( $program ), (string) $category );

		$rows = Repository::query(
			array(
				'program'  => $program,
				'year'     => $year,
				'category' => (string) $category,
			)
		);
		if ( empty( $rows ) ) {
			return $out . '<p>' . esc_html__( 'No results match these choices.', 'guam-restaurants-core' ) . '</p>' . Display::notice();
		}

		$by_year = array();
		foreach ( $rows as $row ) {
			$by_year[ (int) $row['year'] ][] = $row;
		}
		$multi = count( $programs ) > 1;
		foreach ( $by_year as $row_year => $year_rows ) {
			$out .= '<h2 class="gr-awards__year">' . esc_html( (string) $row_year ) . '</h2>';
			$out .= self::table(
				$year_rows,
				$multi ? array( 'program', 'category', 'placement', 'restaurant', 'source' ) : array( 'category', 'placement', 'restaurant', 'source' ),
				/* translators: %d: year. */
				sprintf( __( 'Results recorded for %d', 'guam-restaurants-core' ), $row_year )
			);
		}
		return $out . Display::notice();
	}

	/**
	 * One category across all years.
	 *
	 * @param string $category_slug Category slug.
	 * @param string $program       Program, or ''.
	 * @return string
	 */
	public static function render_category( string $category_slug, string $program = '' ): string {
		wp_enqueue_style( Display::STYLE );

		$category = Repository::category_by_slug( $category_slug );
		$out      = self::heading( 'category', array( 'category' => $category_slug ) ) . self::nav();
		if ( null === $category ) {
			return $out . '<p>' . esc_html__( 'No results are recorded for this category.', 'guam-restaurants-core' ) . '</p>' . Display::notice();
		}

		$programs = Repository::programs();
		$program  = in_array( $program, $programs, true ) ? $program : '';
		$rows     = Repository::query(
			array(
				'program'  => $program,
				'category' => $category,
			)
		);

		$columns = count( $programs ) > 1 ? array( 'year', 'program', 'placement', 'restaurant', 'source' ) : array( 'year', 'placement', 'restaurant', 'source' );
		$out    .= self::table(
			$rows,
			$columns,
			/* translators: %s: category name. */
			sprintf( __( '%s, every year recorded', 'guam-restaurants-core' ), $category )
		);
		$out .= sprintf( '<p><a href="%1$s">%2$s</a></p>', esc_url( add_query_arg( self::FIELD_CATEGORY, sanitize_title( $category ), self::url( 'ranking' ) ) ), esc_html__( 'Most-awarded restaurants in this category', 'guam-restaurants-core' ) );
		return $out . Display::notice();
	}

	/**
	 * Most-awarded ranking, overall or for one category.
	 *
	 * @param string $category_slug Category slug, or '' for overall.
	 * @return string
	 */
	public static function render_ranking( string $category_slug = '' ): string {
		wp_enqueue_style( Display::STYLE );

		$category = '' === $category_slug ? null : Repository::category_by_slug( $category_slug );
		$out      = self::heading( 'ranking', array( 'category' => null === $category ? '' : $category_slug ) ) . self::nav();
		$ranking  = Repository::ranking( (string) $category );

		if ( empty( $ranking ) ) {
			return $out . '<p>' . esc_html__( 'No award results are recorded yet.', 'guam-restaurants-core' ) . '</p>' . Display::notice();
		}

		$out .= '<p class="gr-awards__method">' . esc_html__( 'Counted only from the results recorded on this site, ordered by wins, then finalist placings, then all results. Restaurants with the same counts share a rank.', 'guam-restaurants-core' ) . '</p>';

		$options = '<option value="">' . esc_html__( 'All categories', 'guam-restaurants-core' ) . '</option>';
		foreach ( Repository::categories() as $name ) {
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( sanitize_title( $name ) ), selected( $name, (string) $category, false ), esc_html( $name ) );
		}
		$out .= sprintf(
			'<form class="gr-awards__filters" method="get" action="%1$s">%2$s<label>%3$s <select name="%6$s">%4$s</select></label> <button type="submit">%5$s</button></form>',
			esc_url( self::url( 'ranking' ) ),
			self::hidden_route_fields( 'ranking' ),
			esc_html__( 'Category', 'guam-restaurants-core' ),
			$options,
			esc_html__( 'Show', 'guam-restaurants-core' ),
			esc_attr( self::FIELD_CATEGORY )
		);

		$body = '';
		foreach ( $ranking as $row ) {
			$body .= sprintf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$d</td><td>%4$d</td><td>%5$d</td><td>%6$d</td></tr>',
				$row['rank'],
				Display::restaurant_link( $row['restaurant_id'], $row['restaurant_name'], $row['restaurant_status'] ),
				$row['wins'],
				$row['finalist'],
				$row['other'],
				$row['total']
			);
		}
		$out .= sprintf(
			'<table class="gr-awards__table"><caption>%1$s</caption><thead><tr><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col">%4$s</th><th scope="col">%5$s</th><th scope="col">%6$s</th><th scope="col">%7$s</th></tr></thead><tbody>%8$s</tbody></table>',
			esc_html( null === $category ? __( 'All categories', 'guam-restaurants-core' ) : $category ),
			esc_html__( 'Rank', 'guam-restaurants-core' ),
			esc_html__( 'Restaurant', 'guam-restaurants-core' ),
			esc_html__( 'Wins', 'guam-restaurants-core' ),
			esc_html__( 'Finalist', 'guam-restaurants-core' ),
			esc_html__( 'Other', 'guam-restaurants-core' ),
			esc_html__( 'Total', 'guam-restaurants-core' ),
			$body
		);
		return $out . Display::notice();
	}

	/**
	 * Page heading.
	 *
	 * @param string               $page    Page.
	 * @param array<string, mixed> $request Filters.
	 * @return string
	 */
	private static function heading( string $page, array $request = array() ): string {
		return '<h1 class="gr-awards__heading">' . esc_html( self::title( $page, $request ) ) . '</h1>';
	}

	/**
	 * Links between the awards pages.
	 *
	 * @return string
	 */
	private static function nav(): string {
		return sprintf(
			'<nav class="gr-awards__nav" aria-label="%1$s"><a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a></nav>',
			esc_attr__( 'Awards pages', 'guam-restaurants-core' ),
			esc_url( self::url( 'archive' ) ),
			esc_html__( 'Results by year', 'guam-restaurants-core' ),
			esc_url( self::url( 'ranking' ) ),
			esc_html__( 'Most awarded', 'guam-restaurants-core' )
		);
	}

	/**
	 * Year, category and program selectors.
	 *
	 * @param string[] $programs   Programs.
	 * @param string   $program    Selected program.
	 * @param int[]    $years      Years.
	 * @param int      $year       Selected year (0 for all).
	 * @param string[] $categories Categories.
	 * @param string   $category   Selected category name.
	 * @return string
	 */
	private static function selector_form( array $programs, string $program, array $years, int $year, array $categories, string $category ): string {
		$year_options = '<option value="0">' . esc_html__( 'All years', 'guam-restaurants-core' ) . '</option>';
		foreach ( $years as $option ) {
			$year_options .= sprintf( '<option value="%1$d"%2$s>%1$d</option>', $option, selected( $option, $year, false ) );
		}
		$category_options = '<option value="">' . esc_html__( 'All categories', 'guam-restaurants-core' ) . '</option>';
		foreach ( $categories as $option ) {
			$category_options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( sanitize_title( $option ) ), selected( $option, $category, false ), esc_html( $option ) );
		}
		$program_field = '';
		if ( count( $programs ) > 1 ) {
			$program_options = '<option value="">' . esc_html__( 'All programs', 'guam-restaurants-core' ) . '</option>';
			foreach ( $programs as $option ) {
				$program_options .= sprintf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $option ), selected( $option, $program, false ) );
			}
			$program_field = sprintf( '<label>%1$s <select name="%3$s">%2$s</select></label> ', esc_html__( 'Program', 'guam-restaurants-core' ), $program_options, esc_attr( self::FIELD_PROGRAM ) );
		}

		return sprintf(
			'<form class="gr-awards__filters" method="get" action="%1$s">%2$s%3$s<label>%4$s <select name="%9$s">%5$s</select></label> <label>%6$s <select name="%10$s">%7$s</select></label> <button type="submit">%8$s</button></form>',
			esc_url( self::url( 'archive' ) ),
			self::hidden_route_fields( 'archive' ),
			$program_field,
			esc_html__( 'Year', 'guam-restaurants-core' ),
			$year_options,
			esc_html__( 'Category', 'guam-restaurants-core' ),
			$category_options,
			esc_html__( 'Show', 'guam-restaurants-core' ),
			esc_attr( self::FIELD_YEAR ),
			esc_attr( self::FIELD_CATEGORY )
		);
	}

	/**
	 * Without pretty permalinks the form must carry the route query var.
	 *
	 * @param string $page Page.
	 * @return string
	 */
	private static function hidden_route_fields( string $page ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return '';
		}
		return sprintf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::QUERY_VAR ), esc_attr( $page ) );
	}

	/**
	 * Results table.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows from Repository::query().
	 * @param string[]                         $columns year, program, category, placement, restaurant, source.
	 * @param string                           $caption Caption.
	 * @return string
	 */
	private static function table( array $rows, array $columns, string $caption ): string {
		$labels = array(
			'year'       => __( 'Year', 'guam-restaurants-core' ),
			'program'    => __( 'Program', 'guam-restaurants-core' ),
			'category'   => __( 'Category', 'guam-restaurants-core' ),
			'placement'  => __( 'Placement', 'guam-restaurants-core' ),
			'restaurant' => __( 'Restaurant', 'guam-restaurants-core' ),
			'source'     => __( 'Source', 'guam-restaurants-core' ),
		);
		$head   = '';
		foreach ( $columns as $column ) {
			$head .= '<th scope="col">' . esc_html( $labels[ $column ] ) . '</th>';
		}
		$body = '';
		foreach ( $rows as $row ) {
			$cells = '';
			foreach ( $columns as $column ) {
				switch ( $column ) {
					case 'year':
						$cell = esc_html( (string) $row['year'] );
						break;
					case 'program':
						$cell = esc_html( (string) $row['program'] );
						break;
					case 'category':
						$cell = sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url( 'category', sanitize_title( (string) $row['category'] ) ) ), esc_html( (string) $row['category'] ) );
						break;
					case 'placement':
						$cell = esc_html( Policy::placement_label( (string) $row['placement'] ) );
						break;
					case 'restaurant':
						$cell = Display::restaurant_link( (int) $row['restaurant_id'], (string) $row['restaurant_name'], (string) $row['restaurant_status'] );
						break;
					default:
						$cell = Display::source_link( $row );
				}
				$cells .= '<td>' . $cell . '</td>';
			}
			$body .= '<tr>' . $cells . '</tr>';
		}
		return sprintf( '<table class="gr-awards__table"><caption>%1$s</caption><thead><tr>%2$s</tr></thead><tbody>%3$s</tbody></table>', esc_html( $caption ), $head, $body );
	}
}
