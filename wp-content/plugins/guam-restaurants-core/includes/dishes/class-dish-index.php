<?php
/**
 * Which restaurants serve which dish, across the island.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Dishes;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Highlights\Policy;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; the name is built from a constant and values go through prepare().

/**
 * The index behind the "Where to get it" dish pages (comparison addition
 * 12, Sanjay 2026-10-20). Built from the same first-party content as
 * Popular_Dishes (approved dish photos, dishes named in approved reviews,
 * the owner's menu dishes and signature dishes), and only that: nothing
 * paid, no third-party data.
 *
 * Kept in one option, not autoloaded, and rebuilt in the background a
 * minute after a photo, review, owner menu or restaurant status changes,
 * and once a day. Visitors are stored as short hashes, only to count each
 * person once per restaurant.
 *
 * A dish page is a dish name (as a Popular_Dishes::key()) that at least
 * MIN_PLACES restaurants serve, or one of the seeds (Guam favorites) that
 * at least one serves. A restaurant serves a page's dish when one of its
 * dishes is that name or ends with it: "Chicken kelaguen" and "Beef
 * kelaguen" are both on the kelaguen page.
 */
final class Dish_Index {

	public const OPTION       = 'guam_restaurants_dish_index';
	public const VERSION      = 1;
	public const REBUILD_HOOK = 'guam_restaurants_rebuild_dish_index';

	/**
	 * Restaurants a dish name needs before it gets its own page.
	 */
	public const MIN_PLACES = 2;

	/**
	 * Seconds after a change before the rebuild runs, so a batch of
	 * moderation decisions rebuilds once.
	 */
	public const DELAY = 60;

	/**
	 * Words that are not a dish page on their own: menu words, and plain
	 * ingredients that would gather every "fried rice" or "BBQ chicken".
	 */
	public const NOT_DISHES = array( 'special', 'combo', 'plate', 'set', 'meal', 'lunch', 'dinner', 'breakfast', 'brunch', 'menu', 'food', 'item', 'side', 'drink', 'dessert', 'appetizer', 'rice', 'chicken', 'pork', 'beef', 'fish', 'shrimp', 'egg', 'noodle' );

	/**
	 * Guam favorites: matching key => name shown. They get a page as soon
	 * as one restaurant serves them, and lead the dish list.
	 */
	public const SEEDS = array(
		'kelaguen'   => 'Kelaguen',
		'red rice'   => 'Red rice',
		'kadon pika' => 'Kådon pika',
		'empanada'   => 'Empanada',
		'lumpia'     => 'Lumpia',
		'tinaktak'   => 'Tinaktak',
		'titiya'     => 'Titiyas',
		'apigigi'    => 'Apigigi',
		'latiya'     => 'Latiya',
		'shaved ice' => 'Shaved ice',
	);

