<?php
/**
 * Where each cuisine and mall tag came from, and manual overrides.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps, per restaurant, the origin of each cuisine and mall tag (`source`,
 * `rule` or `manual`, Vocabulary::TAG_ORIGINS) and the manual overrides that
 * imports and rules never change.
 *
 * Any change to the cuisine or mall terms that is not made by a tagger (an
 * editor's checkbox in wp-admin, the REST API, `wp post term`) is recorded
 * as a manual override: an added cuisine is kept, a removed one stays
 * removed, and a mall set or cleared by hand stays that way.
 *
 * Stored in hidden post meta (leading underscore), so it is not editable
 * through the Custom Fields box or REST.
 */
final class Tag_Store {

	public const ORIGINS_META         = '_gr_tag_origins';
	public const CUISINE_SOURCES_META = '_gr_cuisine_sources';
	public const CUISINE_MANUAL_META  = '_gr_cuisine_manual';
	public const MALL_OVERRIDE_META   = '_gr_mall_override';

	/**
	 * Mall override meaning "in no mall".
	 */
	public const NO_MALL = 'none';

	/**
	 * Nesting depth of applying().
	 *
	 * @var int
	 */
	private static int $applying = 0;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'set_object_terms', array( self::class, 'record_manual' ), 20, 6 );
	}

	/**
	 * Runs a callback whose term changes are a tagger's, not a person's.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function applying( callable $callback ) {
		++self::$applying;
		try {
			return $callback();
		} finally {
			--self::$applying;
		}
	}

	/**
	 * Records a person's cuisine or mall change as a manual override.
	 *
	 * @param int    $object_id  Post ID.
	 * @param array  $terms      Terms passed (unused).
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append (unused).
	 * @param array  $old_tt_ids Old term taxonomy IDs.
	 * @return void
	 */
	public static function record_manual( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- action signature.
		$object_id = (int) $object_id;
		if ( self::$applying > 0 || ! in_array( $taxonomy, array( Content_Types::TAX_CUISINE, Content_Types::TAX_MALL ), true ) || Content_Types::POST_TYPE !== get_post_type( $object_id ) ) {
			return;
		}

		$old = Term_Slugs::from_tt_ids( array_map( 'intval', (array) $old_tt_ids ), (string) $taxonomy );
		$new = Term_Slugs::from_tt_ids( array_map( 'intval', (array) $tt_ids ), (string) $taxonomy );
		if ( $old === $new ) {
			return;
		}

		$origins = self::origins( $object_id );
		$user    = get_current_user_id();
		$detail  = $user ? 'user:' . $user : 'manual';

		if ( Content_Types::TAX_MALL === $taxonomy ) {
			update_post_meta( $object_id, self::MALL_OVERRIDE_META, $new ? $new[0] : self::NO_MALL );
			$origins[ Content_Types::TAX_MALL ] = array();
			foreach ( $new as $slug ) {
				$origins[ Content_Types::TAX_MALL ][ $slug ] = self::origin( 'manual', $detail );
			}
			self::save_origins( $object_id, $origins );
			return;
		}

		// Tags from before origins were recorded are kept as manual, as the importer always kept them.
		$manual = self::cuisine_manual( $object_id );
		if ( self::is_legacy_cuisine( $object_id ) ) {
			$manual['add'] = $old;
			foreach ( $old as $slug ) {
				$origins[ Content_Types::TAX_CUISINE ][ $slug ] = self::origin( 'manual', 'legacy' );
			}
		}
		foreach ( array_diff( $new, $old ) as $slug ) {
			$manual['add']                                  = array_values( array_unique( array_merge( $manual['add'], array( $slug ) ) ) );
			$manual['remove']                               = array_values( array_diff( $manual['remove'], array( $slug ) ) );
			$origins[ Content_Types::TAX_CUISINE ][ $slug ] = self::origin( 'manual', $detail );
		}
		foreach ( array_diff( $old, $new ) as $slug ) {
			$manual['remove'] = array_values( array_unique( array_merge( $manual['remove'], array( $slug ) ) ) );
			$manual['add']    = array_values( array_diff( $manual['add'], array( $slug ) ) );
			unset( $origins[ Content_Types::TAX_CUISINE ][ $slug ] );
		}
		self::save_cuisine_manual( $object_id, $manual );
		self::save_origins( $object_id, $origins );
	}

	/**
	 * Tag origins: taxonomy => slug => {origin, detail}.
	 *
	 * @param int $post_id Restaurant.
	 * @return array<string, array<string, array{origin: string, detail: string}>>
	 */
	public static function origins( int $post_id ): array {
		$origins = get_post_meta( $post_id, self::ORIGINS_META, true );
		$origins = is_array( $origins ) ? $origins : array();
		return array(
			Content_Types::TAX_CUISINE => (array) ( $origins[ Content_Types::TAX_CUISINE ] ?? array() ),
			Content_Types::TAX_MALL    => (array) ( $origins[ Content_Types::TAX_MALL ] ?? array() ),
		);
	}

	/**
	 * Saves tag origins.
	 *
	 * @param int                                                                 $post_id Restaurant.
	 * @param array<string, array<string, array{origin: string, detail: string}>> $origins Origins.
	 * @return void
	 */
	public static function save_origins( int $post_id, array $origins ): void {
		foreach ( $origins as $taxonomy => $slugs ) {
			ksort( $slugs );
			$origins[ $taxonomy ] = $slugs;
		}
		update_post_meta( $post_id, self::ORIGINS_META, $origins );
	}

	/**
	 * One origin entry.
	 *
	 * @param string $origin source, rule or manual.
	 * @param string $detail For example `osm:sushi`, `rule:pizza`, `user:1`.
	 * @return array{origin: string, detail: string}
	 */
	public static function origin( string $origin, string $detail ): array {
		return array(
			'origin' => $origin,
			'detail' => $detail,
		);
	}

	/**
	 * Manual cuisine overrides.
	 *
	 * @param int $post_id Restaurant.
	 * @return array{add: string[], remove: string[]}
	 */
	public static function cuisine_manual( int $post_id ): array {
		$manual = get_post_meta( $post_id, self::CUISINE_MANUAL_META, true );
		return array(
			'add'    => array_values( array_map( 'strval', (array) ( is_array( $manual ) ? ( $manual['add'] ?? array() ) : array() ) ) ),
			'remove' => array_values( array_map( 'strval', (array) ( is_array( $manual ) ? ( $manual['remove'] ?? array() ) : array() ) ) ),
		);
	}

	/**
	 * Saves manual cuisine overrides.
	 *
	 * @param int                                    $post_id Restaurant.
	 * @param array{add: string[], remove: string[]} $manual  Overrides.
	 * @return void
	 */
	public static function save_cuisine_manual( int $post_id, array $manual ): void {
		sort( $manual['add'] );
		sort( $manual['remove'] );
		update_post_meta( $post_id, self::CUISINE_MANUAL_META, $manual );
	}

	/**
	 * Whether the restaurant has never been through the cuisine tagger
	 * (tagged before tag origins were recorded).
	 *
	 * @param int $post_id Restaurant.
	 * @return bool
	 */
	public static function is_legacy_cuisine( int $post_id ): bool {
		return '' === get_post_meta( $post_id, self::CUISINE_SOURCES_META, true ) && '' === get_post_meta( $post_id, self::CUISINE_MANUAL_META, true );
	}

	/**
	 * Cuisine slugs each source gave: source => slug => detail.
	 *
	 * @param int $post_id Restaurant.
	 * @return array<string, array<string, string>>
	 */
	public static function cuisine_sources( int $post_id ): array {
		$sources = get_post_meta( $post_id, self::CUISINE_SOURCES_META, true );
		return is_array( $sources ) ? $sources : array();
	}

	/**
	 * Saves the cuisine slugs a source gave.
	 *
	 * @param int                   $post_id Restaurant.
	 * @param string                $source  Source key.
	 * @param array<string, string> $slugs   Slug => detail.
	 * @return void
	 */
	public static function save_cuisine_source( int $post_id, string $source, array $slugs ): void {
		$sources            = self::cuisine_sources( $post_id );
		$sources[ $source ] = $slugs;
		ksort( $sources );
		update_post_meta( $post_id, self::CUISINE_SOURCES_META, $sources );
	}

	/**
	 * Manual mall override: '' (automatic), 'none', or a mall slug.
	 *
	 * @param int $post_id Restaurant.
	 * @return string
	 */
	public static function mall_override( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::MALL_OVERRIDE_META, true );
	}

	/**
	 * Removes manual overrides so the restaurant is tagged automatically again.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	public static function reset_overrides( int $post_id ): void {
		delete_post_meta( $post_id, self::CUISINE_MANUAL_META );
		delete_post_meta( $post_id, self::MALL_OVERRIDE_META );
		update_post_meta( $post_id, self::CUISINE_SOURCES_META, self::cuisine_sources( $post_id ) );
	}
}
