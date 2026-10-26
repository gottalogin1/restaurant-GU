<?php
/**
 * Selling premium profiles through WooCommerce.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Commerce;

use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Premium\Premium;

defined( 'ABSPATH' ) || exit;

/**
 * The small part WooCommerce cannot do by itself: tie a premium profile
 * product to one claimed listing and switch the premium profile on when
 * the order is paid. Everything else (cart, checkout, PayPal, receipts,
 * My Account, PDF invoices through WowInvoice, discounts through
 * WowRevenue) is WooCommerce and its plugins, configured in wp-admin.
 *
 * - A product is a premium profile product when its "Premium profile
 *   days" field (_gr_premium_days) is set. `wp guam-restaurants
 *   premium-products` creates the monthly and annual ones.
 * - The owner buys from My restaurant, which links to the cart with the
 *   restaurant and a nonce. Adding to the cart is refused unless the
 *   buyer is that restaurant's verified owner (Roles::can_speak_for);
 *   the cart is checked again before checkout.
 * - The order line keeps the restaurant (_gr_restaurant_id) and the days
 *   (_gr_premium_days). When the order reaches processing or completed
 *   (paid), the days are added once (_gr_premium_applied). Refunded or
 *   cancelled takes them off again, once (_gr_premium_reversed).
 * - Owners keep their wp-admin dashboard: WooCommerce otherwise sends
 *   users without edit_posts to My Account.
 * - WooCommerce's account and cart icons are kept out of the site header
 *   (WooCommerce adds them to block theme headers by itself). Visitors use
 *   Saved and Sign in there (Saved\View), and only owners buy, from My
 *   restaurant.
 *
 * There is no automatic renewal yet; WPXPO WowSubscriptions is planned
 * for it once released (docs/billing.md §4). Owners renew by buying
 * again, and time already paid for is kept (Premium::extend).
 */
final class Woo_Checkout {

	public const META_DAYS     = '_gr_premium_days';
	public const META_PLAN     = '_gr_premium_plan';
	public const ITEM_REST     = '_gr_restaurant_id';
	public const ITEM_DAYS     = '_gr_premium_days';
	public const ITEM_APPLIED  = '_gr_premium_applied';
	public const ITEM_REVERSED = '_gr_premium_reversed';
	public const CART_KEY      = 'gr_restaurant';
	public const NONCE_FIELD   = '_grnonce';

	/**
	 * WooCommerce's front-end scripts and styles. Only shop pages (cart,
	 * checkout, My Account, products) use them, so other pages drop them;
	 * with them goes jQuery, which no other public page uses.
	 */
	public const SHOP_SCRIPTS = array( 'woocommerce', 'wc-add-to-cart', 'wc-cart-fragments', 'wc-jquery-blockui', 'wc-js-cookie', 'sourcebuster-js', 'wc-order-attribution' );
	public const SHOP_STYLES  = array( 'woocommerce-general', 'woocommerce-layout', 'woocommerce-smallscreen', 'woocommerce-inline', 'woocommerce-blocktheme', 'wc-blocks-style' );

	/**
	 * Hooks registration. The hooks only fire when WooCommerce is active.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_product' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'validate_add' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( self::class, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'show_item_data' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'check_cart' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_line_item' ), 10, 3 );
		add_action( 'woocommerce_order_status_processing', array( self::class, 'paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'paid' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'reversed' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'reversed' ), 10, 1 );
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'keep_owner_admin' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( self::class, 'keep_owner_admin' ) );
		add_filter( 'hooked_block_types', array( self::class, 'no_header_icons' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'drop_shop_assets' ), 100 );
		// Some are enqueued while blocks render, after wp_enqueue_scripts; late ones print in the footer.
		add_action( 'wp_footer', array( self::class, 'drop_shop_assets' ), 1 );
	}

	/**
	 * Whether WooCommerce is running.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return function_exists( 'WC' ) && class_exists( '\WC_Product_Simple' );
	}

	/**
	 * Premium profile days of a product, 0 when it is not one.
	 *
	 * @param \WC_Product|int|null $product Product.
	 * @return int
	 */
	public static function product_days( $product ): int {
		if ( ! self::available() ) {
			return 0;
		}
		$product = $product instanceof \WC_Product ? $product : wc_get_product( (int) $product );
		return $product instanceof \WC_Product ? max( 0, (int) $product->get_meta( self::META_DAYS ) ) : 0;
	}

