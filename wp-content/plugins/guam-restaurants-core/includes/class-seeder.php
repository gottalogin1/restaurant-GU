<?php
/**
 * Idempotent taxonomy seeding.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the seed terms. Terms are matched by slug, so running a seed again
 * never duplicates terms, and meta an editor has already set is left alone.
 */
final class Seeder {

	/**
	 * Seeds everything in dependency order (malls reference villages).
	 *
	 * @return array<string, array{created: int, unchanged: int, failed: int}>
	 */
	public static function seed_all(): array {
		return array(
			'villages'     => self::seed_villages(),
			'malls'        => self::seed_malls(),
			'cuisines'     => self::seed_cuisines(),
			'price_levels' => self::seed_price_levels(),
		);
	}

	/**
	 * Seeds the 19 villages.
	 *
	 * @return array{created: int, unchanged: int, failed: int}
	 */
	public static function seed_villages(): array {
		$counts = self::empty_counts();
		foreach ( Seed_Data::villages() as $slug => $village ) {
			$term_id = self::ensure_term( Content_Types::TAX_VILLAGE, $slug, $village['name'], '', $counts );
			if ( $term_id ) {
				self::add_meta_once( $term_id, Term_Meta::CENSUS_GEOID, $village['geoid'] );
				self::add_meta_once( $term_id, Term_Meta::ALIASES, $village['aliases'] );
				self::add_meta_once( $term_id, Term_Meta::CHAMORRO_NAME, $village['chamorro'] );
			}
		}
		return $counts;
	}

	/**
	 * Seeds the malls, all unverified. Never marks an existing mall unverified.
	 *
	 * @return array{created: int, unchanged: int, failed: int}
	 */
	public static function seed_malls(): array {
		$counts = self::empty_counts();
		foreach ( Seed_Data::malls() as $slug => $mall ) {
			$term_id = self::ensure_term( Content_Types::TAX_MALL, $slug, $mall['name'], '', $counts );
			if ( $term_id ) {
				$village = get_term_by( 'slug', $mall['village'], Content_Types::TAX_VILLAGE );
				self::add_meta_once( $term_id, Term_Meta::VERIFIED, false );
				self::add_meta_once( $term_id, Term_Meta::MALL_VILLAGE, $village ? (int) $village->term_id : 0 );
				self::add_meta_once( $term_id, Term_Meta::ALIASES, $mall['aliases'] );
			}
		}
		return $counts;
	}

	/**
	 * Seeds the cuisines.
	 *
	 * @return array{created: int, unchanged: int, failed: int}
	 */
	public static function seed_cuisines(): array {
		$counts = self::empty_counts();
		foreach ( Seed_Data::cuisines() as $slug => $cuisine ) {
			$term_id = self::ensure_term( Content_Types::TAX_CUISINE, $slug, $cuisine['name'], '', $counts );
			if ( $term_id ) {
				self::add_meta_once( $term_id, Term_Meta::OSM_CUISINE, $cuisine['osm'] );
			}
		}
		return $counts;
	}

	/**
	 * Seeds the price levels.
	 *
	 * @return array{created: int, unchanged: int, failed: int}
	 */
	public static function seed_price_levels(): array {
		$counts = self::empty_counts();
		foreach ( Seed_Data::price_levels() as $slug => $level ) {
			$term_id = self::ensure_term( Content_Types::TAX_PRICE, $slug, $level['name'], $level['description'], $counts );
			if ( $term_id ) {
				self::add_meta_once( $term_id, Term_Meta::RANK, $level['rank'] );
			}
		}
		return $counts;
	}

	/**
	 * Returns the term ID for a slug, creating the term if needed.
	 *
	 * @param string                                           $taxonomy    Taxonomy.
	 * @param string                                           $slug        Term slug.
	 * @param string                                           $name        Term name.
	 * @param string                                           $description Term description.
	 * @param array{created: int, unchanged: int, failed: int} $counts      Counters, updated in place.
	 * @return int Term ID, or 0 on failure.
	 */
	private static function ensure_term( string $taxonomy, string $slug, string $name, string $description, array &$counts ): int {
		$existing = get_terms(
			array(
				'taxonomy'                                => $taxonomy,
				'slug'                                    => $slug,
				'hide_empty'                              => false,
				'fields'                                  => 'ids',
				Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
			)
		);
		if ( ! is_wp_error( $existing ) && $existing ) {
			++$counts['unchanged'];
			return (int) $existing[0];
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug'        => $slug,
				'description' => $description,
			)
		);
		if ( is_wp_error( $result ) ) {
			++$counts['failed'];
			return 0;
		}

		++$counts['created'];
		return (int) $result['term_id'];
	}

	/**
	 * Adds term meta only when the key has never been set.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return void
	 */
	private static function add_meta_once( int $term_id, string $key, $value ): void {
		if ( ! metadata_exists( 'term', $term_id, $key ) ) {
			add_term_meta( $term_id, $key, $value, true );
		}
	}

	/**
	 * Zeroed counters.
	 *
	 * @return array{created: int, unchanged: int, failed: int}
	 */
	private static function empty_counts(): array {
		return array(
			'created'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);
	}
}
