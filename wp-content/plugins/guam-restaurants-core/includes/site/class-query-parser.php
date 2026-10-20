<?php
/**
 * Plain-language search: "cheap Korean open late in Tumon" as filters.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Term_Meta;
use GuamRestaurants\Core\Village_Aliases;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one indexed lookup on the posts table.

/**
 * Reads a typed search with fixed word lists, no outside service: the
 * site's own villages (names, aliases and the spellings below), visible
 * malls, cuisines (names and the words below), price words, "open now",
 * "open late", "breakfast" and "award winners". Each phrase it knows
 * becomes the same filter the form offers; words it does not know stay a
 * keyword search.
 * Filler words ("in", "food", "place") are dropped only when something
 * else was recognized, so a plain name search is never changed.
 *
 * Two ways out keep name searches working: a search in double quotes is
 * never read, and neither is a search of two or more words that appears in
 * a listed restaurant's name ("Pizza Hut", "Korean BBQ House").
 */
final class Query_Parser {

	/**
	 * Other spellings of village names: Chamorro spellings and short forms
	 * not stored as the village's own aliases (Seed_Data::villages()).
	 */
	public const VILLAGE_WORDS = array(
		'barrigada'           => array( 'barigada', 'barigåda' ),
		'dededo'              => array( 'dedidu' ),
		'mongmong-toto-maite' => array( 'mtm' ),
		'santa-rita'          => array( 'sta rita', 'sta. rita' ),
		'sinajana'            => array( 'sinahana', 'sinahånña' ),
		'tamuning'            => array( 'tamuneng', 'tumon bay' ),
		'yigo'                => array( 'yigu', 'andersen', 'andersen afb' ),
	);

	/**
	 * Words that mean a cuisine, by cuisine slug, beside the term's own
	 * name. Dishes are listed only where they clearly name the cuisine.
	 */
	public const CUISINE_WORDS = array(
		'american'           => array( 'burger', 'hamburger', 'diner', 'wings' ),
		'asian'              => array( 'noodles', 'noodle house' ),
		'bakery-and-dessert' => array( 'bakery', 'dessert', 'desserts', 'donut', 'donuts', 'doughnut', 'pastry', 'pastries', 'cake', 'cakes', 'ice cream', 'shave ice', 'sweets' ),
		'bar-and-pub'        => array( 'bar', 'pub', 'drinks', 'beer', 'cocktails', 'happy hour', 'brewery' ),
		'bbq-and-grill'      => array( 'bbq', 'barbecue', 'barbeque', 'grill', 'grilled' ),
		'chamorro'           => array( 'chamoru', 'kelaguen', 'red rice', 'finadene', 'fina denne', 'kadon pika', 'chamorro food' ),
		'chinese'            => array( 'dim sum', 'dumplings', 'hot pot', 'hotpot', 'szechuan', 'cantonese' ),
		'coffee-and-cafe'    => array( 'coffee', 'cafe', 'café', 'espresso', 'latte', 'coffee shop' ),
		'fast-food'          => array( 'fast food', 'drive thru', 'drive through', 'takeout', 'take out' ),
		'filipino'           => array( 'pinoy', 'adobo', 'lumpia', 'pancit', 'sisig', 'halo halo', 'lechon' ),
		'italian'            => array( 'pasta', 'trattoria' ),
		'japanese'           => array( 'sushi', 'sushi bar', 'ramen', 'udon', 'soba', 'izakaya', 'tempura', 'teppanyaki', 'yakiniku', 'sashimi', 'bento' ),
		'korean'             => array( 'kbbq', 'k bbq', 'korean bbq', 'korean barbecue', 'bibimbap', 'kimchi', 'bulgogi' ),
		'local-plate-lunch'  => array( 'plate lunch', 'plate lunches', 'local plate', 'local food', 'plate' ),
		'mexican'            => array( 'taco', 'tacos', 'burrito', 'burritos', 'quesadilla', 'taqueria', 'tex mex' ),
		'pizza'              => array( 'pizzeria' ),
		'seafood'            => array( 'fish', 'poke', 'oyster', 'oysters', 'crab', 'shrimp', 'lobster' ),
		'steakhouse'         => array( 'steak', 'steaks', 'steak house' ),
		'thai'               => array( 'pad thai' ),
		'vietnamese'         => array( 'pho', 'banh mi' ),
	);

