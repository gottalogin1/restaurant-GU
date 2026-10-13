<?php
/**
 * Scheduled jobs: definitions, runner, WP-Cron scheduling and health check.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Billing\Reminders;
use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Discovery\Discoverer;
use GuamRestaurants\Core\Discovery\Places_Client;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Newsletter\Newsletter;
use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Osm\Element_Mapper;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Tagging\Duplicate_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Jobs:
 *
 * - `osm`: weekly OpenStreetMap import, then duplicate detection.
 * - `discover`: daily Google Places discovery (needs a key). Works through
 *   the search log until nothing is due or the free allowance or cap is
 *   reached, so new searches run as soon as there is allowance and every
 *   search is re-checked weekly (Discovery\Search_Log).
 * - `google-status`: monthly Google business-status check (needs a key;
 *   stays inside the free allowance with the default $0 cap).
 * - `yelp-status`: monthly Yelp check, off unless enabled (Yelp has no free tier).
 * - `renewal-reminders`: daily; emails advertisers 14 and 3 days before an
 *   ad ends, and owners before a premium profile ends (Billing\Reminders).
 * - `health-check`: daily; emails the owner when a job missed its window.
 * - `newsletter`: weekly, Monday 06:00 Guam time; assembles the newsletter
 *   draft (Newsletter\Newsletter). It never sends anything to subscribers.
 *
 * Preferred: a real server cron runs `wp guam-restaurants run-job <job>`
 * (set GUAM_RESTAURANTS_CRON_MODE=server; docs/scheduling.md). Otherwise
 * WP-Cron runs them, which needs site visits to trigger. The health check
 * is also scheduled in WP-Cron in server mode as a backstop, so a dead
 * crontab is still noticed.
 */
final class Jobs {

	public const HOOK            = 'guam_restaurants_run_job';
	public const STATE_OPTION    = 'guam_restaurants_core_jobs';
	public const SINCE_OPTION    = 'guam_restaurants_core_jobs_since';
	public const MONTHLY         = 'guam_monthly';
	public const OSM             = 'osm';
	public const GOOGLE_STATUS   = 'google-status';
	public const DISCOVER        = 'discover';
	public const YELP_STATUS     = 'yelp-status';
	public const HEALTH_CHECK    = 'health-check';
	public const RENEWALS        = 'renewal-reminders';
	public const NEWSLETTER      = 'newsletter';
	public const SPEND_REPORT    = 'spend-report';
	public const MODE_SERVER     = 'server';
	public const MODE_WP_CRON    = 'wp-cron';
	public const HEALTH_MIN_HOUR = 20;

