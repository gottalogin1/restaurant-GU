<?php
/**
 * Combines source categories, rules and manual edits into cuisine tags.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Names;

defined( 'ABSPATH' ) || exit;

/**
 * A restaurant's cuisines are:
 *
 *     (cuisines from every source) + (name rules) + (added by hand) - (removed by hand)
 *
 * Source categories are mapped per source (OSM: data/osm-cuisine-map.json)
 * and kept per source, so a source dropping a category drops only its own
 * tag. Name rules come from data/cuisine-rules.json. Manual additions and
 * removals (Tag_Store) always win. Each tag's origin is recorded.
 */
final class Cuisine_Tagger {

	/**
	 * Name rules.
	 *
	 * @var array<int, array{id: string, pattern: string, cuisines: string[], when: string}>
	 */
	private array $rules;

	/**
	 * Constructor.
	 *
	 * @param array<int, array{id: string, pattern: string, cuisines: string[], when: string}> $rules Name rules.
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Loads the rules file; defaults to the bundled data/cuisine-rules.json.
	 *
	 * @param string|null $file Path.
	 * @return Cuisine_Tagger
	 * @throws \RuntimeException When the file is missing, not valid JSON, or has a bad pattern.
	 */
	public static function from_file( ?string $file = null ): Cuisine_Tagger {
		$file = $file ?? GUAM_RESTAURANTS_CORE_DIR . 'data/cuisine-rules.json';
		$json = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Cuisine rules file is missing or not valid JSON: ' . esc_html( $file ) );
		}

