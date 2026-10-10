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
	 * Constructor.
	 *
	 * @param Places_Client $client    Client.
	 * @param int           $delay     Seconds between requests.
	 * @param int           $max_pages Pages per query, 1 to 3.
	 * @param callable|null $sleeper   Defaults to sleep().
	 */
	public function __construct( Places_Client $client, int $delay = 1, int $max_pages = 3, ?callable $sleeper = null ) {
		$this->client    = $client;
		$this->delay     = max( 0, $delay );
		$this->max_pages = max( 1, min( 3, $max_pages ) );
		$this->sleeper   = $sleeper ?? 'sleep';
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
	 * Runs discovery.
	 *
	 * @param array<int, array{kind: string, slug: string, name: string, village: string}> $areas   Areas to search.
	 * @param bool                                                                         $dry_run Report without writing.
	 * @return array{counts: array<string, int>, queries: int, requests: int, errors: string[], run_id: int|null}
	 */
	public function run( array $areas, bool $dry_run = false ): array {
		$listing   = self::listing();
		$known_ids = array_merge( self::stored_place_ids(), self::queued_place_ids() );
		$known_ids = array_fill_keys( $known_ids, true );

		$result = array(
			'counts'   => array_fill_keys( array( self::CREATED, 'updated', self::UNCHANGED, self::FAILED ), 0 ),
			'queries'  => 0,
			'requests' => 0,
			'errors'   => array(),
			'run_id'   => null,
		);

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( 'google' );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;

		foreach ( $areas as $area ) {
			$query = sprintf( self::QUERY_TEMPLATE, $area['name'] );
			++$result['queries'];
			$token = '';

			for ( $page = 0; $page < $this->max_pages; $page++ ) {
				if ( $result['requests'] > 0 && $this->delay > 0 ) {
					call_user_func( $this->sleeper, $this->delay );
				}
				++$result['requests'];

				try {
					$response = $this->client->search( $query, $token );
				} catch ( \Throwable $e ) {
					++$result['counts'][ self::FAILED ];
					$result['errors'][] = $query . ': ' . $e->getMessage();
					break;
				}

				foreach ( $response['places'] as $place ) {
					$status = $this->consider( $place, $area, $query, $listing, $known_ids, $run_id, $dry_run );
					++$result['counts'][ $status ];
				}

				$token = $response['next_page_token'];
				if ( '' === $token ) {
					break;
				}
			}
		}

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], sprintf( 'discover: %d queries, %d requests', $result['queries'], $result['requests'] ) );
		}

		return $result;
	}

	/**
	 * Queues one place if it is not already listed or queued.
	 *
	 * @param array{id: string, name: string, business_status: string}           $place     Place (name used in memory only).
	 * @param array{kind: string, slug: string, name: string, village: string}   $area      Searched area.
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
