<?php
/**
 * Matches restaurants to their Google, Yelp and Foursquare records.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Searches each source near a restaurant's OSM coordinates and scores the
 * candidates by name similarity and distance (within 150 metres).
 *
 * A confident, unambiguous match stores the source's ID and the confidence
 * on the restaurant. A weaker or ambiguous one goes to the review queue as
 * `match_conflict`. Names and coordinates from the source are compared in
 * memory only; the queue payload holds the source ID and our own scores.
 */
final class Matcher {

	public const CREATED   = 'created';
	public const UPDATED   = 'updated';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';
	public const QUEUED    = 'queued';
	public const NO_MATCH  = 'no_match';
	public const SKIPPED   = 'skipped';

	public const RADIUS = 150;

	/**
	 * Confidence at or above which a clear match is stored without review.
	 */
	public const AUTO_ACCEPT = 0.8;

	/**
	 * Confidence at or above which a candidate is sent to review.
	 */
	public const REVIEW = 0.5;

	/**
	 * How far the best candidate must lead the next one to count as clear.
	 */
	public const MARGIN = 0.1;

	/**
	 * Weight of the name score; distance gets the rest.
	 */
	public const NAME_WEIGHT = 0.7;

	/**
	 * Provider.
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Run budget.
	 *
	 * @var Budget
	 */
	private Budget $budget;

	/**
	 * Waits between requests.
	 *
	 * @var callable(int): void
	 */
	private $sleeper;

	/**
	 * Seconds between requests.
	 *
	 * @var int
	 */
	private int $delay;

	/**
	 * Constructor.
	 *
	 * @param Provider      $provider Provider.
	 * @param Budget        $budget   Run budget; the run stops before passing it.
	 * @param int           $delay    Seconds between requests.
	 * @param callable|null $sleeper  Defaults to sleep().
	 */
	public function __construct( Provider $provider, Budget $budget, int $delay = 1, ?callable $sleeper = null ) {
		$this->provider = $provider;
		$this->budget   = $budget;
		$this->delay    = max( 0, $delay );
		$this->sleeper  = $sleeper ?? 'sleep';
	}

