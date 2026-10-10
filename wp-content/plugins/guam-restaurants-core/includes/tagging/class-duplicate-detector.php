<?php
/**
 * Finds restaurants that are probably the same place.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tagging;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Ratings\Matcher;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Compares restaurants (from OSM, award imports, owners and editors) by
 * normalized name, phone and distance.
 *
 * Confidence = 0.55 x name similarity + 0.25 x phone + 0.20 x proximity,
 * where phone is 1 for the same number, 0 for different numbers and 0.5
 * when either is missing, and proximity falls from 1 at the same point to 0
 * at 150 m (0.5 when either has no coordinates).
 *
 * A pair is merged automatically only when confidence is at least 0.92 and
 * the names are near-identical (0.9), the phone numbers match and the two
 * are within 50 m. Pairs from 0.6 go to the review queue as `duplicate`.
 * Pairs a person marked "not the same place" are never queued again.
 */
final class Duplicate_Detector {

	public const AUTO_MERGE      = 0.92;
	public const REVIEW          = 0.6;
	public const AUTO_NAME       = 0.9;
	public const AUTO_DISTANCE_M = 50;
	public const RADIUS_M        = 150;

	/**
	 * Pairs are only compared when this close, when their phones match, or
	 * when one has no coordinates and the names are this similar.
	 */
	public const CANDIDATE_DISTANCE_M = 300;
	public const CANDIDATE_NAME       = 0.85;

	public const NAME_WEIGHT  = 0.55;
	public const PHONE_WEIGHT = 0.25;
	public const NEAR_WEIGHT  = 0.20;

	/**
	 * Restaurant IDs a person said are not duplicates of this one.
	 */
	public const NOT_DUPLICATE_META = '_gr_not_duplicate_of';

	public const MERGED    = 'merged';
	public const QUEUED    = 'queued';
	public const UNCHANGED = 'unchanged';

	/**
	 * Merger.
	 *
	 * @var Merger
	 */
	private Merger $merger;

	/**
	 * Constructor.
	 *
	 * @param Merger|null $merger Merger.
	 */
	public function __construct( ?Merger $merger = null ) {
		$this->merger = $merger ?? new Merger();
	}

	/**
	 * Scores two restaurants.
	 *
	 * @param array{name: string, phone: string, lat: float|null, lng: float|null} $a Restaurant.
	 * @param array{name: string, phone: string, lat: float|null, lng: float|null} $b Restaurant.
	 * @return array{confidence: float, name_similarity: float, phone_match: bool|null, distance_m: int|null}
	 */
	public static function score( array $a, array $b ): array {
		$name = Matcher::name_similarity( $a['name'], $b['name'] );

		$phone_match = null;
		$phone       = 0.5;
		if ( '' !== $a['phone'] && '' !== $b['phone'] ) {
			$phone_match = $a['phone'] === $b['phone'];
			$phone       = $phone_match ? 1.0 : 0.0;
		}

		$distance = null;
		$near     = 0.5;
		if ( null !== $a['lat'] && null !== $b['lat'] ) {
			$distance = Matcher::distance_m( $a['lat'], $a['lng'], $b['lat'], $b['lng'] );
			$near     = max( 0.0, 1 - $distance / self::RADIUS_M );
		}

		return array(
			'confidence'      => round( self::NAME_WEIGHT * $name + self::PHONE_WEIGHT * $phone + self::NEAR_WEIGHT * $near, 3 ),
			'name_similarity' => round( $name, 3 ),
			'phone_match'     => $phone_match,
			'distance_m'      => null === $distance ? null : (int) round( $distance ),
		);
	}

	/**
	 * Whether a scored pair may be merged without a person.
	 *
	 * @param array{confidence: float, name_similarity: float, phone_match: bool|null, distance_m: int|null} $score Score.
	 * @return bool
	 */
	public static function is_auto_merge( array $score ): bool {
		return $score['confidence'] >= self::AUTO_MERGE
			&& $score['name_similarity'] >= self::AUTO_NAME
			&& true === $score['phone_match']
			&& null !== $score['distance_m'] && $score['distance_m'] <= self::AUTO_DISTANCE_M;
	}

