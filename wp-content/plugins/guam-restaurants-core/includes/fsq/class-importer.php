<?php
/**
 * Foursquare Open Source Places importer.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Fsq;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Ratings\Matcher;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Tagging\Cuisine_Tagger;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;
use GuamRestaurants\Core\Tagging\Mall_Tagger;
use GuamRestaurants\Core\Tagging\Tag_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Guam's eating and drinking places from Foursquare Open Source Places
 * (Apache 2.0), matching each one to the listings we already have first.
 *
 * For each place, in order:
 *
 * 1. Already linked (gr_foursquare_id): fill gaps on that listing.
 * 2. A listing scores as the same place (Duplicate_Detector's name, phone
 *    and distance score, at least LINK_CONFIDENCE with a clear lead over the
 *    next one): link it and fill gaps.
 * 3. Otherwise create a draft. When a listing scores at least
 *    Duplicate_Detector::REVIEW, or has a near-identical name within 150 m,
 *    the pair goes to the review queue as a `duplicate`, where an
 *    administrator merges them or marks them different.
 *
 * Filling gaps never overwrites: OpenStreetMap, owners and editors keep
 * their values, and Foursquare only adds an address, phone, website, email
 * or social link where the listing has none. Foursquare's cuisines are used
 * only for listings no other source gives a cuisine. Drafts are never
 * published automatically. A trashed listing is never re-created.
 */
final class Importer {

	public const SOURCE = 'fsq_os';

	public const CREATED   = 'created';
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';

	/**
	 * Score at which a place is linked to an existing listing without review.
	 */
	public const LINK_CONFIDENCE = 0.8;

	/**
	 * Name similarity a link also needs, so a shared phone number (a hotel's
	 * switchboard, say) is not enough on its own.
	 */
	public const LINK_NAME = 0.75;

	/**
	 * How far the best listing must lead the next to count as clear.
	 */
	public const MARGIN = 0.1;

	/**
	 * Listings farther than this are not compared unless the phone matches.
	 */
	public const CANDIDATE_DISTANCE_M = 300;

	/**
	 * Fields filled on a listing that has no value, logged in the change log
	 * when they are facts shown on the site.
	 */
	private const FILL_FIELDS = array(
		Restaurant_Meta::ADDRESS   => true,
		Restaurant_Meta::PHONE     => true,
		Restaurant_Meta::WEBSITE   => true,
		Restaurant_Meta::EMAIL     => false,
		Restaurant_Meta::FACEBOOK  => false,
		Restaurant_Meta::INSTAGRAM => false,
	);

	/**
	 * Row mapper.
	 *
	 * @var Place_Mapper
	 */
	private Place_Mapper $mapper;

	/**
	 * Village locator.
	 *
	 * @var Village_Locator
	 */
	private Village_Locator $villages;

	/**
	 * Cuisine tagger.
	 *
	 * @var Cuisine_Tagger
	 */
	private Cuisine_Tagger $cuisines;

	/**
	 * Rows last refreshed before this date (Y-m-d) are skipped.
	 *
	 * @var string
	 */
	private string $stale_before;

	/**
	 * Mall tagger for the current run.
	 *
	 * @var Mall_Tagger|null
	 */
	private ?Mall_Tagger $mall_tagger = null;

	/**
	 * Constructor.
	 *
	 * @param Place_Mapper         $mapper       Mapper.
	 * @param string               $stale_before Skip rows last refreshed before this date (Y-m-d); '' keeps all.
	 * @param Village_Locator|null $villages     Village locator; defaults to the bundled boundaries.
	 * @param Cuisine_Tagger|null  $cuisines     Cuisine tagger; defaults to data/cuisine-rules.json.
	 */
	public function __construct( Place_Mapper $mapper, string $stale_before = '', ?Village_Locator $villages = null, ?Cuisine_Tagger $cuisines = null ) {
		$this->mapper       = $mapper;
		$this->stale_before = $stale_before;
		$this->villages     = $villages ?? Village_Locator::instance();
		$this->cuisines     = $cuisines ?? Cuisine_Tagger::from_file();
	}

	/**
	 * Imports rows from the Guam extract.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param bool                             $dry_run Report without writing (no ingestion run is logged).
	 * @return array{counts: array<string, int>, linked: int, queued: int, skipped: array<string, int>, unmapped: array<string, int>, errors: string[], run_id: int|null}
	 */
	public function run( array $rows, bool $dry_run = false ): array {
		return Mall_Verification::with_unverified(
			function () use ( $rows, $dry_run ) {
				return Change_Tracker::suppress(
					function () use ( $rows, $dry_run ) {
						return $this->import( $rows, $dry_run );
					}
				);
			}
		);
	}

