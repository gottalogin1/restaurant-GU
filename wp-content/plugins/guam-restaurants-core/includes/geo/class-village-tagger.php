<?php
/**
 * Tags restaurants with their village from their coordinates.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Geo;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Sets a restaurant's village term from its stored coordinates.
 */
final class Village_Tagger {

	public const CREATED   = 'created';
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';

	/**
	 * Locator.
	 *
	 * @var Village_Locator
	 */
	private Village_Locator $locator;

	/**
	 * Constructor.
	 *
	 * @param Village_Locator|null $locator Locator; defaults to the bundled boundaries.
	 */
	public function __construct( ?Village_Locator $locator = null ) {
		$this->locator = $locator ?? Village_Locator::instance();
	}

	/**
	 * Tags one restaurant.
	 *
	 * Uses the coordinates only when their source may be analyzed (OSM,
	 * Foursquare OS Places, owner or manual). A point outside every village
	 * goes to the review queue rather than being guessed.
	 *
	 * @param int      $post_id Restaurant ID.
	 * @param int|null $run_id  Ingestion run ID, when called from an import.
	 * @param bool     $dry_run Report what would change without writing.
	 * @return string One of the class constants: created (first village set),
	 *                updated (village changed), unchanged, or failed.
	 */
	public function tag( int $post_id, ?int $run_id = null, bool $dry_run = false ): string {
		$lat    = get_post_meta( $post_id, Restaurant_Meta::LATITUDE, true );
		$lng    = get_post_meta( $post_id, Restaurant_Meta::LONGITUDE, true );
		$source = (string) get_post_meta( $post_id, Restaurant_Meta::COORD_SOURCE, true );

		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || ! in_array( $source, Vocabulary::COORD_SOURCES, true ) ) {
			return self::FAILED;
		}

		$slug = $this->locator->slug_for( (float) $lat, (float) $lng );
		if ( null === $slug ) {
			if ( ! $dry_run ) {
				Review_Queue::enqueue(
					'village_unresolved',
					array(
						'latitude'     => (float) $lat,
						'longitude'    => (float) $lng,
						'coord_source' => $source,
					),
					'census',
					$post_id,
					null,
					$run_id
				);
			}
			return self::FAILED;
		}

		$term = get_term_by( 'slug', $slug, Content_Types::TAX_VILLAGE );
		if ( ! $term ) {
			return self::FAILED;
		}

		$current = wp_get_object_terms( $post_id, Content_Types::TAX_VILLAGE, array( 'fields' => 'slugs' ) );
		$current = is_wp_error( $current ) ? array() : $current;
		if ( array( $slug ) === $current ) {
			return self::UNCHANGED;
		}

		if ( ! $dry_run ) {
			$result = wp_set_object_terms( $post_id, array( (int) $term->term_id ), Content_Types::TAX_VILLAGE );
			if ( is_wp_error( $result ) ) {
				return self::FAILED;
			}
			Change_Log::record( $post_id, 'tax:' . Content_Types::TAX_VILLAGE, implode( ',', $current ), $slug, 'census', $run_id );
		}

		return $current ? self::UPDATED : self::CREATED;
	}
}
