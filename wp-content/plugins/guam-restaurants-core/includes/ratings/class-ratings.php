<?php
/**
 * Ratings for display.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Returns each source's current rating for a restaurant, following each
 * source's storage rule (docs/data-sources.md §9):
 *
 * - Google and Foursquare: fetched live, never stored. Each call is counted
 *   against the source's free monthly allowance; past it (and the monthly
 *   cap, $0 by default) they are left out until the next month.
 * - Yelp: read from a transient that the scheduled refresh fills, kept at
 *   most 24 hours. A missing transient schedules a refresh; the visitor's
 *   request never waits for Yelp.
 *
 * Sources are returned side by side. There is no combined score.
 */
final class Ratings {

	public const SOURCES = array( 'google', 'yelp', 'foursquare' );

	/**
	 * Sources whose terms allow a server cache (Yelp, 24 hours).
	 */
	public const CACHED_SOURCES = array( 'yelp' );

	/**
	 * Transient prefix; the key is gr_rating_{source}_{post_id}.
	 */
	public const TRANSIENT_PREFIX = 'gr_rating_';

	/**
	 * Filter for replacing the configured providers (tests use it).
	 */
	public const PROVIDERS_FILTER = 'guam_restaurants_rating_providers';

	/**
	 * Configured providers, keyed by source. A source without an API key is left out.
	 *
	 * @return array<string, Provider>
	 */
	public static function providers(): array {
		$providers = array();

		$google = Config::get( Config::GOOGLE_PLACES_KEY );
		if ( '' !== $google ) {
			$providers['google'] = new Google_Provider( $google );
		}
		$yelp = Config::get( Config::YELP_KEY );
		if ( '' !== $yelp ) {
			$providers['yelp'] = new Yelp_Provider( $yelp );
		}
		$foursquare = Config::get( Config::FOURSQUARE_KEY );
		if ( '' !== $foursquare ) {
			$providers['foursquare'] = new Foursquare_Provider( $foursquare );
		}

		/**
		 * Filters the rating providers.
		 *
		 * @param array<string, Provider> $providers Providers keyed by source.
		 */
		$providers = apply_filters( 'guam_restaurants_rating_providers', $providers );

		return array_filter(
			is_array( $providers ) ? $providers : array(),
			static function ( $provider ) {
				return $provider instanceof Provider;
			}
		);
	}

	/**
	 * Current ratings for a restaurant.
	 *
	 * Each entry has: source, label, rating (null when the source has none),
	 * rating_scale (5, or 10 for Foursquare), review_count, url (the place on
	 * the source's site), attribution {text, logo_url, providers}, stars_image
	 * (Yelp's official image), reviews [{author, author_url, author_photo,
	 * rating, text, time, url, flag_url}], reviews_notice, fetched_at and
	 * cached_until (0 when fetched live).
	 *
	 * @param int                  $restaurant_id Restaurant post ID.
	 * @param array<string, mixed> $args {
	 *     Optional.
	 *
	 *     @type string[] $sources Sources to include, in display order. Default all.
	 *     @type bool     $reviews Include review text. Default true.
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public static function get( int $restaurant_id, array $args = array() ): array {
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) ) {
			return array();
		}

		$sources   = self::sanitize_sources( $args['sources'] ?? self::SOURCES );
		$reviews   = (bool) ( $args['reviews'] ?? true );
		$providers = self::providers();
		$ratings   = array();

		foreach ( $sources as $source ) {
			if ( ! isset( $providers[ $source ] ) ) {
				continue;
			}
			$provider = $providers[ $source ];
			$id       = (string) get_post_meta( $restaurant_id, $provider->id_meta_key(), true );
			if ( '' === $id ) {
				continue;
			}

			$rating = $provider->max_cache_seconds() > 0
				? self::cached( $provider, $restaurant_id )
				: self::live( $provider, $id, $reviews );

			if ( null === $rating || ! self::displayable( $rating ) ) {
				continue;
			}
			if ( ! $reviews ) {
				$rating['reviews']        = array();
				$rating['reviews_notice'] = '';
			}
			$ratings[] = $rating;
		}

		return $ratings;
	}

	/**
	 * Sources from a list or comma-separated string, known ones only, in order.
	 *
	 * Yelp may not be shown alongside other ratings or user content (Yelp API
	 * Terms §5(c)), so a list that mixes Yelp with other sources drops Yelp.
	 *
	 * @param mixed $sources List or comma-separated string.
	 * @return string[]
	 */
	public static function sanitize_sources( $sources ): array {
		if ( is_string( $sources ) ) {
			$sources = '' === trim( $sources ) ? self::default_sources() : explode( ',', $sources );
		}
		$clean = array();
		foreach ( (array) $sources as $source ) {
			$source = sanitize_key( (string) $source );
			if ( in_array( $source, self::SOURCES, true ) && ! in_array( $source, $clean, true ) ) {
				$clean[] = $source;
			}
		}
		if ( count( $clean ) > 1 ) {
			$clean = array_values( array_diff( $clean, array( 'yelp' ) ) );
		}
		return $clean;
	}

