<?php
/**
 * Spending caps for rating API calls.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ratings;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks spend against a hard cap. A run budget lives for one command or
 * cron run; the monthly budget for live page-view calls is kept in an
 * option as dollar totals per source (no API content).
 */
final class Budget {

	public const MONTHLY_OPTION = 'guam_restaurants_core_ratings_spend';

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
	 * @param float $cap Cap in dollars.
	 */
	public function __construct( float $cap ) {
		$this->cap = max( 0.0, $cap );
	}

	/**
	 * Run budget from the GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD constant.
	 *
	 * @return Budget
	 */
	public static function for_run(): Budget {
		return new self( Config::money( Config::RUN_CAP_USD, Config::DEFAULT_RUN_CAP_USD ) );
	}

	/**
	 * Records a cost if it fits under the cap.
	 *
	 * @param float $cost Dollars.
	 * @return bool False when the request must not be made.
	 */
	public function try_spend( float $cost ): bool {
		if ( $this->spent + $cost > $this->cap + 1e-9 ) {
			return false;
		}
		$this->spent += $cost;
		return true;
	}

	/**
	 * Dollars spent.
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
	 * Records a live page-view call against this month's cap.
	 *
	 * @param string $source Source key.
	 * @param float  $cost   Dollars.
	 * @return bool False when the monthly cap would be passed.
	 */
	public static function try_spend_monthly( string $source, float $cost ): bool {
		$month = gmdate( 'Y-m' );
		$data  = get_option( self::MONTHLY_OPTION );
		if ( ! is_array( $data ) || ( $data['month'] ?? '' ) !== $month ) {
			$data = array(
				'month' => $month,
				'spend' => array(),
			);
		}

		$cap   = Config::money( Config::MONTHLY_CAP_USD, Config::DEFAULT_MONTHLY_CAP_USD );
		$total = array_sum( $data['spend'] );
		if ( $total + $cost > $cap + 1e-9 ) {
			return false;
		}

		$data['spend'][ $source ] = (float) ( $data['spend'][ $source ] ?? 0 ) + $cost;
		update_option( self::MONTHLY_OPTION, $data, false );
		return true;
	}

	/**
	 * This month's live spend by source.
	 *
	 * @return array<string, float>
	 */
	public static function monthly_spend(): array {
		$data = get_option( self::MONTHLY_OPTION );
		return is_array( $data ) && ( $data['month'] ?? '' ) === gmdate( 'Y-m' ) ? $data['spend'] : array();
	}
}
