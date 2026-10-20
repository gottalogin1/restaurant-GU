<?php
/**
 * Privacy policy, terms, advertising disclosure, data sources and the
 * editorial statement.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Legal;

use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Photos\Agreement;
use GuamRestaurants\Core\Site\Pages as Site_Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Puts the policy texts from data/legal/ into the site's pages once per
 * VERSION (docs/legal-pages.md). Pages someone has edited are never
 * overwritten.
 *
 * - privacy: WordPress's own privacy page (Settings > Privacy). Filled only
 *   while it is a draft nobody has edited (or created as a draft when the
 *   site has none). Stays a draft: counsel fills the bracketed parts and
 *   publishes it.
 * - terms: the Site\Pages terms draft, filled while it still holds the
 *   placeholder. Stays a draft for counsel.
 * - disclosure (/advertising-disclosure/), sources (/data-sources/) and
 *   photos (/photo-upload-agreement/): published. They describe what the site already does.
 * - editorial policy: the editorial statement, corrections and sponsored
 *   feature sections are added above the highlights rules, once.
 *
 * The cookie notice is a section of the privacy policy. The site sets no
 * analytics or advertising cookies, so it needs no consent banner.
 */
final class Pages {

	public const OPTION  = 'guam_restaurants_legal_pages';
	public const VERSION = '2';

