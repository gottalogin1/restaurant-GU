<?php
/**
 * Imports award results from a CSV file.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * For each validated row: find the restaurant, then store the result once
 * per (restaurant, program, year, category).
 *
 * - Clear match: the award is stored on that restaurant.
 * - Possible match (below Restaurant_Matcher::AUTO_ACCEPT, or two close
 *   candidates): nothing is stored; the row goes to the review queue as
 *   `match_conflict` (source `editor`, payload kind `award_match`) with the
 *   facts and our scores. The same row is never queued twice while an item
 *   is pending, and a later run that matches it clearly marks the item merged.
 * - No match: a draft restaurant is created with status `unknown` (or
 *   `permanently_closed`) so past winners stay listed, and the award is stored on it.
 *
 * Awards are always stored with verified = 0: the source URL is the page
 * that mentioned the result, not a check with the publisher.
 */
final class Importer {

	public const SOURCE = 'awards_csv';

	public const QUEUE_KIND = 'award_match';

	/**
	 * Status given to restaurants created for unmatched names.
	 *
	 * @var string
	 */
	private string $unmatched_status;

	/**
	 * Matcher.
	 *
	 * @var Restaurant_Matcher|null
	 */
	private ?Restaurant_Matcher $matcher;

	/**
	 * Constructor.
	 *
	 * @param string                  $unmatched_status unknown or permanently_closed.
	 * @param Restaurant_Matcher|null $matcher          Defaults to one built from the database at run time.
	 * @throws \InvalidArgumentException For any other status.
	 */
	public function __construct( string $unmatched_status = 'unknown', ?Restaurant_Matcher $matcher = null ) {
		if ( ! in_array( $unmatched_status, array( 'unknown', 'permanently_closed' ), true ) ) {
			throw new \InvalidArgumentException( 'Unmatched restaurants must be created as unknown or permanently_closed.' );
		}
		$this->unmatched_status = $unmatched_status;
		$this->matcher          = $matcher;
	}

	/**
	 * Imports a file.
	 *
	 * @param string $path    CSV file.
	 * @param bool   $dry_run Match and count, but write nothing.
	 * @return array<string, mixed> See run().
	 */
	public function import_file( string $path, bool $dry_run = false ): array {
		$parsed = Csv_Reader::read_file( $path );
		return $this->run( $parsed['rows'], $parsed['rejected'], $dry_run, basename( $path ) );
	}

	/**
	 * Imports validated rows.
	 *
	 * @param array<int, array<string, mixed>> $rows     Rows from Csv_Reader.
	 * @param array<int, array<string, mixed>> $rejected Rows the reader rejected (counted as failed).
	 * @param bool                             $dry_run  Write nothing.
	 * @param string                           $label    File name for the run notes.
	 * @return array{counts: array<string, int>, queued: int, rejected: array<int, array<string, mixed>>, restaurants_created: array<int, array{id: int, name: string}>, former_name_matches: array<int, string>, imported: array<string, array<int, string[]>>, errors: string[], run_id: int|null}
	 */
	public function run( array $rows, array $rejected = array(), bool $dry_run = false, string $label = '' ): array {
		$matcher                                = $this->matcher ?? Restaurant_Matcher::from_database();
		$result                                 = array(
			'counts'              => array_fill_keys( array( Repository::CREATED, Repository::UPDATED, Repository::UNCHANGED, Repository::FAILED ), 0 ),
			'queued'              => 0,
			'rejected'            => $rejected,
			'restaurants_created' => array(),
			'former_name_matches' => array(),
			'imported'            => array(),
			'errors'              => array(),
			'run_id'              => null,
		);
		$result['counts'][ Repository::FAILED ] = count( $rejected );

		$run_id           = $dry_run ? 0 : Ingestion_Runs::start( self::SOURCE );
		$run_id           = $run_id > 0 ? $run_id : null;
		$result['run_id'] = $run_id;
		$pending          = self::pending_queue_items();
		$placeholder      = 0;

		foreach ( $rows as $row ) {
			$key   = self::row_key( $row );
			$match = $matcher->match( (string) $row['restaurant_name'] );

			if ( Restaurant_Matcher::REVIEW === $match['status'] ) {
				if ( ! isset( $pending[ $key ] ) && ! $dry_run ) {
					$pending[ $key ] = Review_Queue::enqueue(
						'match_conflict',
						array(
							'kind'            => self::QUEUE_KIND,
							'award'           => self::facts( $row ),
							'candidate_id'    => $match['restaurant_id'],
							'matched_name'    => $match['matched_name'],
							'via'             => $match['via'],
							'runner_up_id'    => $match['runner_up_id'],
							'name_similarity' => $match['confidence'],
							'reason'          => $match['confidence'] >= Restaurant_Matcher::AUTO_ACCEPT ? 'ambiguous' : 'low_confidence',
						),
						'editor',
						$match['restaurant_id'],
						$match['confidence'],
						$run_id
					);
				}
				++$result['queued'];
				continue;
			}

			if ( Restaurant_Matcher::MATCHED === $match['status'] ) {
				$restaurant_id = (int) $match['restaurant_id'];
				if ( 'former_name' === $match['via'] ) {
					$result['former_name_matches'][ $restaurant_id ] = sprintf( '%s → %s', $row['restaurant_name'], $restaurant_id > 0 ? get_the_title( $restaurant_id ) : $match['matched_name'] );
				}
			} elseif ( $dry_run ) {
				$restaurant_id = --$placeholder;
				$matcher->add( $restaurant_id, (string) $row['restaurant_name'] );
				$result['restaurants_created'][] = array(
					'id'   => 0,
					'name' => (string) $row['restaurant_name'],
				);
			} else {
				$restaurant_id = $this->create_restaurant( (string) $row['restaurant_name'] );
				if ( $restaurant_id <= 0 ) {
					++$result['counts'][ Repository::FAILED ];
					$result['errors'][] = sprintf( 'line %d: could not create a restaurant for "%s"', $row['line'] ?? 0, $row['restaurant_name'] );
					continue;
				}
				$matcher->add( $restaurant_id, (string) $row['restaurant_name'] );
				$result['restaurants_created'][] = array(
					'id'   => $restaurant_id,
					'name' => (string) $row['restaurant_name'],
				);
			}

			if ( $dry_run ) {
				$existing = $restaurant_id > 0 ? Repository::find( $restaurant_id, $row['program'], (int) $row['year'], $row['category'] ) : null;
				$status   = null === $existing ? Repository::CREATED : Repository::UNCHANGED;
				if ( null !== $existing && ( $existing['placement'] !== $row['placement'] || $existing['source_url'] !== $row['source_url'] || (string) $existing['source_note'] !== $row['source_note'] ) ) {
					$status = Repository::UPDATED;
				}
			} else {
				$status = Repository::upsert( $restaurant_id, self::facts( $row ) );
				if ( isset( $pending[ $key ] ) && Repository::FAILED !== $status ) {
					self::mark_merged( (int) $pending[ $key ] );
					unset( $pending[ $key ] );
				}
			}

			++$result['counts'][ $status ];
			if ( Repository::FAILED === $status ) {
				$result['errors'][] = sprintf( 'line %d: could not save the award', $row['line'] ?? 0 );
				continue;
			}
			$result['imported'][ $row['program'] ][ (int) $row['year'] ][ $row['category'] ] = $row['category'];
		}

		foreach ( $result['imported'] as $program => $years ) {
			ksort( $years );
			$result['imported'][ $program ] = array_map( 'array_values', $years );
		}

		if ( $run_id ) {
			Ingestion_Runs::finish(
				$run_id,
				$result['counts'],
				sprintf(
					'%s: %d rows, %d rejected, %d queued for review, %d restaurants created',
					'' === $label ? 'awards' : $label,
					count( $rows ) + count( $rejected ),
					count( $rejected ),
					$result['queued'],
					count( $result['restaurants_created'] )
				)
			);
		}

		return $result;
	}

