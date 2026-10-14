<?php
/**
 * Finds restaurants missing from the directory.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Discovery;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seed_Data;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one Places Text Search per village and per mall and adds places we
 * do not list to the review queue as `new_place` candidates. It never
 * creates restaurant posts.
 *
 * A place counts as already listed when its place ID is stored on a
 * restaurant, or a restaurant with the same name is in the searched
 * village (or mall). A same-name match elsewhere is still queued, with
 * lower confidence, because chains have several branches.
 *
 * Only the place ID and how it was found are stored. Google names are
 * compared in memory and dropped; the reviewer opens the place from its ID.
 */
final class Discoverer {

	public const CREATED   = 'created';
	public const UNCHANGED = 'unchanged';
	public const FAILED    = 'failed';

	/**
	 * Text Search query; %s is the village or mall name.
	 */
	public const QUERY_TEMPLATE = 'restaurants in %s, Guam';

	/**
	 * A run stops after this many searches fail in a row (a bad key or an
	 * outage), rather than spend a request on every remaining search.
	 */
	public const MAX_FAILURES_IN_A_ROW = 3;

	/**
	 * Places client.
	 *
	 * @var Places_Client
	 */
	private Places_Client $client;

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
	 * Result pages per query (20 results each).
	 *
	 * @var int
	 */
	private int $max_pages;

	/**
	 * Run budget: each request is a Text Search Pro call, free within the
	 * monthly allowance.
	 *
	 * @var Budget
	 */
	private Budget $budget;

	/**
	 * Constructor.
	 *
	 * @param Places_Client $client    Client.
	 * @param int           $delay     Seconds between requests.
	 * @param int           $max_pages Pages per query, 1 to 3.
	 * @param callable|null $sleeper   Defaults to sleep().
	 * @param Budget|null   $budget    Defaults to the run cap ($0: free calls only).
	 */
	public function __construct( Places_Client $client, int $delay = 1, int $max_pages = 3, ?callable $sleeper = null, ?Budget $budget = null ) {
		$this->client    = $client;
		$this->delay     = max( 0, $delay );
		$this->max_pages = max( 1, min( 3, $max_pages ) );
		$this->sleeper   = $sleeper ?? 'sleep';
		$this->budget    = $budget ?? Budget::for_run();
	}

	/**
	 * Search areas: every village and every mall (verified or not).
	 *
	 * @param string $scope all, villages or malls.
	 * @return array<int, array{kind: string, slug: string, name: string, village: string}>
	 */
	public static function areas( string $scope = 'all' ): array {
		$areas = array();

		if ( 'malls' !== $scope ) {
			foreach ( Seed_Data::villages() as $slug => $village ) {
				$areas[] = array(
					'kind'    => 'village',
					'slug'    => $slug,
					'name'    => $village['name'],
					'village' => $slug,
				);
			}
		}

		if ( 'villages' !== $scope ) {
			$malls = get_terms(
				array(
					'taxonomy'   => Content_Types::TAX_MALL,
					'hide_empty' => false,
					Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
				)
			);
			foreach ( is_wp_error( $malls ) ? array() : $malls as $mall ) {
				$village = get_term( (int) get_term_meta( $mall->term_id, Term_Meta::MALL_VILLAGE, true ), Content_Types::TAX_VILLAGE );
				$areas[] = array(
					'kind'    => 'mall',
					'slug'    => $mall->slug,
					'name'    => $mall->name,
					'village' => $village instanceof \WP_Term ? $village->slug : '',
				);
			}
		}

		return $areas;
	}

	/**
	 * Runs one query per area ("restaurants in <area>, Guam").
	 *
	 * @param array<int, array{kind: string, slug: string, name: string, village: string}> $areas   Areas to search.
	 * @param bool                                                                         $dry_run Report without writing.
	 * @return array{counts: array<string, int>, queries: int, requests: int, errors: string[], run_id: int|null, stopped_at_cap: bool, searches: array<int, array<string, mixed>>}
	 */
	public function run( array $areas, bool $dry_run = false ): array {
		$searches = array();
		foreach ( $areas as $area ) {
			$searches[] = array(
				'query'     => sprintf( self::QUERY_TEMPLATE, $area['name'] ),
				'area_kind' => $area['kind'],
				'area_slug' => $area['slug'],
				'village'   => $area['village'],
			);
		}
		return $this->run_searches( $searches, $dry_run );
	}

