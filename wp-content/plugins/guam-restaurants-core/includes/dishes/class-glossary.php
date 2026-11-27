<?php
/**
 * The CHamoru food glossary.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Dishes;

use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Translation\Dictionary;
use GuamRestaurants\Core\Translation\Languages;

defined( 'ABSPATH' ) || exit;

/**
 * One page of local food words, A to Z, served by Dish_Pages at
 * /dishes/glossary/ (English audience idea E26, Sanjay 2026-11-26).
 *
 * The entries are shipped in data/glossary.php. Each links to its dish page
 * when that page exists, and to the page its facts come from. Dish pages
 * link back to their entry. The terms stay in CHamoru on the Japanese and
 * Korean pages; the definitions are translated through the Dictionary.
 */
final class Glossary {

	/**
	 * Entries as shipped, for the request.
	 *
	 * @var array<string, array{term: string, names?: array<string, string>, dishes: string[], text: string, source: array{0: string, 1: string}}>|null
	 */
	private static ?array $entries = null;

	/**
	 * The shipped entries, A to Z, keyed by anchor.
	 *
	 * @return array<string, array{term: string, dishes: string[], text: string, source: array{0: string, 1: string}}>
	 */
	public static function entries(): array {
		if ( null === self::$entries ) {
			$entries       = include GUAM_RESTAURANTS_CORE_DIR . 'data/glossary.php';
			self::$entries = is_array( $entries ) ? $entries : array();
		}
		return self::$entries;
	}

	/**
	 * The entry for a dish page's dish, as its anchor. Pure.
	 *
	 * A page matches an entry when the page's dish is one of the entry's
	 * dish names, or ends with one ("chicken kelaguen" goes to kelaguen).
	 * The first entry that matches wins.
	 *
	 * @param string                                       $page_key Dish page key (Popular_Dishes::key()).
	 * @param array<string, array{dishes: string[]}>|array $entries  Entries.
	 * @return string Anchor, or ''.
	 */
	public static function anchor_for( string $page_key, array $entries ): string {
		foreach ( $entries as $anchor => $entry ) {
			foreach ( (array) ( $entry['dishes'] ?? array() ) as $dish ) {
				if ( Dish_Index::matches( Popular_Dishes::key( (string) $dish ), $page_key ) ) {
					return (string) $anchor;
				}
			}
		}
		return '';
	}

