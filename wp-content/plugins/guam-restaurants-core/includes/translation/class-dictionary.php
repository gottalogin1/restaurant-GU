<?php
/**
 * Translations of text that does not come from the plugin's code.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Translation;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Page titles and text blocks, cuisine names, price level descriptions, the
 * tagline, and headings and labels set in the theme's templates are saved in the database or in templates in
 * English. On translated pages they are looked up, as written, in
 * data/translations/<language>.php (written with the plugin, see
 * docs/translation.md §4), then in the plugin's translation files (pages the
 * plugin creates take their titles and text from them). Text found in
 * neither stays in English.
 *
 * Restaurant names, village and mall names and anything from other sites
 * are never looked up.
 */
final class Dictionary {

	/**
	 * Blocks whose text is looked up, as one piece each.
	 */
	public const TEXT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list-item', 'core/button', 'core/site-tagline' );

	/**
	 * A text block's HTML: 1 the opening tags, 3 the text, 4 the rest.
	 */
	public const ELEMENT = '#^(\s*(?:<div\b[^>]*>\s*)?<(p|h[1-6]|li|a)\b[^>]*>)(.*?)(</\2>.*)$#is';

	/**
	 * Block settings that hold visible text (headings and labels set in templates).
	 */
	public const TEXT_ATTRIBUTES = array( 'heading', 'label', 'buttonText', 'placeholder' );

	/**
	 * Loaded dictionaries, by language.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $loaded = array();

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'the_title', array( self::class, 'post_title' ), 10, 2 );
		add_filter( 'single_post_title', array( self::class, 'post_title' ), 10, 2 );
		add_filter( 'render_block', array( self::class, 'render_block' ), 10, 2 );
		add_filter( 'render_block_data', array( self::class, 'block_data' ) );
		add_filter( 'get_' . Content_Types::TAX_CUISINE, array( self::class, 'term' ) );
		add_filter( 'get_' . Content_Types::TAX_PRICE, array( self::class, 'term' ) );
		add_filter( 'bloginfo', array( self::class, 'bloginfo' ), 10, 2 );
		add_filter( 'wp_sprintf_l', array( self::class, 'list_separators' ) );
	}

	/**
	 * The translation of a piece of English text in this page's language, or null.
	 *
	 * @param string $source English text or inline HTML, as saved.
	 * @return string|null
	 */
	public static function lookup( string $source ): ?string {
		$code = Languages::current();
		$key  = self::key( $source );
		if ( Languages::ORIGINAL === $code || '' === $key ) {
			return null;
		}
		$found = self::find( $code, $key );
		if ( null !== $found ) {
			return $found;
		}
		// Text saved escaped (Can&#039;t) is looked up as plain text and escaped again.
		$plain = html_entity_decode( $key, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $plain !== $key && wp_strip_all_tags( $plain ) === $plain ) {
			$found = self::find( $code, $plain );
			return null === $found ? null : esc_html( $found );
		}
		return null;
	}

	/**
	 * A translation from the dictionary, then from the plugin's translation files.
	 *
	 * @param string $code Language.
	 * @param string $key  Normalized English text.
	 * @return string|null
	 */
	private static function find( string $code, string $key ): ?string {
		$words = self::words( $code );
		if ( isset( $words[ $key ] ) && '' !== $words[ $key ] ) {
			return $words[ $key ];
		}
		$translated = translate( $key, 'guam-restaurants-core' ); // phpcs:ignore WordPress.WP.I18n -- Saved text that came from the plugin's own strings.
		return $translated !== $key ? $translated : null;
	}

	/**
	 * Translated text, or the English text when there is none.
	 *
	 * @param string $source English text.
	 * @return string
	 */
	public static function text( string $source ): string {
		return self::lookup( $source ) ?? $source;
	}

