<?php
/**
 * Hotels, and the places to eat near each one.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Osm\Mall_Locator;

defined( 'ABSPATH' ) || exit;

/**
 * The hotels that get a "Restaurants near" page (English audience idea E6,
 * comparison addition 21).
 *
 * Hotel names and locations come from OpenStreetMap (`tourism=hotel`,
 * docs/data-sources.md §1b), loaded with `wp guam-restaurants hotels
 * import` or the button on Restaurants > Hotels. An editor can hide a hotel
 * and add one OSM lacks, with a source link.
 *
 * Places are listed by straight-line distance only. Nothing paid, rated or
 * reviewed changes the order (CLAUDE.md rule 5).
 */
final class Hotels {

	public const OPTION = 'guam_restaurants_hotels';

	/**
	 * Source key on Restaurants > Import runs.
	 */
	public const RUN_SOURCE = 'osm-hotels';

	/**
	 * Up to this far is "within walking distance" (about a 17-minute walk).
	 */
	public const WALK_M = 1000;

	/**
	 * Up to this far is "a short drive away".
	 */
	public const DRIVE_M = 3000;

	/**
	 * Most places listed in each band, so a page in Tumon stays readable.
	 */
	public const MAX_WALK  = 60;
	public const MAX_DRIVE = 24;

	/**
	 * Walking pace in metres a minute, and how much longer a real route is
	 * than a straight line. Used only for the "about N min walk" estimate.
	 */
	public const WALK_PACE   = 75;
	public const ROUTE_RATIO = 1.3;

	/**
	 * Other hotels shown as links on a hotel page.
	 */
	public const MAX_NEIGHBOURS = 6;

	/**
	 * Places near each hotel, for the request.
	 *
	 * @var array<string, array{walk: array<int, float>, drive: array<int, float>}>
	 */
	private static array $places = array();

