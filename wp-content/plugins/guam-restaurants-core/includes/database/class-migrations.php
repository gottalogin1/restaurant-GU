<?php
/**
 * One-time setting fixes that ship with an update.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Database;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Mall_Verification;
use GuamRestaurants\Core\Site\Intro;
use GuamRestaurants\Core\Term_Meta;

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
		return array(
			// Visitor accounts keep saved lists (Saved\Members). WordPress ships with
			// sign-up off; turn it on once, as Subscribers, and leave it to the admin after.
			'2026-10-20-visitor-signup'          => static function (): void {
				if ( ! get_option( 'users_can_register' ) ) {
					update_option( 'users_can_register', 1 );
				}
				if ( '' === (string) get_option( 'default_role', '' ) ) {
					update_option( 'default_role', 'subscriber' );
				}
			},
			// Visitor photo originals keep camera and location data; move the ones saved
			// in the public uploads folders into the private folder (Security\Private_Uploads).
			'2026-10-26-private-photo-originals' => static function (): void {
				\GuamRestaurants\Core\Photos\Photo_Submissions::make_originals_private();
			},
			// Sanjay, 2026-10-26: the cuisine is spelled CHamoru and The Plaza is now
			// Dusit Place (web addresses unchanged); then the approved page intros
			// go into Descriptions that are still empty (Site\Intro::fill()).
			'2026-10-26-page-intros'             => static function (): void {
				self::rename_term( Content_Types::TAX_CUISINE, 'chamorro', 'Chamorro', 'CHamoru' );
				$mall = self::rename_term( Content_Types::TAX_MALL, 'the-plaza', 'The Plaza', 'Dusit Place' );
				if ( $mall > 0 ) {
					$aliases = (array) get_term_meta( $mall, Term_Meta::ALIASES, true );
					if ( ! in_array( 'The Plaza', $aliases, true ) ) {
						update_term_meta( $mall, Term_Meta::ALIASES, array_values( array_filter( array_merge( array( 'The Plaza' ), $aliases ) ) ) );
					}
				}
				Intro::fill();
			},
		);
	}

	/**
	 * Renames a seeded term while it still has its old name; a name someone
	 * changed in wp-admin is kept. The slug, and so the web address, stays.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Term slug.
	 * @param string $old      Name to replace.
	 * @param string $name     New name.
	 * @return int Term ID, renamed or not; 0 when there is no such term.
	 */
	private static function rename_term( string $taxonomy, string $slug, string $old, string $name ): int {
		$term = Mall_Verification::with_unverified(
			static function () use ( $slug, $taxonomy ) {
				return get_term_by( 'slug', $slug, $taxonomy );
			}
		);
		if ( ! $term instanceof \WP_Term ) {
			return 0;
		}
		if ( $old === $term->name ) {
			wp_update_term( $term->term_id, $taxonomy, array( 'name' => $name ) );
		}
		return (int) $term->term_id;
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