	/**
	 * Restaurants that a run would search: not trashed, with coordinates,
	 * without an ID for this source (unless rematching) and without a
	 * pending review item for this source.
	 *
	 * @param Provider $provider Provider.
	 * @param bool     $rematch  Include restaurants that already have an ID.
	 * @param int      $limit    Maximum count; 0 for all.
	 * @return int[]
	 */
	public static function pending( Provider $provider, bool $rematch = false, int $limit = 0 ): array {
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

		$queued  = self::queued_restaurants( $provider->source() );
		$pending = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $queued[ $id ] ) || null === self::coordinates( $id ) ) {
				continue;
			}
			if ( ! $rematch && '' !== (string) get_post_meta( $id, $provider->id_meta_key(), true ) ) {
				continue;
			}
			$pending[] = $id;
			if ( $limit > 0 && count( $pending ) >= $limit ) {
				break;
			}
		}
		return $pending;
	}

	/**
	 * Estimate for matching the given number of restaurants: one search each,
	 * free while this month's free allowance lasts.
	 *
	 * @param Provider $provider         Provider.
	 * @param int      $restaurant_count Restaurants.
	 * @return array{calls: int, free: int, cost: float}
	 */
	public static function estimate( Provider $provider, int $restaurant_count ): array {
		return Budget::estimate( $provider->search_sku(), $restaurant_count );
	}

	/**
	 * Runs matching.
	 *
	 * @param int[] $restaurant_ids Restaurants to match.
	 * @param bool  $dry_run        Search and score, but write nothing.
	 * @return array{counts: array<string, int>, requests: int, spent: float, stopped_at_cap: bool, errors: string[], run_id: int|null}
	 */
	public function run( array $restaurant_ids, bool $dry_run = false ): array {
		$result = array(
			'counts'         => array_fill_keys( array( self::CREATED, self::UPDATED, self::UNCHANGED, self::FAILED, self::QUEUED, self::NO_MATCH, self::SKIPPED ), 0 ),
			'requests'       => 0,
			'spent'          => 0.0,
			'stopped_at_cap' => false,
			'errors'         => array(),
			'run_id'         => null,
		);

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( $this->provider->source() . '_match' );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;

		foreach ( $restaurant_ids as $restaurant_id ) {
			$restaurant_id = (int) $restaurant_id;
			$point         = self::coordinates( $restaurant_id );
			if ( null === $point ) {
				++$result['counts'][ self::SKIPPED ];
				continue;
			}

			if ( ! $this->budget->try_call( $this->provider->search_sku() ) ) {
				$result['stopped_at_cap'] = true;
				break;
			}
			if ( $result['requests'] > 0 && $this->delay > 0 ) {
				call_user_func( $this->sleeper, $this->delay );
			}
			++$result['requests'];

			try {
				$candidates = $this->provider->candidates( get_the_title( $restaurant_id ), $point[0], $point[1], self::RADIUS );
			} catch ( \Throwable $e ) {
				++$result['counts'][ self::FAILED ];
				$result['errors'][] = sprintf( '#%d: %s', $restaurant_id, $e->getMessage() );
				continue;
			}

			$status = $this->decide( $restaurant_id, $point, $candidates, $dry_run, $run_id );
			++$result['counts'][ $status ];
		}

		$result['spent'] = $this->budget->spent();

		if ( $run_id ) {
			$counts = $result['counts'];
			Ingestion_Runs::finish(
				$run_id,
				$counts,
				sprintf(
					'%s match: %d requests, %d queued for review, %d no match, $%.2f billed%s',
					$this->provider->source(),
					$result['requests'],
					$counts[ self::QUEUED ],
					$counts[ self::NO_MATCH ],
					$result['spent'],
					$result['stopped_at_cap'] ? ', stopped at the cap or the end of the free allowance' : ''
				)
			);
		}

		return $result;
	}

	/**
	 * Scores the candidates and stores, queues or drops the best one.
	 *
	 * @param int                                                                 $restaurant_id Restaurant.
	 * @param array{0: float, 1: float}                                           $point         Restaurant coordinates.
	 * @param array<int, array{id: string, name: string, lat: float, lng: float}> $candidates    Candidates.
	 * @param bool                                                                $dry_run       Dry run.
	 * @param int|null                                                            $run_id        Run ID.
	 * @return string Count key.
	 */
	private function decide( int $restaurant_id, array $point, array $candidates, bool $dry_run, ?int $run_id ): string {
		$name   = get_the_title( $restaurant_id );
		$scored = array();
		foreach ( $candidates as $candidate ) {
			$score = self::score( $name, $point[0], $point[1], $candidate );
			if ( null !== $score ) {
				$scored[] = $score + array( 'id' => $candidate['id'] );
			}
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		$best = $scored[0] ?? null;
		if ( null === $best || $best['confidence'] < self::REVIEW ) {
			return self::NO_MATCH;
		}

		$runner_up = $scored[1] ?? null;
		$clear     = null === $runner_up || $best['confidence'] - $runner_up['confidence'] >= self::MARGIN;

		if ( $best['confidence'] >= self::AUTO_ACCEPT && $clear ) {
			$key     = $this->provider->id_meta_key();
			$current = (string) get_post_meta( $restaurant_id, $key, true );
			if ( $current === $best['id'] ) {
				if ( ! $dry_run ) {
					update_post_meta( $restaurant_id, Restaurant_Meta::match_confidence_key( $this->provider->source() ), $best['confidence'] );
				}
				return self::UNCHANGED;
			}
			if ( ! $dry_run ) {
				update_post_meta( $restaurant_id, $key, $best['id'] );
				update_post_meta( $restaurant_id, Restaurant_Meta::match_confidence_key( $this->provider->source() ), $best['confidence'] );
			}
			return '' === $current ? self::CREATED : self::UPDATED;
		}

		if ( ! $dry_run ) {
			Review_Queue::enqueue(
				'match_conflict',
				array(
					'kind'            => 'rating_source_match',
					'source'          => $this->provider->source(),
					'external_id'     => $best['id'],
					'name_similarity' => $best['name_similarity'],
					'distance_m'      => $best['distance_m'],
					'runner_up_id'    => $runner_up['id'] ?? '',
					'reason'          => $best['confidence'] >= self::AUTO_ACCEPT ? 'ambiguous' : 'low_confidence',
				),
				$this->provider->source(),
				$restaurant_id,
				$best['confidence'],
				$run_id
			);
		}
		return self::QUEUED;
	}

	/**
	 * Scores a candidate, or null when it is farther than the radius.
	 *
	 * @param string                                                  $name      Our restaurant name.
	 * @param float                                                   $lat       Our latitude.
	 * @param float                                                   $lng       Our longitude.
	 * @param array{id: string, name: string, lat: float, lng: float} $candidate Candidate.
	 * @return array{confidence: float, name_similarity: float, distance_m: int}|null
	 */
	public static function score( string $name, float $lat, float $lng, array $candidate ): ?array {
		$distance = self::distance_m( $lat, $lng, (float) $candidate['lat'], (float) $candidate['lng'] );
		if ( $distance > self::RADIUS ) {
			return null;
		}
		$similarity = self::name_similarity( $name, (string) $candidate['name'] );
		$proximity  = 1 - $distance / self::RADIUS;

		return array(
			'confidence'      => round( self::NAME_WEIGHT * $similarity + ( 1 - self::NAME_WEIGHT ) * $proximity, 3 ),
			'name_similarity' => round( $similarity, 3 ),
			'distance_m'      => (int) round( $distance ),
		);
	}

	/**
	 * Name similarity from 0 to 1: the better of edit-distance similarity and
	 * a word-overlap score, so "Kracked Egg" and "The Kracked Egg Tumon" score high.
	 *
	 * @param string $a Name.
	 * @param string $b Name.
	 * @return float
	 */
	public static function name_similarity( string $a, string $b ): float {
		$a = Names::key( $a );
		$b = Names::key( $b );
		if ( '' === $a || '' === $b ) {
			return 0.0;
		}
		if ( $a === $b ) {
			return 1.0;
		}

		$edit = 1 - levenshtein( substr( $a, 0, 255 ), substr( $b, 0, 255 ) ) / max( strlen( $a ), strlen( $b ) );

		$words_a = array_unique( explode( ' ', $a ) );
		$words_b = array_unique( explode( ' ', $b ) );
		$common  = count( array_intersect( $words_a, $words_b ) );
		$overlap = $common / min( count( $words_a ), count( $words_b ) );
		$dice    = 2 * $common / ( count( $words_a ) + count( $words_b ) );

		return max( 0.0, min( 1.0, max( $edit, ( $overlap + $dice ) / 2 ) ) );
	}

	/**
	 * Great-circle distance in metres.
	 *
	 * @param float $lat1 Latitude.
	 * @param float $lng1 Longitude.
	 * @param float $lat2 Latitude.
	 * @param float $lng2 Longitude.
	 * @return float
	 */
	public static function distance_m( float $lat1, float $lng1, float $lat2, float $lng2 ): float {
		$rad  = M_PI / 180;
		$dlat = ( $lat2 - $lat1 ) * $rad;
		$dlng = ( $lng2 - $lng1 ) * $rad;
		$h    = sin( $dlat / 2 ) ** 2 + cos( $lat1 * $rad ) * cos( $lat2 * $rad ) * sin( $dlng / 2 ) ** 2;
		return 2 * 6371008.8 * asin( min( 1.0, sqrt( $h ) ) );
	}

	/**
	 * A restaurant's coordinates, or null when unset (0 means unset; Guam is nowhere near 0,0).
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{0: float, 1: float}|null
	 */
	private static function coordinates( int $restaurant_id ): ?array {
		$lat = (float) get_post_meta( $restaurant_id, Restaurant_Meta::LATITUDE, true );
		$lng = (float) get_post_meta( $restaurant_id, Restaurant_Meta::LONGITUDE, true );
		return ( 0.0 === $lat && 0.0 === $lng ) ? null : array( $lat, $lng );
	}

	/**
	 * Restaurants with a pending match review for this source.
	 *
	 * @param string $source Source.
	 * @return array<int, bool>
	 */
	private static function queued_restaurants( string $source ): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$ids   = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT restaurant_id FROM {$table} WHERE source = %s AND type = %s AND status = %s AND restaurant_id IS NOT NULL", $source, 'match_conflict', 'pending' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}
}
