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
 * - map, what to eat (the random place picker), recently opened and
 *   closed: published, made of this plugin's blocks.
 * - saved: published (VERSION 5). The visitor's saved restaurants with a
 *   map, a share link and sign-in or account options (Saved\View). Also
 *   the account page for visitor accounts, and the page Meta's app
 *   settings link to for deleting an account.
 * - claim: published, with the claim form block (Claims\Claim_Form). A
 *   claim page still holding the old editor placeholder is upgraded and
 *   published once (VERSION 3).
 * - contact: a draft. It needs a form added in the editor; links to it
 *   appear on restaurant pages only once it is published.
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
	public const VERSION = '5';

	/**
	 * Placeholder the claim page shipped with before the claim form existed.
	 */
	public const OLD_CLAIM_PLACEHOLDER = '[Editor: add the claim form here, then publish this page.]';

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
			'picker'     => array(
				'slug'    => 'what-to-eat',
				'title'   => __( 'What should I eat?', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => $p( __( 'Can\'t decide? Choose a village, food type, price or anything else that matters, and we pick a restaurant at random from the places that match. Every match has the same chance. Ads appear only in the box marked Sponsored, above the pick.', 'guam-restaurants-core' ) )
					. "\n\n" . '<!-- wp:guam-restaurants/random-picker /-->',
				'excerpt' => __( 'Can\'t decide where to eat on Guam? Pick a village, food type and price, and get a random restaurant that matches.', 'guam-restaurants-core' ),
			),
			'saved'      => array(
				'slug'    => 'saved',
				'title'   => __( 'Saved', 'guam-restaurants-core' ),
				'status'  => 'publish',
				'content' => $p( __( 'Restaurants you saved with the heart. Without an account they stay in this browser; sign in to keep them on every device. To delete your account and list, sign in and use Delete my account below.', 'guam-restaurants-core' ) )
					. "\n\n" . '<!-- wp:guam-restaurants/saved-list {"align":"wide"} /-->',
				'excerpt' => __( 'Your saved restaurants on Guam, with a map and a link to share them.', 'guam-restaurants-core' ),
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
				'status'  => 'publish',
				'content' => self::claim_content(),
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
		self::upgrade_claim_page();
		self::ensure();
	}

	/**
	 * Claim page content: who it is for, the steps, and the form.
	 *
	 * @return string
	 */
	public static function claim_content(): string {
		$p = static function ( string $text ): string {
			return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
		};
		return '<!-- wp:guam-restaurants/listing-context /-->' . "\n\n"
			. $p( __( 'Own or manage this restaurant? Claim the listing to keep its hours, menu links, photos and specials up to date, see how many people view it and click through, and reply to reviews.', 'guam-restaurants-core' ) ) . "\n\n"
			. $p( __( 'We send a code to an email address at the restaurant\'s own website domain to confirm you work there, then a person reviews the claim. Claiming is free. Owners cannot change ratings, awards or reviews.', 'guam-restaurants-core' ) ) . "\n\n"
			. '<!-- wp:guam-restaurants/claim-form /-->';
	}

	/**
	 * Puts the claim form on a claim page that still has the old editor
	 * placeholder, and publishes it. A page someone edited is left alone.
	 *
	 * @return void
	 */
	public static function upgrade_claim_page(): void {
		$page = get_page_by_path( self::definitions()['claim']['slug'], OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post || 'draft' !== $page->post_status || false === strpos( $page->post_content, self::OLD_CLAIM_PLACEHOLDER ) ) {
			return;
		}
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => self::claim_content(),
				'post_status'  => 'publish',
			)
		);
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
