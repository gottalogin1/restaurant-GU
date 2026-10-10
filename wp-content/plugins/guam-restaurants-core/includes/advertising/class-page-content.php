<?php
/**
 * Starting content for the advertise and media kit pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Advertising;

use GuamRestaurants\Core\Highlights\Policy_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Block markup that Site\Pages puts in the advertise and media kit pages
 * when it creates them. After that the content lives in the pages and is
 * edited in the block editor, like the site's other fixed pages.
 *
 * Products and prices come from docs/monetization.md, and the media kit
 * follows docs/media-kit-outline.md: audience figures say "not yet
 * published" until analytics have 90 days of data, the homepage leaderboard
 * is left out until the traffic threshold, and the rate card's "Featured
 * listing" is shown as "Sponsored listing". Both pages carry the editorial
 * statement or a link to the editorial policy.
 */
final class Page_Content {

	/**
	 * The advertise page: how advertising works, what can be bought, and the form.
	 *
	 * @return string Block markup.
	 */
	public static function advertise(): string {
		$site = self::site_name();
		return implode(
			"\n\n",
			array(
				self::paragraph(
					esc_html(
						sprintf(
							/* translators: %s: site name. */
							__( 'People use %s to decide where to eat on Guam, by village, mall and cuisine. Advertising puts your restaurant or business in front of them, in clearly labeled places.', 'guam-restaurants-core' ),
							$site
						)
					)
				),
				self::heading( __( 'How advertising works here', 'guam-restaurants-core' ) ),
				self::items(
					array(
						esc_html__( 'Every paid placement is labeled "Sponsored" and sits apart from the regular results.', 'guam-restaurants-core' ),
						esc_html__( 'Advertising never changes ratings, awards, rankings, reviews, or which restaurants we list. A restaurant that advertises keeps its normal place in the results, with no boost.', 'guam-restaurants-core' ),
						esc_html__( 'Claiming your listing is free, and so are corrections, owner quotes and replies to reviews.', 'guam-restaurants-core' ),
						sprintf(
							/* translators: %s: link to the editorial policy. */
							esc_html__( 'Read how we keep advertising separate in our %s.', 'guam-restaurants-core' ),
							self::link( Policy_Page::url(), __( 'editorial policy', 'guam-restaurants-core' ) )
						),
					)
				),
				self::heading( __( 'What you can buy', 'guam-restaurants-core' ) ),
				self::items(
					array(
						'<strong>' . esc_html__( 'Premium profile:', 'guam-restaurants-core' ) . '</strong> ' . esc_html__( 'a richer presentation of your restaurant\'s own page, such as extra photos and menu, ordering and reservation links, with a monthly report of views and clicks.', 'guam-restaurants-core' ),
						'<strong>' . esc_html__( 'Sponsored listing:', 'guam-restaurants-core' ) . '</strong> ' . esc_html__( 'a labeled card in the sponsored area of a village, mall or cuisine page, shared in rotation by up to three advertisers.', 'guam-restaurants-core' ),
						'<strong>' . esc_html__( 'More to come:', 'guam-restaurants-core' ) . '</strong> ' . esc_html__( 'page sponsorships, banners, deal and event posts, newsletter sponsorships and sponsored articles will open as the site grows. Ask us about them.', 'guam-restaurants-core' ),
					)
				),
				self::paragraph(
					sprintf(
						/* translators: %s: link to the media kit. */
						esc_html__( 'Rates, audience figures and ad specifications are in our %s.', 'guam-restaurants-core' ),
						self::link( self::page_url( 'media-kit' ), __( 'media kit', 'guam-restaurants-core' ) )
					)
				),
				self::heading( __( 'Ask about advertising', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'Tell us about your business and what you have in mind. We reply by email.', 'guam-restaurants-core' ) ),
				'<!-- wp:guam-restaurants/advertiser-inquiry /-->',
			)
		);
	}

