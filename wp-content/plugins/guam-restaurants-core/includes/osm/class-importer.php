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
use GuamRestaurants\Core\Restaurant_Meta;
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
 * - Cuisines from OSM are added; cuisines an editor added are kept.
 * - The village always follows the coordinates (Census boundaries). A point
 *   outside every village goes to the review queue once.
 * - A mall is set only when the restaurant has none.
 * - Post status is set only on creation, so trashed or unpublished
 *   restaurants stay that way.
 *
 * Every change to an existing restaurant is written to the change log.
 */
final class Importer {

	public const CREATED   = 'created';
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';

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
	 * Constructor.
	 *
	 * @param Element_Mapper       $mapper      Mapper.
	 * @param Village_Locator|null $villages    Village locator; defaults to the bundled boundaries.
	 * @param float                $mall_radius Metres around a mall point (malls without an outline).
	 * @param string               $post_status Status for new restaurants.
	 */
	public function __construct( Element_Mapper $mapper, ?Village_Locator $villages = null, float $mall_radius = 150.0, string $post_status = 'publish' ) {
		$this->mapper      = $mapper;
		$this->villages    = $villages ?? Village_Locator::instance();
		$this->mall_radius = $mall_radius;
		$this->post_status = $post_status;
	}

	/**
	 * Imports a set of Overpass elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @param bool                             $dry_run  Report without writing (no ingestion run is logged).
	 * @return array{counts: array<string, int>, skipped: array<string, int>, unmapped: array<string, int>, unmatched_malls: array<string, string>, village_unresolved: string[], errors: string[], run_id: int|null}
	 */
	public function run( array $elements, bool $dry_run = false ): array {
		return Mall_Verification::with_unverified(
			function () use ( $elements, $dry_run ) {
				return $this->import( $elements, $dry_run );
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
			'unmatched_malls'    => array(),
			'village_unresolved' => array(),
			'errors'             => array(),
			'run_id'             => null,
		);

		$mall_locator              = new Mall_Locator( $malls, self::mall_terms(), $this->mall_radius );
		$result['unmatched_malls'] = $mall_locator->unmatched();

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( 'osm' );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;
		$existing         = self::existing_ids();

		foreach ( $restaurants as $element ) {
			$reason = Element_Mapper::skip_reason( $element );
			if ( null !== $reason ) {
				$result['skipped'][ $reason ] = ( $result['skipped'][ $reason ] ?? 0 ) + 1;
				continue;
			}

			$record = $this->mapper->map( $element );
			foreach ( $record['unmapped'] as $value ) {
				$result['unmapped'][ $value ] = ( $result['unmapped'][ $value ] ?? 0 ) + 1;
			}

			try {
				$post_id = $existing[ $record['osm_id'] ] ?? 0;
				$outcome = $this->upsert( $record, $post_id, $mall_locator, $run_id, $dry_run, $result['village_unresolved'] );
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

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], self::notes( $result ) );
		}

		return $result;
	}

