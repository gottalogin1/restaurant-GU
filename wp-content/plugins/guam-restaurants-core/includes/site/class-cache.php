<?php
/**
 * Object cache helper for site queries.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Caches computed lists (map points, facet lists, feeds) in the object
 * cache. Keys include WordPress's last-changed stamps for posts, post meta
 * and terms, so an edit or import makes new keys instead of serving stale
 * data; entries also expire after TTL seconds.
 *
 * Without a persistent object cache (Redis or Memcached, for example
 * through W3 Total Cache) this only saves work within one request.
 */
final class Cache {

	public const GROUP = 'guam_restaurants_site';
	public const TTL   = 300;

	/**
	 * Returns the cached value or computes and stores it.
	 *
	 * @param string   $name     Name of the list.
	 * @param mixed    $args     Anything that identifies the variant.
	 * @param callable $callback Computes the value.
	 * @param int      $ttl      Seconds.
	 * @return mixed
	 */
	public static function remember( string $name, $args, callable $callback, int $ttl = self::TTL ) {
		$key   = self::key( $name, $args );
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		if ( $found ) {
			return $value;
		}
		$value = $callback();
		wp_cache_set( $key, $value, self::GROUP, $ttl );
		return $value;
	}

	/**
	 * Cache key.
	 *
	 * @param string $name Name.
	 * @param mixed  $args Variant.
	 * @return string
	 */
	public static function key( string $name, $args ): string {
		$stamp = wp_cache_get_last_changed( 'posts' ) . ':' . wp_cache_get_last_changed( 'post-queries' ) . ':' . wp_cache_get_last_changed( 'terms' ) . ':' . wp_cache_get_last_changed( self::GROUP );
		return $name . ':' . md5( (string) wp_json_encode( $args ) . '|' . $stamp );
	}

	/**
	 * Invalidates every entry (for example after an awards import).
	 *
	 * @return void
	 */
	public static function flush(): void {
		wp_cache_set_last_changed( self::GROUP );
	}
}
