<?php
/**
 * OpenStreetMap restaurant importer.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Osm;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Monitoring\Closures;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Cuisine_Tagger;
use GuamRestaurants\Core\Tagging\Mall_Tagger;
use GuamRestaurants\Core\Tagging\Term_Slugs;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Upserts restaurants from Overpass elements, keyed by gr_osm_id.
 *
 * Update rules, so re-running is safe and editors' work is kept:
 *
 * - OSM wins for name, address, phone, website and hours when OSM has a
 *   value; an empty OSM value never blanks a stored one.
 * - Coordinates and hours entered by an owner or editor (coord source or
 *   hours source `owner`/`manual`) are never overwritten.
 * - Cuisines come from Cuisine_Tagger: OSM's categories (osm-cuisine-map.json)
 *   plus the name rules (cuisine-rules.json). Cuisines an editor added or
 *   removed by hand are never changed.
 * - The village always follows the coordinates (Census boundaries). A point
 *   outside every village goes to the review queue once.
 * - The mall comes from Mall_Tagger (OSM outline or radius, then the mall's
 *   name in the address). A mall set or cleared by hand is never changed.
 * - Every OSM-linked restaurant missing from a full import gets one
 *   "closed" check (Closures); two in a row ask an administrator. A
 *   response with far fewer restaurants than expected skips the checks.
 * - Post status is set only on creation, so trashed or unpublished
 *   restaurants stay that way.
 *
 * Every change to an existing restaurant is written to the change log, and
 * each new restaurant gets a first_seen entry.
 */
final class Importer {

	public const CREATED   = 'created';
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';

	/**
	 * A closure check is skipped when the import holds fewer than this share
	 * of the restaurants already linked to OSM (a truncated response).
	 */
	public const CLOSURE_CHECK_MIN_SHARE = 0.8;

	/**
	 * Fields recorded in the change log.
	 */
	private const META_FIELDS = array(
		Restaurant_Meta::ADDRESS,
		Restaurant_Meta::PHONE,
		Restaurant_Meta::WEBSITE,
		Restaurant_Meta::HOURS,
	);

	/**
	 * Element mapper.
	 *
	 * @var Element_Mapper
	 */
	private Element_Mapper $mapper;

	/**
	 * Village locator.
	 *
	 * @var Village_Locator
	 */
	private Village_Locator $villages;

	/**
	 * Mall radius in metres.
	 *
	 * @var float
	 */
	private float $mall_radius;

	/**
	 * Status for new posts.
	 *
	 * @var string
	 */
	private string $post_status;

	/**
	 * Cuisine tagger.
	 *
	 * @var Cuisine_Tagger
	 */
	private Cuisine_Tagger $cuisines;

	/**
	 * Whether a non-dry run records closure checks.
	 *
	 * @var bool
	 */
	private bool $closure_checks = true;

	/**
	 * Mall tagger for the current run.
	 *
	 * @var Mall_Tagger|null
	 */
	private ?Mall_Tagger $mall_tagger = null;

	/**
	 * Constructor.
	 *
	 * @param Element_Mapper       $mapper      Mapper.
	 * @param Village_Locator|null $villages    Village locator; defaults to the bundled boundaries.
	 * @param float                $mall_radius Metres around a mall point (malls without an outline).
	 * @param string               $post_status Status for new restaurants.
	 * @param Cuisine_Tagger|null  $cuisines    Cuisine tagger; defaults to data/cuisine-rules.json.
	 */
	public function __construct( Element_Mapper $mapper, ?Village_Locator $villages = null, float $mall_radius = 150.0, string $post_status = 'publish', ?Cuisine_Tagger $cuisines = null ) {
		$this->mapper      = $mapper;
		$this->villages    = $villages ?? Village_Locator::instance();
		$this->mall_radius = $mall_radius;
		$this->post_status = $post_status;
		$this->cuisines    = $cuisines ?? Cuisine_Tagger::from_file();
	}

	/**
	 * Turns closure checks on or off (off for partial input files).
	 *
	 * @param bool $enabled Enabled.
	 * @return self
	 */
	public function with_closure_checks( bool $enabled ): self {
		$this->closure_checks = $enabled;
		return $this;
	}

