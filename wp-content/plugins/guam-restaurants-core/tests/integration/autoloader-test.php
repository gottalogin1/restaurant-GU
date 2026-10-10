<?php
/**
 * Autoloader tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Autoloader;
use WP_UnitTestCase;

/**
 * Autoloader file mapping.
 *
 * @covers \GuamRestaurants\Core\Autoloader
 */
class Autoloader_Test extends WP_UnitTestCase {

	/**
	 * Class names map to WordPress-style file names.
	 */
	public function test_maps_class_names_to_wordpress_file_names() {
		$loader = new Autoloader( 'GuamRestaurants\\Core', '/base/' );

		$this->assertSame( '/base/class-plugin.php', $loader->file_for( 'GuamRestaurants\\Core\\Plugin' ) );
		$this->assertSame( '/base/class-content-types.php', $loader->file_for( 'GuamRestaurants\\Core\\Content_Types' ) );
		$this->assertSame( '/base/imports/class-osm-importer.php', $loader->file_for( '\\GuamRestaurants\\Core\\Imports\\Osm_Importer' ) );
		$this->assertSame( '/base/ad-serving/class-ad-slot.php', $loader->file_for( 'GuamRestaurants\\Core\\AdServing\\AdSlot' ) );
	}

	/**
	 * Classes outside the namespace are left to other autoloaders.
	 */
	public function test_ignores_other_namespaces() {
		$loader = new Autoloader( 'GuamRestaurants\\Core', '/base/' );

		$this->assertNull( $loader->file_for( 'Other\\Plugin' ) );
		$this->assertNull( $loader->file_for( 'GuamRestaurants\\CoreExtra\\Plugin' ) );
	}

	/**
	 * Real plugin classes load through the registered autoloader.
	 */
	public function test_plugin_classes_autoload() {
		$this->assertTrue( class_exists( \GuamRestaurants\Core\Activator::class ) );
		$this->assertTrue( class_exists( \GuamRestaurants\Core\Content_Types::class ) );
	}
}
