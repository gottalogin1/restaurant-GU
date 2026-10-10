<?php
/**
 * Which map a page may show, and the map settings.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Ratings\Ratings;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Map tiles and the Google rule.
 *
 * Tiles: MapLibre GL JS with OpenFreeMap vector tiles by default. Its
 * public instance is free with no request limits, needs no key, and
 * allows commercial use; attribution (OpenFreeMap, OpenMapTiles,
 * OpenStreetMap) comes with the style and MapLibre shows it on the map. It
 * has no SLA, so the style URL is a setting (Config::MAP_STYLE_URL).
 *
 * Google: Places content must not appear on or near a non-Google map
 * (Maps ToS §3.2.3(e)). A restaurant page that can show Google ratings uses
 * the free Maps Embed API, or no map at all when no embed key is set.
 */
final class Maps {

	public const DEFAULT_STYLE_URL = 'https://tiles.openfreemap.org/styles/positron';

	/**
	 * Map centre and zoom showing the whole island.
	 */
	public const GUAM_CENTER = array( 144.7937, 13.4443 );
	public const GUAM_ZOOM   = 9.6;

	/**
	 * MapLibre style URL.
	 *
	 * @return string
	 */
	public static function style_url(): string {
		return esc_url_raw( Config::get( Config::MAP_STYLE_URL, self::DEFAULT_STYLE_URL ) );
	}

	/**
	 * Whether Google content (ratings) can appear on this restaurant's page.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return bool
	 */
	public static function google_content_possible( int $restaurant_id ): bool {
		$providers = Ratings::providers();
		return isset( $providers['google'] ) && '' !== (string) get_post_meta( $restaurant_id, Restaurant_Meta::GOOGLE_PLACE_ID, true );
	}

	/**
	 * Which map the restaurant page shows: maplibre, google, or none.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function restaurant_map_kind( int $restaurant_id ): string {
		if ( null === Restaurant_View::coordinates( $restaurant_id ) ) {
			return 'none';
		}
		if ( self::google_content_possible( $restaurant_id ) ) {
			return '' === Config::get( Config::GOOGLE_MAPS_EMBED_KEY ) ? 'none' : 'google';
		}
		return 'maplibre';
	}

	/**
	 * Google Maps Embed URL for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function google_embed_url( int $restaurant_id ): string {
		return add_query_arg(
			array(
				'key' => rawurlencode( Config::get( Config::GOOGLE_MAPS_EMBED_KEY ) ),
				'q'   => rawurlencode( 'place_id:' . get_post_meta( $restaurant_id, Restaurant_Meta::GOOGLE_PLACE_ID, true ) ),
			),
			'https://www.google.com/maps/embed/v1/place'
		);
	}

	/**
	 * Directions link (Google Maps URLs need no key and carry no Places content).
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function directions_url( int $restaurant_id ): string {
		$coordinates = Restaurant_View::coordinates( $restaurant_id );
		if ( null === $coordinates ) {
			return '';
		}
		$args     = array(
			'api'         => '1',
			'destination' => $coordinates['lat'] . ',' . $coordinates['lng'],
		);
		$place_id = (string) get_post_meta( $restaurant_id, Restaurant_Meta::GOOGLE_PLACE_ID, true );
		if ( '' !== $place_id ) {
			$args['destination_place_id'] = $place_id;
		}
		return add_query_arg( array_map( 'rawurlencode', $args ), 'https://www.google.com/maps/dir/' );
	}

	/**
	 * Settings passed to assets/site/map.js.
	 *
	 * @return array<string, mixed>
	 */
	public static function script_settings(): array {
		return array(
			'style'   => self::style_url(),
			'center'  => self::GUAM_CENTER,
			'zoom'    => self::GUAM_ZOOM,
			'library' => array(
				'js'  => plugins_url( 'assets/vendor/maplibre-gl/maplibre-gl.js', GUAM_RESTAURANTS_CORE_FILE ),
				'css' => plugins_url( 'assets/vendor/maplibre-gl/maplibre-gl.css', GUAM_RESTAURANTS_CORE_FILE ),
			),
		);
	}
}
