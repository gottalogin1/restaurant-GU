<?php
/**
 * The labeled sponsored slot.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

defined( 'ABSPATH' ) || exit;

/**
 * Prints an ad from the ad plugin (Advanced Ads, docs/plugins.md) inside a
 * box labeled "Sponsored" (CLAUDE.md rule 5). The slot is a placement
 * only: it never reads or changes ratings, awards or listing order. With
 * no ad to show, nothing is printed.
 *
 * Turn off Advanced Ads' own ad label for this placement so the label is
 * not shown twice.
 */
final class Sponsored {

	/**
	 * The slot's markup.
	 *
	 * @param string $placement Advanced Ads placement slug.
	 * @return string
	 */
	public static function render( string $placement ): string {
		$placement = sanitize_key( $placement );
		$ad        = '';
		if ( '' !== $placement && shortcode_exists( 'the_ad_placement' ) ) {
			$ad = do_shortcode( '[the_ad_placement id="' . $placement . '"]' );
		}
		/**
		 * Filters the ad shown in a sponsored slot.
		 *
		 * @param string $ad        Ad markup ('' for none).
		 * @param string $placement Placement slug.
		 */
		$ad = (string) apply_filters( 'guam_restaurants_sponsored_slot', $ad, $placement );
		if ( '' === trim( wp_strip_all_tags( $ad, true ) ) && false === stripos( $ad, '<img' ) && false === stripos( $ad, '<iframe' ) ) {
			return '';
		}
		Assets::enqueue();
		return sprintf(
			'<aside class="gr-sponsored" aria-label="%1$s"><p class="gr-sponsored__label">%2$s</p><div class="gr-sponsored__body">%3$s</div></aside>',
			esc_attr__( 'Sponsored', 'guam-restaurants-core' ),
			esc_html__( 'Sponsored', 'guam-restaurants-core' ),
			$ad
		);
	}
}
