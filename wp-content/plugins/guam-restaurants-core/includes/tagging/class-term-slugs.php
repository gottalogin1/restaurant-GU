<?php
/**
 * Term slug helpers that see unverified malls.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Mall_Verification;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes term slugs for back-end code. Every lookup includes
 * unverified malls, which the public term filter would otherwise hide.
 */
final class Term_Slugs {

	/**
	 * Sorted term slugs on a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	public static function for_post( int $post_id, string $taxonomy ): array {
		// The object term cache holds every term, unverified malls too, so a primed post needs no query.
		$cached = get_object_term_cache( $post_id, $taxonomy );
		if ( is_array( $cached ) ) {
			$slugs = array_map( 'strval', wp_list_pluck( $cached, 'slug' ) );
			sort( $slugs );
			return $slugs;
		}
		$slugs = wp_get_object_terms(
			$post_id,
			$taxonomy,
			array(
				'fields'                                  => 'slugs',
				Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
			)
		);
		$slugs = is_wp_error( $slugs ) ? array() : array_map( 'strval', $slugs );
		sort( $slugs );
		return $slugs;
	}

	/**
	 * Sorted slugs for term taxonomy IDs.
	 *
	 * @param int[]  $tt_ids   Term taxonomy IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	public static function from_tt_ids( array $tt_ids, string $taxonomy ): array {
		$slugs = Mall_Verification::with_unverified(
			static function () use ( $tt_ids, $taxonomy ) {
				$slugs = array();
				foreach ( $tt_ids as $tt_id ) {
					$term = get_term_by( 'term_taxonomy_id', $tt_id, $taxonomy );
					if ( $term instanceof \WP_Term ) {
						$slugs[] = $term->slug;
					}
				}
				return $slugs;
			}
		);
		$slugs = array_values( array_unique( $slugs ) );
		sort( $slugs );
		return $slugs;
	}

	/**
	 * Sets a post's terms by slug. Unknown slugs are skipped and returned.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string[] $slugs    Slugs.
	 * @param string   $taxonomy Taxonomy.
	 * @return string[] Slugs with no term.
	 */
	public static function set( int $post_id, array $slugs, string $taxonomy ): array {
		return Mall_Verification::with_unverified(
			static function () use ( $post_id, $slugs, $taxonomy ) {
				$ids     = array();
				$missing = array();
				foreach ( $slugs as $slug ) {
					$found = get_terms(
						array(
							'taxonomy'   => $taxonomy,
							'slug'       => $slug,
							'hide_empty' => false,
							'fields'     => 'ids',
						)
					);
					if ( is_wp_error( $found ) || ! $found ) {
						$missing[] = $slug;
						continue;
					}
					$ids[] = (int) $found[0];
				}
				wp_set_object_terms( $post_id, $ids, $taxonomy );
				return $missing;
			}
		);
	}
}
