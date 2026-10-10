<?php
/**
 * Keeps ads apart from editorial content.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

defined( 'ABSPATH' ) || exit;

/**
 * Rules from CLAUDE.md rule 5, docs/monetization.md and
 * docs/editorial-policy.md:
 *
 * - Creative may not use editorial words ("Featured", "Top pick",
 *   "Recommended"...) or mention awards. An ad that does cannot be
 *   activated, and is never served if one slips through.
 * - Ads stay out of sitemaps (core and Yoast). The post type is not
 *   public, so it is already out of searches, feeds, REST and restaurant
 *   queries; this makes the sitemap exclusion explicit.
 * - Two exclusive ads that could land on the same page on the same day
 *   are reported to the editor (conflicts()). Serving still shows only
 *   one of them (Server::select()).
 *
 * Nothing in the ad system writes restaurant posts, their meta or terms,
 * ratings, awards or the change log, and nothing adds clauses to the
 * directory, feed or structured data queries. The tests check that output
 * of those is identical with and without running ads.
 */
final class Guard {

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_sitemaps_post_types', array( self::class, 'core_sitemap_post_types' ) );
		add_filter( 'wpseo_sitemap_exclude_post_type', array( self::class, 'yoast_exclude' ), 10, 2 );
	}

	/**
	 * Patterns that may not appear in ad creative.
	 *
	 * @return array<string, string> Regex => reason.
	 */
	public static function banned_patterns(): array {
		$patterns = array(
			'/\bfeatured\b/i'                          => __( 'Ads may not say "Featured". Paid placements are labeled "Sponsored" only.', 'guam-restaurants-core' ),
			'/\btop[\s-]+picks?\b/i'                   => __( 'Ads may not say "Top pick".', 'guam-restaurants-core' ),
			'/\b(editor\'?s?|staff)\s+(choice|pick)/i' => __( 'Ads may not claim an editor\'s or staff pick.', 'guam-restaurants-core' ),
			'/\brecommended\b/i'                       => __( 'Ads may not say "Recommended".', 'guam-restaurants-core' ),
			'/\baward/i'                               => __( 'Ads may not mention awards.', 'guam-restaurants-core' ),
		);
		/**
		 * Filters the patterns banned from ad creative.
		 *
		 * @param array<string, string> $patterns Regex => reason shown to the editor.
		 */
		return (array) apply_filters( 'guam_restaurants_ad_banned_patterns', $patterns );
	}

	/**
	 * Problems with an ad's visible text.
	 *
	 * @param array<string, mixed> $ad Ad fields.
	 * @return string[]
	 */
	public static function creative_problems( array $ad ): array {
		$text     = implode( ' ', array( (string) ( $ad['headline'] ?? '' ), (string) ( $ad['text'] ?? '' ), (string) ( $ad['image_alt'] ?? '' ) ) );
		$problems = array();
		foreach ( self::banned_patterns() as $pattern => $reason ) {
			if ( preg_match( $pattern, $text ) ) {
				$problems[] = $reason;
			}
		}
		return $problems;
	}

	/**
	 * Other active exclusive ads that could share a page and a day with this one.
	 *
	 * @param array<string, mixed> $ad Ad.
	 * @return int[]
	 */
	public static function conflicts( array $ad ): array {
		$product = (string) ( $ad['product'] ?? '' );
		if ( ! Ads::is_exclusive( $product ) || Ads::STATUS_ACTIVE !== ( $ad['status'] ?? '' ) ) {
			return array();
		}
		$out = array();
		foreach ( Ads::active( $product ) as $other ) {
			if ( (int) ( $ad['id'] ?? 0 ) === (int) $other['id'] ) {
				continue;
			}
			if ( self::dates_overlap( $ad, $other ) && self::targets_overlap( $ad, $other ) ) {
				$out[] = (int) $other['id'];
			}
		}
		return $out;
	}

	/**
	 * Whether two ads' date ranges share a day.
	 *
	 * @param array<string, mixed> $a Ad.
	 * @param array<string, mixed> $b Ad.
	 * @return bool
	 */
	public static function dates_overlap( array $a, array $b ): bool {
		$a_end = '' === (string) $a['end'] ? '9999-12-31' : (string) $a['end'];
		$b_end = '' === (string) $b['end'] ? '9999-12-31' : (string) $b['end'];
		return (string) $a['start'] <= $b_end && (string) $b['start'] <= $a_end;
	}

	/**
	 * Whether some page could match both ads' targets.
	 *
	 * @param array<string, mixed> $a Ad.
	 * @param array<string, mixed> $b Ad.
	 * @return bool
	 */
	public static function targets_overlap( array $a, array $b ): bool {
		foreach ( array_merge( array( 'page_types' ), array_keys( Ads::TARGET_TAXONOMIES ) ) as $key ) {
			$x = (array) ( $a[ $key ] ?? array() );
			$y = (array) ( $b[ $key ] ?? array() );
			if ( $x && $y && ! array_intersect( $x, $y ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Removes ads from the core sitemap.
	 *
	 * @param array<string, \WP_Post_Type> $post_types Post types.
	 * @return array<string, \WP_Post_Type>
	 */
	public static function core_sitemap_post_types( $post_types ): array {
		unset( $post_types[ Ads::POST_TYPE ] );
		return (array) $post_types;
	}

	/**
	 * Removes ads from Yoast's sitemap.
	 *
	 * @param bool   $exclude   Whether excluded.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function yoast_exclude( $exclude, $post_type ): bool {
		return Ads::POST_TYPE === $post_type ? true : (bool) $exclude;
	}
}