		$rules = array();
		foreach ( (array) ( $data['name_rules'] ?? array() ) as $i => $rule ) {
			$pattern = '/' . str_replace( '/', '\/', (string) ( $rule['pattern'] ?? '' ) ) . '/u';
			if ( '//u' === $pattern || false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
				throw new \RuntimeException( esc_html( sprintf( 'Cuisine rule %s has an invalid pattern.', $rule['id'] ?? $i ) ) );
			}
			$rules[] = array(
				'id'       => sanitize_key( (string) ( $rule['id'] ?? 'rule-' . $i ) ),
				'pattern'  => $pattern,
				'cuisines' => array_values( array_map( 'strval', (array) ( $rule['cuisines'] ?? array() ) ) ),
				'when'     => 'no_source_cuisine' === ( $rule['when'] ?? '' ) ? 'no_source_cuisine' : 'always',
			);
		}
		return new self( $rules );
	}

	/**
	 * Every slug the rules can produce.
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		return array_values( array_unique( array_merge( array(), ...array_column( $this->rules, 'cuisines' ) ) ) );
	}

	/**
	 * Cuisines from the name rules.
	 *
	 * @param string $name              Restaurant name.
	 * @param bool   $has_source_cuisine Whether any source gave a cuisine.
	 * @return array<string, string> Slug => `rule:{id}`.
	 */
	public function rule_cuisines( string $name, bool $has_source_cuisine ): array {
		$key = Names::key( $name );
		$out = array();
		foreach ( $this->rules as $rule ) {
			if ( 'no_source_cuisine' === $rule['when'] && $has_source_cuisine ) {
				continue;
			}
			if ( 1 === preg_match( $rule['pattern'], $key ) ) {
				foreach ( $rule['cuisines'] as $slug ) {
					$out[ $slug ] = $out[ $slug ] ?? 'rule:' . $rule['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * Cuisine slugs and their origins for a restaurant, without writing.
	 *
	 * @param string                                 $name    Restaurant name.
	 * @param array<string, array<string, string>>   $sources Source => slug => detail.
	 * @param array{add: string[], remove: string[]} $manual  Manual overrides.
	 * @return array{slugs: string[], origins: array<string, array{origin: string, detail: string}>}
	 */
	public function compute( string $name, array $sources, array $manual ): array {
		$origins = array();
		foreach ( $sources as $slugs ) {
			foreach ( $slugs as $slug => $detail ) {
				$origins[ $slug ] = $origins[ $slug ] ?? Tag_Store::origin( 'source', $detail );
			}
		}
		foreach ( $this->rule_cuisines( $name, (bool) $origins ) as $slug => $detail ) {
			$origins[ $slug ] = $origins[ $slug ] ?? Tag_Store::origin( 'rule', $detail );
		}
		foreach ( $manual['add'] as $slug ) {
			$origins[ $slug ] = $origins[ $slug ] ?? Tag_Store::origin( 'manual', 'manual' );
		}
		foreach ( $manual['remove'] as $slug ) {
			unset( $origins[ $slug ] );
		}
		ksort( $origins );

		return array(
			'slugs'   => array_keys( $origins ),
			'origins' => $origins,
		);
	}

	/**
	 * Records what one source says and returns the restaurant's cuisines,
	 * writing terms and origins unless it is a dry run.
	 *
	 * @param int                        $post_id Restaurant.
	 * @param string|null                $source  Source key, or null to only re-apply rules.
	 * @param array<string, string>|null $slugs   Slug => detail from that source.
	 * @param bool                       $dry_run Compute only.
	 * @return array{old: string[], new: string[], missing: string[]}
	 */
	public function apply( int $post_id, ?string $source = null, ?array $slugs = null, bool $dry_run = false ): array {
		$current = Term_Slugs::for_post( $post_id, Content_Types::TAX_CUISINE );
		$sources = Tag_Store::cuisine_sources( $post_id );
		if ( null !== $source && null !== $slugs ) {
			$sources[ $source ] = $slugs;
		}

		$manual = Tag_Store::cuisine_manual( $post_id );
		$legacy = Tag_Store::is_legacy_cuisine( $post_id );
		if ( $legacy ) {
			// Tags from before origins were recorded: keep what no source or rule explains, as the importer always did.
			$auto          = $this->compute( (string) get_post_field( 'post_title', $post_id, 'raw' ), $sources, $manual );
			$manual['add'] = array_values( array_diff( $current, $auto['slugs'] ) );
		}

		$result = $this->compute( (string) get_post_field( 'post_title', $post_id, 'raw' ), $sources, $manual );
		$out    = array(
			'old'     => $current,
			'new'     => $result['slugs'],
			'missing' => array(),
		);
		if ( $dry_run ) {
			return $out;
		}

		if ( null !== $source && null !== $slugs ) {
			Tag_Store::save_cuisine_source( $post_id, $source, $slugs );
		} elseif ( $legacy ) {
			update_post_meta( $post_id, Tag_Store::CUISINE_SOURCES_META, $sources );
		}
		if ( $legacy ) {
			Tag_Store::save_cuisine_manual( $post_id, $manual );
		}

		if ( $result['slugs'] !== $current ) {
			$out['missing'] = Tag_Store::applying(
				static function () use ( $post_id, $result ) {
					return Term_Slugs::set( $post_id, $result['slugs'], Content_Types::TAX_CUISINE );
				}
			);
			$out['new']     = array_values( array_diff( $result['slugs'], $out['missing'] ) );
		}

		$origins  = Tag_Store::origins( $post_id );
		$previous = $origins[ Content_Types::TAX_CUISINE ];
		$cuisines = array_diff_key( $result['origins'], array_flip( $out['missing'] ) );
		foreach ( $cuisines as $slug => $origin ) {
			if ( $legacy && in_array( $slug, $manual['add'], true ) ) {
				$cuisines[ $slug ] = Tag_Store::origin( 'manual', 'legacy' );
			} elseif ( 'manual' === $origin['origin'] && 'manual' === ( $previous[ $slug ]['origin'] ?? '' ) ) {
				$cuisines[ $slug ] = $previous[ $slug ]; // Keeps who added it.
			}
		}
		$origins[ Content_Types::TAX_CUISINE ] = $cuisines;
		Tag_Store::save_origins( $post_id, $origins );

		return $out;
	}
}
