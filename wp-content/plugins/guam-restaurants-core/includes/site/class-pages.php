<?php
/**
 * Pages the public site links to.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Advertising\Page_Content;
use GuamRestaurants\Core\Corrections\Display as Correction_Display;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the site's fixed pages once (per VERSION) if they do not exist,
 * and finds them for links. Content lives in the page, so it stays editable.
 *
 * - map, recently opened and closed: published, made of this plugin's blocks.
 * - claim, contact: drafts. Each needs a form added in the editor; links
 *   to them appear on restaurant pages only once they are published.
 * - correction: published, with the plugin's correction form and our
 *   published response times (Corrections\Display).
 * - terms: a draft for the terms of use, to be written with counsel
 *   (docs/legal-and-policy.md §7). The footer links to it once published.
 * - advertise and media kit: published. The advertise page holds the
 *   advertiser inquiry form; restaurant pages link to it once published.
 *   The media kit shows the rate card (docs/monetization.md) and leaves
 *   audience figures unmeasured until analytics have 90 days of data
 *   (docs/media-kit-outline.md). Both link to the editorial policy.
 *
 * The privacy page is WordPress's own (Settings > Privacy).
 */
final class Pages {

	public const OPTION  = 'guam_restaurants_site_pages';
	public const VERSION = '2';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_ensure' ), 20 );
	}

	/**
	 * Page definitions keyed by role.
	 *
	 * @return array<string, array{slug: string, title: string, status: string, content: string|callable, excerpt?: string}> Content may be a callable, built only when the page is created. The excerpt is the page's meta description when no SEO plugin sets one.
	 */
	public static function definitions(): array {
		$p = static function ( string $text ): string {
			return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
		};
		return array(
			'map'        => array(
				'slug'    => 'map',
				'title'   => __( 'Map', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => '<!-- wp:guam-restaurants/map-explorer /-->',
				'excerpt' => __( 'Map of restaurants on Guam. Filter by village, mall, cuisine, price, awards and opening hours.', 'guam-restaurants-core' ),
			),
			'recent'     => array(
				'slug'    => 'recently-opened-and-closed',
				'title'   => __( 'Recently opened and closed', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => $p( __( 'Restaurants that opened, reopened or closed recently, as recorded in this directory.', 'guam-restaurants-core' ) )
					. "\n\n" . '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'Opened', 'guam-restaurants-core' ) . '</h2><!-- /wp:heading -->'
					. "\n\n" . '<!-- wp:guam-restaurants/recent-changes {"kind":"openings","days":180,"limit":50,"layout":"list"} /-->'
					. "\n\n" . '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'Reopened', 'guam-restaurants-core' ) . '</h2><!-- /wp:heading -->'
					. "\n\n" . '<!-- wp:guam-restaurants/recent-changes {"kind":"reopenings","days":180,"limit":50,"layout":"list"} /-->'
					. "\n\n" . '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'Closed', 'guam-restaurants-core' ) . '</h2><!-- /wp:heading -->'
					. "\n\n" . '<!-- wp:guam-restaurants/recent-changes {"kind":"closures","days":180,"limit":50,"layout":"list"} /-->',
			),
			'claim'      => array(
				'slug'    => 'claim-this-listing',
				'title'   => __( 'Claim this listing', 'guam-restaurants-core' ),
				'status'  => 'draft',
				'content' => '<!-- wp:guam-restaurants/listing-context /-->' . "\n\n" . $p( __( 'Own or manage this restaurant? Tell us who you are and how we can confirm it, and we will link the listing to your account so you can add an owner quote and reply to reviews.', 'guam-restaurants-core' ) ) . "\n\n" . $p( __( '[Editor: add the claim form here, then publish this page.]', 'guam-restaurants-core' ) ),
			),
			'correction' => array(
				'slug'    => 'suggest-a-correction',
				'title'   => __( 'Suggest a correction', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => array( Correction_Display::class, 'page_content' ),
				'excerpt' => __( 'Report a wrong fact on a restaurant listing, or ask as the owner to change or remove it. We reply within 7 to 14 days.', 'guam-restaurants-core' ),
			),
			'contact'    => array(
				'slug'    => 'contact-the-owner',
				'title'   => __( 'Contact the owner', 'guam-restaurants-core' ),
				'status'  => 'draft',
				'content' => '<!-- wp:guam-restaurants/listing-context /-->' . "\n\n" . $p( __( 'Send a message to the owner of this listing. We pass it on and do not share your email address.', 'guam-restaurants-core' ) ) . "\n\n" . $p( __( '[Editor: add the contact form here, then publish this page.]', 'guam-restaurants-core' ) ),
			),
			'terms'      => array(
				'slug'    => 'terms',
				'title'   => __( 'Terms of use', 'guam-restaurants-core' ),
				'status'  => 'draft',
				'content' => $p( __( '[Draft: write the terms of use with counsel (docs/legal-and-policy.md section 7), then publish this page.]', 'guam-restaurants-core' ) ),
			),
			'media_kit'  => array(
				'slug'    => 'media-kit',
				'title'   => __( 'Media kit', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => array( Page_Content::class, 'media_kit' ),
				'excerpt' => __( 'Audience, advertising products, rates and ad specifications for restaurants and businesses on Guam.', 'guam-restaurants-core' ),
			),
			'advertise'  => array(
				'slug'    => 'advertise',
				'title'   => __( 'Advertise with us', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => array( Page_Content::class, 'advertise' ),
				'excerpt' => __( 'Reach people choosing where to eat on Guam. Ask about premium profiles and sponsored listings.', 'guam-restaurants-core' ),
			),
		);
	}

	/**
	 * Runs ensure() once per VERSION.
	 *
	 * @return void
	 */
	public static function maybe_ensure(): void {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && self::VERSION === ( $stored['version'] ?? '' ) ) {
			return;
		}
		self::ensure();
	}

	/**
	 * Creates missing pages; existing pages (any status) are left alone.
	 *
	 * @return array<string, int> Role => page ID.
	 */
	public static function ensure(): array {
		$ids = array();
		foreach ( self::definitions() as $role => $page ) {
			$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( $existing instanceof \WP_Post && 'trash' !== $existing->post_status ) {
				$ids[ $role ] = (int) $existing->ID;
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => $page['status'],
					'post_name'    => $page['slug'],
					'post_title'   => $page['title'],
					'post_content' => is_callable( $page['content'] ) ? (string) call_user_func( $page['content'] ) : $page['content'],
					'post_excerpt' => $page['excerpt'] ?? '',
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[ $role ] = (int) $id;
			}
		}
		update_option(
			self::OPTION,
			array(
				'version' => self::VERSION,
				'ids'     => $ids,
			)
		);
		return $ids;
	}

	/**
	 * URL of a published site page, or ''.
	 *
	 * @param string $role Role from definitions().
	 * @return string
	 */
	public static function url( string $role ): string {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $role ] ) ) {
			return '';
		}
		$stored = get_option( self::OPTION );
		$id     = is_array( $stored ) ? (int) ( $stored['ids'][ $role ] ?? 0 ) : 0;
		$page   = $id ? get_post( $id ) : null;
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type ) {
			$page = get_page_by_path( $definitions[ $role ]['slug'], OBJECT, 'page' );
		}
		return $page instanceof \WP_Post && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
	}
}