	/**
	 * The media kit page.
	 *
	 * @return string Block markup.
	 */
	public static function media_kit(): string {
		$site        = self::site_name();
		$unpublished = __( 'Not yet published', 'guam-restaurants-core' );
		$now         = __( 'Now', 'guam-restaurants-core' );
		$later       = __( 'Coming soon', 'guam-restaurants-core' );

		return implode(
			"\n\n",
			array(
				self::paragraph(
					esc_html(
						sprintf(
							/* translators: 1: site name, 2: month and year. */
							__( '%1$s is the Guam restaurant directory organized by village, mall and cuisine. This media kit covers who uses the site, what you can advertise and what it costs. Updated %2$s.', 'guam-restaurants-core' ),
							$site,
							wp_date( 'F Y' )
						)
					)
				),
				self::heading( __( 'Who uses the site', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'Residents looking for a place to eat, and visitors planning meals around their hotel or tour. Every restaurant is listed by village, mall and cuisine; ratings are shown from their sources; awards are recorded with links to the published results; and sponsored placements are clearly labeled.', 'guam-restaurants-core' ) ),
				self::heading( __( 'Audience', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'We publish audience figures once our analytics have 90 days of data, and we do not estimate them before then.', 'guam-restaurants-core' ) ),
				self::table(
					array( __( 'Measure', 'guam-restaurants-core' ), __( 'Value', 'guam-restaurants-core' ) ),
					array(
						array( __( 'Monthly page views', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Monthly unique visitors', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Visitors on mobile', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Visitors in Guam and off-island', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Top visitor countries', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Top village, mall and cuisine pages', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Listed restaurants', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Claimed listings', 'guam-restaurants-core' ), $unpublished ),
						array( __( 'Newsletter subscribers and open rate', 'guam-restaurants-core' ), $unpublished ),
					)
				),
				self::heading( __( 'Ad products and rates', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'Prices are in US dollars. Every paid placement is labeled "Sponsored" and appears outside the regular order of results.', 'guam-restaurants-core' ) ),
				self::table(
					array( __( 'Product', 'guam-restaurants-core' ), __( 'What you get', 'guam-restaurants-core' ), __( 'Price', 'guam-restaurants-core' ), __( 'Term', 'guam-restaurants-core' ), __( 'Available', 'guam-restaurants-core' ) ),
					array(
						array( __( 'Premium profile', 'guam-restaurants-core' ), __( 'A richer presentation of your restaurant\'s own page and a monthly report of views and clicks.', 'guam-restaurants-core' ), __( '$39 a month or $390 a year', 'guam-restaurants-core' ), __( 'Monthly or annual', 'guam-restaurants-core' ), $now ),
						array( __( 'Sponsored listing', 'guam-restaurants-core' ), __( 'A labeled card on one village, mall or cuisine page, shared in rotation by up to three advertisers.', 'guam-restaurants-core' ), __( '$75 a month per page', 'guam-restaurants-core' ), __( 'Monthly', 'guam-restaurants-core' ), $now ),
						array( __( 'Page sponsorship', 'guam-restaurants-core' ), __( 'The only sponsor unit near the top of a village, mall or cuisine page.', 'guam-restaurants-core' ), __( '$150 a month (village or mall page), $250 a month (top cuisine page)', 'guam-restaurants-core' ), __( '6 or 12 months', 'guam-restaurants-core' ), $later ),
						array( __( 'Banner', 'guam-restaurants-core' ), __( 'A display ad, sold by impressions.', 'guam-restaurants-core' ), __( '$20 per 1,000 impressions, $100 monthly minimum', 'guam-restaurants-core' ), __( 'Monthly', 'guam-restaurants-core' ), $later ),
						array( __( 'Deal or event post', 'guam-restaurants-core' ), __( 'A short offer or event on your page and in the sponsored deals list.', 'guam-restaurants-core' ), __( '$25 for 7 days, $60 for 30 days', 'guam-restaurants-core' ), __( 'Per post', 'guam-restaurants-core' ), $later ),
						array( __( 'Newsletter sponsorship', 'guam-restaurants-core' ), __( 'One labeled sponsor block in an issue.', 'guam-restaurants-core' ), __( 'From $40 an issue, by list size', 'guam-restaurants-core' ), __( 'Per issue', 'guam-restaurants-core' ), $later ),
						array( __( 'Sponsored article', 'guam-restaurants-core' ), __( 'An article about your restaurant or event, written by our staff and labeled "Sponsored".', 'guam-restaurants-core' ), __( '$200', 'guam-restaurants-core' ), __( 'Per article', 'guam-restaurants-core' ), $later ),
					)
				),
				self::paragraph( esc_html__( 'Founding advertisers get 30 percent off premium profiles, sponsored listings, page sponsorships and banners for six months when they sign a six-month term during our launch period.', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'Hotels, resorts and tour operators pay 1.5 times these rates. A restaurant inside a hotel pays the restaurant rate for its own premium profile.', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'Awards are never part of an advertising package, and owner quotes, editor\'s notes and reviews cannot be bought.', 'guam-restaurants-core' ) ),
				self::heading( __( 'What advertisers get', 'guam-restaurants-core' ) ),
				self::items(
					array(
						esc_html__( 'A monthly report of impressions and clicks for each placement, by day.', 'guam-restaurants-core' ),
						esc_html__( 'Average click-through rates by product, published once we have 90 days of data.', 'guam-restaurants-core' ),
						esc_html__( 'No promises about ratings, rankings or reviews, because advertising never affects them.', 'guam-restaurants-core' ),
					)
				),
				self::heading( __( 'Our editorial statement', 'guam-restaurants-core' ) ),
				'<!-- wp:quote --><blockquote class="wp-block-quote">' . self::paragraph( esc_html( self::editorial_statement( $site ) ) ) . '</blockquote><!-- /wp:quote -->',
				self::paragraph(
					sprintf(
						/* translators: %s: link to the editorial policy. */
						esc_html__( 'Read the full %s.', 'guam-restaurants-core' ),
						self::link( Policy_Page::url(), __( 'editorial policy', 'guam-restaurants-core' ) )
					)
				),
				self::heading( __( 'Ad specifications', 'guam-restaurants-core' ) ),
				self::items(
					array(
						esc_html__( 'Banner sizes and file formats: confirmed in your proposal.', 'guam-restaurants-core' ),
						esc_html__( 'Sponsored listings and deal posts: one photo, a headline and a short text.', 'guam-restaurants-core' ),
						esc_html__( 'Sponsored articles: you supply facts, photos and quotes; we check the facts and write the article.', 'guam-restaurants-core' ),
						esc_html__( 'Creative rules: no awards programs\' names, logos or badges, no claims we cannot verify, and links marked as sponsored.', 'guam-restaurants-core' ),
					)
				),
				self::heading( __( 'Terms', 'guam-restaurants-core' ) ),
				self::paragraph( esc_html__( 'Payment, cancellation and refund terms are set out in your written proposal and advertising agreement.', 'guam-restaurants-core' ) ),
				self::heading( __( 'Contact', 'guam-restaurants-core' ) ),
				self::paragraph(
					sprintf(
						/* translators: %s: link to the advertise page. */
						esc_html__( 'Ask about advertising on our %s.', 'guam-restaurants-core' ),
						self::link( self::page_url( 'advertise' ), __( 'Advertise with us page', 'guam-restaurants-core' ) )
					)
				),
			)
		);
	}

	/**
	 * The editorial statement from docs/editorial-policy.md, word for word.
	 *
	 * @param string $site Site name.
	 * @return string Plain text.
	 */
	public static function editorial_statement( string $site ): string {
		return sprintf(
			/* translators: %s: site name. */
			__( 'Advertising on %s never affects ratings, awards, rankings, or which restaurants we list or remove. Star ratings come from the third-party sources shown beside them and from our users. Awards are recorded from published results, with a link to the original source. Sponsored placements are labeled \'Sponsored\' and appear outside the organic order of results. Advertisers cannot review, edit, or remove user reviews, and they cannot pay to change the facts on a listing. We accept corrections from owners and publish them when verified.', 'guam-restaurants-core' ),
			$site
		);
	}

	/**
	 * Site name as plain text.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * URL of a page by slug, or where it will be.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function page_url( string $slug ): string {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		return $page instanceof \WP_Post && 'publish' === $page->post_status ? (string) get_permalink( $page ) : home_url( '/' . $slug . '/' );
	}

	/**
	 * Link HTML.
	 *
	 * @param string $url  URL.
	 * @param string $text Text.
	 * @return string
	 */
	private static function link( string $url, string $text ): string {
		return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $text ) );
	}

