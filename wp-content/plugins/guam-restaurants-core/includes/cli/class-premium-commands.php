<?php
/**
 * WP-CLI commands for premium profiles.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Commerce\Woo_Checkout;
use GuamRestaurants\Core\Premium\Premium;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants premium-products`.
 */
final class Premium_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants premium-products', array( self::class, 'products' ) );
	}

	/**
	 * Creates the monthly and annual premium profile products in
	 * WooCommerce if they are missing. Safe to run again: existing products
	 * (and any price changed in wp-admin) are left alone.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants premium-products
	 *
	 * @return void
	 */
	public static function products(): void {
		if ( ! Woo_Checkout::available() ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}
		$counts = Woo_Checkout::create_products();
		$rows   = array();
		foreach ( $counts['ids'] as $plan => $id ) {
			$product = wc_get_product( $id );
			$rows[]  = array(
				'plan'   => $plan,
				'id'     => $id,
				'days'   => Premium::PLANS[ $plan ]['days'],
				'price'  => $product ? $product->get_price() : '',
				'status' => $product ? $product->get_status() : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'plan', 'id', 'days', 'price', 'status' ) );
		$message = sprintf( 'Premium products: created %d, updated 0, unchanged %d, failed %d.', $counts['created'], $counts['unchanged'], $counts['failed'] );
		if ( $counts['failed'] > 0 ) {
			WP_CLI::error( $message );
		}
		WP_CLI::success( $message );
	}
}
