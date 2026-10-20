<?php
/**
 * Which dishes visitors photograph and mention most at a restaurant.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Dishes;

use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Highlights;
use GuamRestaurants\Core\Highlights\Policy;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * Popular dishes (layout comparison change 11, Sanjay 2026-10-20), built
 * only from first-party content:
 *
 * - Visitors: approved photo uploads (dish name, optional 1 to 5 star
 *   dish rating) and approved visitor reviews that name the dishes the
 *   visitor tried (Visitor_Reviews, up to Visitor_Reviews::MAX_DISHES).
 * - The owner: dishes on the owner's menu (Owner_Content::MENU_DISHES) and
 *   the signature dishes of the newest approved owner quote.
 *
 * Ranking (rank()) counts people, not posts: a dish's score is the
 * number of different visitors who photographed it or named it in a
 * review, so one visitor uploading five photos counts once. Ties go to
 * the dish with more ratings, then the higher average, then the name.
 * Uploads and reviews from the owner or from anyone who said they are
 * connected to the restaurant are never counted and never rated (16 CFR
 * 465). Owner-listed dishes nobody has mentioned yet come after the
 * mentioned ones, in the owner's order, labeled "On the menu".
 *
 * Nothing paid takes part: ads, premium profiles, awards and third-party
 * ratings are not read here (CLAUDE.md rule 5). The sponsored spot shown
 * before the list (Display) is separate and labeled.
 */
final class Popular_Dishes {

	/**
	 * Most dishes shown.
	 */
	public const LIMIT = 8;

	/**
	 * Most photo and review rows read per restaurant.
	 */
	private const MAX_ROWS = 500;

	/**
	 * A dish name as a matching key: accents and case removed, "&" read
	 * as "and", punctuation dropped, spaces collapsed, and a plural "s"
	 * dropped from longer words, so "Chicken Kelaguen", "chicken kelaguen!"
	 * and "Chicken  Kelaguens" match.
	 *
	 * @param string $name Dish name.
	 * @return string '' when nothing is left.
	 */
	public static function key( string $name ): string {
		$name  = strtolower( remove_accents( wp_strip_all_tags( $name ) ) );
		$name  = str_replace( '&', ' and ', $name );
		$name  = (string) preg_replace( '/[^a-z0-9]+/', ' ', $name );
		$words = array_filter( explode( ' ', $name ), 'strlen' );
		$words = array_map(
			static function ( string $word ): string {
				return strlen( $word ) > 3 && 's' === substr( $word, -1 ) && 's' !== substr( $word, -2, 1 ) ? substr( $word, 0, -1 ) : $word;
			},
			$words
		);
		return implode( ' ', $words );
	}