	/**
	 * Runs the searches the search log says are due: never-run queries
	 * first, then weekly re-checks, until none are left, the request limit
	 * is reached or the next request would pass the cost cap. Each finished
	 * search is recorded in the log; a village whose search filled every
	 * page gets narrower cuisine queries for a later run.
	 *
	 * @param string   $scope        all, villages, malls or island.
	 * @param bool     $dry_run      Report without writing (the log is not updated).
	 * @param int|null $max_requests Stop after this many requests; null for no limit.
	 * @param int      $recheck_days Days before a search is due again.
	 * @return array{counts: array<string, int>, queries: int, requests: int, errors: string[], run_id: int|null, stopped_at_cap: bool, searches: array<int, array<string, mixed>>, added_queries: int, left: int}
	 */
	public function run_from_log( string $scope = 'all', bool $dry_run = false, ?int $max_requests = null, int $recheck_days = Search_Log::DEFAULT_RECHECK_DAYS ): array {
		$added = $dry_run ? 0 : Search_Log::sync();
		$due   = Search_Log::due( $scope );

		$result = $this->run_searches( $due, $dry_run, $max_requests );

		foreach ( $result['searches'] as $search ) {
			if ( $dry_run || empty( $search['id'] ) || ! $search['done'] ) {
				continue;
			}
			Search_Log::record( (int) $search['id'], $search['results'], $search['new'], $search['saturated'], $result['run_id'], $recheck_days );
			if ( $search['saturated'] && 'village' === $search['area_kind'] && Search_Log::ORIGIN_AREA === ( $search['origin'] ?? '' ) ) {
				$added += Search_Log::narrow( (string) $search['village'] );
			}
		}
		foreach ( $result['searches'] as $search ) {
			if ( ! $dry_run && ! empty( $search['id'] ) && '' !== $search['error'] ) {
				Search_Log::record_error( (int) $search['id'], $search['error'] );
			}
		}

		$result['added_queries'] = $added;
		$result['left']          = $dry_run ? count( $due ) - count( array_filter( array_column( $result['searches'], 'done' ) ) ) : count( Search_Log::due( $scope ) );
		if ( $result['run_id'] ) {
			Ingestion_Runs::append_note( $result['run_id'], sprintf( '%d searches still due', $result['left'] ) );
		}
		return $result;
	}

