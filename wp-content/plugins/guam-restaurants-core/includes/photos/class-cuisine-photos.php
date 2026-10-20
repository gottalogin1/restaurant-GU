<?php
/**
 * Openly licensed, illustrative photos for each cuisine.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * A cuisine can have one illustrative photo (term meta), shown on a
 * restaurant that has neither a featured image nor an owner photo. The
 * photo is not of that restaurant, so it always shows the "Illustrative
 * photo" label and the credit its license asks for.
 */
final class Cuisine_Photos {

	public const TERM_PHOTO = 'gr_photo_id';
	public const CREDIT     = '_gr_photo_credit';

	/**
	 * License codes we accept. Each allows commercial use and resizing.
	 */
	public const LICENSES = array( 'cc0', 'pdm', 'by', 'by-sa' );

	/**
	 * Illustrative photo for a restaurant: the first of its cuisines that has one.
	 *
	 * @param \WP_Term[] $cuisines The restaurant's cuisines.
	 * @return array{id: int, credit: array<string, string>}|null
	 */
	public static function for_cuisines( array $cuisines ): ?array {
		foreach ( $cuisines as $cuisine ) {
			$photo = self::get( $cuisine->term_id );
			if ( null !== $photo ) {
				return $photo;
			}
		}
		return null;
	}

	/**
	 * A cuisine's photo, if it has one that still exists.
	 *
	 * @param int $term_id Cuisine term.
	 * @return array{id: int, credit: array<string, string>}|null
	 */
	public static function get( int $term_id ): ?array {
		$id = (int) get_term_meta( $term_id, self::TERM_PHOTO, true );
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		return array(
			'id'     => $id,
			'credit' => self::credit( $id ),
		);
	}

	/**
	 * Credit stored on an attachment.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array<string, string> title, creator, creator_url, license, license_version, license_url, source_url, openverse_id.
	 */
	public static function credit( int $attachment_id ): array {
		$credit = get_post_meta( $attachment_id, self::CREDIT, true );
		return array_map( 'strval', is_array( $credit ) ? $credit : array() );
	}

	/**
	 * Sets a cuisine's photo and its credit.
	 *
	 * @param int                   $term_id       Cuisine term.
	 * @param int                   $attachment_id Attachment.
	 * @param array<string, string> $credit        Credit, see credit().
	 * @return void
	 */
	public static function set( int $term_id, int $attachment_id, array $credit ): void {
		update_post_meta( $attachment_id, self::CREDIT, array_map( 'sanitize_text_field', $credit ) );
		update_term_meta( $term_id, self::TERM_PHOTO, $attachment_id );
		Cache::flush();
	}

	/**
	 * Removes a cuisine's photo. The attachment stays in the media library.
	 *
	 * @param int $term_id Cuisine term.
	 * @return bool Whether it had one.
	 */
	public static function remove( int $term_id ): bool {
		$had = (int) get_term_meta( $term_id, self::TERM_PHOTO, true ) > 0;
		delete_term_meta( $term_id, self::TERM_PHOTO );
		Cache::flush();
		return $had;
	}

	/**
	 * Short license name, for example "CC BY 2.0" or "Public domain".
	 *
	 * @param array<string, string> $credit Credit.
	 * @return string
	 */
	public static function license_label( array $credit ): string {
		$code = strtolower( $credit['license'] ?? '' );
		if ( 'pdm' === $code ) {
			return __( 'Public domain', 'guam-restaurants-core' );
		}
		if ( 'cc0' === $code ) {
			return 'CC0';
		}
		return trim( 'CC ' . strtoupper( $code ) . ' ' . ( $credit['license_version'] ?? '' ) );
	}

	/**
	 * The label and credit shown with the photo.
	 *
	 * @param array<string, string> $credit Credit.
	 * @param bool                  $full   Whether to say the photo is not from this restaurant.
	 * @return string HTML.
	 */
	public static function credit_html( array $credit, bool $full ): string {
		$parts   = array( esc_html( $full ? __( 'Illustrative photo, not from this restaurant', 'guam-restaurants-core' ) : __( 'Illustrative photo', 'guam-restaurants-core' ) ) );
		$creator = '' !== ( $credit['creator'] ?? '' ) ? $credit['creator'] : __( 'Unknown author', 'guam-restaurants-core' );
		$parts[] = '' !== ( $credit['source_url'] ?? '' )
			? sprintf( '<a href="%1$s" rel="nofollow noopener">%2$s</a>', esc_url( $credit['source_url'] ), esc_html( $creator ) )
			: esc_html( $creator );
		$license = self::license_label( $credit );
		$parts[] = '' !== ( $credit['license_url'] ?? '' )
			? sprintf( '<a href="%1$s" rel="license nofollow noopener">%2$s</a>', esc_url( $credit['license_url'] ), esc_html( $license ) )
			: esc_html( $license );
		return '<span class="gr-media__credit">' . implode( ' <span aria-hidden="true">·</span> ', $parts ) . '</span>';
	}

	/**
	 * Cuisines used by at least one restaurant.
	 *
	 * @return \WP_Term[]
	 */
	public static function used_cuisines(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_CUISINE,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		return is_array( $terms ) ? $terms : array();
	}
}
