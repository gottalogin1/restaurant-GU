<?php
/**
 * Saved English text that the dictionary translates.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Highlights\Policy_Page;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the English text on the site that Dictionary looks up, so new
 * text can be translated and added to data/translations/ (`wp
 * guam-restaurants translations missing`): page and post titles, text
 * blocks in pages, posts and restaurant descriptions, block headings and
 * labels in the theme's templates, cuisine names, price level descriptions
 * and the tagline. Pages that stay in English (english_only_ids()) are
 * left out.
 */
final class Source_Text {

	/**
	 * Post types whose titles and text blocks are translated.
	 */
	private const POST_TYPES = array( 'page', 'post', Content_Types::POST_TYPE );

	/**
	 * Every piece of text, keyed and deduplicated as the dictionary writes it.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$texts = array( (string) get_option( 'blogdescription' ) );

		foreach ( self::theme_files() as $file ) {
			$texts = array_merge( $texts, self::from_blocks( parse_blocks( (string) file_get_contents( $file ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local theme file.
		}

		$english = self::english_only_ids();
		$posts   = get_posts(
			array(
				'post_type'      => array_merge( self::POST_TYPES, array( 'wp_template', 'wp_template_part' ) ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		foreach ( $posts as $post ) {
			if ( in_array( $post->ID, $english, true ) ) {
				continue;
			}
			if ( in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
				$texts[] = $post->post_title;
			}
			$texts = array_merge( $texts, self::from_blocks( parse_blocks( $post->post_content ) ) );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => array( Content_Types::TAX_CUISINE, Content_Types::TAX_PRICE ),
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$texts[] = Content_Types::TAX_CUISINE === $term->taxonomy ? $term->name : $term->description;
		}

		$keys = array_filter( array_map( array( Dictionary::class, 'key' ), $texts ) );
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Text with no translation in a language yet.
	 *
	 * @param string $code ja or ko.
	 * @return string[]
	 */
	public static function missing( string $code ): array {
		$texts    = self::all();
		$previous = Languages::current();
		Languages::set( $code );
		unload_textdomain( 'guam-restaurants-core' );
		load_textdomain( 'guam-restaurants-core', GUAM_RESTAURANTS_CORE_DIR . 'languages/guam-restaurants-core-' . Languages::locale() . '.mo' );

		$missing = array();
		foreach ( $texts as $text ) {
			if ( null === Dictionary::lookup( $text ) ) {
				$missing[] = $text;
			}
		}

		unload_textdomain( 'guam-restaurants-core' );
		Languages::set( $previous );
		return $missing;
	}

	/**
	 * Text in blocks: text blocks and text settings, inner blocks included.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return string[]
	 */
	public static function from_blocks( array $blocks ): array {
		$texts = array();
		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( in_array( $name, Dictionary::TEXT_BLOCKS, true ) && preg_match( Dictionary::ELEMENT, (string) ( $block['innerHTML'] ?? '' ), $match ) ) {
				$texts[] = wp_strip_all_tags( $match[3] ) === $match[3] ? html_entity_decode( $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $match[3];
			}
			if ( 0 === strpos( $name, 'guam-restaurants/' ) || in_array( $name, array( 'core/navigation-link', 'core/search' ), true ) ) {
				foreach ( Dictionary::TEXT_ATTRIBUTES as $attribute ) {
					if ( is_string( $block['attrs'][ $attribute ] ?? null ) ) {
						$texts[] = $block['attrs'][ $attribute ];
					}
				}
			}
			$texts = array_merge( $texts, self::from_blocks( (array) ( $block['innerBlocks'] ?? array() ) ) );
		}
		return $texts;
	}

	/**
	 * The theme's templates and template parts.
	 *
	 * @return string[]
	 */
	private static function theme_files(): array {
		$dir = get_stylesheet_directory();
		return array_merge( (array) glob( $dir . '/templates/*.html' ), (array) glob( $dir . '/parts/*.html' ) );
	}

	/**
	 * Pages that stay in English: the legal pages, the editorial policy and
	 * the pages for advertisers. Their /ja/ and /ko/ addresses go to the
	 * English page (Router). Worked out again whenever posts change.
	 *
	 * @return int[]
	 */
	public static function english_only_ids(): array {
		static $ids = array();
		$stamp      = wp_cache_get_last_changed( 'posts' );
		if ( ! isset( $ids[ $stamp ] ) ) {
			$list = array( (int) get_option( 'wp_page_for_privacy_policy' ) );
			foreach ( array_keys( Legal_Pages::definitions() ) as $role ) {
				$list[] = url_to_postid( Legal_Pages::url( $role ) );
			}
			foreach ( array( 'terms', Policy_Page::SLUG, 'advertise', 'media-kit' ) as $slug ) {
				$page   = get_page_by_path( $slug, OBJECT, 'page' );
				$list[] = $page instanceof \WP_Post ? $page->ID : 0;
			}
			$ids = array( $stamp => array_values( array_unique( array_filter( array_map( 'intval', $list ) ) ) ) );
		}
		return $ids[ $stamp ];
	}
}