	/**
	 * Every hotel, by slug, in name order.
	 *
	 * @return array<string, array{name: string, osm: string, lat: float, lng: float, village: string, hidden: bool, source: string}>
	 */
	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$list  = array();
		foreach ( is_array( $saved ) ? $saved : array() as $slug => $hotel ) {
			$clean = self::clean( (array) $hotel );
			if ( null !== $clean && is_string( $slug ) && '' !== $slug ) {
				$list[ $slug ] = $clean;
			}
		}
		uasort(
			$list,
			static function ( array $a, array $b ): int {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $list;
	}

	/**
	 * Hotels that are not hidden.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function visible(): array {
		return array_filter(
			self::all(),
			static function ( array $hotel ): bool {
				return ! $hotel['hidden'];
			}
		);
	}

	/**
	 * One visible hotel, or null.
	 *
	 * @param string $slug Slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$visible = self::visible();
		return isset( $visible[ $slug ] ) ? array( 'slug' => $slug ) + $visible[ $slug ] : null;
	}

	/**
	 * Visible hotels with enough places near them for a page, by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function with_pages(): array {
		$list = array();
		foreach ( self::visible() as $slug => $hotel ) {
			$hotel = array( 'slug' => (string) $slug ) + $hotel;
			if ( self::has_page( $hotel ) ) {
				$list[ (string) $slug ] = $hotel;
			}
		}
		return $list;
	}

	/**
	 * Whether a hotel has a page: Cuisine_Villages::MIN_PLACES places within
	 * a short drive, the rule every generated page on the site follows.
	 *
	 * @param array<string, mixed> $hotel Hotel.
	 * @return bool
	 */
	public static function has_page( array $hotel ): bool {
		$places = self::places( $hotel );
		return count( $places['walk'] ) + count( $places['drive'] ) >= Cuisine_Villages::MIN_PLACES;
	}

	/**
	 * A stored hotel with every field typed, or null when it lacks a name or
	 * a usable location.
	 *
	 * @param array<string, mixed> $hotel Raw.
	 * @return array{name: string, osm: string, lat: float, lng: float, village: string, hidden: bool, source: string}|null
	 */
	public static function clean( array $hotel ): ?array {
		$name = sanitize_text_field( (string) ( $hotel['name'] ?? '' ) );
		$lat  = $hotel['lat'] ?? null;
		$lng  = $hotel['lng'] ?? null;
		if ( '' === $name || ! is_numeric( $lat ) || ! is_numeric( $lng ) || ! self::on_guam( (float) $lat, (float) $lng ) ) {
			return null;
		}
		$osm = (string) ( $hotel['osm'] ?? '' );
		return array(
			'name'    => $name,
			'osm'     => 1 === preg_match( '#^(node|way|relation)/\d+$#', $osm ) ? $osm : '',
			'lat'     => round( (float) $lat, 7 ),
			'lng'     => round( (float) $lng, 7 ),
			'village' => sanitize_key( (string) ( $hotel['village'] ?? '' ) ),
			'hidden'  => ! empty( $hotel['hidden'] ),
			'source'  => esc_url_raw( (string) ( $hotel['source'] ?? '' ) ),
		);
	}

	/**
	 * Whether a point is inside Guam's bounding box.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return bool
	 */
	public static function on_guam( float $lat, float $lng ): bool {
		list( $south, $west, $north, $east ) = \GuamRestaurants\Core\Osm\Overpass_Client::GUAM_BBOX;
		return $lat >= $south && $lat <= $north && $lng >= $west && $lng <= $east;
	}

	/**
	 * Named hotels in an Overpass response: nodes have a point, ways and
	 * relations a center (`out center`).
	 *
	 * @param array<int, array<string, mixed>> $elements Overpass elements.
	 * @return array<string, array{name: string, lat: float, lng: float}> By OSM id ("way/123").
	 */
	public static function from_osm( array $elements ): array {
		$list = array();
		foreach ( $elements as $element ) {
			$tags = isset( $element['tags'] ) && is_array( $element['tags'] ) ? $element['tags'] : array();
			if ( 'hotel' !== ( $tags['tourism'] ?? '' ) ) {
				continue;
			}
			$name = trim( (string) ( $tags['name:en'] ?? $tags['name'] ?? '' ) );
			$type = (string) ( $element['type'] ?? '' );
			$id   = (int) ( $element['id'] ?? 0 );
			$lat  = $element['lat'] ?? ( $element['center']['lat'] ?? null );
			$lng  = $element['lon'] ?? ( $element['center']['lon'] ?? null );
			if ( '' === $name || ! in_array( $type, array( 'node', 'way', 'relation' ), true ) || $id <= 0 || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				continue;
			}
			$list[ $type . '/' . $id ] = array(
				'name' => $name,
				'lat'  => (float) $lat,
				'lng'  => (float) $lng,
			);
		}
		return $list;
	}

	/**
	 * Merges an OSM import into the saved list. Hotels gone from OSM are
	 * dropped, hidden hotels stay hidden, and hotels an editor added by hand
	 * are kept as they are.
	 *
	 * @param array<string, array<string, mixed>>                        $saved    Current list, by slug.
	 * @param array<string, array{name: string, lat: float, lng: float}> $imported From from_osm().
	 * @param callable|null                                              $village  Village slug for a point; defaults to the Census villages.
	 * @return array{hotels: array<string, array<string, mixed>>, counts: array{created: int, updated: int, unchanged: int, removed: int}}
	 */
	public static function merge( array $saved, array $imported, ?callable $village = null ): array {
		$village = $village ?? static function ( float $lat, float $lng ): string {
			return (string) Village_Locator::instance()->slug_for( $lat, $lng );
		};
		$counts  = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'removed'   => 0,
		);
		$by_osm  = array();
		$hotels  = array();
		foreach ( $saved as $slug => $hotel ) {
			$hotel = self::clean( (array) $hotel );
			if ( null === $hotel ) {
				continue;
			}
			if ( '' === $hotel['osm'] ) {
				$hotels[ (string) $slug ] = $hotel;
			} elseif ( isset( $imported[ $hotel['osm'] ] ) ) {
				$by_osm[ $hotel['osm'] ] = array( (string) $slug, $hotel );
			} else {
				++$counts['removed'];
			}
		}
		// Hotels keeping their name keep their slug, so they go first and no
		// renamed or new hotel can take it.
		uksort(
			$imported,
			static function ( $a, $b ) use ( $by_osm, $imported ): int {
				$keep_a = isset( $by_osm[ $a ] ) && $by_osm[ $a ][1]['name'] === $imported[ $a ]['name'];
				$keep_b = isset( $by_osm[ $b ] ) && $by_osm[ $b ][1]['name'] === $imported[ $b ]['name'];
				$order  = $keep_b <=> $keep_a;
				return 0 !== $order ? $order : strcmp( (string) $a, (string) $b );
			}
		);
		foreach ( $imported as $osm => $place ) {
			$fresh = self::clean(
				array(
					'name'    => $place['name'],
					'osm'     => $osm,
					'lat'     => $place['lat'],
					'lng'     => $place['lng'],
					'village' => $village( (float) $place['lat'], (float) $place['lng'] ),
					'source'  => 'https://www.openstreetmap.org/' . $osm,
				)
			);
			if ( null === $fresh ) {
				continue;
			}
			if ( isset( $by_osm[ $osm ] ) ) {
				list( $slug, $old ) = $by_osm[ $osm ];
				$fresh['hidden']    = $old['hidden'];
				$slug               = $old['name'] === $fresh['name'] ? $slug : self::unique_slug( $fresh, $hotels, $osm );
				++$counts[ $old === $fresh ? 'unchanged' : 'updated' ];
			} else {
				$slug = self::unique_slug( $fresh, $hotels, $osm );
				++$counts['created'];
			}
			$hotels[ $slug ] = $fresh;
		}
		return array(
			'hotels' => $hotels,
			'counts' => $counts,
		);
	}

