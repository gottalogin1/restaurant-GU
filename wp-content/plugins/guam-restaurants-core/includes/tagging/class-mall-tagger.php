<?php
/**
 * Mall membership by boundary, by address text, or by hand.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Osm\Mall_Locator;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which mall a restaurant is in, in this order:
 *
 * 1. A manual override (Tag_Store::mall_override()): a mall, or "none".
 * 2. The mall's boundary: inside its OSM outline, or within the radius of
 *    a mall OSM maps as a point (origin `source`).
 * 3. The mall's name or an alias in the street address, for example
 *    "Micronesia Mall, 1088 W Marine Corps Dr" (origin `rule`).
 *
 * Unverified malls are assigned too; they stay hidden from visitors until
 * verified (Mall_Verification).
 */
final class Mall_Tagger {

	/**
	 * Option holding the mall outlines from the last OSM import.
	 */
	public const GEOMETRY_OPTION = 'guam_restaurants_core_mall_geometries';

	/**
	 * Boundary lookup.
	 *
	 * @var Mall_Locator
	 */
	private Mall_Locator $boundaries;

	/**
	 * Normalized names and aliases => slug, longest first.
	 *
	 * @var array<string, string>
	 */
	private array $names = array();

	/**
	 * Constructor.
	 *
	 * @param Mall_Locator                                                                      $boundaries Boundary lookup.
	 * @param array<int, array{slug: string, name: string, aliases: string[], osm_id?: string}> $terms      Mall terms.
	 */
	public function __construct( Mall_Locator $boundaries, array $terms ) {
		$this->boundaries = $boundaries;
		foreach ( $terms as $term ) {
			foreach ( array_merge( array( $term['name'] ), $term['aliases'] ) as $name ) {
				$key = self::normalize( (string) $name );
				if ( '' !== $key ) {
					$this->names[ $key ] = $term['slug'];
				}
			}
		}
		uksort(
			$this->names,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
	}

	/**
	 * Tagger using the outlines saved by the last OSM import.
	 *
	 * @param float $radius Metres around a mall mapped as a point.
	 * @return Mall_Tagger
	 */
	public static function from_saved( float $radius = 150.0 ): Mall_Tagger {
		$geometries = get_option( self::GEOMETRY_OPTION );
		return new self( Mall_Locator::from_geometries( is_array( $geometries ) ? $geometries : array(), $radius ), Importer::mall_terms() );
	}

	/**
	 * Saves outlines for later re-tagging; malls missing from this import keep their saved outline.
	 *
	 * @param array<string, array<string, mixed>> $geometries Slug => geometry (Mall_Locator::geometries()).
	 * @return void
	 */
	public static function save_geometries( array $geometries ): void {
		if ( ! $geometries ) {
			return;
		}
		$saved = get_option( self::GEOMETRY_OPTION );
		update_option( self::GEOMETRY_OPTION, array_merge( is_array( $saved ) ? $saved : array(), $geometries ), false );
	}

	/**
	 * The automatic mall for a place, ignoring overrides.
	 *
	 * @param float|null $lat     Latitude, or null when unknown.
	 * @param float|null $lng     Longitude.
	 * @param string     $address Street address.
	 * @return array{slug: string, origin: string, detail: string}|null
	 */
	public function resolve( ?float $lat, ?float $lng, string $address ): ?array {
		if ( null !== $lat && null !== $lng ) {
			$match = $this->boundaries->match_for( $lat, $lng );
			if ( $match ) {
				return array(
					'slug'   => $match['slug'],
					'origin' => 'source',
					'detail' => $match['detail'],
				);
			}
		}

		$slug = $this->slug_in_address( $address );
		if ( null !== $slug ) {
			return array(
				'slug'   => $slug,
				'origin' => 'rule',
				'detail' => 'address',
			);
		}
		return null;
	}

	/**
	 * Mall named in an address, longest name first, whole words only.
	 *
	 * @param string $address Address.
	 * @return string|null
	 */
	public function slug_in_address( string $address ): ?string {
		$haystack = ' ' . self::normalize( $address ) . ' ';
		if ( '  ' === $haystack ) {
			return null;
		}
		foreach ( $this->names as $name => $slug ) {
			if ( false !== strpos( $haystack, ' ' . $name . ' ' ) ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * Tags a restaurant. A manual override always wins.
	 *
	 * @param int        $post_id Restaurant.
	 * @param float|null $lat     Latitude (defaults to the stored one).
	 * @param float|null $lng     Longitude.
	 * @param bool       $dry_run Compute only.
	 * @return array{old: string, new: string, origin: string}
	 */
	public function apply( int $post_id, ?float $lat = null, ?float $lng = null, bool $dry_run = false ): array {
		$current = Term_Slugs::for_post( $post_id, Content_Types::TAX_MALL );
		$old     = $current[0] ?? '';

		if ( null === $lat || null === $lng ) {
			$stored_lat = (float) get_post_meta( $post_id, Restaurant_Meta::LATITUDE, true );
			$stored_lng = (float) get_post_meta( $post_id, Restaurant_Meta::LONGITUDE, true );
			$lat        = 0.0 === $stored_lat && 0.0 === $stored_lng ? null : $stored_lat;
			$lng        = null === $lat ? null : $stored_lng;
		}

		$override = Tag_Store::mall_override( $post_id );
		$origins  = Tag_Store::origins( $post_id );

		if ( '' !== $override ) {
			return array(
				'old'    => $old,
				'new'    => $old,
				'origin' => 'manual',
			);
		}

		$match = $this->resolve( $lat, $lng, (string) get_post_meta( $post_id, Restaurant_Meta::ADDRESS, true ) );
		$new   = $match ? $match['slug'] : '';

		// A mall set before origins were recorded that the boundary and address
		// do not explain is treated as set by hand, as the importer always kept it.
		if ( '' !== $old && $new !== $old && ! isset( $origins[ Content_Types::TAX_MALL ][ $old ] ) ) {
			if ( ! $dry_run ) {
				update_post_meta( $post_id, Tag_Store::MALL_OVERRIDE_META, $old );
				$origins[ Content_Types::TAX_MALL ] = array( $old => Tag_Store::origin( 'manual', 'legacy' ) );
				Tag_Store::save_origins( $post_id, $origins );
			}
			return array(
				'old'    => $old,
				'new'    => $old,
				'origin' => 'manual',
			);
		}

		if ( ! $dry_run ) {
			if ( $new !== $old || count( $current ) > 1 ) {
				Tag_Store::applying(
					static function () use ( $post_id, $new ) {
						Term_Slugs::set( $post_id, '' === $new ? array() : array( $new ), Content_Types::TAX_MALL );
					}
				);
			}
			$origins[ Content_Types::TAX_MALL ] = $match ? array( $new => Tag_Store::origin( $match['origin'], $match['detail'] ) ) : array();
			Tag_Store::save_origins( $post_id, $origins );
		}

		return array(
			'old'    => $old,
			'new'    => $new,
			'origin' => $match ? $match['origin'] : '',
		);
	}

	/**
	 * Lowercase words without accents or punctuation; keeps a leading "the"
	 * so "The Plaza" does not match "Tumon Sands Plaza".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtolower( remove_accents( $text ) );
		$text = str_replace( '&', ' and ', $text );
		$text = (string) preg_replace( "/['’`]/u", '', $text );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}
}
