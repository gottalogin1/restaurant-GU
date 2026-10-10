<?php
/**
 * Base test case.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Term_Meta;
use WP_UnitTestCase;

/**
 * Re-registers meta before each test: the WordPress test case unregisters
 * every meta key after each test, but plugins register theirs only once, on init.
 *
 * The custom tables are created once in the bootstrap, as real tables, so
 * tests never run CREATE TABLE (which would commit the test transaction).
 */
abstract class Test_Case extends WP_UnitTestCase {

	/**
	 * Registers meta.
	 */
	public function set_up() {
		parent::set_up();
		Restaurant_Meta::register();
		Term_Meta::register();
	}
}
