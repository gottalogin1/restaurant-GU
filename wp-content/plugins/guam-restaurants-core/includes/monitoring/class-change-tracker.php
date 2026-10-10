<?php
/**
 * Change log entries for edits made outside the importers.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Term_Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the change log when a person edits a tracked fact in wp-admin, the
 * REST API or WP-CLI: status, hours, phone, website, address, price level,
 * village and mall. Also records when a restaurant is first seen.
 *
 * Importers and other back-end jobs record their own changes with their
 * source and run ID, so they run inside suppress() and nothing is logged twice.
 */
final class Change_Tracker {

	/**
	 * Meta keys whose changes are logged.
	 */
	public const META_FIELDS = array(
		Restaurant_Meta::STATUS,
		Restaurant_Meta::HOURS,
		Restaurant_Meta::PHONE,
		Restaurant_Meta::WEBSITE,
		Restaurant_Meta::ADDRESS,
	);

	/**
	 * Taxonomies whose changes are logged.
	 */
	public const TAXONOMIES = array(
		Content_Types::TAX_PRICE,
		Content_Types::TAX_VILLAGE,
		Content_Types::TAX_MALL,
	);

	public const FIRST_SEEN = 'first_seen';

	/**
	 * Nesting depth of suppress().
	 *
	 * @var int
	 */
	private static int $suppressed = 0;

	/**
	 * Values before an update, keyed by "post_id:meta_key".
	 *
	 * @var array<string, mixed>
	 */
	private static array $before = array();

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'update_post_meta', array( self::class, 'remember' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'updated' ), 10, 4 );
		add_action( 'added_post_meta', array( self::class, 'added' ), 10, 4 );
		add_action( 'set_object_terms', array( self::class, 'terms_set' ), 10, 6 );
		add_action( 'transition_post_status', array( self::class, 'status_transition' ), 10, 3 );
	}

	/**
	 * Runs a callback without logging edits (the callback logs its own).
	 *
	 * @param callable $callback Callback.
	 * @return mixed The callback's return value.
	 */
	public static function suppress( callable $callback ) {
		++self::$suppressed;
		try {
			return $callback();
		} finally {
			--self::$suppressed;
		}
	}

	/**
	 * Whether edits are being logged right now.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return 0 === self::$suppressed;
	}

	/**
	 * Source for a person's edit: `owner` when the restaurant's linked owner
	 * makes it without editor rights, otherwise `editor`.
	 *
	 * @param int $post_id Restaurant.
	 * @return string
	 */
	public static function editor_source( int $post_id ): string {
		$user = get_current_user_id();
		if ( $user && (int) get_post_meta( $post_id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) === $user && ! current_user_can( 'edit_others_posts' ) ) {
			return 'owner';
		}
		return 'editor';
	}

	/**
	 * Keeps the old value before a tracked meta update.
	 *
	 * @param int    $meta_id   Meta ID (unused).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public static function remember( $meta_id, $object_id, $meta_key ): void {
		if ( self::tracks_meta( (int) $object_id, (string) $meta_key ) ) {
			self::$before[ $object_id . ':' . $meta_key ] = get_post_meta( (int) $object_id, (string) $meta_key, true );
		}
	}

	/**
	 * Logs a tracked meta update.
	 *
	 * @param int    $meta_id    Meta ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public static function updated( $meta_id, $object_id, $meta_key, $meta_value ): void {
		$key = $object_id . ':' . $meta_key;
		if ( ! array_key_exists( $key, self::$before ) ) {
			return;
		}
		$old = self::$before[ $key ];
		unset( self::$before[ $key ] );
		if ( self::tracks_meta( (int) $object_id, (string) $meta_key ) && $old !== $meta_value ) {
			Change_Log::record( (int) $object_id, (string) $meta_key, '' === $old ? null : $old, $meta_value, self::editor_source( (int) $object_id ) );
		}
	}

	/**
	 * Logs a tracked meta value set for the first time.
	 *
	 * @param int    $meta_id    Meta ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 * @return void
	 */
	public static function added( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( self::tracks_meta( (int) $object_id, (string) $meta_key ) && '' !== $meta_value && array() !== $meta_value ) {
			// Before the first save, the field read as its registered default (status: unknown).
			$default = get_metadata_default( 'post', (int) $object_id, (string) $meta_key, true );
			$old     = '' === $default || array() === $default ? null : $default;
			if ( $old !== $meta_value ) {
				Change_Log::record( (int) $object_id, (string) $meta_key, $old, $meta_value, self::editor_source( (int) $object_id ) );
			}
		}
	}

	/**
	 * Logs a tracked taxonomy change.
	 *
	 * @param int    $object_id  Post ID.
	 * @param array  $terms      Terms passed (unused).
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append (unused).
	 * @param array  $old_tt_ids Old term taxonomy IDs.
	 * @return void
	 */
	public static function terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- action signature.
		if ( ! self::active() || ! in_array( $taxonomy, self::TAXONOMIES, true ) || Content_Types::POST_TYPE !== get_post_type( (int) $object_id ) ) {
			return;
		}
		$old = self::slugs_for( array_map( 'intval', (array) $old_tt_ids ), (string) $taxonomy );
		$new = self::slugs_for( array_map( 'intval', (array) $tt_ids ), (string) $taxonomy );
		if ( $old !== $new ) {
			Change_Log::record( (int) $object_id, 'tax:' . $taxonomy, implode( ',', $old ), implode( ',', $new ), self::editor_source( (int) $object_id ) );
		}
	}

	/**
	 * Records first sight of a restaurant a person publishes.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function status_transition( $new_status, $old_status, $post ): void {
		if ( ! self::active() || 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post || Content_Types::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( self::has_first_seen( $post->ID ) ) {
			return;
		}
		self::record_first_seen( $post->ID, self::editor_source( $post->ID ), null, false );
	}

	/**
	 * Writes a first_seen entry.
	 *
	 * @param int      $post_id  Restaurant.
	 * @param string   $source   Fact source.
	 * @param int|null $run_id   Ingestion run.
	 * @param bool     $baseline True for restaurants found by a source's first run (not new openings).
	 * @param string   $external Source ID, for example the OSM element.
	 * @return int Row ID.
	 */
	public static function record_first_seen( int $post_id, string $source, ?int $run_id, bool $baseline, string $external = '' ): int {
		$value = array( 'source' => $source );
		if ( '' !== $external ) {
			$value['id'] = $external;
		}
		if ( $baseline ) {
			$value['baseline'] = true;
		}
		return Change_Log::record( $post_id, self::FIRST_SEEN, null, $value, $source, $run_id );
	}

	/**
	 * Whether a restaurant already has a first_seen entry.
	 *
	 * @param int $post_id Restaurant.
	 * @return bool
	 */
	public static function has_first_seen( int $post_id ): bool {
		global $wpdb;
		$table = Schema::table( Schema::CHANGE_LOG );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE restaurant_id = %d AND field = %s LIMIT 1", $post_id, self::FIRST_SEEN ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether a meta change should be logged now.
	 *
	 * @param int    $post_id  Post.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	private static function tracks_meta( int $post_id, string $meta_key ): bool {
		return self::active() && in_array( $meta_key, self::META_FIELDS, true ) && Content_Types::POST_TYPE === get_post_type( $post_id );
	}

	/**
	 * Sorted term slugs for term taxonomy IDs.
	 *
	 * @param int[]  $tt_ids   Term taxonomy IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private static function slugs_for( array $tt_ids, string $taxonomy ): array {
		return Term_Slugs::from_tt_ids( $tt_ids, $taxonomy );
	}
}
