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
 */
final class Slot {

	public const MAX_COUNT = 6;

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

		$items = '';
		foreach ( $ads as $ad ) {
			$items .= self::item( $ad, (string) $context['page_type'] );
		}
		if ( '' === $items ) {
			return '';
		}

		Assets::enqueue();
		wp_enqueue_script( Tracking::SCRIPT );
		return sprintf(
			'<aside class="gr-sponsored gr-ads gr-ads--%1$s" aria-label="%2$s"><ul class="gr-ads__list">%3$s</ul></aside>',
			esc_attr( str_replace( '_', '-', $product ) ),
			esc_attr__( 'Sponsored', 'guam-restaurants-core' ),
			$items
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