	/**
	 * Job definitions.
	 *
	 * @return array<string, array{label: string, source: string, interval: int, grace: int, recurrence: string, cron: string}>
	 */
	public static function definitions(): array {
		return array(
			self::OSM           => array(
				'label'      => 'OpenStreetMap import',
				'source'     => 'osm',
				'interval'   => WEEK_IN_SECONDS,
				'grace'      => DAY_IN_SECONDS,
				'recurrence' => 'weekly',
				'cron'       => '15 3 * * 1',
			),
			self::DISCOVER      => array(
				'label'      => 'Google discovery (search log)',
				'source'     => 'google',
				'interval'   => DAY_IN_SECONDS,
				'grace'      => DAY_IN_SECONDS,
				'recurrence' => 'daily',
				'cron'       => '0 4 * * *',
			),
			self::GOOGLE_STATUS => array(
				'label'      => 'Google status check',
				'source'     => 'google_status',
				'interval'   => 31 * DAY_IN_SECONDS,
				'grace'      => 3 * DAY_IN_SECONDS,
				'recurrence' => self::MONTHLY,
				'cron'       => '30 4 2 * *',
			),
			self::YELP_STATUS   => array(
				'label'      => 'Yelp status check',
				'source'     => 'yelp_status',
				'interval'   => 31 * DAY_IN_SECONDS,
				'grace'      => 3 * DAY_IN_SECONDS,
				'recurrence' => self::MONTHLY,
				'cron'       => '45 4 2 * *',
			),
			self::RENEWALS      => array(
				'label'      => 'Renewal reminder emails',
				'source'     => 'renewal_reminders',
				'interval'   => DAY_IN_SECONDS,
				'grace'      => DAY_IN_SECONDS,
				'recurrence' => 'daily',
				'cron'       => '30 22 * * *',
			),
			self::HEALTH_CHECK  => array(
				'label'      => 'Daily health check',
				'source'     => 'health_check',
				'interval'   => DAY_IN_SECONDS,
				'grace'      => 0,
				'recurrence' => 'daily',
				'cron'       => '0 7 * * *',
			),
			self::NEWSLETTER    => array(
				'label'      => 'Newsletter draft',
				'source'     => 'newsletter',
				'interval'   => WEEK_IN_SECONDS,
				'grace'      => DAY_IN_SECONDS,
				'recurrence' => 'weekly',
				'cron'       => '0 20 * * 0',
			),
			self::SPEND_REPORT  => array(
				'label'      => 'Monthly API spend report',
				'source'     => 'spend_report',
				'interval'   => 31 * DAY_IN_SECONDS,
				'grace'      => 3 * DAY_IN_SECONDS,
				'recurrence' => self::MONTHLY,
				'cron'       => '0 22 1 * *',
			),
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'cron_schedules', array( self::class, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 30 days.
		add_action( self::HOOK, array( self::class, 'run_from_cron' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Adds the monthly WP-Cron schedule.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedules( array $schedules ): array {
		$schedules[ self::MONTHLY ] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once a month', 'guam-restaurants-core' ),
		);
		return $schedules;
	}

	/**
	 * `server` or `wp-cron`.
	 *
	 * @return string
	 */
	public static function mode(): string {
		return self::MODE_SERVER === Config::get( Config::CRON_MODE ) ? self::MODE_SERVER : self::MODE_WP_CRON;
	}

	/**
	 * Whether a job can run here (keys and switches set).
	 *
	 * @param string $job Job.
	 * @return bool
	 */
	public static function enabled( string $job ): bool {
		switch ( $job ) {
			case self::GOOGLE_STATUS:
			case self::DISCOVER:
				return '' !== Config::get( Config::GOOGLE_PLACES_KEY );
			case self::YELP_STATUS:
				return '' !== Config::get( Config::YELP_KEY ) && in_array( strtolower( Config::get( Config::YELP_STATUS_CHECKS ) ), array( '1', 'true', 'yes', 'on' ), true );
			default:
				return isset( self::definitions()[ $job ] );
		}
	}

	/**
	 * Keeps WP-Cron events in line with the mode: every enabled job in
	 * wp-cron mode; only the health-check backstop in server mode.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		add_option( self::SINCE_OPTION, time() );
		$mode = self::mode();
		foreach ( self::definitions() as $job => $definition ) {
			$wanted = self::enabled( $job ) && ( self::MODE_WP_CRON === $mode || self::HEALTH_CHECK === $job );
			$next   = wp_next_scheduled( self::HOOK, array( $job ) );
			if ( $wanted && ! $next ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $definition['recurrence'], self::HOOK, array( $job ) );
			} elseif ( ! $wanted && $next ) {
				wp_clear_scheduled_hook( self::HOOK, array( $job ) );
			}
		}
	}

	/**
	 * Removes every scheduled job (plugin deactivation).
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * WP-Cron callback.
	 *
	 * @param string $job Job.
	 * @return void
	 */
	public static function run_from_cron( $job ): void {
		$job = (string) $job;
		// In server mode the crontab normally runs the health check; the WP-Cron copy is a backstop.
		if ( self::HEALTH_CHECK === $job && self::ran_recently( $job, self::HEALTH_MIN_HOUR * HOUR_IN_SECONDS ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) && in_array( $job, array( self::OSM, self::DISCOVER ), true ) ) {
			set_time_limit( 900 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long import in a cron request.
		}
		self::run( $job );
	}

	/**
	 * Runs a job and records the outcome. Exceptions are caught, logged as
	 * a failed ingestion run, and returned.
	 *
	 * @param string $job Job.
	 * @param array  $options Per-job options: `elements` (osm: use these instead of calling Overpass), `client` (osm: an Overpass_Client, for tests).
	 * @return array{ok: bool, run_id: int|null, summary: string, error: string, result: array<string, mixed>}
	 */
	public static function run( string $job, array $options = array() ): array {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $job ] ) ) {
			return self::outcome( false, null, '', 'Unknown job: ' . $job );
		}

		self::update_state( $job, array( 'last_started' => time() ) );

		try {
			switch ( $job ) {
				case self::OSM:
					$outcome = self::run_osm( $options );
					break;
				case self::DISCOVER:
					$outcome = self::run_discover( $options );
					break;
				case self::GOOGLE_STATUS:
				case self::YELP_STATUS:
					$outcome = self::run_status( $job, $options );
					break;
				case self::RENEWALS:
					$outcome = self::run_renewals( $options );
					break;
				case self::SPEND_REPORT:
					$outcome = self::outcome( true, null, Spend_Report::run(), '' );
					break;
				case self::NEWSLETTER:
					$draft   = Newsletter::assemble( $options );
					$outcome = self::outcome( true, null, Newsletter::summary( $draft ), '', $draft );
					break;
				default:
					$outcome = self::outcome( true, null, Health_Check::run(), '' );
			}
		} catch ( \Throwable $e ) {
			$attempts = $e instanceof Request_Failed ? $e->attempt_log() : array();
			$run_id   = in_array( $job, array( self::HEALTH_CHECK, self::RENEWALS, self::SPEND_REPORT ), true ) ? null : Ingestion_Runs::log_failure( $definitions[ $job ]['source'], $e->getMessage(), $attempts );
			// Also to the PHP error log, which the host's log monitoring reads (docs/monitoring.md §2).
			error_log( sprintf( '[guam-restaurants] job %s failed: %s', $job, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intended production log line.
			$outcome = self::outcome( false, $run_id ? $run_id : null, '', $e->getMessage() );
		}

		self::update_state(
			$job,
			array(
				'last_finished' => time(),
				'last_ok'       => $outcome['ok'],
				'last_success'  => $outcome['ok'] ? time() : ( self::state( $job )['last_success'] ?? 0 ),
				'last_run_id'   => $outcome['run_id'],
				'last_error'    => $outcome['error'],
			)
		);

		return $outcome;
	}

	/**
	 * The OSM job: import, closure checks, duplicate detection, alert.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	private static function run_osm( array $options ): array {
		$attempts = array();
		if ( isset( $options['elements'] ) ) {
			$elements = (array) $options['elements'];
		} else {
			$client   = $options['client'] ?? new Overpass_Client();
			$elements = $client->fetch()['elements'];
			$attempts = $client->attempt_log();
		}

		$importer = new Importer( new Element_Mapper( Cuisine_Map::from_file() ) );
		$result   = $importer->run( $elements );
		$run_id   = $result['run_id'];
		if ( $run_id ) {
			Ingestion_Runs::record_retries( $run_id, $attempts );
		}

		$duplicates = ( new Duplicate_Detector() )->run( false, $run_id );
		if ( $run_id ) {
			Ingestion_Runs::append_note( $run_id, Duplicate_Detector::summary( $duplicates['counts'] ) );
		}

		Alerts::closures_in_run( 'OpenStreetMap import', $result['closures']['closed'], $result['closures']['queued'], $run_id );

		$counts = $result['counts'];
		return self::outcome(
			true,
			$run_id,
			sprintf(
				'created %d, updated %d, unchanged %d, failed %d; %d missing from OSM; %s',
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$counts['failed'],
				count( $result['closures']['closed'] ),
				Duplicate_Detector::summary( $duplicates['counts'] )
			),
			'',
			$result + array( 'duplicates' => $duplicates )
		);
	}

	/**
	 * The discovery job: due searches from the search log, until the cap.
	 *
	 * @param array<string, mixed> $options Options: `client` (a Places_Client, for tests), `delay`, `sleeper`, `budget`, `max_requests`.
	 * @return array<string, mixed>
	 */
	private static function run_discover( array $options ): array {
		$client = $options['client'] ?? Places_Client::from_config();
		if ( null === $client ) {
			return self::outcome( true, null, 'Skipped: no Google Places key.', '' );
		}

		$discoverer = new Discoverer( $client, (int) ( $options['delay'] ?? 1 ), 3, $options['sleeper'] ?? null, $options['budget'] ?? null );
		$result     = $discoverer->run_from_log( 'all', false, $options['max_requests'] ?? null );

		return self::outcome(
			true,
			$result['run_id'],
			sprintf(
				'%d searches, %d requests, %d new candidate(s) queued, %d failed; %d searches still due%s',
				$result['queries'],
				$result['requests'],
				$result['counts']['created'],
				$result['counts']['failed'],
				$result['left'],
				$result['stopped_at_cap'] ? ' (stopped at the cost cap)' : ''
			),
			'',
			$result
		);
	}

	/**
	 * A status-check job.
	 *
	 * @param string               $job     Job.
	 * @param array<string, mixed> $options Options: `source` (a Status_Source, for tests).
	 * @return array<string, mixed>
	 */
	private static function run_status( string $job, array $options ): array {
		if ( ! self::enabled( $job ) && ! isset( $options['source'] ) ) {
			return self::outcome( true, null, 'Skipped: not enabled (no API key, or Yelp checks are off).', '' );
		}
		$source = $options['source'] ?? ( self::GOOGLE_STATUS === $job
			? new Google_Status_Source( Config::get( Config::GOOGLE_PLACES_KEY ) )
			: new Yelp_Status_Source( Config::get( Config::YELP_KEY ) ) );

		$checker = new Status_Checker( $source, $options['budget'] ?? Budget::for_run(), (int) ( $options['delay'] ?? 1 ), $options['sleeper'] ?? null );
		$result  = $checker->run();

		Alerts::closures_in_run( ucfirst( $source->source() ) . ' status check', $result['closed'], $result['queued'], $result['run_id'] );

		return self::outcome( true, $result['run_id'], Status_Checker::notes( $source->source(), $result ), '', $result );
	}

	/**
	 * The renewal reminder job. Nothing is written to ingestion runs: it
	 * imports nothing; its outcome is kept in the job state.
	 *
	 * @param array<string, mixed> $options Options: `today` (Y-m-d, for tests).
	 * @return array<string, mixed>
	 */
	private static function run_renewals( array $options ): array {
		$today   = isset( $options['today'] ) ? (string) $options['today'] : null;
		$ads     = Reminders::run( $today );
		$premium = Reminders::run_premium( $today );
		$counts  = array();
		foreach ( $ads as $key => $value ) {
			$counts[ $key ] = $value + (int) ( $premium[ $key ] ?? 0 );
		}
		$counts['premium'] = $premium;
		return self::outcome(
			0 === $counts['failed'],
			null,
			sprintf( 'sent %d (premium profiles %d), skipped (no email) %d, failed %d', $counts['sent'], $premium['sent'], $counts['skipped_no_email'], $counts['failed'] ),
			$counts['failed'] > 0 ? sprintf( '%d reminder emails could not be sent; check the site email setup.', $counts['failed'] ) : '',
			$counts
		);
	}

	/**
	 * Saved state for a job.
	 *
	 * @param string $job Job.
	 * @return array<string, mixed>
	 */
	public static function state( string $job ): array {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) && isset( $state[ $job ] ) && is_array( $state[ $job ] ) ? $state[ $job ] : array();
	}

	/**
	 * Merges into a job's saved state.
	 *
	 * @param string               $job    Job.
	 * @param array<string, mixed> $values Values.
	 * @return void
	 */
	public static function update_state( string $job, array $values ): void {
		$state         = get_option( self::STATE_OPTION );
		$state         = is_array( $state ) ? $state : array();
		$state[ $job ] = array_merge( isset( $state[ $job ] ) && is_array( $state[ $job ] ) ? $state[ $job ] : array(), $values );
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Last successful run as a Unix time, from the job state or, for runs
	 * started by hand (for example `wp guam-restaurants import-osm`), the
	 * ingestion runs table. 0 when never.
	 *
	 * @param string $job Job.
	 * @return int
	 */
	public static function last_success( string $job ): int {
		$state = (int) ( self::state( $job )['last_success'] ?? 0 );
		$run   = Ingestion_Runs::last_success( self::definitions()[ $job ]['source'] ?? '' );
		$from  = $run && $run['finished_at'] ? (int) strtotime( $run['finished_at'] . ' UTC' ) : 0;
		return max( $state, $from );
	}

	/**
	 * When the job is next due to have run by (last success + interval + grace).
	 *
	 * @param string $job Job.
	 * @return int Unix time.
	 */
	public static function deadline( string $job ): int {
		$definition = self::definitions()[ $job ];
		$last       = self::last_success( $job );
		$since      = (int) get_option( self::SINCE_OPTION, time() );
		return max( $last, $since ) + $definition['interval'] + $definition['grace'];
	}

	/**
	 * Whether the job succeeded within the given number of seconds.
	 *
	 * @param string $job     Job.
	 * @param int    $seconds Seconds.
	 * @return bool
	 */
	private static function ran_recently( string $job, int $seconds ): bool {
		return self::last_success( $job ) > time() - $seconds;
	}

	/**
	 * Builds an outcome.
	 *
	 * @param bool                 $ok      Success.
	 * @param int|null             $run_id  Run.
	 * @param string               $summary Summary.
	 * @param string               $error   Error.
	 * @param array<string, mixed> $result  Raw result.
	 * @return array{ok: bool, run_id: int|null, summary: string, error: string, result: array<string, mixed>}
	 */
	private static function outcome( bool $ok, ?int $run_id, string $summary, string $error, array $result = array() ): array {
		return array(
			'ok'      => $ok,
			'run_id'  => $run_id,
			'summary' => $summary,
			'error'   => $error,
			'result'  => $result,
		);
	}
}