	/**
	 * Creates a draft restaurant for a past winner we do not list.
	 *
	 * @param string $name Name.
	 * @return int Post ID, or 0.
	 */
	private function create_restaurant( string $name ): int {
		// A past winner is history, not an opening or a closure, so it is kept out of the change log.
		$status = $this->unmatched_status;
		$id     = Change_Tracker::suppress(
			static function () use ( $name, $status ) {
				return wp_insert_post(
					array(
						'post_type'   => Content_Types::POST_TYPE,
						'post_status' => 'draft',
						'post_title'  => $name,
						'meta_input'  => array( Restaurant_Meta::STATUS => $status ),
					),
					true
				);
			}
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * The stored facts of a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array{program: string, year: int, category: string, placement: string, restaurant_name: string, source_url: string, source_note: string}
	 */
	private static function facts( array $row ): array {
		return array(
			'program'         => (string) $row['program'],
			'year'            => (int) $row['year'],
			'category'        => (string) $row['category'],
			'placement'       => (string) $row['placement'],
			'restaurant_name' => (string) $row['restaurant_name'],
			'source_url'      => (string) $row['source_url'],
			'source_note'     => (string) $row['source_note'],
		);
	}

	/**
	 * Identity of a row for queue de-duplication.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return string
	 */
	private static function row_key( array $row ): string {
		return implode( '|', array( strtolower( (string) $row['program'] ), (int) $row['year'], strtolower( (string) $row['category'] ), Names::key( (string) $row['restaurant_name'] ) ) );
	}

	/**
	 * Pending award-match queue items keyed by row identity.
	 *
	 * @return array<string, int> Row key => queue item ID.
	 */
	private static function pending_queue_items(): array {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, payload FROM {$table} WHERE type = %s AND source = %s AND status = %s", 'match_conflict', 'editor', 'pending' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$items = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) && self::QUEUE_KIND === ( $payload['kind'] ?? '' ) && is_array( $payload['award'] ?? null ) ) {
				$items[ self::row_key( $payload['award'] ) ] = (int) $row['id'];
			}
		}
		return $items;
	}

	/**
	 * Closes a queue item whose row has now been matched clearly.
	 *
	 * @param int $item_id Queue item.
	 * @return void
	 */
	private static function mark_merged( int $item_id ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Schema::table( Schema::REVIEW_QUEUE ),
			array(
				'status'      => 'merged',
				'resolved_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $item_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}
}