	/**
	 * Imports hotels from Overpass elements, saves them and logs the run
	 * (source `osm-hotels` on Restaurants > Import runs). Running it twice
	 * with the same data changes nothing.
	 *
	 * @param array<int, array<string, mixed>> $elements Overpass elements.
	 * @param bool                             $dry_run  Count only; save nothing.
	 * @return array{created: int, updated: int, unchanged: int, removed: int, failed: int, run_id: int}
	 */
	public static function import( array $elements, bool $dry_run = false ): array {
		$imported = self::from_osm( $elements );
		$result   = self::merge( (array) get_option( self::OPTION, array() ), $imported );
		$counts   = $result['counts'] + array( 'failed' => count( $imported ) - ( $result['counts']['created'] + $result['counts']['updated'] + $result['counts']['unchanged'] ) );
		$run_id   = 0;
		if ( ! $dry_run ) {
			$run_id = Ingestion_Runs::start( self::RUN_SOURCE );
			update_option( self::OPTION, $result['hotels'], false );
			Cache::flush();
			self::reset();
			Ingestion_Runs::finish( $run_id, $counts, sprintf( 'Hotels: %d removed (no longer in OSM).', $counts['removed'] ) );
		}
		return $counts + array( 'run_id' => $run_id );
	}

	/**
	 * Saves the list an editor changed (Restaurants > Hotels).
	 *
	 * @param array<string, array<string, mixed>> $hotels Hotels by slug.
	 * @return void
	 */
	public static function save( array $hotels ): void {
		update_option( self::OPTION, $hotels, false );
		Cache::flush();
		self::reset();
	}

	/**
	 * A slug from the name, with the village (then the OSM id) added when
	 * another hotel already has it.
	 *
	 * @param array<string, mixed>                $hotel  Hotel.
	 * @param array<string, array<string, mixed>> $taken  Hotels so far, by slug.
	 * @param string                              $osm    OSM id, or '' for a hand-added hotel.
	 * @return string
	 */
	public static function unique_slug( array $hotel, array $taken, string $osm = '' ): string {
		$base = sanitize_title( (string) $hotel['name'] );
		$base = '' === $base ? 'hotel' : $base;
		foreach ( array( $base, $base . '-' . $hotel['village'], $base . '-' . str_replace( '/', '-', $osm ) ) as $slug ) {
			if ( '' !== $slug && ! isset( $taken[ $slug ] ) && '-' !== substr( $slug, -1 ) ) {
				return $slug;
			}
		}
		$n = 2;
		while ( isset( $taken[ $base . '-' . $n ] ) ) {
			++$n;
		}
		return $base . '-' . $n;
	}

	/**
	 * Places within DRIVE_M of a point, nearest first, split into walking
	 * distance and a short drive.
	 *
	 * @param float                                     $lat    Latitude.
	 * @param float                                     $lng    Longitude.
	 * @param array<int, array{lat: float, lng: float}> $points Restaurant points by ID.
	 * @return array{walk: array<int, float>, drive: array<int, float>} Metres by restaurant ID.
	 */
	public static function nearby( float $lat, float $lng, array $points ): array {
		$near = array();
		foreach ( $points as $id => $point ) {
			$metres = Mall_Locator::distance( $lat, $lng, (float) $point['lat'], (float) $point['lng'] );
			if ( $metres <= self::DRIVE_M ) {
				$near[ (int) $id ] = $metres;
			}
		}
		// Nearest first; equal distances by ID, so the order never depends on anything else.
		uksort(
			$near,
			static function ( int $a, int $b ) use ( $near ): int {
				$order = $near[ $a ] <=> $near[ $b ];
				return 0 !== $order ? $order : $a <=> $b;
			}
		);
		$walk  = array();
		$drive = array();
		foreach ( $near as $id => $metres ) {
			if ( $metres <= self::WALK_M ) {
				if ( count( $walk ) < self::MAX_WALK ) {
					$walk[ $id ] = $metres;
				}
			} elseif ( count( $drive ) < self::MAX_DRIVE ) {
				$drive[ $id ] = $metres;
			}
		}
		return array(
			'walk'  => $walk,
			'drive' => $drive,
		);
	}

