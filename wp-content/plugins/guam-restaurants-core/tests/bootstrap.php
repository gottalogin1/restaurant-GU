<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library, then this plugin.
 *
 * Run through Docker so WordPress core and the test database are available:
 * `make test` (see README.md).
 *
 * @package GuamRestaurants\Core
 */

$guam_project_root = dirname( __DIR__, 4 );

require_once $guam_project_root . '/vendor/autoload.php';

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $guam_project_root . '/vendor/yoast/phpunit-polyfills' );

$guam_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $guam_tests_dir ) {
	$guam_tests_dir = $guam_project_root . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $guam_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		// WooCommerce, when `make woocommerce` has put it in place, for the checkout tests.
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $woocommerce ) ) {
			require $woocommerce;
		}
		require dirname( __DIR__ ) . '/guam-restaurants-core.php';
	}
);

// WooCommerce's tables, before its init hooks query them (the test database starts empty).
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_roles();
		}
	}
);

require $guam_tests_dir . '/includes/bootstrap.php';

// Real tables, created once before any test transaction starts.
\GuamRestaurants\Core\Database\Schema::install();
\GuamRestaurants\Core\Highlights\Roles::install();

require __DIR__ . '/class-test-case.php';
require __DIR__ . '/class-fake-api.php';
require __DIR__ . '/class-ratings-fixtures.php';
require __DIR__ . '/class-guam-fixtures.php';
require __DIR__ . '/class-guam-test-case.php';
