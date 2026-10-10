<?php
/**
 * Monthly API spend report per source.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the call counts Ratings\Budget keeps for every paid API (Google
 * Places, Yelp, Foursquare; discovery, matching, status checks and page
 * views all count there) into a per-source report for one month
 * (docs/monitoring.md §3).
 *
 * The cost is an estimate on the same basis as the caps: calls past 90% of
 * a SKU's free allowance are priced at its list price, so it errs high.
 * The provider's own billing page is the real bill. Free sources that are
 * not metered (Overpass, OpenFreeMap, Google Maps Embed) and flat-fee
 * services (SendFox) are listed as notes.
 *
 * The `spend-report` job emails the previous month's report once, on the
 * first run of each month.
 */
final class Spend_Report {

	/**
	 * Source label => SKU prefix.
	 */
	public const SOURCES = array(
		'Google Places' => 'google_',
		'Yelp'          => 'yelp_',
		'Foursquare'    => 'foursquare_',
	);

	public const NOTES = array(
		'OpenStreetMap Overpass: free, not metered (weekly import).',
		'OpenFreeMap map tiles: free, not metered.',
		'Google Maps Embed API: free with no usage limit, not metered.',
		'SendFox: flat monthly plan, not per call.',
	);

	/**
	 * The report rows for a month.
	 *
	 * @param string $month YYYY-MM.
	 * @return array{month: string, rows: array<int, array{source: string, calls: int, free_calls: int, billable_calls: int, estimated_cost: float}>, total_cost: float, monthly_cap: float, run_cap: float}
	 */
	public static function build( string $month ): array {
		$usage = Budget::usage_for( $month );
		$rows  = array();
		$total = 0.0;
		foreach ( self::SOURCES as $label => $prefix ) {
			$row = array(
				'source'         => $label,
				'calls'          => 0,
				'free_calls'     => 0,
				'billable_calls' => 0,
				'estimated_cost' => 0.0,
			);
			foreach ( $usage['calls'] as $sku => $calls ) {
				if ( 0 !== strpos( (string) $sku, $prefix ) ) {
					continue;
				}
				$free                   = min( (int) $calls, Pricing::free_per_month( (string) $sku ) );
				$row['calls']          += (int) $calls;
				$row['free_calls']     += $free;
				$row['billable_calls'] += (int) $calls - $free;
				$row['estimated_cost'] += ( (int) $calls - $free ) * Pricing::price( (string) $sku );
			}
			$row['estimated_cost'] = round( $row['estimated_cost'], 2 );
			$total                += $row['estimated_cost'];
			$rows[]                = $row;
		}
		return array(
			'month'       => $month,
			'rows'        => $rows,
			'total_cost'  => round( $total, 2 ),
			'monthly_cap' => Config::money( Config::MONTHLY_CAP_USD, Config::DEFAULT_MONTHLY_CAP_USD ),
			'run_cap'     => Config::money( Config::RUN_CAP_USD, Config::DEFAULT_RUN_CAP_USD ),
		);
	}

	/**
	 * The month before the current one (UTC), YYYY-MM.
	 *
	 * @param int|null $now Unix time.
	 * @return string
	 */
	public static function previous_month( ?int $now = null ): string {
		$now = $now ?? time();
		return gmdate( 'Y-m', (int) gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ) - 1, 1, (int) gmdate( 'Y', $now ) ) );
	}

	/**
	 * Plain-text lines for the email and the command.
	 *
	 * @param array<string, mixed> $report From build().
	 * @return string[]
	 */
	public static function lines( array $report ): array {
		$lines = array(
			sprintf( 'API usage and estimated spend for %s (UTC month).', $report['month'] ),
			'',
		);
		foreach ( $report['rows'] as $row ) {
			$lines[] = sprintf(
				'- %s: %s calls (%s free, %s billable), estimated %s',
				$row['source'],
				number_format( $row['calls'] ),
				number_format( $row['free_calls'] ),
				number_format( $row['billable_calls'] ),
				Pricing::format( $row['estimated_cost'] )
			);
		}
		$lines[] = '';
		$lines[] = sprintf( 'Total estimated: %s. Caps: %s per month for page views, %s per run.', Pricing::format( $report['total_cost'] ), Pricing::format( $report['monthly_cap'] ), Pricing::format( $report['run_cap'] ) );
		$lines[] = 'Estimates price calls past 90% of each free allowance at list price, so they err high. Check each provider\'s billing page for the real bill.';
		$lines[] = '';
		$lines[] = 'Not metered here:';
		foreach ( self::NOTES as $note ) {
			$lines[] = '- ' . $note;
		}
		return $lines;
	}

	/**
	 * Emails the previous month's report once (the `spend-report` job).
	 *
	 * @param int|null $now Unix time.
	 * @return string Summary.
	 */
	public static function run( ?int $now = null ): string {
		Budget::archive_previous_month();
		$month = self::previous_month( $now );
		if ( ( Jobs::state( Jobs::SPEND_REPORT )['reported_month'] ?? '' ) === $month ) {
			return sprintf( 'Spend report for %s already sent.', $month );
		}
		$report = self::build( $month );
		$sent   = Alerts::send( sprintf( 'API spend report for %s: %s estimated', $month, Pricing::format( $report['total_cost'] ) ), self::lines( $report ) );
		if ( $sent ) {
			Jobs::update_state( Jobs::SPEND_REPORT, array( 'reported_month' => $month ) );
		}
		return sprintf( 'Spend report for %s: %s estimated; %s.', $month, Pricing::format( $report['total_cost'] ), $sent ? 'email sent' : 'email could not be sent' );
	}
}