	/**
	 * Index built in this request, so a page and its links read it once.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $index = null;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::REBUILD_HOOK, array( self::class, 'rebuild' ) );
		add_action( 'added_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'meta_changed' ), 10, 3 );
		add_action( 'guam_restaurants_highlight_moderated', array( self::class, 'schedule' ) );
		add_action( 'transition_post_status', array( self::class, 'status_changed' ), 10, 3 );
	}

	/**
	 * Schedules a rebuild when dish content or a restaurant's status changes.
	 *
	 * @param int|int[] $meta_id  Meta ID (unused).
	 * @param int       $post_id  Post.
	 * @param string    $meta_key Key.
	 * @return void
	 */
	public static function meta_changed( $meta_id, $post_id, $meta_key ): void {
		if ( in_array( $meta_key, array( Photo_Submissions::STATUS, Photo_Submissions::DISH, Owner_Content::MENU_DISHES, Restaurant_Meta::STATUS ), true ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedules a rebuild when a restaurant is published or unpublished.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 * @return void
	 */
	public static function status_changed( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof \WP_Post && Content_Types::POST_TYPE === $post->post_type && in_array( 'publish', array( $new_status, $old_status ), true ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedules one background rebuild, if none is waiting.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::REBUILD_HOOK ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::REBUILD_HOOK );
		}
	}

	/**
	 * The index: built now if there is none, else as stored (a day-old one
	 * is rebuilt in the background).
	 *
	 * @return array{version: int, built: int, places: array<int, array<string, array{name: string, people: string[], owner: bool}>>, pages: array<string, array{key: string, name: string, places: int, seed: bool}>}
	 */
	public static function get(): array {
		if ( null !== self::$index ) {
			return self::$index;
		}
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || self::VERSION !== ( $stored['version'] ?? 0 ) ) {
			return self::rebuild();
		}
		if ( (int) ( $stored['built'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
			self::schedule();
		}
		self::$index = $stored;
		return self::$index;
	}

	/**
	 * Rebuilds and stores the index.
	 *
	 * @return array<string, mixed> See get().
	 */
	public static function rebuild(): array {
		$places = array();
		foreach ( self::candidates() as $restaurant_id ) {
			$entries = self::entries( Popular_Dishes::mentions( $restaurant_id ), Popular_Dishes::owner_dishes( $restaurant_id ) );
			if ( $entries ) {
				$places[ $restaurant_id ] = $entries;
			}
		}
		self::$index = array(
			'version' => self::VERSION,
			'built'   => time(),
			'places'  => $places,
			'pages'   => self::pages( $places ),
		);
		update_option( self::OPTION, self::$index, false );
		return self::$index;
	}

	/**
	 * Forgets the index read in this request (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$index = null;
	}

	/**
	 * One restaurant's dishes: key => name, the visitors who named it (as
	 * hashes) and whether the owner lists it. Pure. Mentions from the
	 * owner or a connected person never count (Popular_Dishes::rank()).
	 *
	 * @param array<int, array<string, mixed>>  $mentions Popular_Dishes::mentions().
	 * @param array<int, array<string, string>> $owner    Popular_Dishes::owner_dishes().
	 * @return array<string, array{name: string, people: string[], owner: bool}>
	 */
	public static function entries( array $mentions, array $owner ): array {
		$out      = array();
		$spelling = array();
		$anon     = 0;
		foreach ( $mentions as $mention ) {
			$name = trim( (string) ( $mention['dish'] ?? '' ) );
			$key  = Popular_Dishes::key( $name );
			if ( '' === $key || ! empty( $mention['connected'] ) ) {
				continue;
			}
			$person = strtolower( trim( (string) ( $mention['person'] ?? '' ) ) );
			$person = '' === $person ? 'anon:' . ( ++$anon ) : $person;

			$out[ $key ] = $out[ $key ] ?? array(
				'name'   => $name,
				'people' => array(),
				'owner'  => false,
			);
			$out[ $key ]['people'][ substr( md5( $person ), 0, 10 ) ] = true;
			$spelling[ $key ][ $name ]                                = ( $spelling[ $key ][ $name ] ?? 0 ) + 1;
		}
		foreach ( $spelling as $key => $names ) {
			arsort( $names );
			$out[ $key ]['name'] = (string) array_key_first( $names );
		}
		foreach ( $owner as $item ) {
			$name = trim( (string) ( $item['name'] ?? '' ) );
			$key  = Popular_Dishes::key( $name );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ]          = $out[ $key ] ?? array(
				'name'   => $name,
				'people' => array(),
			);
			$out[ $key ]['owner'] = true;
		}
		foreach ( $out as $key => $entry ) {
			$out[ $key ]['people'] = array_keys( $entry['people'] );
		}
		return $out;
	}

	/**
	 * Whether a restaurant's dish belongs on a page: the same name, or a
	 * name ending with it ("beef kelaguen" on the kelaguen page).
	 *
	 * @param string $page_key Page's key.
	 * @param string $dish_key Dish's key.
	 * @return bool
	 */
	public static function matches( string $page_key, string $dish_key ): bool {
		return $page_key === $dish_key || ( strlen( $dish_key ) > strlen( $page_key ) && str_ends_with( $dish_key, ' ' . $page_key ) );
	}

	/**
	 * Dish pages. Pure.
	 *
	 * @param array<int, array<string, array{name: string, people: string[], owner: bool}>> $places Restaurant => entries().
	 * @return array<string, array{key: string, name: string, places: int, seed: bool}> Slug => page, by name.
	 */
	public static function pages( array $places ): array {
		$served = array();
		$names  = array();
		foreach ( $places as $restaurant_id => $entries ) {
			foreach ( $entries as $key => $entry ) {
				$names[ $key ][ $entry['name'] ] = ( $names[ $key ][ $entry['name'] ] ?? 0 ) + 1;
				$words                           = explode( ' ', (string) $key );
				$count                           = count( $words );
				for ( $i = 0; $i < $count; $i++ ) {
					$served[ implode( ' ', array_slice( $words, $i ) ) ][ $restaurant_id ] = true;
				}
			}
		}

		$pages = array();
		foreach ( $served as $key => $restaurants ) {
			$key  = (string) $key;
			$seed = isset( self::SEEDS[ $key ] );
			if ( ! $seed && ( ! isset( $names[ $key ] ) || count( $restaurants ) < self::MIN_PLACES || ! self::is_dish( $key ) ) ) {
				continue;
			}
			if ( $seed ) {
				$name = self::SEEDS[ $key ];
			} else {
				arsort( $names[ $key ] );
				$name = (string) array_key_first( $names[ $key ] );
			}
			$pages[ sanitize_title( $key ) ] = array(
				'key'    => $key,
				'name'   => $name,
				'places' => count( $restaurants ),
				'seed'   => $seed,
			);
		}
		uasort(
			$pages,
			static function ( array $a, array $b ): int {
				return strcasecmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
			}
		);
		return $pages;
	}

	/**
	 * The restaurants on a page, best known first. Pure.
	 *
	 * Order: how many different visitors named the dish there (each person
	 * once, whichever of the matching dishes they named), then places
	 * where only the owner lists it, then name.
	 *
	 * @param array<int, array<string, array{name: string, people: string[], owner: bool}>> $places   Restaurant => entries().
	 * @param string                                                                        $page_key Page's key.
	 * @param array<int, string>                                                            $titles   Restaurant => name, for ties.
	 * @return array<int, array{visitors: int, owner: bool, names: string[]}> Restaurant => why it is listed.
	 */
	public static function places( array $places, string $page_key, array $titles = array() ): array {
		$out = array();
		foreach ( $places as $restaurant_id => $entries ) {
			$people = array();
			$owner  = false;
			$names  = array();
			foreach ( $entries as $key => $entry ) {
				if ( ! self::matches( $page_key, (string) $key ) ) {
					continue;
				}
				$people  = array_merge( $people, $entry['people'] );
				$owner   = $owner || ! empty( $entry['owner'] );
				$names[] = $entry['name'];
			}
			if ( $names ) {
				$out[ (int) $restaurant_id ] = array(
					'visitors' => count( array_unique( $people ) ),
					'owner'    => $owner,
					'names'    => $names,
				);
			}
		}
		uksort(
			$out,
			static function ( int $a, int $b ) use ( $out, $titles ): int {
				return array( - $out[ $a ]['visitors'], strtolower( $titles[ $a ] ?? '' ), $a )
					<=> array( - $out[ $b ]['visitors'], strtolower( $titles[ $b ] ?? '' ), $b );
			}
		);
		return $out;
	}

	/**
	 * The page a restaurant's dish links to: the page for that exact name,
	 * else the page for the longest name it ends with.
	 *
	 * @param string $dish_name Dish name as shown.
	 * @return string Slug, or ''.
	 */
	public static function page_for( string $dish_name ): string {
		$key = Popular_Dishes::key( $dish_name );
		if ( '' === $key ) {
			return '';
		}
		$pages = self::get()['pages'];
		$words = explode( ' ', $key );
		$count = count( $words );
		for ( $i = 0; $i < $count; $i++ ) {
			$slug = sanitize_title( implode( ' ', array_slice( $words, $i ) ) );
			if ( isset( $pages[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Whether a key can be a dish page on its own.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function is_dish( string $key ): bool {
		return strlen( $key ) >= 3 && 1 !== preg_match( '/^[\d ]+$/', $key ) && ! in_array( $key, self::NOT_DISHES, true );
	}

	/**
	 * Published, not permanently closed restaurants with any dish content:
	 * an approved dish photo, a review or owner quote naming dishes, or
	 * menu dishes from the owner.
	 *
	 * @return int[]
	 */
	private static function candidates(): array {
		global $wpdb;
		$highlights = Schema::table( Schema::HIGHLIGHTS );
		$ids        = array_merge(
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT p.post_parent FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s INNER JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = %s AND d.meta_value <> '' WHERE p.post_type = 'attachment' AND p.post_parent > 0",
					Photo_Submissions::STATUS,
					Photo_Submissions::APPROVED,
					Photo_Submissions::DISH
				)
			),
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT restaurant_id FROM {$highlights} WHERE type IN (%s, %s) AND status = %s AND dishes IS NOT NULL AND dishes <> ''",
					Visitor_Reviews::TYPE,
					Policy::OWNER_QUOTE,
					'approved'
				)
			),
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' AND meta_value <> 'a:0:{}'",
					Owner_Content::MENU_DISHES
				)
			)
		);
		$ids        = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Content_Types::POST_TYPE,
					'post_status'    => 'publish',
					'post__in'       => $ids,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key, background job.
						'relation' => 'OR',
						array(
							'key'     => Restaurant_Meta::STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => Restaurant_Meta::STATUS,
							'value'   => 'permanently_closed',
							'compare' => '!=',
						),
					),
				)
			)
		);
	}
}