	/**
	 * Paragraph block.
	 *
	 * @param string $html Escaped HTML.
	 * @return string
	 */
	private static function paragraph( string $html ): string {
		return '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
	}

	/**
	 * Heading block (h2).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function heading( string $text ): string {
		return '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->';
	}

	/**
	 * List block.
	 *
	 * @param string[] $items Escaped HTML per item.
	 * @return string
	 */
	private static function items( array $items ): string {
		$html = '';
		foreach ( $items as $item ) {
			$html .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
		}
		return '<!-- wp:list --><ul class="wp-block-list">' . $html . '</ul><!-- /wp:list -->';
	}

	/**
	 * Table block.
	 *
	 * @param string[]   $head Header cells (plain text).
	 * @param string[][] $rows Body rows (plain text).
	 * @return string
	 */
	private static function table( array $head, array $rows ): string {
		$thead = '<tr><th>' . implode( '</th><th>', array_map( 'esc_html', $head ) ) . '</th></tr>';
		$tbody = '';
		foreach ( $rows as $row ) {
			$tbody .= '<tr><td>' . implode( '</td><td>', array_map( 'esc_html', $row ) ) . '</td></tr>';
		}
		return '<!-- wp:table --><figure class="wp-block-table"><table><thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table></figure><!-- /wp:table -->';
	}
}
