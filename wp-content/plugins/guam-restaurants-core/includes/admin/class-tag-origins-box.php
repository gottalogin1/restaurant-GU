<?php
/**
 * "Tag sources" box on the restaurant edit screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Tagging\Tag_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Shows where each cuisine and mall tag came from (a source, a rule or a
 * manual edit), and lets an editor hand tagging back to the automatic rules.
 */
final class Tag_Origins_Box {

	private const NONCE_ACTION = 'guam_restaurants_tag_origins';
	private const NONCE_FIELD  = 'guam_restaurants_tag_origins_nonce';
	private const RESET_FIELD  = 'guam_restaurants_reset_tags';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes_' . Content_Types::POST_TYPE, array( self::class, 'add' ) );
		add_action( 'save_post_' . Content_Types::POST_TYPE, array( self::class, 'save' ), 20 );
	}

	/**
	 * Adds the box.
	 *
	 * @return void
	 */
	public static function add(): void {
		add_meta_box( 'gr-tag-origins', __( 'Tag sources', 'guam-restaurants-core' ), array( self::class, 'render' ), Content_Types::POST_TYPE, 'side', 'low' );
	}

	/**
	 * Renders the box.
	 *
	 * @param \WP_Post $post Restaurant.
	 * @return void
	 */
	public static function render( \WP_Post $post ): void {
		$labels  = array(
			'source' => __( 'from data source', 'guam-restaurants-core' ),
			'rule'   => __( 'from a rule', 'guam-restaurants-core' ),
			'manual' => __( 'set by hand', 'guam-restaurants-core' ),
		);
		$origins = Tag_Store::origins( $post->ID );
		foreach ( array(
			Content_Types::TAX_CUISINE => __( 'Cuisines', 'guam-restaurants-core' ),
			Content_Types::TAX_MALL    => __( 'Mall', 'guam-restaurants-core' ),
		) as $taxonomy => $heading ) {
			echo '<p><strong>' . esc_html( $heading ) . '</strong></p><ul style="margin-top:0">';
			if ( ! $origins[ $taxonomy ] ) {
				echo '<li>' . esc_html( Content_Types::TAX_MALL === $taxonomy && Tag_Store::NO_MALL === Tag_Store::mall_override( $post->ID ) ? __( 'Not in a mall (set by hand)', 'guam-restaurants-core' ) : __( 'None recorded', 'guam-restaurants-core' ) ) . '</li>';
			}
			foreach ( $origins[ $taxonomy ] as $slug => $origin ) {
				printf( '<li><code>%1$s</code> %2$s <span class="description">(%3$s)</span></li>', esc_html( (string) $slug ), esc_html( $labels[ $origin['origin'] ] ?? $origin['origin'] ), esc_html( (string) $origin['detail'] ) );
			}
			echo '</ul>';
		}
		$manual = Tag_Store::cuisine_manual( $post->ID );
		if ( $manual['remove'] ) {
			echo '<p class="description">' . esc_html__( 'Removed by hand (imports will not add back):', 'guam-restaurants-core' ) . ' ' . esc_html( implode( ', ', $manual['remove'] ) ) . '</p>';
		}
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<label><input type="checkbox" name="' . esc_attr( self::RESET_FIELD ) . '" value="1" /> ' . esc_html__( 'Clear hand-set tags so the next import or `wp guam-restaurants retag` sets them automatically', 'guam-restaurants-core' ) . '</label>';
	}

	/**
	 * Clears overrides when asked.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( empty( $_POST[ self::RESET_FIELD ] ) || ! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		Tag_Store::reset_overrides( $post_id );
	}
}