	/**
	 * Creates or updates one restaurant.
	 *
	 * @param array<string, mixed> $record             Mapped element.
	 * @param int                  $post_id            Existing post, or 0.
	 * @param Mall_Locator         $malls              Mall locator.
	 * @param int|null             $run_id             Ingestion run.
	 * @param bool                 $dry_run            Dry run.
	 * @param string[]             $village_unresolved OSM ids with no village, appended to.
	 * @return array{status: string, post_id: int}
	 * @throws \RuntimeException When WordPress refuses a write.
	 */
	private function upsert( array $record, int $post_id, Mall_Locator $malls, ?int $run_id, bool $dry_run, array &$village_unresolved ): array {
		list( $lat, $lng ) = self::effective_point( $post_id, $record );

		$village = $this->villages->slug_for( $lat, $lng );
		$mall    = $malls->slug_for( $lat, $lng );
		if ( null === $village ) {
			$village_unresolved[] = $record['osm_id'];
		}

		if ( ! $post_id ) {
			if ( ! $dry_run ) {
				$post_id = $this->create( $record, $village, $mall, $run_id );
			}
			return array(
				'status'  => self::CREATED,
				'post_id' => $post_id,
			);
		}

		$changes = $this->diff( $post_id, $record, $village, $mall );

		if ( null === $village && ! $dry_run ) {
			self::queue_unresolved_village( $post_id, $record, $run_id );
		}

		if ( ! $changes ) {
			return array(
				'status'  => self::UNCHANGED,
				'post_id' => $post_id,
			);
		}

		if ( ! $dry_run ) {
			$this->apply( $post_id, $changes );
			foreach ( $changes as $field => $change ) {
				$source = 'tax:' . Content_Types::TAX_VILLAGE === $field ? 'census' : 'osm';
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
	 * @param string|null          $mall    Mall slug.
	 * @param int|null             $run_id  Ingestion run.
	 * @return int Post ID.
	 * @throws \RuntimeException When the post cannot be created.
	 */
	private function create( array $record, ?string $village, ?string $mall, ?int $run_id ): int {
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
		foreach ( $record['meta'] as $key => $value ) {
			if ( array() !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		if ( $record['cuisines'] ) {
			wp_set_object_terms( $post_id, self::term_ids( $record['cuisines'], Content_Types::TAX_CUISINE ), Content_Types::TAX_CUISINE );
		}
		if ( $village ) {
			wp_set_object_terms( $post_id, self::term_ids( array( $village ), Content_Types::TAX_VILLAGE ), Content_Types::TAX_VILLAGE );
		} else {
			self::queue_unresolved_village( $post_id, $record, $run_id );
		}
		if ( $mall ) {
			wp_set_object_terms( $post_id, self::term_ids( array( $mall ), Content_Types::TAX_MALL ), Content_Types::TAX_MALL );
		}

		return (int) $post_id;
	}

	/**
	 * Fields that would change on an existing restaurant.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $record  Mapped element.
	 * @param string|null          $village Village slug from the coordinates.
	 * @param string|null          $mall    Mall slug.
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private function diff( int $post_id, array $record, ?string $village, ?string $mall ): array {
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

		$cuisines = self::slugs( $post_id, Content_Types::TAX_CUISINE );
		$merged   = array_values( array_unique( array_merge( $cuisines, $record['cuisines'] ) ) );
		if ( count( $merged ) !== count( $cuisines ) ) {
			sort( $merged );
			$changes[ 'tax:' . Content_Types::TAX_CUISINE ] = array(
				'old' => implode( ',', $cuisines ),
				'new' => implode( ',', $merged ),
			);
		}

		$villages = self::slugs( $post_id, Content_Types::TAX_VILLAGE );
		if ( null !== $village && array( $village ) !== $villages ) {
			$changes[ 'tax:' . Content_Types::TAX_VILLAGE ] = array(
				'old' => implode( ',', $villages ),
				'new' => $village,
			);
		}

		if ( null !== $mall && ! self::slugs( $post_id, Content_Types::TAX_MALL ) ) {
			$changes[ 'tax:' . Content_Types::TAX_MALL ] = array(
				'old' => '',
				'new' => $mall,
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
	 * Term slugs on a post, sorted.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	private static function slugs( int $post_id, string $taxonomy ): array {
		$slugs = wp_get_object_terms(
			$post_id,
			$taxonomy,
			array(
				'fields'                                  => 'slugs',
				Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
			)
		);
		$slugs = is_wp_error( $slugs ) ? array() : $slugs;
		sort( $slugs );
		return $slugs;
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
		if ( $result['unmatched_malls'] ) {
			$parts[] = 'OSM malls with no term: ' . implode( ', ', array_filter( $result['unmatched_malls'] ) );
		}
		return implode( '; ', $parts );
	}
}
