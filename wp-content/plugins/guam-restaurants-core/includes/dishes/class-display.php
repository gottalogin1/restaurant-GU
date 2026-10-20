<?php
/**
 * Popular dishes block, with its sponsored spot.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Dishes;

use GuamRestaurants\Core\Ads\Context as Ad_Context;
use GuamRestaurants\Core\Ads\Slot;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Block `guam-restaurants/popular-dishes` on restaurant pages.
 *
 * Before the list sits one sponsored spot (ad product `dish_sponsor`,
 * Sanjay 2026-10-20): a box labeled "Sponsored" in which the running
 * sponsors that target this page take turns (Ads\Slot, rotating). It is
 * printed above the section heading so it never reads as one of the
 * dishes, and the list below is the same with or without it. With no
 * sponsor running it invites businesses to advertise, except on a
 * restaurant with a premium profile, which shows no other restaurant's
 * ads (Ads\Server::matches).
 */
final class Display {

	public const BLOCK_FOLDER = 'blocks/popular-dishes';
	public const PRODUCT      = 'dish_sponsor';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Registers the block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type( GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER, array( 'render_callback' => array( self::class, 'render_block' ) ) );
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes (unused).
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function render_block( $attributes, $content = '', $block = null ): string {
		unset( $attributes, $content );
		$id = $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_queried_object_id();
		return self::render( $id );
	}

	/**
	 * The section.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function render( int $restaurant_id ): string {
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || 'publish' !== get_post_status( $restaurant_id ) ) {
			return '';
		}
		Assets::enqueue();
		$dishes = Popular_Dishes::for_restaurant( $restaurant_id );

		$items = '';
		foreach ( $dishes as $dish ) {
			$items .= self::item( $dish );
		}
		$list = '' !== $items
			? '<ul class="gr-dishes__list">' . $items . '</ul>'
			: '<p class="gr-dishes__empty">' . esc_html__( 'No dishes yet. Tell others what you ordered: add a photo of a dish, or name the dishes you tried when you write a review.', 'guam-restaurants-core' ) . '</p>';

		return sprintf(
			'<section class="gr-dishes" id="gr-dishes">%1$s<h2 class="gr-section-title gr-dishes__title">%2$s</h2><p class="gr-dishes__intro">%3$s</p>%4$s<p class="gr-dishes__add"><a href="#gr-photos">%5$s</a> · <a href="#gr-visitor-reviews">%6$s</a></p></section>',
			self::sponsor( $restaurant_id ),
			esc_html__( 'Popular dishes', 'guam-restaurants-core' ),
			esc_html__( 'Ordered by how many visitors photographed each dish or named it in an approved review. Dishes the owner listed come next. Owners and staff cannot rate dishes, and sponsors cannot change this list.', 'guam-restaurants-core' ),
			$list,
			esc_html__( 'Add a dish photo', 'guam-restaurants-core' ),
			esc_html__( 'Write a review', 'guam-restaurants-core' )
		);
	}

	/**
	 * One dish.
	 *
	 * @param array<string, mixed> $dish From Popular_Dishes::rank().
	 * @return string
	 */
	private static function item( array $dish ): string {
		$image = $dish['photo'] ? wp_get_attachment_image(
			(int) $dish['photo'],
			'medium',
			false,
			array(
				'loading' => 'lazy',
				'alt'     => (string) $dish['name'],
			)
		) : '';
		$media = '' !== $image
			? '<a class="gr-dish__media" href="' . esc_url( (string) wp_get_attachment_image_url( (int) $dish['photo'], 'large' ) ) . '">' . $image . '</a>'
			: '';

		$counts = array();
		if ( $dish['visitors'] > 0 ) {
			/* translators: %s: number of visitors. */
			$counts[] = sprintf( _n( '%s visitor', '%s visitors', $dish['visitors'], 'guam-restaurants-core' ), number_format_i18n( $dish['visitors'] ) );
		}
		if ( $dish['photos'] > 0 ) {
			/* translators: %s: number of photos. */
			$counts[] = sprintf( _n( '%s photo', '%s photos', $dish['photos'], 'guam-restaurants-core' ), number_format_i18n( $dish['photos'] ) );
		}
		if ( $dish['reviews'] > 0 ) {
			/* translators: %s: number of reviews. */
			$counts[] = sprintf( _n( '%s review', '%s reviews', $dish['reviews'], 'guam-restaurants-core' ), number_format_i18n( $dish['reviews'] ) );
		}

		$rating = '';
		if ( null !== $dish['average'] ) {
			$rating = sprintf(
				'<p class="gr-dish__rating"><span aria-hidden="true">★</span> <span class="screen-reader-text">%1$s</span>%2$s</p>',
				esc_html__( 'Visitors\' average dish rating:', 'guam-restaurants-core' ),
				esc_html(
					sprintf(
						/* translators: 1: average stars, e.g. 4.5, 2: number of ratings. */
						_n( '%1$s/5 · %2$s rating', '%1$s/5 · %2$s ratings', $dish['ratings'], 'guam-restaurants-core' ),
						number_format_i18n( (float) $dish['average'], 1 ),
						number_format_i18n( $dish['ratings'] )
					)
				)
			);
		}

		$menu = '';
		if ( $dish['owner'] ) {
			$menu = '<p class="gr-dish__menu"><span class="gr-dish__tag">' . esc_html__( 'On the menu', 'guam-restaurants-core' ) . '</span>'
				. ( '' !== $dish['price'] ? ' <span class="gr-dish__price">' . esc_html( (string) $dish['price'] ) . '</span>' : '' ) . '</p>'
				. ( '' !== $dish['details'] ? '<p class="gr-dish__details">' . esc_html( (string) $dish['details'] ) . '</p>' : '' );
		}

		return sprintf(
			'<li class="gr-dish">%1$s<div class="gr-dish__body"><h3 class="gr-dish__name">%2$s</h3>%3$s%4$s%5$s</div></li>',
			$media,
			esc_html( (string) $dish['name'] ),
			$counts ? '<p class="gr-dish__counts">' . esc_html( implode( ' · ', $counts ) ) . '</p>' : '',
			$rating,
			$menu
		);
	}

	/**
	 * The sponsored spot before the list, or an invitation to advertise.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function sponsor( int $restaurant_id ): string {
		$ad = Slot::render( self::PRODUCT, Slot::MAX_COUNT, Ad_Context::for_restaurant( $restaurant_id ) );
		if ( '' !== $ad ) {
			return '<div class="gr-dishes__sponsor">' . $ad . '</div>';
		}
		$advertise = Pages::url( 'advertise' );
		if ( '' === $advertise || Premium::is_active( $restaurant_id ) ) {
			return '';
		}
		return sprintf(
			'<div class="gr-dishes__sponsor"><aside class="gr-sponsored gr-dishes__open-slot" aria-label="%1$s"><p class="gr-sponsored__label">%2$s</p><p class="gr-ad__headline"><a href="%3$s">%4$s</a></p><p class="gr-ad__text">%5$s</p></aside></div>',
			esc_attr__( 'Advertising space', 'guam-restaurants-core' ),
			esc_html__( 'Advertise here', 'guam-restaurants-core' ),
			esc_url( $advertise ),
			esc_html__( 'Your restaurant could be here, before Popular dishes', 'guam-restaurants-core' ),
			esc_html__( 'People read this section when they are choosing what to eat. Sponsors take turns in this labeled spot on restaurant pages, chosen by village, mall or food type.', 'guam-restaurants-core' )
		);
	}
}