	/**
	 * Imports a set of Overpass elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param bool                             $dry_run  Report without writing (no ingestion run is logged).
	 * @return array{counts: array<string, int>, skipped: array<string, int>, unmapped: array<string, int>, no_cuisine_tag: int, unmatched_malls: array<string, string>, village_unresolved: string[], closures: array{checked: bool, closed: int[], queued: int[], note: string}, errors: string[], run_id: int|null}
	 */
	public function run( array $elements, bool $dry_run = false ): array {
		return Mall_Verification::with_unverified(
			function () use ( $elements, $dry_run ) {
				return Change_Tracker::suppress(
					function () use ( $elements, $dry_run ) {
						return $this->import( $elements, $dry_run );
					}
				);
			}
		);
	}

	/**
	 * Does the work of run().
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param bool                             $dry_run  Dry run.
	 * @return array<string, mixed>
	 */
	private function import( array $elements, bool $dry_run ): array {
		$restaurants = array();
		$malls       = array();
		foreach ( $elements as $element ) {
			if ( Element_Mapper::is_restaurant( $element ) ) {
				$restaurants[] = $element;
			} elseif ( 'mall' === ( $element['tags']['shop'] ?? '' ) ) {
				$malls[] = $element;
			}
		}

		$result = array(
			'counts'             => array_fill_keys( array( self::CREATED, self::UPDATED, self::UNCHANGED, self::FAILED ), 0 ),
			'skipped'            => array(),
			'unmapped'           => array(),
			'no_cuisine_tag'     => 0,
			'unmatched_malls'    => array(),
			'village_unresolved' => array(),
			'closures'           => array(
				'checked' => false,
				'closed'  => array(),
				'queued'  => array(),
				'note'    => '',
			),
			'errors'             => array(),
			'run_id'             => null,
		);

		$mall_terms                = self::mall_terms();
		$mall_locator              = new Mall_Locator( $malls, $mall_terms, $this->mall_radius );
		$result['unmatched_malls'] = $mall_locator->unmatched();
		$this->mall_tagger         = new Mall_Tagger( $mall_locator, $mall_terms );
		if ( ! $dry_run ) {
			Mall_Tagger::save_geometries( $mall_locator->geometries() );
		}

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( 'osm' );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;
		$existing         = self::existing_ids();
		$baseline         = null !== $run_id && ! Ingestion_Runs::has_previous( 'osm', $run_id );
		$seen             = array();

		foreach ( $restaurants as $element ) {
			$reason = Element_Mapper::skip_reason( $element );
			if ( null !== $reason ) {
				$result['skipped'][ $reason ] = ( $result['skipped'][ $reason ] ?? 0 ) + 1;
				continue;
			}

			$record = $this->mapper->map( $element );
			if ( '' === $record['osm_cuisine'] ) {
				++$result['no_cuisine_tag'];
			}
			foreach ( $record['unmapped'] as $value ) {
				$result['unmapped'][ $value ] = ( $result['unmapped'][ $value ] ?? 0 ) + 1;
			}

			$seen[ $record['osm_id'] ] = true;

			try {
				$post_id = $existing[ $record['osm_id'] ] ?? 0;
				$outcome = $this->upsert( $record, $post_id, $run_id, $dry_run, $baseline, $result['village_unresolved'] );
				if ( self::CREATED === $outcome['status'] && $outcome['post_id'] ) {
					$existing[ $record['osm_id'] ] = $outcome['post_id'];
				}
				++$result['counts'][ $outcome['status'] ];
			} catch ( \Throwable $e ) {
				++$result['counts'][ self::FAILED ];
				$result['errors'][] = $record['osm_id'] . ': ' . $e->getMessage();
			}
		}

		arsort( $result['unmapped'] );

		if ( $run_id && $this->closure_checks ) {
			$result['closures'] = self::check_closures( $existing, $seen, $run_id );
		}

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], self::notes( $result ), $result['errors'] );
		}

		return $result;
	}

	/**
	 * Records a closure check for every active OSM-linked restaurant: closed
	 * when its element is missing from this import, open when present.
	 *
	 * @param array<string, int>  $existing OSM id => post ID before this run.
	 * @param array<string, bool> $seen     OSM ids in this run.
	 * @param int                 $run_id   Ingestion run.
	 * @return array{checked: bool, closed: int[], queued: int[], note: string}
	 */
	private static function check_closures( array $existing, array $seen, int $run_id ): array {
		$out = array(
			'checked' => false,
			'closed'  => array(),
			'queued'  => array(),
			'note'    => '',
		);

		$active = array();
		foreach ( $existing as $osm_id => $post_id ) {
			$status = get_post_status( $post_id );
			if ( $status && 'trash' !== $status && 'permanently_closed' !== get_post_meta( $post_id, Restaurant_Meta::STATUS, true ) ) {
				$active[ $osm_id ] = $post_id;
			}
		}
		if ( ! $active ) {
			return $out;
		}

		$present = count( array_intersect_key( $active, $seen ) );
		if ( $present < self::CLOSURE_CHECK_MIN_SHARE * count( $active ) ) {
			$out['note'] = sprintf( 'closure checks skipped: only %d of %d known restaurants in the response', $present, count( $active ) );
			return $out;
		}

		$out['checked'] = true;
		foreach ( $active as $osm_id => $post_id ) {
			$closed = ! isset( $seen[ $osm_id ] );
			$check  = Closures::record_check( $post_id, 'osm', $closed, $run_id, $closed ? array( 'osm_id' => $osm_id ) : array() );
			if ( $closed ) {
				$out['closed'][] = $post_id;
			}
			if ( $check['queued'] ) {
				$out['queued'][] = $post_id;
			}
		}
		return $out;
	}

	/**
	 * Creates or updates one restaurant.
	 *
	 * @param array<string, mixed> $record             Mapped element.
	 * @param int                  $post_id            Existing post, or 0.
	 * @param int|null             $run_id             Ingestion run.
	 * @param bool                 $dry_run            Dry run.
	 * @param bool                 $baseline           Whether this is OSM's first run (new posts are not openings).
	 * @param string[]             $village_unresolved OSM ids with no village, appended to.
	 * @return array{status: string, post_id: int}
	 * @throws \RuntimeException When WordPress refuses a write.
	 */
	private function upsert( array $record, int $post_id, ?int $run_id, bool $dry_run, bool $baseline, array &$village_unresolved ): array {
		list( $lat, $lng ) = self::effective_point( $post_id, $record );

		$village = $this->villages->slug_for( $lat, $lng );
		if ( null === $village ) {
			$village_unresolved[] = $record['osm_id'];
		}

		if ( ! $post_id ) {
			if ( ! $dry_run ) {
				$post_id = $this->create( $record, $village, $lat, $lng, $run_id );
				Change_Tracker::record_first_seen( $post_id, 'osm', $run_id, $baseline, $record['osm_id'] );
			}
			return array(
				'status'  => self::CREATED,
				'post_id' => $post_id,
			);
		}

		$changes = $this->diff( $post_id, $record, $village );

		if ( null === $village && ! $dry_run ) {
			self::queue_unresolved_village( $post_id, $record, $run_id );
		}

		if ( ! $dry_run ) {
			$this->apply( $post_id, $changes );
			self::save_osm_cuisine( $post_id, $record['osm_cuisine'] );
		}

		// Tags last, so the mall can use an updated address.
		$cuisine = $this->cuisines->apply( $post_id, 'osm', $record['cuisine_details'], $dry_run );
		if ( $cuisine['old'] !== $cuisine['new'] ) {
			$changes[ 'tax:' . Content_Types::TAX_CUISINE ] = array(
				'old'    => implode( ',', $cuisine['old'] ),
				'new'    => implode( ',', $cuisine['new'] ),
				'source' => 'osm',
			);
		}
		$mall = $this->mall_tagger->apply( $post_id, $lat, $lng, $dry_run );
		if ( $mall['old'] !== $mall['new'] ) {
			$changes[ 'tax:' . Content_Types::TAX_MALL ] = array(
				'old'    => $mall['old'],
				'new'    => $mall['new'],
				'source' => 'rule' === $mall['origin'] ? 'system' : 'osm',
			);
		}

		if ( ! $changes ) {
			return array(
				'status'  => self::UNCHANGED,
				'post_id' => $post_id,
			);
		}

		if ( ! $dry_run ) {
			foreach ( $changes as $field => $change ) {
				$source = $change['source'] ?? ( 'tax:' . Content_Types::TAX_VILLAGE === $field ? 'census' : 'osm' );
				Change_Log::record( $post_id, $field, $change['old'], $change['new'], $source, $run_id );
			}
		}

		return array(
			'status'  => self::UPDATED,
			'post_id' => $post_id,
		);
	}

	/**
	 * The point used for village and mall tagging: the stored coordinates
	 * when an owner or editor set them, otherwise OSM's.
	 *
	 * @param int                  $post_id Existing post, or 0.
	 * @param array<string, mixed> $record  Mapped element.
	 * @return array{0: float, 1: float}
	 */
	private static function effective_point( int $post_id, array $record ): array {
		if ( $post_id && in_array( get_post_meta( $post_id, Restaurant_Meta::COORD_SOURCE, true ), array( 'owner', 'manual' ), true ) ) {
			$lat = get_post_meta( $post_id, Restaurant_Meta::LATITUDE, true );
			$lng = get_post_meta( $post_id, Restaurant_Meta::LONGITUDE, true );
			if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
				return array( (float) $lat, (float) $lng );
			}
		}
		return array( $record['latitude'], $record['longitude'] );
	}

	/**
	 * Creates a restaurant.
	 *
	 * @param array<string, mixed> $record  Mapped element.
	 * @param string|null          $village Village slug.
	 * @param float                $lat     Latitude for the mall.
	 * @param float                $lng     Longitude for the mall.
	 * @param int|null             $run_id  Ingestion run.
	 * @return int Post ID.
	 * @throws \RuntimeException When the post cannot be created.
	 */
	private function create( array $record, ?string $village, float $lat, float $lng, ?int $run_id ): int {
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_status' => $this->post_status,
					'post_title'  => $record['title'],
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, Restaurant_Meta::OSM_ID, $record['osm_id'] );
		update_post_meta( $post_id, Restaurant_Meta::LATITUDE, $record['latitude'] );
		update_post_meta( $post_id, Restaurant_Meta::LONGITUDE, $record['longitude'] );
		update_post_meta( $post_id, Restaurant_Meta::COORD_SOURCE, 'osm' );
		self::save_osm_cuisine( $post_id, $record['osm_cuisine'] );
		foreach ( $record['meta'] as $key => $value ) {
			if ( array() !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		$missing = $this->cuisines->apply( $post_id, 'osm', $record['cuisine_details'] )['missing'];
		if ( $missing ) {
			throw new \RuntimeException( esc_html( 'No ' . Content_Types::TAX_CUISINE . ' term with slug ' . implode( ', ', $missing ) . '; run `wp guam seed`.' ) );
		}
		if ( $village ) {
			wp_set_object_terms( $post_id, self::term_ids( array( $village ), Content_Types::TAX_VILLAGE ), Content_Types::TAX_VILLAGE );
		} else {
			self::queue_unresolved_village( $post_id, $record, $run_id );
		}
		$this->mall_tagger->apply( $post_id, $lat, $lng );

		return (int) $post_id;
	}

	/**
	 * Fields that would change on an existing restaurant.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $record  Mapped element.
	 * @param string|null          $village Village slug from the coordinates.
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private function diff( int $post_id, array $record, ?string $village ): array {
		$changes = array();

		$title     = (string) get_post_field( 'post_title', $post_id, 'raw' );
		$new_title = self::stored_title( $record['title'] );
		if ( $new_title !== $title ) {
			$changes['title'] = array(
				'old' => $title,
				'new' => $new_title,
			);
		}

		$coord_source = (string) get_post_meta( $post_id, Restaurant_Meta::COORD_SOURCE, true );
		if ( ! in_array( $coord_source, array( 'owner', 'manual' ), true ) ) {
			foreach ( array(
				Restaurant_Meta::LATITUDE  => $record['latitude'],
				Restaurant_Meta::LONGITUDE => $record['longitude'],
			) as $key => $new ) {
				$old = get_post_meta( $post_id, $key, true );
				if ( ! is_numeric( $old ) || round( (float) $old, 7 ) !== round( $new, 7 ) ) {
					$changes[ $key ] = array(
						'old' => is_numeric( $old ) ? (float) $old : null,
						'new' => $new,
					);
				}
			}
			if ( 'osm' !== $coord_source ) {
				$changes[ Restaurant_Meta::COORD_SOURCE ] = array(
					'old' => $coord_source,
					'new' => 'osm',
				);
			}
		}

		foreach ( self::META_FIELDS as $key ) {
			$new = $record['meta'][ $key ];
			if ( array() === $new || '' === $new ) {
				continue;
			}
			$old = get_post_meta( $post_id, $key, true );
			if ( Restaurant_Meta::HOURS === $key && is_array( $old ) && in_array( $old['source'] ?? '', array( 'owner', 'manual' ), true ) ) {
				continue;
			}
			if ( $old !== $new ) {
				$changes[ $key ] = array(
					'old' => $old,
					'new' => $new,
				);
			}
		}

		$villages = Term_Slugs::for_post( $post_id, Content_Types::TAX_VILLAGE );
		if ( null !== $village && array( $village ) !== $villages ) {
			$changes[ 'tax:' . Content_Types::TAX_VILLAGE ] = array(
				'old' => implode( ',', $villages ),
				'new' => $village,
			);
		}

		return $changes;
	}

	/**
	 * Writes changes found by diff().
	 *
	 * @param int                                          $post_id Post ID.
	 * @param array<string, array{old: mixed, new: mixed}> $changes Changes.
	 * @return void
	 * @throws \RuntimeException When the title update fails.
	 */
	private function apply( int $post_id, array $changes ): void {
		foreach ( $changes as $field => $change ) {
			if ( 'title' === $field ) {
				$result = wp_update_post(
					wp_slash(
						array(
							'ID'         => $post_id,
							'post_title' => $change['new'],
						)
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( esc_html( $result->get_error_message() ) );
				}
			} elseif ( 0 === strpos( $field, 'tax:' ) ) {
				$taxonomy = substr( $field, 4 );
				$slugs    = '' === $change['new'] ? array() : explode( ',', $change['new'] );
				wp_set_object_terms( $post_id, self::term_ids( $slugs, $taxonomy ), $taxonomy );
			} else {
				update_post_meta( $post_id, $field, $change['new'] );
			}
		}
	}

	/**
	 * The title as WordPress will store it (wp_insert_post() runs the title
	 * through the `db` sanitize filters, which may encode characters), so
	 * the next run compares like with like.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private static function stored_title( string $title ): string {
		return wp_unslash( sanitize_post_field( 'post_title', wp_slash( $title ), 0, 'db' ) );
	}

	/**
	 * Adds one village_unresolved item per restaurant while it is pending.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $record  Mapped element.
	 * @param int|null             $run_id  Ingestion run.
	 * @return void
	 */
	private static function queue_unresolved_village( int $post_id, array $record, ?int $run_id ): void {
		global $wpdb;

		$table   = Schema::table( Schema::REVIEW_QUEUE );
		$pending = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE restaurant_id = %d AND type = 'village_unresolved' AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			)
		);
		if ( (int) $pending > 0 ) {
			return;
		}

		Review_Queue::enqueue(
			'village_unresolved',
			array(
				'osm_id'       => $record['osm_id'],
				'latitude'     => $record['latitude'],
				'longitude'    => $record['longitude'],
				'coord_source' => 'osm',
			),
			'osm',
			$post_id,
			null,
			$run_id
		);
	}

	/**
	 * OSM id => post ID for every restaurant that has one, in any status
	 * (trashed ones too, so a trashed restaurant is not re-created).
	 *
	 * @return array<string, int>
	 */
	public static function existing_ids(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.meta_value AS osm_id, MIN(p.ID) AS post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'auto-draft' AND pm.meta_value <> ''
				GROUP BY pm.meta_value",
				Restaurant_Meta::OSM_ID,
				Content_Types::POST_TYPE
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['osm_id'] ] = (int) $row['post_id'];
		}
		return $map;
	}

	/**
	 * Mall terms, including unverified ones.
	 *
	 * @return array<int, array{slug: string, name: string, aliases: string[], osm_id: string}>
	 */
	public static function mall_terms(): array {
		$terms = get_terms(
			array(
				'taxonomy'                                => Content_Types::TAX_MALL,
				'hide_empty'                              => false,
				Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			$aliases = get_term_meta( $term->term_id, Term_Meta::ALIASES, true );
			$out[]   = array(
				'slug'    => $term->slug,
				'name'    => $term->name,
				'aliases' => is_array( $aliases ) ? $aliases : array(),
				'osm_id'  => (string) get_term_meta( $term->term_id, Term_Meta::OSM_ID, true ),
			);
		}
		return $out;
	}

	/**
	 * Term IDs for slugs; unknown slugs are an error (the cuisine map must
	 * only name seeded terms).
	 *
	 * @param string[] $slugs    Slugs.
	 * @param string   $taxonomy Taxonomy.
	 * @return int[]
	 * @throws \RuntimeException When a slug has no term.
	 */
	private static function term_ids( array $slugs, string $taxonomy ): array {
		$ids = array();
		foreach ( $slugs as $slug ) {
			// get_terms() rather than get_term_by(), so unverified malls are found too.
			$found = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'slug'       => $slug,
					'hide_empty' => false,
					'fields'     => 'ids',
					Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
				)
			);
			if ( is_wp_error( $found ) || ! $found ) {
				throw new \RuntimeException( esc_html( "No {$taxonomy} term with slug {$slug}; run `wp guam seed`." ) );
			}
			$ids[] = (int) $found[0];
		}
		return $ids;
	}

	/**
	 * Stores OSM's raw cuisine tag, so restaurants without a cuisine can be
	 * told apart: no tag in OSM, or a value the cuisine map does not cover.
	 * Not a fact shown on the site, so it is not in the change log or the
	 * updated count.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   Raw tag; '' when absent.
	 * @return void
	 */
	private static function save_osm_cuisine( int $post_id, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, Restaurant_Meta::OSM_CUISINE );
		} elseif ( get_post_meta( $post_id, Restaurant_Meta::OSM_CUISINE, true ) !== $value ) {
			update_post_meta( $post_id, Restaurant_Meta::OSM_CUISINE, $value );
		}
	}

	/**
	 * Ingestion run notes.
	 *
	 * @param array<string, mixed> $result Run result.
	 * @return string
	 */
	private static function notes( array $result ): string {
		$parts = array( 'import-osm' );
		foreach ( $result['skipped'] as $reason => $count ) {
			$parts[] = "skipped {$count} ({$reason})";
		}
		if ( $result['village_unresolved'] ) {
			$parts[] = count( $result['village_unresolved'] ) . ' outside every village';
		}
		if ( $result['unmapped'] ) {
			$values = array();
			foreach ( array_slice( $result['unmapped'], 0, 30, true ) as $value => $count ) {
				$values[] = "{$value} ({$count})";
			}
			$parts[] = 'unmapped cuisines: ' . implode( ', ', $values );
		}
		if ( $result['no_cuisine_tag'] ) {
			$parts[] = $result['no_cuisine_tag'] . ' with no OSM cuisine tag';
		}
		if ( $result['unmatched_malls'] ) {
			$parts[] = 'OSM malls with no term: ' . implode( ', ', array_filter( $result['unmatched_malls'] ) );
		}
		if ( $result['closures']['checked'] ) {
			$parts[] = sprintf( '%d missing from OSM (closure check), %d queued for closure approval', count( $result['closures']['closed'] ), count( $result['closures']['queued'] ) );
		} elseif ( '' !== $result['closures']['note'] ) {
			$parts[] = $result['closures']['note'];
		}
		return implode( '; ', $parts );
	}
}
