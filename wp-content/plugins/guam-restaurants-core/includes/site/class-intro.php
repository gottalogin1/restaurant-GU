<?php
/**
 * Introductions on village, mall, cuisine and price pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * The introduction is the term's Description, written and edited in
 * wp-admin (Restaurants > Villages, Malls, Cuisines). Until one is
 * written, a short factual line is built from the listings themselves, so
 * no page is empty and nothing is copied from elsewhere.
 */
final class Intro {

	/**
	 * Introduction markup for a term.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	public static function render( \WP_Term $term ): string {
		$description = trim( (string) term_description( $term ) );
		if ( '' !== $description ) {
			return '<div class="gr-intro">' . wp_kses_post( $description ) . '</div>';
		}
		return '<div class="gr-intro gr-intro--default"><p>' . esc_html( self::default_text( $term ) ) . '</p></div>';
	}

	/**
	 * The generated line, for example "12 restaurants in Hagåtña, most often Japanese, Chamorro and Korean."
	 *
	 * @param \WP_Term $term Term.
	 * @return string Plain text.
	 */
	public static function default_text( \WP_Term $term ): string {
		return Cache::remember(
			'intro',
			array( $term->term_id ),
			static function () use ( $term ) {
				$count = (int) $term->count;
				switch ( $term->taxonomy ) {
					case Content_Types::TAX_CUISINE:
						$top = self::top_terms( $term, Content_Types::TAX_VILLAGE );
						/* translators: 1: number, 2: cuisine. */
						$text = sprintf( _n( '%1$s %2$s restaurant on Guam.', '%1$s %2$s restaurants on Guam.', $count, 'guam-restaurants-core' ), number_format_i18n( $count ), $term->name );
						/* translators: %s: list of villages. */
						return $top ? $text . ' ' . sprintf( __( 'Most are in %s.', 'guam-restaurants-core' ), wp_sprintf_l( '%l', $top ) ) : $text;
					case Content_Types::TAX_PRICE:
						/* translators: 1: number, 2: price level. */
						return sprintf( _n( '%1$s restaurant at price level %2$s.', '%1$s restaurants at price level %2$s.', $count, 'guam-restaurants-core' ), number_format_i18n( $count ), $term->name );
					default:
						$top = self::top_terms( $term, Content_Types::TAX_CUISINE );
						/* translators: 1: number, 2: village or mall. */
						$text = sprintf( _n( '%1$s restaurant in %2$s.', '%1$s restaurants in %2$s.', $count, 'guam-restaurants-core' ), number_format_i18n( $count ), self::place_name( $term ) );
						/* translators: %s: list of cuisines. */
						return $top ? $text . ' ' . sprintf( __( 'The most common cuisines here are %s.', 'guam-restaurants-core' ), wp_sprintf_l( '%l', $top ) ) : $text;
				}
			},
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Village name with its Chamorro name, or mall name with its village.
	 *
	 * @param \WP_Term $term Term.
	 * @return string
	 */
	private static function place_name( \WP_Term $term ): string {
		if ( Content_Types::TAX_MALL === $term->taxonomy ) {
			$village = get_term( (int) get_term_meta( $term->term_id, Term_Meta::MALL_VILLAGE, true ), Content_Types::TAX_VILLAGE );
			/* translators: 1: mall, 2: village. */
			return $village instanceof \WP_Term ? sprintf( __( '%1$s, %2$s', 'guam-restaurants-core' ), $term->name, $village->name ) : $term->name;
		}
		return $term->name;
	}

	/**
	 * The three most common terms of another taxonomy among this term's restaurants.
	 *
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Other taxonomy.
	 * @return string[] Names.
	 */
	private static function top_terms( \WP_Term $term, string $taxonomy ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- aggregate over core tables; cached by the caller.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.name FROM {$wpdb->term_relationships} a
				INNER JOIN {$wpdb->posts} p ON p.ID = a.object_id AND p.post_status = 'publish' AND p.post_type = %s
				INNER JOIN {$wpdb->term_relationships} b ON b.object_id = a.object_id
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = b.term_taxonomy_id AND tt.taxonomy = %s
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE a.term_taxonomy_id = %d
				GROUP BY t.term_id, t.name ORDER BY COUNT(*) DESC, t.name ASC LIMIT 3",
				Content_Types::POST_TYPE,
				$taxonomy,
				(int) $term->term_taxonomy_id
			)
		);
		return array_map( 'strval', (array) $names );
	}
}
