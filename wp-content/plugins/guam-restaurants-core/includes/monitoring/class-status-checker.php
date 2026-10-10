<?php
/**
 * Monthly business-status check against Google (and Yelp when enabled).
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Asks a source whether each restaurant with that source's ID is still
 * open and feeds the answer to Closures, which asks an administrator after
 * two consecutive "closed" answers. Each call goes through the run Budget,
 * so with the default $0 cap the run stops at the end of the free
 * allowance instead of making a paid call.
 */
final class Status_Checker {

	/**
	 * Source.
	 *
	 * @var Status_Source
	 */
	private Status_Source $source;

	/**
	 * Run budget.
	 *
	 * @var Budget
	 */
	private Budget $budget;

	/**
	 * Seconds between requests.
	 *
	 * @var int
	 */
	private int $delay;

	/**
	 * Waits between requests.
	 *
	 * @var callable(int): void
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Status_Source $source  Source.
	 * @param Budget        $budget  Run budget.
	 * @param int           $delay   Seconds between requests.
	 * @param callable|null $sleeper Defaults to sleep().
	 */
	public function __construct( Status_Source $source, Budget $budget, int $delay = 1, ?callable $sleeper = null ) {
		$this->source  = $source;
		$this->budget  = $budget;
		$this->delay   = max( 0, $delay );
		$this->sleeper = $sleeper ?? 'sleep';
	}

	/**
	 * Restaurants to check: not trashed, not already permanently closed, with this source's ID.
	 *
	 * @param Status_Source $source Source.
	 * @return int[]
	 */
	public static function restaurants( Status_Source $source ): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $source->id_meta_key(),
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( $id ) {
					return 'permanently_closed' !== get_post_meta( $id, Restaurant_Meta::STATUS, true );
				}
			)
		);
	}

	/**
	 * Estimate for checking every restaurant this month.
	 *
	 * @param Status_Source $source Source.
	 * @return array{calls: int, free: int, cost: float}
	 */
	public static function estimate( Status_Source $source ): array {
		return Budget::estimate( $source->sku(), count( self::restaurants( $source ) ) );
	}

	/**
	 * Runs the check.
	 *
	 * @param int[]|null $restaurant_ids Restaurants; null for all.
	 * @param bool       $dry_run        Call the source but record nothing.
	 * @return array{counts: array<string, int>, closed: int[], queued: int[], requests: int, spent: float, stopped_at_cap: bool, errors: string[], run_id: int|null}
	 */
	public function run( ?array $restaurant_ids = null, bool $dry_run = false ): array {
		$restaurant_ids = $restaurant_ids ?? self::restaurants( $this->source );
		$label          = $this->source->source() . '_status';

		$result = array(
			'counts'         => array(
				'created'   => 0,
				'updated'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			),
			'closed'         => array(),
			'queued'         => array(),
			'requests'       => 0,
			'spent'          => 0.0,
			'stopped_at_cap' => false,
			'errors'         => array(),
			'run_id'         => null,
		);

		$started          = $dry_run ? 0 : Ingestion_Runs::start( $label );
		$run_id           = $started > 0 ? $started : null;
		$result['run_id'] = $run_id;

		foreach ( $restaurant_ids as $restaurant_id ) {
			$id = (string) get_post_meta( $restaurant_id, $this->source->id_meta_key(), true );
			if ( '' === $id ) {
				continue;
			}
			if ( ! $this->budget->try_call( $this->source->sku() ) ) {
				$result['stopped_at_cap'] = true;
				break;
			}
			if ( $result['requests'] > 0 && $this->delay > 0 ) {
				call_user_func( $this->sleeper, $this->delay );
			}
			++$result['requests'];

			try {
				$status = $this->source->check( $id );
			} catch ( \Throwable $e ) {
				// A place ID Google no longer knows is not evidence of closure.
				++$result['counts']['failed'];
				$result['errors'][] = sprintf( '#%d: %s', $restaurant_id, $e->getMessage() );
				continue;
			}

			if ( Status_Source::UNKNOWN === $status ) {
				++$result['counts']['unchanged'];
				continue;
			}

			$closed = Status_Source::CLOSED === $status;
			if ( $closed ) {
				$result['closed'][] = $restaurant_id;
				++$result['counts']['updated'];
			} else {
				++$result['counts']['unchanged'];
			}

			if ( ! $dry_run ) {
				$check = Closures::record_check( $restaurant_id, $this->source->source(), $closed, $run_id, array( $this->source->source() . '_id' => $id ) );
				if ( $check['queued'] ) {
					$result['queued'][] = $restaurant_id;
				}
			}
		}

		$result['spent'] = $this->budget->spent();

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], self::notes( $this->source->source(), $result ), $result['errors'] );
		}

		return $result;
	}

	/**
	 * Run notes.
	 *
	 * @param string               $source Source key.
	 * @param array<string, mixed> $result Result.
	 * @return string
	 */
	public static function notes( string $source, array $result ): string {
		return sprintf(
			'%s status: %d checked, %d looked permanently closed, %d queued for approval, %s billed%s',
			$source,
			$result['requests'],
			count( $result['closed'] ),
			count( $result['queued'] ),
			Pricing::format( $result['spent'] ),
			$result['stopped_at_cap'] ? ', stopped at the cost cap or the end of the free allowance' : ''
		);
	}
}