	/**
	 * Default sources for a block or shortcode: every source except Yelp,
	 * which needs a section of its own.
	 *
	 * @return string[]
	 */
	public static function default_sources(): array {
		return array( 'google', 'foursquare' );
	}

	/**
	 * Transient key.
	 *
	 * @param string $source        Source key.
	 * @param int    $restaurant_id Restaurant ID.
	 * @return string
	 */
	public static function transient_key( string $source, int $restaurant_id ): string {
		return self::TRANSIENT_PREFIX . $source . '_' . $restaurant_id;
	}

	/**
	 * Fetches and caches a rating for the longest time the source allows.
	 * Used only by the scheduled refresh.
	 *
	 * @param Provider $provider      Provider.
	 * @param int      $restaurant_id Restaurant ID.
	 * @return bool Whether a rating was stored.
	 * @throws \RuntimeException On request failure.
	 */
	public static function refresh( Provider $provider, int $restaurant_id ): bool {
		$ttl = $provider->max_cache_seconds();
		$id  = (string) get_post_meta( $restaurant_id, $provider->id_meta_key(), true );
		if ( $ttl <= 0 || '' === $id ) {
			return false;
		}

		$rating                 = $provider->fetch( $id, true );
		$rating['cached_until'] = time() + $ttl;
		return set_transient( self::transient_key( $provider->source(), $restaurant_id ), $rating, $ttl );
	}

	/**
	 * A cached rating, or null after scheduling a refresh.
	 *
	 * @param Provider $provider      Provider.
	 * @param int      $restaurant_id Restaurant ID.
	 * @return array<string, mixed>|null
	 */
	private static function cached( Provider $provider, int $restaurant_id ): ?array {
		$key    = self::transient_key( $provider->source(), $restaurant_id );
		$rating = get_transient( $key );

		// Never serve data past the source's limit, even if the transient outlived it.
		if ( is_array( $rating ) && (int) ( $rating['cached_until'] ?? 0 ) >= time() ) {
			return $rating;
		}
		if ( false !== $rating ) {
			delete_transient( $key );
		}

		Refresher::schedule_one( $restaurant_id, $provider->source() );
		return null;
	}

	/**
	 * A live rating, or null when no call is allowed or the request fails.
	 *
	 * With reviews, Google bills at a higher SKU with its own free allowance.
	 * When that call is not allowed, the rating is fetched without reviews.
	 *
	 * @param Provider $provider Provider.
	 * @param string   $id       Source ID.
	 * @param bool     $reviews  Include reviews.
	 * @return array<string, mixed>|null
	 */
	private static function live( Provider $provider, string $id, bool $reviews ): ?array {
		foreach ( $reviews ? array( true, false ) : array( false ) as $with_reviews ) {
			if ( ! Budget::try_call_monthly( $provider->source(), $provider->fetch_sku( $with_reviews ), $provider->fetch_calls( $with_reviews ) ) ) {
				continue;
			}
			try {
				$rating                 = $provider->fetch( $id, $with_reviews );
				$rating['cached_until'] = 0;
				return $rating;
			} catch ( \Throwable $e ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Whether a rating can be shown under the source's display rules.
	 *
	 * @param array<string, mixed> $rating Rating.
	 * @return bool
	 */
	private static function displayable( array $rating ): bool {
		if ( null === $rating['rating'] || '' === (string) $rating['url'] ) {
			return false;
		}
		// Yelp ratings must use Yelp's own star images.
		return 'yelp' !== $rating['source'] || '' !== (string) $rating['stars_image'];
	}
}