	/**
	 * Ranks dishes. Pure, so the rules can be tested alone.
	 *
	 * @param array<int, array<string, mixed>>  $mentions {
	 *     Visitor mentions, each with these keys.
	 *
	 *     @type string $dish      Dish name as written.
	 *     @type string $source    photo or review.
	 *     @type string $person    Who (user ID or display name), for counting people once.
	 *     @type int    $rating    1 to 5, or 0 for none (reviews never rate a dish).
	 *     @type bool   $connected From the owner or a connected person.
	 *     @type int    $photo     Attachment ID, or 0.
	 *     @type string $date      Y-m-d H:i:s, newest wins for a person's rating.
	 * }
	 * @param array<int, array<string, string>> $owner Owner-listed dishes in order, each {name, price, details}.
	 * @param int                               $limit Most dishes.
	 * @return array<int, array{name: string, visitors: int, photos: int, reviews: int, ratings: int, average: float|null, photo: int, owner: bool, price: string, details: string}>
	 */
	public static function rank( array $mentions, array $owner, int $limit = self::LIMIT ): array {
		$dishes = array();
		$blank  = static function ( string $name ): array {
			return array(
				'name'     => $name,
				'visitors' => 0,
				'photos'   => 0,
				'reviews'  => 0,
				'ratings'  => 0,
				'average'  => null,
				'photo'    => 0,
				'owner'    => false,
				'price'    => '',
				'details'  => '',
			);
		};

		// Newest first, so each person's newest rating and the newest photo win.
		usort(
			$mentions,
			static function ( array $a, array $b ): int {
				return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) );
			}
		);

		$people   = array();
		$anon     = 0;
		$spelling = array();
		$rated    = array();
		$photo    = array();
		foreach ( $mentions as $mention ) {
			$name = trim( (string) ( $mention['dish'] ?? '' ) );
			$key  = self::key( $name );
			if ( '' === $key ) {
				continue;
			}
			$dishes[ $key ] = $dishes[ $key ] ?? $blank( $name );
			$photo_id       = (int) ( $mention['photo'] ?? 0 );
			if ( ! empty( $mention['connected'] ) ) {
				// Their photo may illustrate the dish, but it never counts.
				$photo[ $key ]['connected'] = $photo[ $key ]['connected'] ?? $photo_id;
				continue;
			}
			if ( $photo_id > 0 && empty( $photo[ $key ]['visitor'] ) ) {
				$photo[ $key ]['visitor'] = $photo_id;
			}
			$source = 'photo' === ( $mention['source'] ?? '' ) ? 'photos' : 'reviews';
			++$dishes[ $key ][ $source ];
			$person                    = strtolower( trim( (string) ( $mention['person'] ?? '' ) ) );
			$person                    = '' === $person ? 'anon:' . ( ++$anon ) : $person;
			$people[ $key ][ $person ] = true;
			$spelling[ $key ][ $name ] = ( $spelling[ $key ][ $name ] ?? 0 ) + 1;
			$rating                    = (int) ( $mention['rating'] ?? 0 );
			if ( $rating >= 1 && $rating <= 5 && ! isset( $rated[ $key ][ $person ] ) ) {
				$rated[ $key ][ $person ] = $rating;
			}
		}

		foreach ( $dishes as $key => $dish ) {
			$dishes[ $key ]['visitors'] = count( $people[ $key ] ?? array() );
			$dishes[ $key ]['photo']    = (int) ( $photo[ $key ]['visitor'] ?? ( $photo[ $key ]['connected'] ?? 0 ) );
			if ( ! empty( $rated[ $key ] ) ) {
				$dishes[ $key ]['ratings'] = count( $rated[ $key ] );
				$dishes[ $key ]['average'] = round( array_sum( $rated[ $key ] ) / count( $rated[ $key ] ), 1 );
			}
			if ( ! empty( $spelling[ $key ] ) ) {
				// The spelling most visitors used; the first one seen on a tie (arsort keeps order).
				arsort( $spelling[ $key ] );
				$dishes[ $key ]['name'] = (string) array_key_first( $spelling[ $key ] );
			}
		}

		$order = 0;
		$menu  = array();
		foreach ( $owner as $item ) {
			$name = trim( (string) ( $item['name'] ?? '' ) );
			$key  = self::key( $name );
			if ( '' === $key || isset( $menu[ $key ] ) ) {
				continue;
			}
			$menu[ $key ]   = $order++;
			$dishes[ $key ] = $dishes[ $key ] ?? $blank( $name );
			// The owner's spelling is the menu's spelling.
			$dishes[ $key ]['name']    = $name;
			$dishes[ $key ]['owner']   = true;
			$dishes[ $key ]['price']   = (string) ( $item['price'] ?? '' );
			$dishes[ $key ]['details'] = (string) ( $item['details'] ?? '' );
		}

		$mentioned = array_filter(
			$dishes,
			static function ( array $dish ): bool {
				return $dish['visitors'] > 0;
			}
		);
		uasort(
			$mentioned,
			static function ( array $a, array $b ): int {
				return array( - $a['visitors'], - $a['ratings'], - (float) $a['average'], strtolower( $a['name'] ) )
					<=> array( - $b['visitors'], - $b['ratings'], - (float) $b['average'], strtolower( $b['name'] ) );
			}
		);
		$unmentioned = array_diff_key( array_intersect_key( $dishes, $menu ), $mentioned );
		uksort(
			$unmentioned,
			static function ( string $a, string $b ) use ( $menu ): int {
				return $menu[ $a ] <=> $menu[ $b ];
			}
		);

		return array_slice( array_values( array_merge( $mentioned, $unmentioned ) ), 0, max( 1, $limit ) );
	}

	/**
	 * Popular dishes of a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @param int $limit         Most dishes.
	 * @return array<int, array<string, mixed>> See rank().
	 */
	public static function for_restaurant( int $restaurant_id, int $limit = self::LIMIT ): array {
		return self::rank( self::mentions( $restaurant_id ), self::owner_dishes( $restaurant_id ), $limit );
	}

	/**
	 * Visitor mentions from approved photos and approved reviews.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	public static function mentions( int $restaurant_id ): array {
		$mentions = array();
		$photos   = Photo_Submissions::ids( Photo_Submissions::APPROVED, $restaurant_id, self::MAX_ROWS );
		update_meta_cache( 'post', $photos );
		foreach ( $photos as $id ) {
			$user       = (int) get_post_meta( $id, Photo_Submissions::USER, true );
			$mentions[] = array(
				'dish'      => (string) get_post_meta( $id, Photo_Submissions::DISH, true ),
				'source'    => 'photo',
				'person'    => $user > 0 ? 'user:' . $user : 'name:' . (string) get_post_meta( $id, Photo_Submissions::NAME, true ),
				'rating'    => (int) get_post_meta( $id, Photo_Submissions::RATING, true ),
				'connected' => (bool) get_post_meta( $id, Photo_Submissions::CONNECTED, true ) || (bool) get_post_meta( $id, Photo_Submissions::FROM_OWNER, true ),
				'photo'     => $id,
				'date'      => (string) get_post_field( 'post_date_gmt', $id ),
			);
		}

		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT author_user_id, author_name, disclosure, dishes, created_at FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s AND dishes IS NOT NULL AND dishes <> '' ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$restaurant_id,
				Visitor_Reviews::TYPE,
				'approved',
				self::MAX_ROWS
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$user = (int) $row['author_user_id'];
			$seen = array();
			foreach ( explode( "\n", (string) $row['dishes'] ) as $dish ) {
				$key = self::key( $dish );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$mentions[]   = array(
					'dish'      => $dish,
					'source'    => 'review',
					'person'    => $user > 0 ? 'user:' . $user : 'name:' . (string) $row['author_name'],
					'rating'    => 0,
					'connected' => '' !== trim( (string) $row['disclosure'] ),
					'photo'     => 0,
					'date'      => (string) $row['created_at'],
				);
			}
		}
		return $mentions;
	}

	/**
	 * Owner-listed dishes: the menu first, then the newest approved owner
	 * quote's signature dishes.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array<string, string>>
	 */
	public static function owner_dishes( int $restaurant_id ): array {
		$dishes = Owner_Content::get( $restaurant_id )['menu_dishes'];
		$quote  = Highlights::approved( $restaurant_id, Policy::OWNER_QUOTE, 1 );
		if ( $quote && '' !== (string) $quote[0]['dishes'] ) {
			foreach ( explode( "\n", (string) $quote[0]['dishes'] ) as $name ) {
				$dishes[] = array(
					'name'    => $name,
					'price'   => '',
					'details' => '',
				);
			}
		}
		return $dishes;
	}
}