	/**
	 * Does the work of run().
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param bool                             $dry_run Dry run.
	 * @return array<string, mixed>
	 */
	private function import( array $rows, bool $dry_run ): array {
		$result = array(
			'counts'   => array_fill_keys( array( self::CREATED, self::UPDATED, self::UNCHANGED, self::FAILED ), 0 ),
			'linked'   => 0,
			'queued'   => 0,
			'skipped'  => array(),
			'unmapped' => array(),
			'errors'   => array(),
			'run_id'   => null,
		);

		$this->mall_tagger = Mall_Tagger::from_saved();

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( self::SOURCE );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;
		$baseline         = null !== $run_id && ! Ingestion_Runs::has_previous( self::SOURCE, $run_id );
		$existing         = self::existing_ids();
		$listings         = self::unlinked_listings();

		foreach ( $rows as $row ) {
			$reason = Place_Mapper::skip_reason( $row, $this->stale_before );
			if ( null !== $reason ) {
				$result['skipped'][ $reason ] = ( $result['skipped'][ $reason ] ?? 0 ) + 1;
				continue;
			}
			$record = $this->mapper->map( $row );
			foreach ( $record['unmapped'] as $value ) {
				$result['unmapped'][ $value ] = ( $result['unmapped'][ $value ] ?? 0 ) + 1;
			}

			try {
				if ( isset( $existing[ $record['fsq_id'] ] ) ) {
					$changed = $this->fill( $existing[ $record['fsq_id'] ], $record, $run_id, $dry_run );
					++$result['counts'][ $changed ? self::UPDATED : self::UNCHANGED ];
					continue;
				}

				$best = self::best_match( $record, $listings );
				if ( null !== $best && self::is_link( $best ) ) {
					$post_id = $best['id'];
					unset( $listings[ $post_id ] );
					if ( ! $dry_run ) {
						update_post_meta( $post_id, Restaurant_Meta::FOURSQUARE_ID, $record['fsq_id'] );
						update_post_meta( $post_id, Restaurant_Meta::FOURSQUARE_MATCH_CONFIDENCE, $best['score']['confidence'] );
					}
					$existing[ $record['fsq_id'] ] = $post_id;
					$this->fill( $post_id, $record, $run_id, $dry_run );
					++$result['linked'];
					++$result['counts'][ self::UPDATED ];
					continue;
				}

				$post_id = $dry_run ? 0 : $this->create( $record, $run_id, $baseline );
				if ( $post_id ) {
					$existing[ $record['fsq_id'] ] = $post_id;
				}
				if ( null !== $best && self::needs_review( $best ) ) {
					if ( ! $dry_run ) {
						self::queue_duplicate( $post_id, $best, $run_id );
					}
					++$result['queued'];
				}
				++$result['counts'][ self::CREATED ];
			} catch ( \Throwable $e ) {
				++$result['counts'][ self::FAILED ];
				$result['errors'][] = $record['fsq_id'] . ': ' . $e->getMessage();
			}
		}

		arsort( $result['unmapped'] );

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], self::notes( $result ), $result['errors'] );
		}

		return $result;
	}

	/**
	 * The listing that best matches a place, with its score.
	 *
	 * @param array<string, mixed>             $record   Mapped place.
	 * @param array<int, array<string, mixed>> $listings Listings not yet linked to Foursquare.
	 * @return array{id: int, score: array<string, mixed>, lead: float}|null
	 */
	public static function best_match( array $record, array $listings ): ?array {
		$place = array(
			'name'  => $record['title'],
			'phone' => $record['meta'][ Restaurant_Meta::PHONE ],
			'lat'   => $record['latitude'],
			'lng'   => $record['longitude'],
		);

		$scored = array();
		foreach ( $listings as $listing ) {
			if ( ! self::is_candidate( $place, $listing ) ) {
				continue;
			}
			$scored[] = array(
				'id'    => (int) $listing['id'],
				'score' => Duplicate_Detector::score( $place, $listing ),
			);
		}
		if ( ! $scored ) {
			return null;
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return $b['score']['confidence'] <=> $a['score']['confidence'];
			}
		);
		$best         = $scored[0];
		$best['lead'] = round( $best['score']['confidence'] - ( $scored[1]['score']['confidence'] ?? 0.0 ), 3 );
		return $best;
	}

	/**
	 * Whether a listing is worth scoring: the same phone, within
	 * CANDIDATE_DISTANCE_M, or (with no coordinates) a near-identical name.
	 *
	 * @param array<string, mixed> $place   Place.
	 * @param array<string, mixed> $listing Listing.
	 * @return bool
	 */
	private static function is_candidate( array $place, array $listing ): bool {
		if ( '' !== $place['phone'] && $place['phone'] === $listing['phone'] ) {
			return true;
		}
		if ( null !== $listing['lat'] ) {
			return Matcher::distance_m( $place['lat'], $place['lng'], $listing['lat'], $listing['lng'] ) <= self::CANDIDATE_DISTANCE_M;
		}
		return Matcher::name_similarity( $place['name'], $listing['name'] ) >= Duplicate_Detector::CANDIDATE_NAME;
	}

	/**
	 * Whether a match is good enough to link without review.
	 *
	 * @param array{id: int, score: array<string, mixed>, lead: float} $best Best match.
	 * @return bool
	 */
	public static function is_link( array $best ): bool {
		return $best['score']['confidence'] >= self::LINK_CONFIDENCE
			&& $best['score']['name_similarity'] >= self::LINK_NAME
			&& $best['lead'] >= self::MARGIN;
	}

	/**
	 * Whether a new draft should be checked against its closest listing: the
	 * duplicate review score, or a near-identical name close by (a changed
	 * phone number should not hide a duplicate).
	 *
	 * @param array{id: int, score: array<string, mixed>, lead: float} $best Best match.
	 * @return bool
	 */
	public static function needs_review( array $best ): bool {
		return $best['score']['confidence'] >= Duplicate_Detector::REVIEW
			|| ( $best['score']['name_similarity'] >= Duplicate_Detector::CANDIDATE_NAME && null !== $best['score']['distance_m'] && $best['score']['distance_m'] <= Duplicate_Detector::RADIUS_M );
	}

	/**
	 * Adds what a listing is missing. Never overwrites a value.
	 *
	 * @param int                  $post_id Listing.
	 * @param array<string, mixed> $record  Mapped place.
	 * @param int|null             $run_id  Ingestion run.
	 * @param bool                 $dry_run Dry run.
	 * @return bool Whether anything was (or would be) added.
	 */
	private function fill( int $post_id, array $record, ?int $run_id, bool $dry_run ): bool {
		if ( ! $post_id || 'trash' === get_post_status( $post_id ) ) {
			return false;
		}

		$changed = false;
		foreach ( self::FILL_FIELDS as $key => $logged ) {
			$new = $record['meta'][ $key ];
			if ( '' === $new || '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				continue;
			}
			// A website field holding the Facebook page already covers that link.
			if ( Restaurant_Meta::WEBSITE !== $key && null !== Restaurant_Meta::social_network( $new ) && (string) get_post_meta( $post_id, Restaurant_Meta::WEBSITE, true ) === $new ) {
				continue;
			}
			$changed = true;
			if ( $dry_run ) {
				continue;
			}
			update_post_meta( $post_id, $key, $new );
			if ( $logged ) {
				Change_Log::record( $post_id, $key, '', $new, self::SOURCE, $run_id );
			}
		}

		if ( $record['cuisine_details'] && ! self::has_other_cuisine_source( $post_id ) ) {
			$cuisine = $this->cuisines->apply( $post_id, self::SOURCE, $record['cuisine_details'], $dry_run );
			if ( $cuisine['old'] !== $cuisine['new'] ) {
				$changed = true;
				if ( ! $dry_run ) {
					Change_Log::record( $post_id, 'tax:' . Content_Types::TAX_CUISINE, implode( ',', $cuisine['old'] ), implode( ',', $cuisine['new'] ), self::SOURCE, $run_id );
				}
			}
		}

		return $changed;
	}

	/**
	 * Whether a source other than Foursquare gives the listing a cuisine.
	 *
	 * @param int $post_id Listing.
	 * @return bool
	 */
	private static function has_other_cuisine_source( int $post_id ): bool {
		foreach ( Tag_Store::cuisine_sources( $post_id ) as $source => $slugs ) {
			if ( self::SOURCE !== $source && $slugs ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Creates a draft listing.
	 *
	 * @param array<string, mixed> $record   Mapped place.
	 * @param int|null             $run_id   Ingestion run.
	 * @param bool                 $baseline Whether this is Foursquare's first run.
	 * @return int Post ID.
	 * @throws \RuntimeException When the post cannot be created.
	 */
	private function create( array $record, ?int $run_id, bool $baseline ): int {
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => Content_Types::POST_TYPE,
					'post_status' => 'draft',
					'post_title'  => $record['title'],
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, Restaurant_Meta::FOURSQUARE_ID, $record['fsq_id'] );
		update_post_meta( $post_id, Restaurant_Meta::LATITUDE, $record['latitude'] );
		update_post_meta( $post_id, Restaurant_Meta::LONGITUDE, $record['longitude'] );
		update_post_meta( $post_id, Restaurant_Meta::COORD_SOURCE, self::SOURCE );
		foreach ( $record['meta'] as $key => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}
		Change_Tracker::record_first_seen( $post_id, self::SOURCE, $run_id, $baseline, $record['fsq_id'] );

		if ( $record['cuisine_details'] ) {
			$this->cuisines->apply( $post_id, self::SOURCE, $record['cuisine_details'] );
		} else {
			$this->cuisines->apply( $post_id );
		}

		$village = $this->villages->slug_for( $record['latitude'], $record['longitude'] );
		if ( null !== $village ) {
			wp_set_object_terms( $post_id, $village, Content_Types::TAX_VILLAGE );
		} else {
			Review_Queue::enqueue(
				'village_unresolved',
				array(
					'fsq_id'       => $record['fsq_id'],
					'latitude'     => $record['latitude'],
					'longitude'    => $record['longitude'],
					'coord_source' => self::SOURCE,
				),
				self::SOURCE,
				$post_id,
				null,
				$run_id
			);
		}
		$this->mall_tagger->apply( $post_id, $record['latitude'], $record['longitude'] );

		return (int) $post_id;
	}

	/**
	 * Asks an administrator whether a new draft is a listing we already have.
	 *
	 * @param int                                                      $post_id New draft.
	 * @param array{id: int, score: array<string, mixed>, lead: float} $best    Closest listing.
	 * @param int|null                                                 $run_id  Ingestion run.
	 * @return void
	 */
	private static function queue_duplicate( int $post_id, array $best, ?int $run_id ): void {
		Review_Queue::enqueue(
			'duplicate',
			array(
				'duplicate_of'    => $best['id'],
				'name_similarity' => $best['score']['name_similarity'],
				'phone_match'     => $best['score']['phone_match'],
				'distance_m'      => $best['score']['distance_m'],
			),
			self::SOURCE,
			$post_id,
			$best['score']['confidence'],
			$run_id
		);
	}

	/**
	 * Foursquare ID => listing, in any status (trashed ones too, so a
	 * trashed listing is not re-created).
	 *
	 * @return array<string, int>
	 */
	public static function existing_ids(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT pm.meta_value AS fsq_id, MIN(p.ID) AS post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'auto-draft' AND pm.meta_value <> ''
				GROUP BY pm.meta_value",
				Restaurant_Meta::FOURSQUARE_ID,
				Content_Types::POST_TYPE
			),
			ARRAY_A
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row['fsq_id'] ] = (int) $row['post_id'];
		}
		return $map;
	}

	/**
	 * Listings a place may match: not trashed, not permanently closed, and
	 * not already linked to a Foursquare place.
	 *
	 * @return array<int, array{id: int, name: string, phone: string, lat: float|null, lng: float|null}>
	 */
	private static function unlinked_listings(): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( '' !== (string) get_post_meta( $id, Restaurant_Meta::FOURSQUARE_ID, true ) || 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
				continue;
			}
			$lat        = (float) get_post_meta( $id, Restaurant_Meta::LATITUDE, true );
			$lng        = (float) get_post_meta( $id, Restaurant_Meta::LONGITUDE, true );
			$has_point  = ! ( 0.0 === $lat && 0.0 === $lng );
			$out[ $id ] = array(
				'id'    => $id,
				'name'  => (string) get_post_field( 'post_title', $id, 'raw' ),
				'phone' => (string) get_post_meta( $id, Restaurant_Meta::PHONE, true ),
				'lat'   => $has_point ? $lat : null,
				'lng'   => $has_point ? $lng : null,
			);
		}
		return $out;
	}

	/**
	 * Ingestion run notes.
	 *
	 * @param array<string, mixed> $result Run result.
	 * @return string
	 */
	private static function notes( array $result ): string {
		$parts = array( 'import-fsq' );
		if ( $result['linked'] ) {
			$parts[] = $result['linked'] . ' matched to existing listings';
		}
		if ( $result['queued'] ) {
			$parts[] = $result['queued'] . ' new drafts queued as possible duplicates';
		}
		foreach ( $result['skipped'] as $reason => $count ) {
			$parts[] = "skipped {$count} ({$reason})";
		}
		if ( $result['unmapped'] ) {
			$values = array();
			foreach ( array_slice( $result['unmapped'], 0, 30, true ) as $value => $count ) {
				$values[] = "{$value} ({$count})";
			}
			$parts[] = 'unmapped categories: ' . implode( ', ', $values );
		}
		return implode( '; ', $parts );
	}
}
