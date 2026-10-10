<?php
/**
 * The highlights and reviews section of the editorial policy page.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Reviews\Visitor_Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * `[guam_editorial_policy]` prints the rules from Policy, so the published
 * rules and the moderators' choices can never drift apart. On first run the
 * plugin makes sure the page at /editorial-policy/ (docs/legal-and-policy.md
 * §9) carries the shortcode: it creates the page if there is none, or adds
 * the shortcode to the end of an existing page that lacks it.
 */
final class Policy_Page {

	public const SHORTCODE = 'guam_editorial_policy';
	public const SLUG      = 'editorial-policy';
	public const OPTION    = 'guam_restaurants_policy_page';

	/**
	 * Bump to run ensure() again on existing sites.
	 */
	public const VERSION = '1';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'init', array( self::class, 'maybe_ensure' ), 20 );
	}

	/**
	 * Runs ensure() once per VERSION.
	 *
	 * @return void
	 */
	public static function maybe_ensure(): void {
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && self::VERSION === ( $stored['version'] ?? '' ) && 'page' === get_post_type( (int) ( $stored['id'] ?? 0 ) ) ) {
			return;
		}
		self::ensure();
	}

	/**
	 * Creates the page, or adds the shortcode to an existing one.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	public static function ensure(): int {
		$block = '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->';
		$page  = get_page_by_path( self::SLUG );

		if ( $page instanceof \WP_Post ) {
			$id = (int) $page->ID;
			if ( ! has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => rtrim( (string) $page->post_content ) . "\n\n" . $block,
					)
				);
			}
		} else {
			$id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => self::SLUG,
					'post_title'   => __( 'Editorial policy', 'guam-restaurants-core' ),
					'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'How we decide what appears on restaurant pages, and how we keep it independent of advertising.', 'guam-restaurants-core' ) . '</p><!-- /wp:paragraph -->' . "\n\n" . $block,
				)
			);
		}

		if ( $id > 0 ) {
			update_option(
				self::OPTION,
				array(
					'id'      => $id,
					'version' => self::VERSION,
				)
			);
		}
		return max( 0, $id );
	}

	/**
	 * URL of the policy page.
	 *
	 * @return string
	 */
	public static function url(): string {
		$stored = get_option( self::OPTION );
		$id     = is_array( $stored ) ? (int) ( $stored['id'] ?? 0 ) : 0;
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return home_url( '/' . self::SLUG . '/' );
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		$items = static function ( array $lines ): string {
			return '<ul>' . implode(
				'',
				array_map(
					static function ( $line ) {
						return '<li>' . esc_html( $line ) . '</li>';
					},
					$lines
				)
			) . '</ul>';
		};

		$out  = '<section class="gr-policy" id="highlights-and-reviews">';
		$out .= '<h2>' . esc_html__( 'Highlights and reviews', 'guam-restaurants-core' ) . '</h2>';
		$out .= '<p>' . esc_html__( 'Each restaurant page has a Highlights section. Every highlight is labeled with who wrote it:', 'guam-restaurants-core' ) . '</p>';
		$out .= $items(
			array(
				Policy::type_label( Policy::OWNER_QUOTE ) . ': ' . __( 'a short description and signature dishes written by the restaurant\'s owner or manager. We check that the account belongs to the restaurant before it can post.', 'guam-restaurants-core' ),
				Policy::type_label( Policy::EDITOR_NOTE ) . ': ' . __( 'a short note written by our staff in their own words. Staff do not write about restaurants they or their families are connected to, and say so if a restaurant paid for a meal or gave them anything.', 'guam-restaurants-core' ),
				__( 'User reviews', 'guam-restaurants-core' ) . ': ' . __( 'a star rating and comment from a visitor to this site, shown separately from the owner\'s and editor\'s words.', 'guam-restaurants-core' ),
				Policy::type_label( Policy::OWNER_RESPONSE ) . ': ' . __( 'an owner may post one public response to each review. Owners cannot edit, hide or delete reviews.', 'guam-restaurants-core' ),
			)
		);

		$out .= '<h3>' . esc_html__( 'Moderation', 'guam-restaurants-core' ) . '</h3>';
		$out .= '<p>' . esc_html__( 'Every highlight, review and response waits for an administrator before it appears. We publish positive and critical reviews alike. We reject something only when its content breaks one of these rules, and we apply them the same way whatever the rating:', 'guam-restaurants-core' ) . '</p>';
		$out .= $items( array_values( Policy::rules() ) );
		$out .= $items(
			array(
				__( 'We never remove or hide a review because a restaurant, advertiser or prospective advertiser asks, or because it is negative.', 'guam-restaurants-core' ),
				__( 'Highlights are free. They cannot be bought, are not part of any advertising package, and never change ratings, awards or the order of any list or search result.', 'guam-restaurants-core' ),
				__( 'We never pay for reviews or reward reviewers for what they say.', 'guam-restaurants-core' ),
				__( 'Anyone connected to a restaurant (owner, staff, relative) or to a competitor must say so in their review. People linked to a restaurant\'s account cannot review it.', 'guam-restaurants-core' ),
			)
		);

		$out .= '<h3>' . esc_html__( 'Writing a review', 'guam-restaurants-core' ) . '</h3>';
		$out .= $items(
			array(
				__( 'Log in, or give an email address. We email a link, and your review goes to a moderator once you open it. We use the address only to send that link and do not keep it.', 'guam-restaurants-core' ),
				/* translators: %d: reviews per hour. */
				sprintf( __( 'To stop spam we use a hidden form field, a minimum time to fill in the form, and a limit of %d reviews an hour and one review of each restaurant a day per visitor and per email address. These limits use scrambled (hashed) copies of your IP address and email that expire within a day; neither is stored with your review.', 'guam-restaurants-core' ), Visitor_Reviews::PER_HOUR ),
			)
		);

		$out .= '<h3>' . esc_html( Policy::badge_label() ) . '</h3>';
		$out .= '<p>' . esc_html( Policy::badge_explanation() ) . ' ' . esc_html__( 'Reviews sent by email always confirm an address; logged-in reviewers can choose to confirm their account email. The badge does not mean we checked a visit, a receipt or a purchase.', 'guam-restaurants-core' ) . '</p>';

		return $out . '</section>';
	}
}
