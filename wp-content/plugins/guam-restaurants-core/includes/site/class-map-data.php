<?php
/**
 * Restaurant points for the map.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * GeoJSON for the map page: every matching restaurant with coordinates,
 * clustered in the browser. Only our own facts are included.
 */
final class Map_Data {

	/**
	 * Safety limit on points in one response.
	 */
	public const MAX_POINTS = 5000;

	/**
	 * A GeoJSON FeatureCollection.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public static function feature_collection( array $filters ): array {
		$filters['page'] = 1;
		$cache_args      = array( Filters::to_params( $filters ), Filters::is_timed( $filters ) ? Clock::minute_of_week() : null );
		return Cache::remember(
			'map',
			$cache_args,
			static function () use ( $filters ) {
				$query = Directory_Query::query(
					$filters,
					self::MAX_POINTS,
					array(
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);
				$ids   = array_map( 'intval', $query->posts );
				$out   = array();
				foreach ( array_chunk( $ids, 500 ) as $chunk ) {
					_prime_post_caches( $chunk, false, true );
					update_object_term_cache( $chunk, Content_Types::POST_TYPE );
					foreach ( $chunk as $id ) {
						$feature = self::feature( $id );
						if ( null !== $feature ) {
							$out[] = $feature;
						}
					}
				}
				return array(
					'type'     => 'FeatureCollection',
					'features' => $out,
				);
			},
			Filters::is_timed( $filters ) ? 60 : Cache::TTL
		);
	}

	/**
	 * One point, or null without coordinates.
	 *
	 * @param int $id Restaurant.
	 * @return array<string, mixed>|null
	 */
	public static function feature( int $id ): ?array {
		$coordinates = Restaurant_View::coordinates( $id );
		if ( null === $coordinates ) {
			return null;
		}
		$price = Restaurant_View::price( $id );
		return array(
			'type'       => 'Feature',
			'id'         => $id,
			'geometry'   => array(
				'type'        => 'Point',
				'coordinates' => array( round( $coordinates['lng'], 6 ), round( $coordinates['lat'], 6 ) ),
			),
			'properties' => array(
				'name'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'url'     => (string) get_permalink( $id ),
				'cuisine' => Restaurant_View::term_names( $id, Content_Types::TAX_CUISINE ),
				'place'   => implode( ', ', array_filter( array( Restaurant_View::term_names( $id, Content_Types::TAX_VILLAGE ), Restaurant_View::term_names( $id, Content_Types::TAX_MALL ) ) ) ),
				'price'   => null === $price ? '' : $price->name,
			),
		);
	}
}
