<?php
/**
 * Free-allowance tracking and spending caps for rating API calls.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Counts every API call per SKU for the calendar month (UTC) and prices it:
 * free while the SKU's free allowance lasts, the SKU's price after that.
 * A call is refused when its price would pass the cap. Both caps default
 * to $0, so only free calls are made.
 *
 * A run budget lives for one command or cron run; page-view calls use the
 * monthly cap. The month's counts are kept in one option (numbers only, no
 * API content).
 */
final class Budget {

	public const MONTHLY_OPTION = 'guam_restaurants_core_ratings_usage';

	/**
	 * Earlier months' usage, kept for the monthly spend report
	 * (Monitoring\Spend_Report): month => calls and spend. Numbers only.
	 */
	public const HISTORY_OPTION = 'guam_restaurants_core_ratings_usage_history';

	public const HISTORY_MONTHS = 24;

	/**
	 * Cap in dollars.
	 *
	 * @var float
	 */
	private float $cap;

	/**
	 * Spent so far in this run.
	 *
	 * @var float
	 */
	private float $spent = 0.0;

	/**
	 * Constructor.
	 *
	 * @param float $cap Cap in dollars; 0 allows free calls only.
	 */
	public function __construct( float $cap ) {
		$this->cap = max( 0.0, $cap );
	}

	/**
	 * Run budget from GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD (default $0).
	 *
	 * @return Budget
	 */
	public static function for_run(): Budget {
		return new self( Config::money( Config::RUN_CAP_USD, Config::DEFAULT_RUN_CAP_USD ) );
	}

	/**
	 * Records one call if it is free or fits under this run's cap.
	 *
	 * @param string $sku   SKU.
	 * @param int    $calls Calls (some fetches are two requests).
	 * @return bool False when the call must not be made.
	 */
	public function try_call( string $sku, int $calls = 1 ): bool {
		$cost = self::price_of( $sku, $calls );
		if ( $this->spent + $cost > $this->cap + 1e-9 ) {
			return false;
		}
		$this->spent += $cost;
		self::record( $sku, $calls, $cost, '' );
		return true;
	}

	/**
	 * Records one page-view call if it is free or fits under the monthly cap
	 * (GUAM_RESTAURANTS_RATINGS_MONTHLY_CAP_USD, default $0).
	 *
	 * @param string $source Source key.
	 * @param string $sku    SKU.
	 * @param int    $calls  Calls.
	 * @return bool False when the call must not be made.
	 */
	public static function try_call_monthly( string $source, string $sku, int $calls = 1 ): bool {
		$cost  = self::price_of( $sku, $calls );
		$cap   = Config::money( Config::MONTHLY_CAP_USD, Config::DEFAULT_MONTHLY_CAP_USD );
		$usage = self::usage();
		if ( array_sum( $usage['spend'] ) + $cost > $cap + 1e-9 ) {
			return false;
		}
		self::record( $sku, $calls, $cost, $source );
		return true;
	}

	/**
	 * Price of the next calls on a SKU, given this month's usage.
	 *
	 * @param string $sku   SKU.
	 * @param int    $calls Calls.
	 * @return float Dollars; 0 while the free allowance lasts.
	 */
	public static function price_of( string $sku, int $calls = 1 ): float {
		$paid = max( 0, $calls - self::free_left( $sku ) );
		return $paid * Pricing::price( $sku );
	}

	/**
	 * Free calls left this month on a SKU.
	 *
	 * @param string $sku SKU.
	 * @return int
	 */
	public static function free_left( string $sku ): int {
		return max( 0, Pricing::free_per_month( $sku ) - (int) ( self::usage()['calls'][ $sku ] ?? 0 ) );
	}

	/**
	 * Worst-case estimate for a number of calls on a SKU.
	 *
	 * @param string $sku   SKU.
	 * @param int    $calls Calls.
	 * @return array{calls: int, free: int, cost: float}
	 */
	public static function estimate( string $sku, int $calls ): array {
		$free = min( $calls, self::free_left( $sku ) );
		return array(
			'calls' => $calls,
			'free'  => $free,
			'cost'  => ( $calls - $free ) * Pricing::price( $sku ),
		);
	}

	/**
	 * Dollars spent in this run.
	 *
	 * @return float
	 */
	public function spent(): float {
		return $this->spent;
	}

	/**
	 * Cap in dollars.
	 *
	 * @return float
	 */
	public function cap(): float {
		return $this->cap;
	}

	/**
	 * This month's usage: calls per SKU and page-view spend per source.
	 *
	 * @return array{month: string, calls: array<string, int>, spend: array<string, float>}
	 */
	public static function usage(): array {
		$month = gmdate( 'Y-m' );
		$data  = get_option( self::MONTHLY_OPTION );
		if ( ! is_array( $data ) || ( $data['month'] ?? '' ) !== $month ) {
			return array(
				'month' => $month,
				'calls' => array(),
				'spend' => array(),
			);
		}
		return array(
			'month' => $month,
			'calls' => (array) ( $data['calls'] ?? array() ),
			'spend' => (array) ( $data['spend'] ?? array() ),
		);
	}

	/**
	 * Adds calls (and page-view spend) to this month's usage.
	 *
	 * @param string $sku    SKU.
	 * @param int    $calls  Calls.
	 * @param float  $cost   Dollars.
	 * @param string $source Source for page-view spend, or '' for runs.
	 * @return void
	 */
	private static function record( string $sku, int $calls, float $cost, string $source ): void {
		self::archive_previous_month();
		$usage                  = self::usage();
		$usage['calls'][ $sku ] = (int) ( $usage['calls'][ $sku ] ?? 0 ) + $calls;
		if ( '' !== $source ) {
			$usage['spend'][ $source ] = (float) ( $usage['spend'][ $source ] ?? 0 ) + $cost;
		}
		update_option( self::MONTHLY_OPTION, $usage, false );
	}

	/**
	 * Moves a finished month's counts into the history option before the
	 * monthly counts start again. Safe to call any time.
	 *
	 * @return void
	 */
	public static function archive_previous_month(): void {
		$data = get_option( self::MONTHLY_OPTION );
		if ( ! is_array( $data ) || empty( $data['month'] ) || gmdate( 'Y-m' ) === $data['month'] ) {
			return;
		}
		$history                            = (array) get_option( self::HISTORY_OPTION, array() );
		$history[ (string) $data['month'] ] = array(
			'calls' => (array) ( $data['calls'] ?? array() ),
			'spend' => (array) ( $data['spend'] ?? array() ),
		);
		krsort( $history );
		update_option( self::HISTORY_OPTION, array_slice( $history, 0, self::HISTORY_MONTHS, true ), false );
		delete_option( self::MONTHLY_OPTION );
	}

	/**
	 * Usage for any month (YYYY-MM): this month's live counts, or the
	 * archived counts of an earlier month (empty when none were recorded).
	 *
	 * @param string $month Month.
	 * @return array{month: string, calls: array<string, int>, spend: array<string, float>}
	 */
	public static function usage_for( string $month ): array {
		if ( gmdate( 'Y-m' ) === $month ) {
			return self::usage();
		}
		$data = get_option( self::MONTHLY_OPTION );
		if ( ! is_array( $data ) || ( $data['month'] ?? '' ) !== $month ) {
			$data = ( (array) get_option( self::HISTORY_OPTION, array() ) )[ $month ] ?? array();
		}
		return array(
			'month' => $month,
			'calls' => array_map( 'intval', (array) ( $data['calls'] ?? array() ) ),
			'spend' => array_map( 'floatval', (array) ( $data['spend'] ?? array() ) ),
		);
	}
}