	/**
	 * Published premium profile products the owner can buy, by plan.
	 *
	 * @return array<string, \WC_Product>
	 */
	public static function products(): array {
		if ( ! self::available() ) {
			return array();
		}
		$out = array();
		foreach ( array_keys( Premium::PLANS ) as $plan ) {
			$product = self::product_for_plan( $plan );
			if ( $product && 'publish' === $product->get_status() && $product->is_purchasable() && self::product_days( $product ) > 0 ) {
				$out[ $plan ] = $product;
			}
		}
		return $out;
	}

	/**
	 * The product made for a plan, in any status.
	 *
	 * @param string $plan Plan key.
	 * @return \WC_Product|null
	 */
	public static function product_for_plan( string $plan ): ?\WC_Product {
		if ( ! self::available() ) {
			return null;
		}
		$ids     = wc_get_products(
			array(
				'status'     => array( 'publish', 'draft', 'pending', 'private' ),
				'limit'      => 1,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'ids',
				'meta_key'   => self::META_PLAN, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $plan, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$product = $ids ? wc_get_product( (int) $ids[0] ) : null;
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Creates the monthly and annual products if missing. Existing ones are
	 * left alone, so prices changed in wp-admin are kept.
	 *
	 * @return array{created: int, unchanged: int, failed: int, ids: array<string, int>}
	 */
	public static function create_products(): array {
		$counts = array(
			'created'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
			'ids'       => array(),
		);
		if ( ! self::available() ) {
			$counts['failed'] = count( Premium::PLANS );
			return $counts;
		}
		$names = array(
			'monthly' => __( 'Premium profile, 1 month', 'guam-restaurants-core' ),
			'annual'  => __( 'Premium profile, 12 months', 'guam-restaurants-core' ),
		);
		foreach ( Premium::PLANS as $plan => $info ) {
			$existing = self::product_for_plan( $plan );
			if ( $existing ) {
				$counts['ids'][ $plan ] = $existing->get_id();
				++$counts['unchanged'];
				continue;
			}
			try {
				$product = new \WC_Product_Simple();
				$product->set_name( $names[ $plan ] ?? $plan );
				$product->set_status( 'publish' );
				$product->set_regular_price( $info['price'] );
				$product->set_virtual( true );
				$product->set_sold_individually( true );
				$product->set_catalog_visibility( 'hidden' );
				$product->set_description( __( 'A premium profile for the restaurant you manage: a call-to-action button, up to 20 photos, and no other restaurant\'s Sponsored placements on your page. It does not change ratings, awards or where your restaurant appears in lists. Buy it from My restaurant in your account so it is linked to your listing. It does not renew automatically.', 'guam-restaurants-core' ) );
				$product->update_meta_data( self::META_DAYS, (int) $info['days'] );
				$product->update_meta_data( self::META_PLAN, $plan );
				$id = $product->save();
				if ( $id > 0 ) {
					$counts['ids'][ $plan ] = (int) $id;
					++$counts['created'];
				} else {
					++$counts['failed'];
				}
			} catch ( \Exception $e ) {
				++$counts['failed'];
			}
		}
		return $counts;
	}

	/**
	 * Link that puts a plan for a restaurant in the cart.
	 *
	 * @param \WC_Product $product       Product.
	 * @param int         $restaurant_id Restaurant.
	 * @return string
	 */
	public static function buy_url( \WC_Product $product, int $restaurant_id ): string {
		return add_query_arg(
			array(
				'add-to-cart'     => $product->get_id(),
				self::CART_KEY    => $restaurant_id,
				self::NONCE_FIELD => wp_create_nonce( self::nonce_action( $restaurant_id ) ),
			),
			wc_get_cart_url()
		);
	}

	/**
	 * Product edit screen: the days field.
	 *
	 * @return void
	 */
	public static function product_fields(): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_DAYS,
				'label'             => __( 'Premium profile days', 'guam-restaurants-core' ),
				'description'       => __( 'Set this only on premium profile products: the days a paid order adds to the buyer\'s listing (30 for monthly, 365 for annual). Leave empty for any other product.', 'guam-restaurants-core' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the days field. WooCommerce has checked the nonce and the
	 * edit_product capability before this action runs.
	 *
	 * @param \WC_Product $product Product.
	 * @return void
	 */
	public static function save_product( $product ): void {
		if ( ! $product instanceof \WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce's product capability.
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce).
		$days = isset( $_POST[ self::META_DAYS ] ) ? absint( wp_unslash( $_POST[ self::META_DAYS ] ) ) : 0;
		if ( $days > 0 ) {
			$product->update_meta_data( self::META_DAYS, $days );
		} else {
			$product->delete_meta_data( self::META_DAYS );
		}
	}

	/**
	 * Refuses a premium profile in the cart unless the buyer is the
	 * restaurant's verified owner.
	 *
	 * @param bool $passed     Earlier result.
	 * @param int  $product_id Product.
	 * @return bool
	 */
	public static function validate_add( $passed, $product_id ): bool {
		if ( ! $passed || self::product_days( (int) $product_id ) < 1 ) {
			return (bool) $passed;
		}
		$restaurant = self::requested_restaurant();
		$error      = self::cart_problem( get_current_user_id(), $restaurant );
		if ( '' === $error ) {
			$nonce = isset( $_REQUEST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE_FIELD ] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, self::nonce_action( $restaurant ) ) ) {
				$error = __( 'That link has expired. Open My restaurant in your account and choose the plan again.', 'guam-restaurants-core' );
			}
		}
		if ( '' !== $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Remembers the restaurant on the cart line.
	 *
	 * @param array<string, mixed> $data       Cart item data.
	 * @param int                  $product_id Product.
	 * @return array<string, mixed>
	 */
	public static function cart_item_data( $data, $product_id ): array {
		$data = (array) $data;
		if ( self::product_days( (int) $product_id ) > 0 ) {
			$data[ self::CART_KEY ] = self::requested_restaurant();
		}
		return $data;
	}

	/**
	 * Shows the restaurant in the cart and checkout.
	 *
	 * @param array<int, array<string, string>> $item_data Lines.
	 * @param array<string, mixed>              $cart_item Cart item.
	 * @return array<int, array<string, string>>
	 */
	public static function show_item_data( $item_data, $cart_item ): array {
		$item_data = (array) $item_data;
		if ( ! empty( $cart_item[ self::CART_KEY ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Restaurant', 'guam-restaurants-core' ),
				'value' => get_the_title( (int) $cart_item[ self::CART_KEY ] ),
			);
		}
		return $item_data;
	}

	/**
	 * Checks premium lines again before checkout (the owner link may have
	 * changed since they were added) and removes any that no longer fit.
	 *
	 * @return void
	 */
	public static function check_cart(): void {
		if ( ! self::available() || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( self::product_days( (int) ( $item['product_id'] ?? 0 ) ) < 1 ) {
				continue;
			}
			$error = self::cart_problem( get_current_user_id(), (int) ( $item[ self::CART_KEY ] ?? 0 ) );
			if ( '' !== $error ) {
				WC()->cart->remove_cart_item( $key );
				wc_add_notice( $error, 'error' );
			}
		}
	}

	/**
	 * Copies the restaurant and days to the order line.
	 *
	 * @param \WC_Order_Item_Product $item   Order line.
	 * @param string                 $key    Cart item key.
	 * @param array<string, mixed>   $values Cart item.
	 * @return void
	 */
	public static function order_line_item( $item, $key, $values ): void {
		unset( $key );
		$days = self::product_days( (int) ( $values['product_id'] ?? 0 ) );
		if ( ! $item instanceof \WC_Order_Item_Product || $days < 1 || empty( $values[ self::CART_KEY ] ) ) {
			return;
		}
		$restaurant = (int) $values[ self::CART_KEY ];
		$item->add_meta_data( self::ITEM_REST, $restaurant, true );
		$item->add_meta_data( self::ITEM_DAYS, $days, true );
		$item->add_meta_data( __( 'Restaurant', 'guam-restaurants-core' ), get_the_title( $restaurant ), true );
	}

	/**
	 * A paid order: adds each line's days once.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function paid( $order_id ): void {
		$order = self::order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$line = self::line( $item );
			if ( null === $line ) {
				continue;
			}
			if ( '' !== (string) $item->get_meta( self::ITEM_APPLIED ) && '' === (string) $item->get_meta( self::ITEM_REVERSED ) ) {
				continue;
			}
			/* translators: %s: order number. */
			$result = Premium::extend( $line['restaurant'], $line['days'], sprintf( __( 'Order #%s paid', 'guam-restaurants-core' ), $order->get_order_number() ) );
			if ( null === $result ) {
				/* translators: %d: restaurant ID. */
				$order->add_order_note( sprintf( __( 'Premium profile not added: restaurant %d was not found.', 'guam-restaurants-core' ), $line['restaurant'] ) );
				continue;
			}
			$item->update_meta_data( self::ITEM_APPLIED, $result['from'] . '/' . $result['until'] );
			$item->delete_meta_data( self::ITEM_REVERSED );
			$item->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: restaurant, 2: number of days, 3: end date. */
					__( 'Premium profile for %1$s: %2$d days added; it now runs until %3$s.', 'guam-restaurants-core' ),
					get_the_title( $line['restaurant'] ),
					$line['days'],
					$result['until']
				)
			);
		}
	}

	/**
	 * A refunded or cancelled order: takes added days off once.
	 *
	 * @param int $order_id Order.
	 * @return void
	 */
	public static function reversed( $order_id ): void {
		$order = self::order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$line = self::line( $item );
			if ( null === $line || '' === (string) $item->get_meta( self::ITEM_APPLIED ) || '' !== (string) $item->get_meta( self::ITEM_REVERSED ) ) {
				continue;
			}
			/* translators: 1: order number, 2: order status. */
			$until = Premium::shorten( $line['restaurant'], $line['days'], sprintf( __( 'Order #%1$s %2$s', 'guam-restaurants-core' ), $order->get_order_number(), $order->get_status() ) );
			$item->update_meta_data( self::ITEM_REVERSED, $until );
			$item->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: restaurant, 2: number of days, 3: end date. */
					__( 'Premium profile for %1$s: %2$d days taken off; it now ends %3$s.', 'guam-restaurants-core' ),
					get_the_title( $line['restaurant'] ),
					$line['days'],
					'' === $until ? '-' : $until
				)
			);
		}
	}

	/**
	 * Stops WooCommerce adding its account and mini cart blocks to headers.
	 *
	 * @param string[] $types Block types hooked at this spot.
	 * @return string[]
	 */
	public static function no_header_icons( $types ): array {
		return array_values( array_diff( (array) $types, array( 'woocommerce/customer-account', 'woocommerce/mini-cart' ) ) );
	}

	/**
	 * Takes WooCommerce's scripts and styles off pages that are not shop pages.
	 *
	 * @return void
	 */
	public static function drop_shop_assets(): void {
		if ( ! self::available() || is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
			return;
		}
		foreach ( self::SHOP_SCRIPTS as $handle ) {
			wp_dequeue_script( $handle );
		}
		foreach ( self::SHOP_STYLES as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Keeps wp-admin and the toolbar open to restaurant owners, who use My
	 * restaurant there.
	 *
	 * @param bool $block WooCommerce's decision.
	 * @return bool
	 */
	public static function keep_owner_admin( $block ): bool {
		$user_id = get_current_user_id();
		if ( $block && $user_id > 0 && user_can( $user_id, Roles::OWNER ) && Roles::owned_restaurants( $user_id ) ) {
			return false;
		}
		return (bool) $block;
	}

	/**
	 * Why a user cannot buy a premium profile for a restaurant, or ''.
	 *
	 * @param int $user_id       Buyer.
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	public static function cart_problem( int $user_id, int $restaurant_id ): string {
		if ( $user_id < 1 ) {
			return __( 'Log in with the account linked to your restaurant, then buy the premium profile from My restaurant.', 'guam-restaurants-core' );
		}
		if ( $restaurant_id < 1 || ! Roles::can_speak_for( $user_id, $restaurant_id ) ) {
			return __( 'A premium profile can only be bought by the verified owner of the restaurant, from My restaurant. Claim your listing first.', 'guam-restaurants-core' );
		}
		return '';
	}

	/**
	 * The restaurant and days on an order line, or null.
	 *
	 * @param mixed $item Order line.
	 * @return array{restaurant: int, days: int}|null
	 */
	private static function line( $item ): ?array {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}
		$restaurant = (int) $item->get_meta( self::ITEM_REST );
		$days       = (int) $item->get_meta( self::ITEM_DAYS ) * max( 1, (int) $item->get_quantity() );
		return $restaurant > 0 && $days > 0 ? array(
			'restaurant' => $restaurant,
			'days'       => $days,
		) : null;
	}

	/**
	 * An order by ID.
	 *
	 * @param mixed $order_id Order ID.
	 * @return \WC_Order|null
	 */
	private static function order( $order_id ): ?\WC_Order {
		if ( ! self::available() ) {
			return null;
		}
		$order = wc_get_order( (int) $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * The restaurant in the add-to-cart request.
	 *
	 * @return int
	 */
	private static function requested_restaurant(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is checked in validate_add().
		return isset( $_REQUEST[ self::CART_KEY ] ) ? absint( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : 0;
	}

	/**
	 * Nonce action for buying for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return string
	 */
	private static function nonce_action( int $restaurant_id ): string {
		return 'gr_premium_buy_' . $restaurant_id;
	}
}
