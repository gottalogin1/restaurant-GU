<?php
/**
 * WordPress test-suite configuration. Values come from environment variables set in docker-compose.yml.
 *
 * The test suite DROPS every table in this database on each run, so it must be a dedicated database.
 *
 * @package GuamRestaurants\Core
 */

$guam_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

define( 'ABSPATH', rtrim( $guam_env( 'WP_CORE_DIR', '/var/www/html/' ), '/' ) . '/' );

define( 'DB_NAME', $guam_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', $guam_env( 'WP_TESTS_DB_USER', 'wordpress' ) );
define( 'DB_PASSWORD', $guam_env( 'WP_TESTS_DB_PASSWORD', 'wordpress' ) );
define( 'DB_HOST', $guam_env( 'WP_TESTS_DB_HOST', 'db' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Guam Restaurants Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