	/**
	 * Price words => price level ranks (1 is $, 4 is $$$$; Seed_Data::price_levels()).
	 */
	public const PRICE_WORDS = array(
		'cheap'             => array( 1 ),
		'cheapest'          => array( 1 ),
		'cheap eats'        => array( 1 ),
		'inexpensive'       => array( 1 ),
		'affordable'        => array( 1 ),
		'budget'            => array( 1 ),
		'low cost'          => array( 1 ),
		'low price'         => array( 1 ),
		'mid range'         => array( 2 ),
		'midrange'          => array( 2 ),
		'moderate'          => array( 2 ),
		'moderately priced' => array( 2 ),
		'reasonable'        => array( 1, 2 ),
		'expensive'         => array( 3, 4 ),
		'pricey'            => array( 3, 4 ),
		'upscale'           => array( 3, 4 ),
		'high end'          => array( 3, 4 ),
		'fancy'             => array( 3, 4 ),
		'splurge'           => array( 3, 4 ),
		'fine dining'       => array( 4 ),
		'special occasion'  => array( 3, 4 ),
	);

	/**
	 * Words before an amount, as in "under $20".
	 */
	public const PRICE_LIMITS = array( 'under', 'below', 'less than', 'cheaper than', 'up to', 'max', 'maximum', 'at most', 'within' );

	/**
	 * Lowest per-person price of each level, for "under $N".
	 */
	public const PRICE_FLOORS = array(
		1 => 0,
		2 => 15,
		3 => 30,
		4 => 60,
	);

	public const OPEN_WORDS = array( 'open now', 'open right now', 'right now', 'currently open', 'still open', 'open today', 'open', 'now' );

	public const LATE_WORDS = array( 'open late', 'late night', 'late', 'late nite', 'open till late', 'open until late', 'after 10', 'after 10pm', 'after 10 pm', 'after midnight', 'midnight', 'night owl', '24 hours', '24 hour', '24 7', 'all night' );

	/**
	 * Phrases only: "breakfast" alone is often part of a dish ("breakfast
	 * burritos"), so it stays in the keyword search.
	 */
	public const BREAKFAST_WORDS = array( 'for breakfast', 'open for breakfast', 'breakfast place', 'breakfast places', 'breakfast spot', 'breakfast spots', 'early breakfast' );

	public const AWARD_WORDS = array( 'award winning', 'award winner', 'award winners', 'award', 'awards', 'awarded', 'prize winning' );

	/**
	 * Dropped once something else was recognized.
	 */
	public const FILLER_WORDS = array(
		'a',
		'an',
		'the',
		'in',
		'at',
		'near',
		'around',
		'by',
		'on',
		'of',
		'for',
		'with',
		'and',
		'or',
		'to',
		'from',
		'me',
		'i',
		'im',
		'we',
		'want',
		'wanna',
		'looking',
		'find',
		'show',
		'get',
		'where',
		'what',
		'whats',
		'is',
		'are',
		'that',
		'thats',
		'which',
		'some',
		'any',
		'place',
		'places',
		'spot',
		'spots',
		'restaurant',
		'restaurants',
		'food',
		'eat',
		'eats',
		'eating',
		'dining',
		'meal',
		'lunch',
		'dinner',
		'good',
		'great',
		'nice',
		'best',
		'tasty',
		'yummy',
		'delicious',
		'something',
		'anything',
		'guam',
		'island',
		'area',
		'please',
		'near me',
		'nearby',
		'priced',
		'price',
		'prices',
		'per',
		'person',
		'dollars',
		'dollar',
		'bucks',
		'pm',
		'til',
		'till',
		'until',
		'cuisine',
		'style',
	);

