<?php
/**
 * Custom tables, created and upgraded with dbDelta().
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the plugin's custom tables (design/content-model.md §5).
 *
 * There is deliberately no rating snapshot table: no third-party source
 * permits storing rating history (docs/data-sources.md §7).
 */
final class Schema {

	/**
	 * Bump when a table definition changes so upgrades run dbDelta() again.
	 */
	public const VERSION = '4';

	public const VERSION_OPTION = 'guam_restaurants_core_db_version';

	public const AWARDS         = 'gr_awards';
	public const CHANGE_LOG     = 'gr_change_log';
	public const INGESTION_RUNS = 'gr_ingestion_runs';
	public const REVIEW_QUEUE   = 'gr_review_queue';
	public const HIGHLIGHTS     = 'gr_highlights';
	public const AD_DAILY_STATS = 'gr_ad_daily_stats';

	/**
	 * Tables with a restaurant_id column, cleaned up when a restaurant is deleted.
	 */
	public const RESTAURANT_TABLES = array(
		self::AWARDS,
		self::CHANGE_LOG,
		self::REVIEW_QUEUE,
		self::HIGHLIGHTS,
	);

	/**
	 * Returns the prefixed table name.
	 *
	 * @param string $table One of the table constants.
	 * @return string
	 */
	public static function table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Creates or upgrades every table.
	 *
	 * @return void
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::definitions() as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Runs install() when the stored schema version is out of date.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Deletes a restaurant's rows from the custom tables.
	 *
	 * @param int $post_id Post being deleted.
	 * @return void
	 */
	public static function delete_restaurant_rows( int $post_id ): void {
		global $wpdb;

		if ( \GuamRestaurants\Core\Content_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( self::RESTAURANT_TABLES as $table ) {
			$wpdb->delete( self::table( $table ), array( 'restaurant_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * CREATE TABLE statements in dbDelta format (two spaces after PRIMARY KEY, KEY not INDEX).
	 *
	 * @return array<string, string> Keyed by unprefixed table name.
	 */
	public static function definitions(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$awards         = self::table( self::AWARDS );
		$change_log     = self::table( self::CHANGE_LOG );
		$ingestion_runs = self::table( self::INGESTION_RUNS );
		$review_queue   = self::table( self::REVIEW_QUEUE );
		$highlights     = self::table( self::HIGHLIGHTS );
		$ad_daily_stats = self::table( self::AD_DAILY_STATS );

		return array(
			self::AWARDS         => "CREATE TABLE $awards (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  restaurant_id bigint(20) unsigned NOT NULL,
  program varchar(191) NOT NULL,
  year smallint(5) unsigned NOT NULL,
  category varchar(191) NOT NULL DEFAULT '',
  placement varchar(20) NOT NULL,
  source_url varchar(2048) NOT NULL,
  source_note text NULL,
  verified tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uniq_award (restaurant_id,program,year,category),
  KEY restaurant_year (restaurant_id,year),
  KEY program_year (program,year)
) $charset;",

			self::CHANGE_LOG     => "CREATE TABLE $change_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  restaurant_id bigint(20) unsigned NOT NULL,
  field varchar(64) NOT NULL,
  old_value longtext NULL,
  new_value longtext NULL,
  source varchar(32) NOT NULL,
  detected_at datetime NOT NULL,
  run_id bigint(20) unsigned NULL,
  PRIMARY KEY  (id),
  KEY restaurant_detected (restaurant_id,detected_at),
  KEY field_detected (field,detected_at),
  KEY run_id (run_id)
) $charset;",

			self::INGESTION_RUNS => "CREATE TABLE $ingestion_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source varchar(32) NOT NULL,
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  created int(10) unsigned NOT NULL DEFAULT 0,
  updated int(10) unsigned NOT NULL DEFAULT 0,
  unchanged int(10) unsigned NOT NULL DEFAULT 0,
  failed int(10) unsigned NOT NULL DEFAULT 0,
  notes text NULL,
  errors longtext NULL,
  PRIMARY KEY  (id),
  KEY source_started (source,started_at)
) $charset;",

			self::REVIEW_QUEUE   => "CREATE TABLE $review_queue (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(32) NOT NULL,
  payload longtext NOT NULL,
  confidence decimal(4,3) NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  restaurant_id bigint(20) unsigned NULL,
  source varchar(32) NOT NULL,
  run_id bigint(20) unsigned NULL,
  resolved_by bigint(20) unsigned NULL,
  resolved_at datetime NULL,
  PRIMARY KEY  (id),
  KEY status_type (status,type,created_at),
  KEY restaurant_id (restaurant_id)
) $charset;",

			self::HIGHLIGHTS     => "CREATE TABLE $highlights (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  restaurant_id bigint(20) unsigned NOT NULL,
  type varchar(20) NOT NULL,
  body text NOT NULL,
  author_name varchar(191) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  parent_id bigint(20) unsigned NULL,
  author_user_id bigint(20) unsigned NULL,
  disclosure varchar(255) NULL,
  moderation_reason varchar(32) NULL,
  moderated_by bigint(20) unsigned NULL,
  moderated_at datetime NULL,
  rating tinyint(3) unsigned NULL,
  dishes text NULL,
  email_verified tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY restaurant_status_type (restaurant_id,status,type),
  KEY status_created (status,created_at),
  KEY parent_id (parent_id)
) $charset;",

			self::AD_DAILY_STATS => "CREATE TABLE $ad_daily_stats (
  ad_id bigint(20) unsigned NOT NULL,
  stat_date date NOT NULL,
  page_type varchar(32) NOT NULL,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (ad_id,stat_date,page_type),
  KEY stat_date (stat_date)
) $charset;",
		);
	}
}
