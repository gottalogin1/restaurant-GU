<?php
/**
 * Prints the ads in a sponsored slot.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * The sponsored-slot block's output. Every ad carries its own visible
 * "Sponsored" label (never "Featured", docs/monetization.md), every link
 * has rel="sponsored noopener", and the slot sits in its own bordered box
 * outside the organic results (CLAUDE.md rule 5). There is no structured
 * data or rating markup in a slot. With no ad to show, nothing is printed.
 *
 * In a rotating product's slot (Ads::is_rotating) every matching ad is
 * printed, in rotation order, but only one is visible at a time:
 * assets/ads/rotation.js moves to the next every ROTATE_MS, round-robin,
 * with Pause and Next buttons. The starting ad comes from the clock, so
 * a page served from a full page cache still rotates and every sponsor
 * gets the same share of time; nothing is stored in the browser. Without
 * JavaScript the first ad shows. Hidden ads are counted only once they
 * are shown (beacon.js counts what is on screen).
 */
final class Slot {

	public const MAX_COUNT = 6;
	public const ROTATE_MS = 8000;
	public const ROTATION  = 'guam-restaurants-ads-rotation';

	/**
	 * Slot markup.
	 *
	 * @param string                    $product Product key.
	 * @param int                       $count   Most ads.
	 * @param array<string, mixed>|null $context Page context; defaults to the current page.
	 * @return string
	 */
	public static function render( string $product, int $count = 1, ?array $context = null ): string {
		$product = sanitize_key( $product );
		$count   = max( 1, min( self::MAX_COUNT, $count ) );
		$context = $context ?? Context::current();
		$ads     = Server::select( $product, $count, $context );
		if ( ! $ads ) {
			return '';
		}

		$rotating = Ads::is_rotating( $product );
		$items    = array();
		foreach ( $ads as $ad ) {
			$item = self::item( $ad, (string) $context['page_type'] );
			if ( '' !== $item ) {
				$items[] = $rotating && $items ? str_replace( '<li class="gr-ad" ', '<li class="gr-ad" hidden ', $item ) : $item;
			}
		}
		if ( ! $items ) {
			return '';
		}

		Assets::enqueue();
		wp_enqueue_script( Tracking::SCRIPT );
		$rotate = '';
		if ( $rotating && count( $items ) > 1 ) {
			wp_enqueue_script( self::ROTATION );
			$rotate = sprintf( ' data-gr-rotate="%d"', self::ROTATE_MS );
		}
		return sprintf(
			'<aside class="gr-sponsored gr-ads gr-ads--%1$s%2$s" aria-label="%3$s"%4$s><ul class="gr-ads__list">%5$s</ul></aside>',
			esc_attr( str_replace( '_', '-', $product ) ),
			$rotating ? ' gr-ads--rotating' : '',
			esc_attr__( 'Sponsored', 'guam-restaurants-core' ),
			$rotate,
			implode( '', $items )
		);
	}

	/**
	 * One ad.
	 *
	 * @param array<string, mixed> $ad        Ad.
	 * @param string               $page_type Page type, sent with the beacon.
	 * @return string
	 */
	public static function item( array $ad, string $page_type ): string {
		$restaurant = (int) $ad['restaurant_id'];
		if ( $restaurant && ( Content_Types::POST_TYPE !== get_post_type( $restaurant ) || 'publish' !== get_post_status( $restaurant ) ) ) {
			$restaurant = 0;
		}
		$link     = '' !== $ad['link'] ? (string) $ad['link'] : ( $restaurant ? (string) get_permalink( $restaurant ) : '' );
		$headline = '' !== $ad['headline'] ? (string) $ad['headline'] : ( $restaurant ? get_the_title( $restaurant ) : '' );
		if ( '' === $link ) {
			return '';
		}
		$open = sprintf( '<a href="%s" rel="sponsored noopener">', esc_url( $link ) );

		$body = '';
		if ( Ads::CREATIVE_IMAGE === $ad['creative_type'] ) {
			$image = $ad['image_id'] ? wp_get_attachment_image(
				(int) $ad['image_id'],
				'large',
				false,
				array(
					'alt'      => '' !== $ad['image_alt'] ? $ad['image_alt'] : $headline,
					'loading'  => 'lazy',
					'decoding' => 'async',
					'class'    => 'gr-ad__image',
				)
			) : '';
			if ( '' === $image ) {
				return '';
			}
			$body .= '<div class="gr-ad__media">' . $open . $image . '</a></div>';
		}
		if ( '' !== $headline ) {
			$body .= '<p class="gr-ad__headline">' . $open . esc_html( $headline ) . '</a></p>';
		}
		if ( '' !== $ad['text'] ) {
			$body .= '<p class="gr-ad__text">' . esc_html( (string) $ad['text'] ) . '</p>';
		}

		return sprintf(
			'<li class="gr-ad" data-gr-ad="%1$d" data-gr-ad-page="%2$s"><p class="gr-sponsored__label">%3$s</p>%4$s</li>',
			(int) $ad['id'],
			esc_attr( $page_type ),
			esc_html__( 'Sponsored', 'guam-restaurants-core' ),
			$body
		);
	}
}
