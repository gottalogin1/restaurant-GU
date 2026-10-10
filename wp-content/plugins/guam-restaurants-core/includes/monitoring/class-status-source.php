<?php
/**
 * A source that can say whether a restaurant is still open.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

defined( 'ABSPATH' ) || exit;

/**
 * Business status lookups for the monthly status check. Results are used
 * in memory only; nothing from the source is stored except its own ID.
 */
interface Status_Source {

	public const OPEN    = 'open';
	public const CLOSED  = 'closed';
	public const UNKNOWN = 'unknown';

	/**
	 * Source key: google or yelp.
	 *
	 * @return string
	 */
	public function source(): string;

	/**
	 * Post meta key holding this source's ID.
	 *
	 * @return string
	 */
	public function id_meta_key(): string;

	/**
	 * Billing unit (Pricing SKU) of one check.
	 *
	 * @return string
	 */
	public function sku(): string;

	/**
	 * Whether the place is permanently closed. Temporary closures count as open.
	 *
	 * @param string $id Source ID.
	 * @return string One of the constants.
	 * @throws \RuntimeException On request failure.
	 */
	public function check( string $id ): string;
}
