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
		require dirname( __DIR__ ) . '/guam-restaurants-core.php';
	}
);

require $guam_tests_dir . '/includes/bootstrap.php';