	/**
	 * Finds duplicates among all restaurants, merging or queueing them.
	 *
	 * @param bool     $dry_run Report only.
	 * @param int|null $run_id  Log under this run (for example the OSM import); otherwise a `duplicates` run is logged.
	 * @return array{counts: array<string, int>, pairs: array<int, array<string, mixed>>, run_id: int|null}
	 */
	public function run( bool $dry_run = false, ?int $run_id = null ): array {
		$own_run = null === $run_id && ! $dry_run;
		if ( $own_run ) {
			$started = Ingestion_Runs::start( 'duplicates' );
			$run_id  = $started > 0 ? $started : null;
		}

		$result = array(
			'counts' => array(
				self::MERGED => 0,
				self::QUEUED => 0,
				'failed'     => 0,
			),
			'pairs'  => array(),
			'run_id' => $run_id,
		);
		$errors = array();

		$restaurants = self::restaurants();
		$merged      = array();
		$queued      = self::queued_pairs();
		$ids         = array_keys( $restaurants );
		$count       = count( $ids );

		for ( $i = 0; $i < $count; $i++ ) {
			$a = $restaurants[ $ids[ $i ] ];
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$b = $restaurants[ $ids[ $j ] ];
				if ( isset( $merged[ $a['id'] ] ) || isset( $merged[ $b['id'] ] ) || ! self::is_candidate( $a, $b ) ) {
					continue;
				}
				if ( in_array( $b['id'], $a['not_duplicate'], true ) || in_array( $a['id'], $b['not_duplicate'], true ) ) {
					continue;
				}

				$score = self::score( $a, $b );
				if ( $score['confidence'] < self::REVIEW ) {
					continue;
				}

				$pair_key = self::pair_key( $a['id'], $b['id'] );
				$auto     = self::is_auto_merge( $score );
				$pair     = $score + array(
					'ids'    => array( $a['id'], $b['id'] ),
					'names'  => array( $a['name'], $b['name'] ),
					'action' => $auto ? self::MERGED : self::QUEUED,
				);

				if ( ! $auto && isset( $queued[ $pair_key ] ) ) {
					continue;
				}
				$result['pairs'][] = $pair;
				++$result['counts'][ $pair['action'] ];
				if ( $dry_run ) {
					continue;
				}

				if ( $auto ) {
					try {
						$merge                         = $this->merger->merge( $a['id'], $b['id'], 'system', $run_id, $score );
						$merged[ $merge['merged_id'] ] = true;
					} catch ( \Throwable $e ) {
						--$result['counts'][ self::MERGED ];
						++$result['counts']['failed'];
						$errors[] = sprintf( '#%d + #%d: %s', $a['id'], $b['id'], $e->getMessage() );
					}
					continue;
				}

				Review_Queue::enqueue(
					'duplicate',
					array(
						'duplicate_of'    => $b['id'],
						'name_similarity' => $score['name_similarity'],
						'phone_match'     => $score['phone_match'],
						'distance_m'      => $score['distance_m'],
					),
					'system',
					$a['id'],
					$score['confidence'],
					$run_id
				);
				$queued[ $pair_key ] = true;
			}
		}

		if ( $own_run && $run_id ) {
			Ingestion_Runs::finish(
				$run_id,
				array(
					'updated'   => $result['counts'][ self::MERGED ],
					'unchanged' => max( 0, count( $restaurants ) - 2 * $result['counts'][ self::MERGED ] ),
					'failed'    => $result['counts']['failed'],
				),
				self::summary( $result['counts'] ),
				$errors
			);
		}

