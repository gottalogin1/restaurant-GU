<?php
/**
 * Branch names: "McDonald's, Mangilao".
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Names;

defined( 'ABSPATH' ) || exit;

/**
 * Restaurants that share a name (branches of a chain) are titled
 * "<name>, <village>" so they can be told apart; a restaurant with a name of
 * its own keeps the plain name (Sanjay, 2026-10-20).
 *
 * Names::key() ignores the suffix, so matching (discovery, duplicates,
 * awards, imports) still sees "McDonald's, Mangilao" as "McDonald's".
 * A branch with no village (or more than one) keeps its title and gets a
 * `village_unresolved` review item; once a person sets its village, the
 * village hook renames it and closes that item.
 */
final class Branch_Names {

	/**
	 * Statuses that count as a listing (trash does not).
	 */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Review payload reason for a branch with no village.
	 */
	public const QUEUE_REASON = 'branch_name';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'set_object_terms', array( self::class, 'village_set' ), 20, 4 );
	}

	/**
	 * Renames a restaurant's branches when a person sets its village.
	 * Importers run with the change tracker suppressed and rename once at
	 * the end of their run instead.
	 *
	 * @param int    $object_id Object.
	 * @param array  $terms     Terms.
	 * @param array  $tt_ids    Term taxonomy IDs.
	 * @param string $taxonomy  Taxonomy.
	 * @return void
	 */
	public static function village_set( $object_id, $terms, $tt_ids, $taxonomy ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature.
		if ( Content_Types::TAX_VILLAGE !== $taxonomy || ! Change_Tracker::active() || Content_Types::POST_TYPE !== get_post_type( (int) $object_id ) ) {
			return;
		}
		self::apply( false, array( Names::key( (string) get_post_field( 'post_title', (int) $object_id, 'raw' ) ) ) );
	}

	/**
	 * Village name for a restaurant's suffix.
	 *
	 * @param int $post_id Restaurant.
	 * @return string '' when it has no village or more than one.
	 */
	public static function village_label( int $post_id ): string {
		$terms = wp_get_object_terms( $post_id, Content_Types::TAX_VILLAGE );
		if ( is_wp_error( $terms ) || 1 !== count( $terms ) ) {
			return '';
		}
		return html_entity_decode( $terms[0]->name, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Renames every branch that needs it.
	 *
	 * @param bool          $dry_run Report without writing.
	 * @param string[]|null $keys    Only these name keys (Names::key()); null for all.
	 * @return array{renamed: array<int, array{old: string, new: string}>, no_village: int[], same_village: int[]}
	 */
	public static function apply( bool $dry_run = false, ?array $keys = null ): array {
		$result = array(
			'renamed'      => array(),
			'no_village'   => array(),
			'same_village' => array(),
		);

		foreach ( self::groups( $keys ) as $ids ) {
			$labels = array();
			foreach ( $ids as $id ) {
				$labels[ $id ] = self::village_label( $id );
			}
			$per_village = array_count_values( array_filter( $labels ) );

			foreach ( $ids as $id ) {
				$label = $labels[ $id ];
				if ( '' === $label ) {
					$result['no_village'][] = $id;
					if ( ! $dry_run ) {
						self::flag( $id );
					}
					continue;
				}
				if ( $per_village[ $label ] > 1 ) {
					$result['same_village'][] = $id;
				}

				$title = (string) get_post_field( 'post_title', $id, 'raw' );
				$new   = self::stored_title( Names::split_village( $title )[0] . ', ' . $label );
				if ( $new === $title ) {
					continue;
				}
				$result['renamed'][ $id ] = array(
					'old' => $title,
					'new' => $new,
				);
				if ( ! $dry_run ) {
					wp_update_post(
						wp_slash(
							array(
								'ID'         => $id,
								'post_title' => $new,
							)
						)
					);
					self::unflag( $id );
				}
			}
		}

		return $result;
	}

	/**
	 * Restaurants grouped by name key, only groups of two or more.
	 *
	 * @param string[]|null $keys Only these keys; null for all.
	 * @return array<string, int[]>
	 */
	private static function groups( ?array $keys ): array {
		global $wpdb;
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$rows     = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$statuses}) ORDER BY ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( Content_Types::POST_TYPE ), self::STATUSES )
			),
			ARRAY_A
		);

		$wanted = null === $keys ? null : array_flip( $keys );
		$groups = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$key = Names::key( (string) $row['post_title'] );
			if ( '' === $key || ( null !== $wanted && ! isset( $wanted[ $key ] ) ) ) {
				continue;
			}
			$groups[ $key ][] = (int) $row['ID'];
		}
		return array_filter(
			$groups,
			static function ( array $ids ): bool {
				return count( $ids ) > 1;
			}
		);
	}

	/**
	 * A title as WordPress stores it, so an unchanged title is not rewritten.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function stored_title( string $title ): string {
		return wp_unslash( sanitize_post_field( 'post_title', wp_slash( $title ), 0, 'db' ) );
	}

	/**
	 * Asks for a branch's village in the review queue (once).
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	private static function flag( int $post_id ): void {
		if ( self::pending_flag( $post_id ) ) {
			return;
		}
		Review_Queue::enqueue(
			'village_unresolved',
			array(
				'reason' => self::QUEUE_REASON,
				'note'   => __( 'Another listing has the same name. Set this one\'s village in the editor and its name becomes "Name, Village".', 'guam-restaurants-core' ),
			),
			'system',
			$post_id
		);
	}

	/**
	 * Closes a branch's village request once it has been renamed.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	private static function unflag( int $post_id ): void {
		$item = self::pending_flag( $post_id );
		if ( $item ) {
			Review_Queue::resolve( $item, 'approved', 0 );
		}
	}

	/**
	 * Pending branch-village request for a restaurant.
	 *
	 * @param int $post_id Restaurant.
	 * @return int Item ID, or 0.
	 */
	private static function pending_flag( int $post_id ): int {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM {$table} WHERE restaurant_id = %d AND type = 'village_unresolved' AND status = 'pending'", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( self::QUEUE_REASON === ( $payload['reason'] ?? '' ) ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}
}