	/**
	 * Places near a hotel, kept for the request.
	 *
	 * @param array<string, mixed> $hotel Hotel.
	 * @return array{walk: array<int, float>, drive: array<int, float>}
	 */
	public static function places( array $hotel ): array {
		$key = (string) $hotel['lat'] . ',' . (string) $hotel['lng'];
		if ( ! isset( self::$places[ $key ] ) ) {
			self::$places[ $key ] = self::nearby( (float) $hotel['lat'], (float) $hotel['lng'], self::points() );
		}
		return self::$places[ $key ];
	}

	/**
	 * Forgets the places kept for the request (tests, imports).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$places = array();
	}

	/**
	 * Every listed restaurant with coordinates (published, not closed).
	 *
	 * @return array<int, array{lat: float, lng: float}>
	 */
	public static function points(): array {
		return Cache::remember(
			'hotel-points',
			1,
			static function (): array {
				$ids    = Directory_Query::query(
					Filters::from_params( array() ),
					-1,
					array(
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'update_post_term_cache' => false,
					)
				)->posts;
				$points = array();
				foreach ( array_map( 'intval', $ids ) as $id ) {
					$point = Restaurant_View::coordinates( $id );
					if ( null !== $point ) {
						$points[ $id ] = $point;
					}
				}
				return $points;
			}
		);
	}

	/**
	 * Visible hotels with pages within WALK_M of a point, nearest first.
	 *
	 * @param float  $lat     Latitude.
	 * @param float  $lng     Longitude.
	 * @param string $exclude Slug to leave out (the page's own hotel).
	 * @param int    $limit   Most to return.
	 * @return array<string, float> Metres by slug.
	 */
	public static function hotels_near( float $lat, float $lng, string $exclude = '', int $limit = self::MAX_NEIGHBOURS ): array {
		$near = array();
		foreach ( self::visible() as $slug => $hotel ) {
			$metres = Mall_Locator::distance( $lat, $lng, $hotel['lat'], $hotel['lng'] );
			if ( $slug !== $exclude && $metres <= self::WALK_M ) {
				$near[ (string) $slug ] = $metres;
			}
		}
		asort( $near );
		$out = array();
		foreach ( $near as $slug => $metres ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			if ( self::has_page( array( 'slug' => $slug ) + self::visible()[ $slug ] ) ) {
				$out[ $slug ] = $metres;
			}
		}
		return $out;
	}

	/**
	 * Estimated walking time in minutes, at least 1.
	 *
	 * @param float $metres Straight-line distance.
	 * @return int
	 */
	public static function walk_minutes( float $metres ): int {
		return max( 1, (int) round( $metres * self::ROUTE_RATIO / self::WALK_PACE ) );
	}

	/**
	 * A distance for people: "350 m" under a kilometre, "1.4 km" above.
	 *
	 * @param float $metres Metres.
	 * @return string
	 */
	public static function distance_label( float $metres ): string {
		if ( $metres < 1000 ) {
			/* translators: %s: distance in metres. */
			return sprintf( __( '%s m', 'guam-restaurants-core' ), number_format_i18n( max( 10, (int) ( round( $metres / 10 ) * 10 ) ) ) );
		}
		/* translators: %s: distance in kilometres. */
		return sprintf( __( '%s km', 'guam-restaurants-core' ), number_format_i18n( $metres / 1000, 1 ) );
	}

	/**
	 * Name of a hotel's village, or ''.
	 *
	 * @param array<string, mixed> $hotel Hotel.
	 * @return string
	 */
	public static function village_name( array $hotel ): string {
		if ( '' === (string) $hotel['village'] ) {
			return '';
		}
		$term = get_term_by( 'slug', (string) $hotel['village'], Content_Types::TAX_VILLAGE );
		return $term instanceof \WP_Term ? $term->name : '';
	}
}