	public const STATEMENT_MARKER = 'Our editorial statement';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'maybe_ensure' ), 30 );
	}

	/**
	 * Published pages created here, keyed by role.
	 *
	 * @return array<string, array{slug: string, title: string, file: string, excerpt: string}>
	 */
	public static function definitions(): array {
		return array(
			'disclosure' => array(
				'slug'    => 'advertising-disclosure',
				'title'   => __( 'Advertising disclosure', 'guam-restaurants-core' ),
				'file'    => 'advertising-disclosure.md',
				'excerpt' => __( 'How the directory makes money, how sponsored placements are labeled, and what advertising can never buy.', 'guam-restaurants-core' ),
			),
			'sources'    => array(
				'slug'    => 'data-sources',
				'title'   => __( 'Data sources and credits', 'guam-restaurants-core' ),
				'file'    => 'data-attributions.md',
				'excerpt' => __( 'Where the directory\'s restaurant details, maps, ratings and award results come from, and their licences.', 'guam-restaurants-core' ),
			),
			'photos'     => array(
				'slug'    => Agreement::SLUG,
				'title'   => __( 'Photo upload agreement', 'guam-restaurants-core' ),
				'file'    => 'photo-upload-agreement.md',
				'excerpt' => __( 'What you confirm and agree to when you upload a photo, including the transfer of the photo to us.', 'guam-restaurants-core' ),
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
	 * Does every step and records what happened.
	 *
	 * @return array<string, string> Role => what was done.
	 */
	public static function ensure(): array {
		$done = array(
			'privacy'   => self::fill_privacy(),
			'terms'     => self::fill_terms(),
			'editorial' => self::add_editorial_statement(),
		);
		$ids  = array();
		foreach ( self::definitions() as $role => $page ) {
			$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( $existing instanceof \WP_Post && 'trash' !== $existing->post_status ) {
				$ids[ $role ]  = (int) $existing->ID;
				$done[ $role ] = 'kept';
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_name'    => $page['slug'],
					'post_title'   => $page['title'],
					'post_content' => self::content( $page['file'] ),
					'post_excerpt' => $page['excerpt'],
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				$ids[ $role ]  = (int) $id;
				$done[ $role ] = 'created';
			}
		}
		update_option(
			self::OPTION,
			array(
				'version' => self::VERSION,
				'ids'     => $ids,
			)
		);
		return $done;
	}

	/**
	 * Block markup for a policy file, with this site's names and links.
	 *
	 * @param string $file File in data/legal/.
	 * @return string
	 */
	public static function content( string $file ): string {
		$path = GUAM_RESTAURANTS_CORE_DIR . 'data/legal/' . basename( $file );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$text = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		return Markdown::to_blocks( $text, self::vars() );
	}

	/**
	 * Placeholder values.
	 *
	 * @return array<string, string>
	 */
	public static function vars(): array {
		$page    = static function ( string $slug ): string {
			$post = get_page_by_path( $slug, OBJECT, 'page' );
			return $post instanceof \WP_Post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : home_url( '/' . $slug . '/' );
		};
		$privacy = get_privacy_policy_url();
		return array(
			'site'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'home'           => home_url( '/' ),
			'date'           => wp_date( 'F j, Y' ),
			'privacy_url'    => '' !== $privacy ? $privacy : home_url( '/privacy-policy/' ),
			'terms_url'      => $page( 'terms' ),
			'editorial_url'  => Policy_Page::url(),
			'correction_url' => $page( 'suggest-a-correction' ),
			'advertise_url'  => $page( 'advertise' ),
			'disclosure_url' => $page( 'advertising-disclosure' ),
			'contact_url'    => $page( 'contact-the-owner' ),
		);
	}

	/**
	 * URL of a published legal page by role (disclosure, sources), or ''.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	public static function url( string $role ): string {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $role ] ) ) {
			return '';
		}
		$page = get_page_by_path( $definitions[ $role ]['slug'], OBJECT, 'page' );
		return $page instanceof \WP_Post && 'publish' === $page->post_status ? (string) get_permalink( $page ) : '';
	}

	/**
	 * Fills WordPress's privacy page while it is an unedited draft, or
	 * creates one.
	 *
	 * @return string What was done.
	 */
	public static function fill_privacy(): string {
		$id   = (int) get_option( 'wp_page_for_privacy_policy' );
		$page = $id ? get_post( $id ) : null;
		if ( ! $page instanceof \WP_Post || 'trash' === $page->post_status ) {
			$new = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'draft',
					'post_name'    => 'privacy-policy',
					'post_title'   => __( 'Privacy policy', 'guam-restaurants-core' ),
					'post_content' => self::content( 'privacy-policy.md' ),
				),
				true
			);
			if ( is_wp_error( $new ) ) {
				return 'failed';
			}
			update_option( 'wp_page_for_privacy_policy', (int) $new );
			return 'created draft';
		}
		if ( 'draft' !== $page->post_status || $page->post_modified_gmt !== $page->post_date_gmt ) {
			return 'kept (published or edited)';
		}
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => self::content( 'privacy-policy.md' ),
			)
		);
		return 'filled draft';
	}

	/**
	 * Fills the terms draft while it still holds the Site\Pages placeholder.
	 *
	 * @return string What was done.
	 */
	public static function fill_terms(): string {
		$page = get_page_by_path( Site_Pages::definitions()['terms']['slug'], OBJECT, 'page' );
		if ( ! $page instanceof \WP_Post ) {
			return 'missing';
		}
		if ( 'draft' !== $page->post_status || false === strpos( $page->post_content, '[Draft: write the terms of use with counsel' ) ) {
			return 'kept (published or edited)';
		}
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => self::content( 'terms.md' ),
			)
		);
		return 'filled draft';
	}

	/**
	 * Adds the editorial statement and the advertising, corrections and
	 * sponsored feature sections above the highlights rules, once.
	 *
	 * @return string What was done.
	 */
	public static function add_editorial_statement(): string {
		$page  = get_page_by_path( Policy_Page::SLUG, OBJECT, 'page' );
		$block = '<!-- wp:shortcode -->[' . Policy_Page::SHORTCODE . ']<!-- /wp:shortcode -->';
		if ( ! $page instanceof \WP_Post ) {
			return 'missing';
		}
		if ( false !== strpos( $page->post_content, self::STATEMENT_MARKER ) ) {
			return 'kept (already there)';
		}
		$position = strpos( $page->post_content, $block );
		if ( false === $position ) {
			return 'kept (shortcode block not found; add the text by hand)';
		}
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => substr( $page->post_content, 0, $position ) . self::content( 'editorial-policy.md' ) . "\n\n" . substr( $page->post_content, $position ),
			)
		);
		return 'added statement';
	}
}
