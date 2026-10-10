<?php
/**
 * Village names and aliases in search (for example "Tumon" finds Tamuning).
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a village name or alias in a search into a village filter.
 *
 * There is no Tumon term: Tumon, Upper Tumon and Harmon are aliases of
 * Tamuning, so a search for "tumon" lists Tamuning restaurants and
 * /village/tumon/ redirects to /village/tamuning/.
 */
final class Village_Aliases {

	private const ALIAS_ONLY_VAR = 'guam_restaurants_alias_only';

	/**
	 * Hooks into front-end search, the REST API and 404 handling.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pre_get_posts', array( self::class, 'filter_main_search' ) );
		add_filter( 'posts_search', array( self::class, 'drop_alias_only_search' ), 10, 2 );
		add_filter( 'rest_' . Content_Types::POST_TYPE . '_query', array( self::class, 'filter_rest_query' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'redirect_alias_archive' ) );
	}

	/**
	 * Applies extract() to the main front-end search query.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public static function filter_main_search( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$match = self::extract( (string) $query->get( 's' ) );
		if ( ! $match['village_id'] ) {
			return;
		}

		$query->set( 'post_type', Content_Types::POST_TYPE );
		$query->set( 'tax_query', self::add_village_clause( $query->get( 'tax_query' ), $match['village_id'] ) );
		if ( '' === $match['search'] ) {
			// Nothing but the place name was searched: keep `s` so WordPress
			// still treats this as a search, but drop the keyword clause.
			$query->set( self::ALIAS_ONLY_VAR, true );
		} else {
			$query->set( 's', $match['search'] );
		}
	}

	/**
	 * Removes the keyword clause from an alias-only search.
	 *
	 * @param string    $search Search SQL.
	 * @param \WP_Query $query  Query.
	 * @return string
	 */
	public static function drop_alias_only_search( string $search, \WP_Query $query ): string {
		return $query->get( self::ALIAS_ONLY_VAR ) ? '' : $search;
	}

	/**
	 * Applies extract() to REST searches, for example /wp/v2/gr_restaurant?search=tumon.
	 *
	 * @param array<string, mixed> $args    WP_Query arguments.
	 * @param \WP_REST_Request     $request Request (unused).
	 * @return array<string, mixed>
	 */
	public static function filter_rest_query( array $args, $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- filter signature.
		if ( empty( $args['s'] ) ) {
			return $args;
		}

		$match = self::extract( (string) $args['s'] );
		if ( $match['village_id'] ) {
			$args['s']         = $match['search'];
			$args['tax_query'] = self::add_village_clause( $args['tax_query'] ?? array(), $match['village_id'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}
		return $args;
	}

	/**
	 * Redirects /village/{alias}/ to the village it belongs to.
	 *
	 * @return void
	 */
	public static function redirect_alias_archive(): void {
		if ( ! is_404() ) {
			return;
		}

		$slug = (string) get_query_var( Content_Types::TAX_VILLAGE );
		if ( '' === $slug ) {
			return;
		}

		$village_id = self::village_for_phrase( str_replace( '-', ' ', $slug ) );
		if ( $village_id ) {
			$link = get_term_link( $village_id, Content_Types::TAX_VILLAGE );
			if ( ! is_wp_error( $link ) ) {
				wp_safe_redirect( $link, 301 );
				exit;
			}
		}
	}

	/**
	 * Finds the first village name or alias in a search string.
	 *
	 * Longer phrases win, so "Upper Tumon" matches before "Tumon" and
	 * "Agana Heights" before "Agana".
	 *
	 * @param string $search Raw search string.
	 * @return array{search: string, village_id: int} The search with the matched phrase removed, and the village term ID (0 for no match).
	 */
	public static function extract( string $search ): array {
		$normalized = self::normalize( $search );
		if ( '' === $normalized ) {
			return array(
				'search'     => $search,
				'village_id' => 0,
			);
		}

		foreach ( self::phrase_map() as $phrase => $village_id ) {
			$pattern = '/(^| )' . preg_quote( $phrase, '/' ) . '( |$)/';
			if ( preg_match( $pattern, $normalized ) ) {
				$rest = trim( (string) preg_replace( '/ +/', ' ', (string) preg_replace( $pattern, ' ', $normalized, 1 ) ) );
				return array(
					'search'     => $rest,
					'village_id' => $village_id,
				);
			}
		}

		return array(
			'search'     => $search,
			'village_id' => 0,
		);
	}

	/**
	 * Returns the village whose name or alias is exactly this phrase.
	 *
	 * @param string $phrase Phrase.
	 * @return int Village term ID, or 0.
	 */
	public static function village_for_phrase( string $phrase ): int {
		$map = self::phrase_map();
		return $map[ self::normalize( $phrase ) ] ?? 0;
	}

	/**
	 * Lowercases, strips accents and apostrophes, and collapses everything
	 * else that is not a letter or digit to single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		$text = strtolower( remove_accents( $text ) );
		$text = str_replace( array( "'", '’', '`' ), '', $text );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}

	/**
	 * Normalized phrase => village term ID, longest phrase first.
	 *
	 * @return array<string, int>
	 */
	private static function phrase_map(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_VILLAGE,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$map = array();
		foreach ( $terms as $term ) {
			$phrases = array_merge( array( $term->name, $term->slug ), (array) get_term_meta( $term->term_id, Term_Meta::ALIASES, true ) );
			foreach ( $phrases as $phrase ) {
				$phrase = self::normalize( (string) $phrase );
				if ( '' !== $phrase && ! isset( $map[ $phrase ] ) ) {
					$map[ $phrase ] = (int) $term->term_id;
				}
			}
		}

		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);
		return $map;
	}

	/**
	 * Adds a village clause to an existing tax query.
	 *
	 * @param mixed $tax_query  Existing tax query.
	 * @param int   $village_id Village term ID.
	 * @return array<int|string, mixed>
	 */
	private static function add_village_clause( $tax_query, int $village_id ): array {
		$tax_query   = is_array( $tax_query ) ? $tax_query : array();
		$tax_query[] = array(
			'taxonomy' => Content_Types::TAX_VILLAGE,
			'field'    => 'term_id',
			'terms'    => array( $village_id ),
		);
		return $tax_query;
	}
}