		return $result;
	}

	/**
	 * One-line summary for run notes.
	 *
	 * @param array<string, int> $counts Counts.
	 * @return string
	 */
	public static function summary( array $counts ): string {
		return sprintf( 'duplicates: %d merged automatically, %d queued for review', $counts[ self::MERGED ], $counts[ self::QUEUED ] );
	}

	/**
	 * Whether two restaurants are worth scoring.
	 *
	 * @param array<string, mixed> $a Restaurant.
	 * @param array<string, mixed> $b Restaurant.
	 * @return bool
	 */
	private static function is_candidate( array $a, array $b ): bool {
		if ( '' !== $a['phone'] && $a['phone'] === $b['phone'] ) {
			return true;
		}
		if ( null !== $a['lat'] && null !== $b['lat'] ) {
			return Matcher::distance_m( $a['lat'], $a['lng'], $b['lat'], $b['lng'] ) <= self::CANDIDATE_DISTANCE_M;
		}
		return $a['key'] === $b['key'] || Matcher::name_similarity( $a['name'], $b['name'] ) >= self::CANDIDATE_NAME;
	}

	/**
	 * Restaurants to compare: not trashed, not permanently closed.
	 *
	 * @return array<int, array{id: int, name: string, key: string, phone: string, lat: float|null, lng: float|null, not_duplicate: int[]}>
	 */
	private static function restaurants(): array {
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
			if ( 'permanently_closed' === get_post_meta( $id, Restaurant_Meta::STATUS, true ) ) {
				continue;
			}
			$lat        = (float) get_post_meta( $id, Restaurant_Meta::LATITUDE, true );
			$lng        = (float) get_post_meta( $id, Restaurant_Meta::LONGITUDE, true );
			$has_point  = ! ( 0.0 === $lat && 0.0 === $lng );
			$name       = (string) get_post_field( 'post_title', $id, 'raw' );
			$out[ $id ] = array(
				'id'            => $id,
				'name'          => $name,
				'key'           => Names::key( $name ),
				'phone'         => (string) get_post_meta( $id, Restaurant_Meta::PHONE, true ),
				'lat'           => $has_point ? $lat : null,
				'lng'           => $has_point ? $lng : null,
				'not_duplicate' => array_map( 'intval', get_post_meta( $id, self::NOT_DUPLICATE_META, false ) ),
			);
		}
		return $out;
	}

	/**
	 * Pairs with a pending duplicate item.
	 *
	 * @return array<string, bool>
	 */
	private static function queued_pairs(): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT restaurant_id, payload FROM {$table} WHERE type = %s AND status = %s", 'duplicate', 'pending' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pairs = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( isset( $payload['duplicate_of'] ) ) {
				$pairs[ self::pair_key( (int) $row['restaurant_id'], (int) $payload['duplicate_of'] ) ] = true;
			}
		}
		return $pairs;
	}

	/**
	 * Order-independent key for a pair.
	 *
	 * @param int $a ID.
	 * @param int $b ID.
	 * @return string
	 */
	private static function pair_key( int $a, int $b ): string {
		return min( $a, $b ) . ':' . max( $a, $b );
	}

	/**
	 * Records that two restaurants are different places (rejecting a duplicate item).
	 *
	 * @param int $a Restaurant.
	 * @param int $b Restaurant.
	 * @return void
	 */
	public static function mark_not_duplicate( int $a, int $b ): void {
		Change_Tracker::suppress(
			static function () use ( $a, $b ) {
				if ( ! in_array( (string) $b, get_post_meta( $a, self::NOT_DUPLICATE_META, false ), true ) ) {
					add_post_meta( $a, self::NOT_DUPLICATE_META, $b );
				}
				if ( ! in_array( (string) $a, get_post_meta( $b, self::NOT_DUPLICATE_META, false ), true ) ) {
					add_post_meta( $b, self::NOT_DUPLICATE_META, $a );
				}
			}
		);
	}
}