	/**
	 * The dish page slug an entry links to: its first dish name that has a
	 * page. Pure.
	 *
	 * @param array{dishes: string[]}|array $entry Entry.
	 * @param array<string, mixed>          $pages Dish_Index pages, by slug.
	 * @return string Slug, or ''.
	 */
	public static function page_for( array $entry, array $pages ): string {
		foreach ( (array) ( $entry['dishes'] ?? array() ) as $dish ) {
			$slug = sanitize_title( Popular_Dishes::key( (string) $dish ) );
			if ( '' !== $slug && isset( $pages[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * URL of the glossary, or of one entry on it.
	 *
	 * @param string $anchor Entry anchor; '' for the top.
	 * @return string
	 */
	public static function url( string $anchor = '' ): string {
		$url = get_option( 'permalink_structure' )
			? home_url( '/dishes/glossary/' )
			: add_query_arg( Dish_Pages::QUERY_VAR, 'glossary', home_url( '/' ) );
		return '' === $anchor ? $url : $url . '#' . rawurlencode( $anchor );
	}

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public static function title(): string {
		return __( 'CHamoru food glossary', 'guam-restaurants-core' );
	}

	/**
	 * Meta description.
	 *
	 * @return string
	 */
	public static function description(): string {
		return __( 'What kelaguen, fina\'denne\', titiyas, chalakiles and other Guam food words mean, with links to the restaurants that serve each dish.', 'guam-restaurants-core' );
	}

	/**
	 * An entry's definition in the page's language.
	 *
	 * @param array{text: string}|array $entry Entry.
	 * @return string Plain text.
	 */
	public static function text( array $entry ): string {
		return Dictionary::text( (string) ( $entry['text'] ?? '' ) );
	}

	/**
	 * An entry's term in the page's language. CHamoru words stay as
	 * written; the few English terms (Fiesta table, Shaved ice, Red rice)
	 * carry their own `names`.
	 *
	 * @param array{term: string, names?: array<string, string>}|array $entry Entry.
	 * @return string Plain text.
	 */
	public static function term( array $entry ): string {
		return (string) ( $entry['names'][ Languages::current() ] ?? $entry['term'] ?? '' );
	}

	/**
	 * The link a dish page shows to the glossary: to its own entry when
	 * there is one, otherwise to the top.
	 *
	 * @param array{key: string, name: string}|null $dish Dish page, or null on the dish list.
	 * @return string
	 */
	public static function link( ?array $dish ): string {
		$anchor = null === $dish ? '' : self::anchor_for( (string) $dish['key'], self::entries() );
		if ( '' === $anchor ) {
			return sprintf(
				'<p class="gr-dish-page__glossary"><a href="%1$s">%2$s</a></p>',
				esc_url( self::url() ),
				esc_html__( 'New to CHamoru food? Read the food glossary.', 'guam-restaurants-core' )
			);
		}
		return sprintf(
			'<p class="gr-dish-page__glossary"><a href="%1$s">%2$s</a></p>',
			esc_url( self::url( $anchor ) ),
			sprintf(
				/* translators: %s: glossary term, for example "Kelaguen". */
				esc_html__( 'What is %s? Read it in the food glossary.', 'guam-restaurants-core' ),
				Languages::keep( esc_html( self::term( self::entries()[ $anchor ] ) ) )
			)
		);
	}

	/**
	 * The page body.
	 *
	 * @return string
	 */
	public static function render(): string {
		Assets::enqueue();
		$pages = Dish_Index::get()['pages'];
		$jump  = '';
		$items = '';
		foreach ( self::entries() as $anchor => $entry ) {
			$anchor = (string) $anchor;
			$term   = Languages::keep( esc_html( self::term( $entry ) ) );
			$jump  .= sprintf( '<li><a href="#%1$s">%2$s</a></li>', esc_attr( $anchor ), $term );

			$links = array();
			$slug  = self::page_for( $entry, $pages );
			if ( '' !== $slug ) {
				$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Dish_Pages::url( $slug ) ), esc_html( Dish_Pages::title( $pages[ $slug ] ) ) );
			}
			$links[] = sprintf(
				/* translators: %s: name of the website the facts come from, for example "Guampedia". */
				esc_html__( 'Source: %s', 'guam-restaurants-core' ),
				sprintf( '<a href="%1$s" rel="noopener">%2$s</a>', esc_url( $entry['source'][1] ), Languages::keep( esc_html( $entry['source'][0] ) ) )
			);

			$items .= sprintf(
				'<div class="gr-glossary__entry" id="%1$s"><dt class="gr-glossary__term">%2$s</dt><dd class="gr-glossary__text"><p>%3$s</p><p class="gr-glossary__links">%4$s</p></dd></div>',
				esc_attr( $anchor ),
				$term,
				esc_html( self::text( $entry ) ),
				implode( ' · ', $links )
			);
		}

		return sprintf(
			'<div class="gr-dish-page gr-glossary-page alignwide"><p class="gr-dish-page__back"><a href="%1$s">%2$s</a></p><h1 class="gr-dish-page__title">%3$s</h1><p class="gr-dish-page__about">%4$s</p><nav aria-label="%5$s"><ul class="gr-glossary__jump" role="list">%6$s</ul></nav><dl class="gr-glossary">%7$s</dl><p class="gr-dish-page__intro">%8$s</p></div>',
			esc_url( Dish_Pages::url() ),
			esc_html__( 'All dishes', 'guam-restaurants-core' ),
			esc_html( self::title() ),
			esc_html__( 'Words you will see on menus and fiesta tables around Guam, in plain language. When listed restaurants serve a dish, its entry links to where to get it.', 'guam-restaurants-core' ),
			esc_attr__( 'Glossary terms', 'guam-restaurants-core' ),
			$jump,
			$items,
			esc_html__( 'Spellings follow Guampedia. Menus often use older spellings, such as finadene for fina\'denne\'.', 'guam-restaurants-core' )
		);
	}

	/**
	 * Structured data for the page: a DefinedTermSet.
	 *
	 * @return array<string, mixed>
	 */
	public static function structured_data(): array {
		$url   = self::url();
		$terms = array();
		foreach ( self::entries() as $anchor => $entry ) {
			$terms[] = array(
				'@type'            => 'DefinedTerm',
				'@id'              => self::url( (string) $anchor ),
				'name'             => self::term( $entry ),
				'description'      => self::text( $entry ),
				'inDefinedTermSet' => $url,
			);
		}
		return array(
			'@context'       => 'https://schema.org',
			'@type'          => 'DefinedTermSet',
			'@id'            => $url,
			'name'           => self::title(),
			'url'            => $url,
			'inLanguage'     => (string) get_bloginfo( 'language' ),
			'hasDefinedTerm' => $terms,
		);
	}

	/**
	 * Forgets the entries (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$entries = null;
	}
}