	/**
	 * Built word lists, by cache key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $lists = array();

	/**
	 * Whether a search should be left as typed: quoted, or part of a
	 * restaurant's name.
	 *
	 * @param string $text Search text.
	 * @return bool
	 */
	public static function is_literal( string $text ): bool {
		$text = trim( $text );
		if ( 1 === preg_match( '/^["“”].*["“”]$/u', $text ) && mb_strlen( $text ) > 1 ) {
			return true;
		}
		if ( count( preg_split( '/\s+/', $text ) ) < 2 ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title LIKE %s LIMIT 1",
				Content_Types::POST_TYPE,
				'%' . $wpdb->esc_like( $text ) . '%'
			)
		);
	}

	/**
	 * Reads a search.
	 *
	 * @param string $text Search text.
	 * @return array{facets: array<string, string[]>, open: bool, late: bool, breakfast: bool, award: bool, search: string, matches: array<int, array{type: string, value: string, phrase: string}>}
	 *         Filters found, the words left over (as typed) and what each phrase was read as.
	 */
	public static function parse( string $text ): array {
		$out = array(
			'facets'    => array_fill_keys( array_keys( Filters::FACETS ), array() ),
			'open'      => false,
			'late'      => false,
			'breakfast' => false,
			'award'     => false,
			'search'    => trim( $text ),
			'matches'   => array(),
		);

		// Each typed word, and the normalized words inside it ("late-night" is two).
		$words = array();
		$parts = array();
		foreach ( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) as $index => $word ) {
			$words[ $index ] = $word;
			if ( 1 === preg_match( '/^\$+$/', $word ) && strlen( $word ) <= 4 ) {
				$parts[] = array(
					'word'  => $index,
					'token' => str_repeat( '$', strlen( $word ) ),
				);
				continue;
			}
			foreach ( explode( ' ', Village_Aliases::normalize( $word ) ) as $token ) {
				if ( '' !== $token ) {
					$parts[] = array(
						'word'  => $index,
						'token' => self::singular( $token ),
					);
				}
			}
		}
		if ( ! $parts ) {
			return $out;
		}

		$lists    = self::lists();
		$tokens   = array_column( $parts, 'token' );
		$used     = array_fill( 0, count( $parts ), false );
		$matched  = false;
		$position = 0;
		$count    = count( $tokens );
		while ( $position < $count ) {
			$hit = self::match_at( $tokens, $position, $lists['phrases'] );
			if ( null === $hit ) {
				$hit = self::price_limit_at( $tokens, $position, $lists['limits'] );
			}
			if ( null === $hit ) {
				++$position;
				continue;
			}
			$phrase = self::phrase( $words, $parts, $position, $hit['length'] );
			foreach ( $hit['actions'] as $action ) {
				self::apply( $out, $action, $phrase, $lists['prices'] );
			}
			for ( $i = $position; $i < $position + $hit['length']; $i++ ) {
				$used[ $i ] = true;
			}
			$matched   = true;
			$position += $hit['length'];
		}
		if ( ! $matched ) {
			return $out;
		}

		// Drop filler, then rebuild the rest from what was typed.
		foreach ( $tokens as $i => $token ) {
			if ( ! $used[ $i ] && isset( $lists['fillers'][ $token ] ) ) {
				$used[ $i ] = true;
			}
		}
		// A word untouched is kept as typed; a word partly read keeps its other parts.
		$kept = array();
		$read = array();
		foreach ( $parts as $i => $part ) {
			if ( $used[ $i ] ) {
				$read[ $part['word'] ] = true;
			} else {
				$kept[ $part['word'] ][] = $part['token'];
			}
		}
		$rest = array();
		foreach ( $kept as $index => $tokens_left ) {
			$rest = array_merge( $rest, isset( $read[ $index ] ) ? $tokens_left : array( $words[ $index ] ) );
		}
		$out['search'] = trim( implode( ' ', $rest ), " \t,.;:!?-" );
		return $out;
	}

	/**
	 * Clears the built word lists (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$lists = array();
	}

	/**
	 * Adds one recognized phrase's filter.
	 *
	 * @param array<string, mixed>       $out    Result so far.
	 * @param array{0: string, 1: mixed} $action Type and value.
	 * @param string                     $phrase The words as typed.
	 * @param array<int, string>         $prices Rank => price slug.
	 * @return void
	 */
	private static function apply( array &$out, array $action, string $phrase, array $prices ): void {
		list( $type, $value ) = $action;
		if ( 'filler' === $type ) {
			return;
		}
		if ( isset( Filters::FACETS[ $type ] ) || 'rank' === $type ) {
			$facet = 'rank' === $type ? 'price' : $type;
			$slugs = 'rank' === $type ? array_values( array_intersect_key( $prices, array_flip( (array) $value ) ) ) : array( (string) $value );
			foreach ( $slugs as $slug ) {
				if ( ! in_array( $slug, $out['facets'][ $facet ], true ) ) {
					$out['facets'][ $facet ][] = $slug;
					$out['matches'][]          = array(
						'type'   => $facet,
						'value'  => $slug,
						'phrase' => $phrase,
					);
				}
			}
			return;
		}
		if ( ! $out[ $type ] ) {
			$out[ $type ]     = true;
			$out['matches'][] = array(
				'type'   => $type,
				'value'  => '1',
				'phrase' => $phrase,
			);
		}
	}

	/**
	 * The longest known phrase starting at a position.
	 *
	 * @param string[]                                $tokens   Tokens.
	 * @param int                                     $position Position.
	 * @param array<string, array<int, array<mixed>>> $phrases  First token => phrases, longest first.
	 * @return array{length: int, actions: array<int, array{0: string, 1: mixed}>}|null
	 */
	private static function match_at( array $tokens, int $position, array $phrases ): ?array {
		foreach ( $phrases[ $tokens[ $position ] ] ?? array() as $entry ) {
			$length = count( $entry['tokens'] );
			if ( array_slice( $tokens, $position, $length ) === $entry['tokens'] ) {
				return array(
					'length'  => $length,
					'actions' => $entry['actions'],
				);
			}
		}
		return null;
	}

	/**
	 * "under $20", "less than 30 dollars": the price levels that start below the amount.
	 *
	 * @param string[]   $tokens   Tokens.
	 * @param int        $position Position.
	 * @param string[][] $limits   Limit phrases as tokens.
	 * @return array{length: int, actions: array<int, array{0: string, 1: mixed}>}|null
	 */
	private static function price_limit_at( array $tokens, int $position, array $limits ): ?array {
		foreach ( $limits as $limit ) {
			$length = count( $limit );
			if ( array_slice( $tokens, $position, $length ) !== $limit ) {
				continue;
			}
			$amount = $tokens[ $position + $length ] ?? '';
			if ( 1 !== preg_match( '/^\d{1,4}$/', $amount ) ) {
				continue;
			}
			$ranks = array();
			foreach ( self::PRICE_FLOORS as $rank => $floor ) {
				if ( (int) $amount > $floor ) {
					$ranks[] = $rank;
				}
			}
			if ( ! $ranks ) {
				return null;
			}
			return array(
				'length'  => $length + 1,
				'actions' => array( array( 'rank', $ranks ) ),
			);
		}
		return null;
	}

	/**
	 * The typed words behind a run of tokens.
	 *
	 * @param string[]                                    $words    Words as typed.
	 * @param array<int, array{word: int, token: string}> $parts  Tokens and their word.
	 * @param int                                         $position First token.
	 * @param int                                         $length   Token count.
	 * @return string
	 */
	private static function phrase( array $words, array $parts, int $position, int $length ): string {
		$indexes = array();
		for ( $i = $position; $i < $position + $length; $i++ ) {
			$indexes[ $parts[ $i ]['word'] ] = true;
		}
		$typed = array();
		foreach ( array_keys( $indexes ) as $index ) {
			$typed[] = $words[ $index ];
		}
		return trim( implode( ' ', $typed ), " \t,.;:!?" );
	}

	/**
	 * Word lists built from the site's terms, kept per request until terms change.
	 *
	 * @return array{phrases: array<string, array<int, array<mixed>>>, limits: string[][], fillers: array<string, true>, prices: array<int, string>}
	 */
	private static function lists(): array {
		$key = wp_cache_get_last_changed( 'terms' ) . ( Mall_Verification::can_see_unverified() ? ':all' : '' );
		if ( isset( self::$lists[ $key ] ) ) {
			return self::$lists[ $key ];
		}

		$phrases = array();
		$add     = static function ( string $phrase, string $type, $value ) use ( &$phrases ): void {
			$tokens = self::tokens( $phrase );
			if ( ! $tokens ) {
				return;
			}
			$key = implode( ' ', $tokens );
			if ( ! isset( $phrases[ $key ] ) ) {
				$phrases[ $key ] = array(
					'tokens'  => $tokens,
					'actions' => array(),
				);
			}
			// One phrase can mean two things ("korean bbq" is never both, but a
			// cuisine and a mall can share a word); the first meaning wins.
			if ( ! $phrases[ $key ]['actions'] ) {
				$phrases[ $key ]['actions'][] = array( $type, $value );
			}
		};

		// Status words first, so "open late" is not read as "open" plus "late".
		foreach ( self::LATE_WORDS as $phrase ) {
			$add( $phrase, 'late', true );
		}
		foreach ( self::OPEN_WORDS as $phrase ) {
			$add( $phrase, 'open', true );
		}
		foreach ( self::BREAKFAST_WORDS as $phrase ) {
			$add( $phrase, 'breakfast', true );
		}
		foreach ( self::AWARD_WORDS as $phrase ) {
			$add( $phrase, 'award', true );
		}

		$prices = array();
		foreach ( self::terms( Content_Types::TAX_PRICE ) as $term ) {
			$rank = (int) get_term_meta( $term->term_id, Term_Meta::RANK, true );
			if ( $rank > 0 ) {
				$prices[ $rank ] = $term->slug;
				$add( str_repeat( '$', $rank ), 'rank', array( $rank ) );
			}
		}
		ksort( $prices );
		foreach ( self::PRICE_WORDS as $phrase => $ranks ) {
			$add( $phrase, 'rank', $ranks );
		}

		foreach ( self::terms( Content_Types::TAX_VILLAGE ) as $term ) {
			$names = array_merge( array( $term->name, $term->slug ), (array) get_term_meta( $term->term_id, Term_Meta::ALIASES, true ), self::VILLAGE_WORDS[ $term->slug ] ?? array() );
			foreach ( $names as $name ) {
				$add( (string) $name, 'village', $term->slug );
			}
		}
		foreach ( self::terms( Content_Types::TAX_MALL ) as $term ) {
			$names = array_merge( array( $term->name ), (array) get_term_meta( $term->term_id, Term_Meta::ALIASES, true ) );
			foreach ( $names as $name ) {
				$add( (string) $name, 'mall', $term->slug );
			}
		}
		foreach ( self::terms( Content_Types::TAX_CUISINE ) as $term ) {
			foreach ( array_merge( array( $term->name, $term->slug ), self::CUISINE_WORDS[ $term->slug ] ?? array() ) as $name ) {
				$add( (string) $name, 'cuisine', $term->slug );
			}
		}
		// Multi-word fillers ("near me") are phrases that match nothing.
		$fillers = array();
		foreach ( self::FILLER_WORDS as $phrase ) {
			$tokens = self::tokens( $phrase );
			if ( count( $tokens ) > 1 ) {
				$add( $phrase, 'filler', true );
			} elseif ( $tokens ) {
				$fillers[ $tokens[0] ] = true;
			}
		}

		$by_first = array();
		foreach ( $phrases as $entry ) {
			$by_first[ $entry['tokens'][0] ][] = $entry;
		}
		foreach ( $by_first as &$entries ) {
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return count( $b['tokens'] ) <=> count( $a['tokens'] );
				}
			);
		}
		unset( $entries );

		$limits = array_map( array( self::class, 'tokens' ), self::PRICE_LIMITS );
		usort(
			$limits,
			static function ( array $a, array $b ): int {
				return count( $b ) <=> count( $a );
			}
		);

		self::$lists[ $key ] = array(
			'phrases' => $by_first,
			'limits'  => $limits,
			'fillers' => $fillers,
			'prices'  => $prices,
		);
		return self::$lists[ $key ];
	}

	/**
	 * A phrase as matching tokens. "$$" stays as it is; everything else is
	 * normalized like village names and made singular.
	 *
	 * @param string $phrase Phrase.
	 * @return string[]
	 */
	private static function tokens( string $phrase ): array {
		if ( 1 === preg_match( '/^\$+$/', $phrase ) ) {
			return array( $phrase );
		}
		$normalized = Village_Aliases::normalize( $phrase );
		return '' === $normalized ? array() : array_map( array( self::class, 'singular' ), explode( ' ', $normalized ) );
	}

	/**
	 * Crude singular, applied the same way to typed words and to the lists:
	 * "tacos" and "taco" match.
	 *
	 * @param string $token Normalized word.
	 * @return string
	 */
	private static function singular( string $token ): string {
		if ( strlen( $token ) > 3 && 's' === substr( $token, -1 ) && 'ss' !== substr( $token, -2 ) ) {
			return substr( $token, 0, -1 );
		}
		return $token;
	}

	/**
	 * Every term of a taxonomy, listed or not (a search for a cuisine with
	 * no listings should say so, not search for the word).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return \WP_Term[]
	 */
	private static function terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		return is_array( $terms ) ? $terms : array();
	}
}