	/**
	 * How a piece of text is written in the dictionary: trimmed, spaces collapsed.
	 *
	 * @param string $source Text.
	 * @return string
	 */
	public static function key( string $source ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $source ) );
	}

	/**
	 * A language's dictionary.
	 *
	 * @param string $code Language.
	 * @return array<string, string>
	 */
	public static function words( string $code ): array {
		if ( ! isset( self::$loaded[ $code ] ) ) {
			$file                  = GUAM_RESTAURANTS_CORE_DIR . 'data/translations/' . sanitize_key( $code ) . '.php';
			$words                 = is_readable( $file ) ? include $file : array();
			self::$loaded[ $code ] = is_array( $words ) ? $words : array();
		}
		/**
		 * Filters a language's dictionary.
		 *
		 * @param array<string, string> $words English => translation.
		 * @param string                $code  Language.
		 */
		return (array) apply_filters( 'guam_restaurants_dictionary', self::$loaded[ $code ], $code );
	}

	/**
	 * Titles of pages and posts; restaurant names stay as written.
	 *
	 * @param string   $title Title.
	 * @param int|null $post  Post ID or object.
	 * @return string
	 */
	public static function post_title( $title, $post = null ) {
		if ( ! is_string( $title ) || ! Languages::is_translated() || Content_Types::POST_TYPE === get_post_type( $post ) ) {
			return $title;
		}
		return self::text( $title );
	}

	/**
	 * Text blocks: the inner text of the paragraph, heading, list item or button.
	 *
	 * @param string               $html  Block HTML.
	 * @param array<string, mixed> $block Block.
	 * @return string
	 */
	public static function render_block( $html, $block = array() ) {
		if ( ! is_string( $html ) || ! Languages::is_translated() || ! in_array( $block['blockName'] ?? '', self::TEXT_BLOCKS, true ) ) {
			return $html;
		}
		return self::translate_element( $html );
	}

	/**
	 * Translates the content of the first element whose content is text,
	 * keeping its tags and attributes.
	 *
	 * @param string $html Block HTML, e.g. <p class="x">Text <a href="/y">link</a></p>.
	 * @return string
	 */
	public static function translate_element( string $html ): string {
		if ( ! preg_match( self::ELEMENT, $html, $match ) ) {
			return $html;
		}
		$translated = self::lookup( $match[3] );
		return null === $translated ? $html : $match[1] . $translated . $match[4];
	}

	/**
	 * Headings and labels set on the plugin's blocks and on menu and search blocks.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function block_data( $block ) {
		$name = is_array( $block ) ? (string) ( $block['blockName'] ?? '' ) : '';
		if ( ! Languages::is_translated() || ! is_array( $block['attrs'] ?? null ) || ( 0 !== strpos( $name, 'guam-restaurants/' ) && ! in_array( $name, array( 'core/navigation-link', 'core/search' ), true ) ) ) {
			return $block;
		}
		foreach ( self::TEXT_ATTRIBUTES as $attribute ) {
			if ( is_string( $block['attrs'][ $attribute ] ?? null ) ) {
				$block['attrs'][ $attribute ] = self::text( $block['attrs'][ $attribute ] );
			}
		}
		return $block;
	}

	/**
	 * Cuisine names and price level descriptions.
	 *
	 * @param \WP_Term $term Term.
	 * @return \WP_Term
	 */
	public static function term( $term ) {
		if ( $term instanceof \WP_Term && Languages::is_translated() ) {
			$term->name        = self::text( $term->name );
			$term->description = self::text( $term->description );
		}
		return $term;
	}

	/**
	 * The tagline.
	 *
	 * @param string $value Value.
	 * @param string $show  What was asked for.
	 * @return string
	 */
	public static function bloginfo( $value, $show = '' ) {
		return 'description' === $show && is_string( $value ) && Languages::is_translated() ? self::text( $value ) : $value;
	}

	/**
	 * Lists such as "Asian, Bakery and dessert, and Vietnamese" joined the
	 * way each language writes them (WordPress's own words for these come
	 * from its language packs, which a site may not have installed).
	 *
	 * @param array<string, string> $separators Separators.
	 * @return array<string, string>
	 */
	public static function list_separators( $separators ) {
		$comma = array(
			'ja' => '、',
			'ko' => ', ',
		)[ Languages::current() ] ?? null;
		if ( null === $comma || ! is_array( $separators ) ) {
			return $separators;
		}
		return array_merge(
			$separators,
			array(
				'between'          => $comma,
				'between_last_two' => $comma,
				'between_only_two' => $comma,
			)
		);
	}
}
