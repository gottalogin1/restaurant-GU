<?php
/**
 * PSR-4 style autoloader that follows WordPress file naming.
 *
 * Maps `GuamRestaurants\Core\Sub_Space\Foo_Bar` to `includes/sub-space/class-foo-bar.php`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Loads plugin classes on demand.
 */
final class Autoloader {

	/**
	 * Namespace prefix this autoloader handles, with a trailing backslash.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Base directory for the namespace prefix, with a trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Creates and registers an autoloader.
	 *
	 * @param string $prefix   Namespace prefix, for example `GuamRestaurants\Core`.
	 * @param string $base_dir Directory holding the class files.
	 * @return self
	 */
	public static function register( string $prefix, string $base_dir ): self {
		$loader = new self( $prefix, $base_dir );
		spl_autoload_register( array( $loader, 'load' ) );
		return $loader;
	}

	/**
	 * Constructor.
	 *
	 * @param string $prefix   Namespace prefix.
	 * @param string $base_dir Directory holding the class files.
	 */
	public function __construct( string $prefix, string $base_dir ) {
		$this->prefix   = trim( $prefix, '\\' ) . '\\';
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Returns the file path a class would live in, or null if the class is outside the prefix.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string|null
	 */
	public function file_for( string $class_name ): ?string {
		$class_name = ltrim( $class_name, '\\' );
		if ( 0 !== strpos( $class_name, $this->prefix ) ) {
			return null;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $this->prefix ) ) );
		$class = array_pop( $parts );
		$parts = array_map( array( $this, 'to_file_slug' ), $parts );

		$dir = $parts ? implode( '/', $parts ) . '/' : '';

		return $this->base_dir . $dir . 'class-' . $this->to_file_slug( $class ) . '.php';
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public function load( string $class_name ): void {
		$file = $this->file_for( $class_name );
		if ( null !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Converts `Foo_Bar` or `FooBar` to `foo-bar`.
	 *
	 * @param string $name Class or namespace segment.
	 * @return string
	 */
	private function to_file_slug( string $name ): string {
		$name = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '-', $name );
		return strtolower( str_replace( '_', '-', $name ) );
	}
}
