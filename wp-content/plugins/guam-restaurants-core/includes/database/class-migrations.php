<?php
/**
 * One-time setting fixes that ship with an update.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Database;

use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Runs each migration once per site, in order, and remembers which ran.
 *
 * Updates must never overwrite settings someone chose. When an update has to
 * change a stored setting on purpose (a wrong default, a renamed option), add
 * a migration here instead of calling update_option() on every load or in
 * the activation hook. A migration runs once; if someone changes the setting
 * again afterwards, their value is kept.
 *
 * To add one, append an entry to definitions() with a new, never-reused ID
 * (date plus a short name). Where you can, change a value only while it
 * still holds the old default, so a choice someone made is kept even on the
 * first run. Never edit or remove an entry that has shipped: sites that
 * already ran it will not run it again.
 */
final class Migrations {

	public const OPTION = 'guam_restaurants_core_migrations';

	public const LOCK_OPTION = 'guam_restaurants_core_migrations_lock';

	public const LOCK_TTL = 600;

	/**
	 * The migrations, oldest first.
	 *
	 * @return array<string, callable(): void> ID => callback.
	 */
	public static function definitions(): array {
		return array();
	}

	/**
	 * IDs already applied on this site.
	 *
	 * @return array<string, int> ID => Unix time it ran.
	 */
	public static function applied(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Migrations not yet applied, in order.
	 *
	 * @param array<string, callable>|null $definitions Defaults to definitions().
	 * @return array<string, callable>
	 */
	public static function pending( ?array $definitions = null ): array {
		return array_diff_key( $definitions ?? self::definitions(), self::applied() );
	}

	/**
	 * Runs pending migrations on a normal page load, unless
	 * GUAM_RESTAURANTS_DB_UPGRADE=cli leaves that to `wp guam-restaurants db upgrade`.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( ! self::pending() ) {
			return;
		}
		if ( 'cli' === strtolower( Config::get( Config::DB_UPGRADE ) ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Runs pending migrations under a lock. Each one is recorded as soon as
	 * it succeeds; one that throws stops the run and is retried next time.
	 *
	 * @param array<string, callable>|null $definitions Defaults to definitions().
	 * @return string[]|null IDs applied by this call, or null when another run holds the lock.
	 */
	public static function run( ?array $definitions = null ): ?array {
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			if ( time() - (int) get_option( self::LOCK_OPTION ) < self::LOCK_TTL ) {
				return null;
			}
			update_option( self::LOCK_OPTION, time(), false );
		}

		$ran = array();
		try {
			foreach ( self::pending( $definitions ) as $id => $callback ) {
				call_user_func( $callback );
				$applied        = self::applied();
				$applied[ $id ] = time();
				update_option( self::OPTION, $applied, false );
				$ran[] = (string) $id;
			}
		} finally {
			delete_option( self::LOCK_OPTION );
		}
		return $ran;
	}
}
