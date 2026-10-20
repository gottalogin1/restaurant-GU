<?php
/**
 * A visitor's saved restaurants.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Saved;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * One list of saved restaurants per person (layout change 6).
 *
 * - Without an account the list lives only in the visitor's browser
 *   (localStorage, assets/site/saved.js). The server never sees it until
 *   the visitor asks for the Saved page or signs in.
 * - With an account it is stored in user meta, so it follows the person
 *   to every device. Saves made in the browser are added to it at sign-in.
 * - A shared list is just restaurant IDs in the Saved page's URL
 *   (`?list=12,34`). Nothing about the person who shared it is stored or
 *   shown.
 *
 * Order is the order of saving, oldest first; pages show the newest first.
 * The list never changes what other visitors see: no counts of saves,
 * no "popular" ranking and no recommendations (privacy policy).
 */
final class Saved_List {

	/**
	 * User meta holding a member's list (restaurant IDs, oldest first).
	 */
	public const META = 'gr_saved_restaurants';

	/**
	 * Most restaurants one list keeps; the oldest are dropped beyond it.
	 */
	public const MAX = 500;

	/**
	 * Most restaurants a shared link carries.
	 */
	public const SHARE_MAX = 100;

	/**
	 * Restaurant IDs from a comma-separated string or an array, in order,
	 * without duplicates (the last occurrence wins, so a re-save moves an
	 * ID to the end) and without anything that is not a positive integer.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Most IDs kept (the newest).
	 * @return int[]
	 */
	public static function parse_ids( $value, int $max = self::MAX ): array {
		if ( is_scalar( $value ) ) {
			$value = explode( ',', (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( is_int( $id ) ? $id > 0 : ( is_string( $id ) && 1 === preg_match( '/^\s*[1-9][0-9]{0,18}\s*$/', $id ) ) ) {
				unset( $ids[ (int) $id ] );
				$ids[ (int) $id ] = (int) $id;
			}
		}
		return array_slice( array_values( $ids ), -max( 1, $max ) );
	}

	/**
	 * Keeps only IDs of published restaurants, in the same order.
	 *
	 * @param int[] $ids Restaurant IDs.
	 * @return int[]
	 */
	public static function published( array $ids ): array {
		if ( ! $ids ) {
			return array();
		}
		_prime_post_caches( $ids, false, false );
		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return Content_Types::POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id );
				}
			)
		);
	}

	/**
	 * A member's list, oldest first.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function get( int $user_id ): array {
		return $user_id > 0 ? self::parse_ids( get_user_meta( $user_id, self::META, true ) ) : array();
	}

	/**
	 * Stores a member's list.
	 *
	 * @param int   $user_id User.
	 * @param int[] $ids     Restaurant IDs, oldest first.
	 * @return int[] The stored list.
	 */
	private static function put( int $user_id, array $ids ): array {
		$ids = self::parse_ids( $ids );
		if ( $ids ) {
			update_user_meta( $user_id, self::META, $ids );
		} else {
			delete_user_meta( $user_id, self::META );
		}
		return $ids;
	}

	/**
	 * Adds and removes restaurants. Added IDs that are not published
	 * restaurants are ignored; added IDs already on the list keep their
	 * place, so merging a browser's list does not reorder the account's.
	 *
	 * @param int   $user_id User.
	 * @param int[] $add     Restaurant IDs to add, oldest first.
	 * @param int[] $remove  Restaurant IDs to remove.
	 * @return int[] The list after the change.
	 */
	public static function change( int $user_id, array $add, array $remove = array() ): array {
		if ( $user_id < 1 ) {
			return array();
		}
		$list = self::get( $user_id );
		$new  = array_diff( self::published( self::parse_ids( $add ) ), $list );
		$list = array_merge( $list, $new );
		$list = array_values( array_diff( $list, self::parse_ids( $remove ) ) );
		return self::put( $user_id, $list );
	}

	/**
	 * Empties a member's list.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	public static function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
	}

	/**
	 * Removes a deleted restaurant from every list that holds it.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function forget_restaurant( int $post_id ): void {
		if ( Content_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$users = get_users(
			array(
				'meta_key'     => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- runs only when a restaurant is deleted.
				'meta_compare' => 'EXISTS',
				'fields'       => 'ID',
				'number'       => -1,
			)
		);
		foreach ( $users as $user_id ) {
			$list = self::get( (int) $user_id );
			if ( in_array( $post_id, $list, true ) ) {
				self::put( (int) $user_id, array_values( array_diff( $list, array( $post_id ) ) ) );
			}
		}
	}

	/**
	 * The Saved page link that shares these restaurants (newest SHARE_MAX).
	 *
	 * @param int[]  $ids  Restaurant IDs, oldest first.
	 * @param string $page Saved page URL.
	 * @return string '' when there is nothing to share.
	 */
	public static function share_url( array $ids, string $page ): string {
		$ids = self::parse_ids( $ids, self::SHARE_MAX );
		return $ids && '' !== $page ? add_query_arg( View::LIST_PARAM, implode( ',', $ids ), $page ) : '';
	}
}