	/**
	 * Runs searches, up to max_pages each.
	 *
	 * @param array<int, array<string, mixed>> $searches     Each with query, area_kind, area_slug, village (and id, origin from the log).
	 * @param bool                             $dry_run      Report without writing.
	 * @param int|null                         $max_requests Request limit.
	 * @return array{counts: array<string, int>, queries: int, requests: int, errors: string[], run_id: int|null, stopped_at_cap: bool, searches: array<int, array<string, mixed>>}
	 */
	private function run_searches( array $searches, bool $dry_run, ?int $max_requests = null ): array {
		$listing   = self::listing();
		$known_ids = array_merge( self::stored_place_ids(), self::queued_place_ids() );
		$known_ids = array_fill_keys( $known_ids, true );

		$result = array(
			'counts'         => array_fill_keys( array( self::CREATED, 'updated', self::UNCHANGED, self::FAILED ), 0 ),
			'queries'        => 0,
			'requests'       => 0,
			'errors'         => array(),
			'run_id'         => null,
			'stopped_at_cap' => false,
			'searches'       => array(),
		);

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( 'google' );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;
		$failures         = 0;

		foreach ( $searches as $search ) {
			if ( $failures >= self::MAX_FAILURES_IN_A_ROW ) {
				$result['errors'][] = sprintf( 'Stopped after %d failed searches in a row.', $failures );
				break;
			}
			$query = (string) $search['query'];
			$area  = array(
				'kind'    => (string) $search['area_kind'],
				'slug'    => (string) $search['area_slug'],
				'village' => (string) $search['village'],
			);
			$stats = $search + array(
				'results'   => 0,
				'new'       => 0,
				'saturated' => false,
				'done'      => false,
				'error'     => '',
			);
			$token = '';

			for ( $page = 0; $page < $this->max_pages; $page++ ) {
				if ( null !== $max_requests && $result['requests'] >= $max_requests ) {
					break 2;
				}
				if ( ! $this->budget->try_call( Pricing::GOOGLE_TEXT_SEARCH_PRO ) ) {
					$result['stopped_at_cap'] = true;
					break 2;
				}
				if ( 0 === $page ) {
					++$result['queries'];
				}
				if ( $result['requests'] > 0 && $this->delay > 0 ) {
					call_user_func( $this->sleeper, $this->delay );
				}
				++$result['requests'];

				try {
					$response = $this->client->search( $query, $token );
				} catch ( \Throwable $e ) {
					++$result['counts'][ self::FAILED ];
					$result['errors'][] = $query . ': ' . $e->getMessage();
					$stats['error']     = $e->getMessage();
					break;
				}

				foreach ( $response['places'] as $place ) {
					$status = $this->consider( $place, $area, $query, $listing, $known_ids, $run_id, $dry_run );
					++$result['counts'][ $status ];
					++$stats['results'];
					$stats['new'] += self::CREATED === $status ? 1 : 0;
				}

				$token = $response['next_page_token'];
				if ( '' === $token ) {
					break;
				}
			}

			// A search that stopped only because max_pages ran out still had results left.
			$stats['saturated']   = '' === $stats['error'] && '' !== $token;
			$stats['done']        = '' === $stats['error'];
			$failures             = $stats['done'] ? 0 : $failures + 1;
			$result['searches'][] = $stats;
		}

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], sprintf( 'discover: %d queries, %d requests', $result['queries'], $result['requests'] ), $result['errors'] );
			Ingestion_Runs::record_retries( $run_id, $this->client->attempt_log() );
		}

		return $result;
	}

	/**
	 * Queues one place if it is not already listed or queued.
	 *
	 * @param array{id: string, name: string, business_status: string}           $place     Place (name used in memory only).
	 * @param array{kind: string, slug: string, village: string}                 $area      Searched area (kind village, mall or island).
	 * @param string                                                             $query     Query text.
	 * @param array<string, array<int, array{village: string, malls: string[]}>> $listing   Our restaurants by name key.
	 * @param array<string, bool>                                                $known_ids Place IDs already stored or queued, updated in place.
	 * @param int|null                                                           $run_id    Run ID.
	 * @param bool                                                               $dry_run   Dry run.
	 * @return string created or unchanged.
	 */
	private function consider( array $place, array $area, string $query, array $listing, array &$known_ids, ?int $run_id, bool $dry_run ): string {
		if ( isset( $known_ids[ $place['id'] ] ) || 'CLOSED_PERMANENTLY' === $place['business_status'] ) {
			return self::UNCHANGED;
		}

		$same_name = $listing[ Names::key( $place['name'] ) ] ?? array();
		// An island-wide search cannot tell which branch it found; village searches catch new branches.
		if ( 'island' === $area['kind'] && $same_name ) {
			return self::UNCHANGED;
		}
		foreach ( $same_name as $restaurant ) {
			$in_area = 'mall' === $area['kind']
				? in_array( $area['slug'], $restaurant['malls'], true ) || ( '' !== $area['village'] && $restaurant['village'] === $area['village'] )
				: $restaurant['village'] === $area['village'];
			if ( $in_area ) {
				return self::UNCHANGED;
			}
		}

		$known_ids[ $place['id'] ] = true;
		if ( ! $dry_run ) {
			Review_Queue::enqueue(
				'new_place',
				array(
					'place_id'            => $place['id'],
					'found_by'            => array(
						'kind'  => $area['kind'],
						'slug'  => $area['slug'],
						'query' => $query,
					),
					'same_name_elsewhere' => (bool) $same_name,
					'google_maps_url'     => 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode( $place['id'] ),
				),
				'google',
				null,
				$same_name ? 0.3 : 0.6,
				$run_id
			);
		}
		return self::CREATED;
	}

	/**
	 * Our restaurants (any status but trash), by name key.
	 *
	 * @return array<string, array<int, array{village: string, malls: string[]}>>
	 */
	public static function listing(): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$listing = array();
		foreach ( $ids as $id ) {
			$villages = wp_get_object_terms( $id, Content_Types::TAX_VILLAGE, array( 'fields' => 'slugs' ) );
			$malls    = wp_get_object_terms(
				$id,
				Content_Types::TAX_MALL,
				array(
					'fields' => 'slugs',
					Mall_Verification::INCLUDE_UNVERIFIED_ARG => true,
				)
			);

			$listing[ Names::key( get_the_title( $id ) ) ][] = array(
				'village' => is_wp_error( $villages ) || ! $villages ? '' : $villages[0],
				'malls'   => is_wp_error( $malls ) ? array() : $malls,
			);
		}
		return $listing;
	}

	/**
	 * Place IDs stored on restaurants.
	 *
	 * @return string[]
	 */
	private static function stored_place_ids(): array {
		global $wpdb;
		return $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", Restaurant_Meta::GOOGLE_PLACE_ID )
		);
	}

	/**
	 * Place IDs already in the review queue, in any status, so a rejected
	 * candidate is not offered again.
	 *
	 * @return string[]
	 */
	private static function queued_place_ids(): array {
		global $wpdb;

		$table    = Schema::table( Schema::REVIEW_QUEUE );
		$payloads = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT payload FROM {$table} WHERE source = %s AND type = %s", 'google', 'new_place' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$ids = array();
		foreach ( $payloads as $payload ) {
			$data = json_decode( (string) $payload, true );
			if ( ! empty( $data['place_id'] ) ) {
				$ids[] = (string) $data['place_id'];
			}
		}
		return $ids;
	}
}
