<?php
/**
 * Admin controls for scheduled tasks: pause, resume and one extra run.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Ratings;
use GuamRestaurants\Core\Ratings\Refresher;

defined( 'ABSPATH' ) || exit;

/**
 * The scheduled tasks an administrator controls on Restaurants > Scheduled
 * jobs: the eight jobs (Jobs) and the twice-daily ratings refresh.
 *
 * - Paused tasks keep no WP-Cron event, `run-job` from the server crontab
 *   skips them, and the health check does not report them. The pause is a
 *   database option, so it survives `git pull` and `make update`.
 * - "Run now" queues one extra run as a single WP-Cron event and starts
 *   WP-Cron right away, so a long import runs in the background instead of
 *   the admin request. It runs even when the task is paused, and does not
 *   move the regular schedule.
 */
final class Job_Controls {

	public const PAUSED_OPTION   = 'guam_restaurants_core_jobs_paused';
	public const RUN_NOW_HOOK    = 'guam_restaurants_run_job_now';
	public const RATINGS_REFRESH = 'ratings-refresh';

	/**
	 * A run that started this long ago without finishing is taken as stopped.
	 */
	public const RUNNING_TIMEOUT = HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::RUN_NOW_HOOK, array( self::class, 'run_queued' ) );
		add_filter( 'cron_request', array( self::class, 'loopback_request' ) );
	}

	/**
	 * Every controllable task: slug => label and how often it runs.
	 *
	 * @return array<string, array{label: string, every: string}>
	 */
	public static function tasks(): array {
		$every = array(
			'daily'       => __( 'Daily', 'guam-restaurants-core' ),
			'weekly'      => __( 'Weekly', 'guam-restaurants-core' ),
			Jobs::MONTHLY => __( 'Monthly', 'guam-restaurants-core' ),
		);
		$tasks = array();
		foreach ( Jobs::definitions() as $job => $definition ) {
			$tasks[ $job ] = array(
				'label' => $definition['label'],
				'every' => $every[ $definition['recurrence'] ] ?? $definition['recurrence'],
			);
		}
		$tasks[ self::RATINGS_REFRESH ] = array(
			'label' => 'Ratings refresh (cached Yelp ratings)',
			'every' => __( 'Twice a day', 'guam-restaurants-core' ),
		);
		return $tasks;
	}

	/**
	 * Whether a task can run here (API keys and switches set).
	 *
	 * @param string $task Task.
	 * @return bool
	 */
	public static function enabled( string $task ): bool {
		return self::RATINGS_REFRESH === $task ? true : Jobs::enabled( $task );
	}

	/**
	 * Whether an administrator paused the task.
	 *
	 * @param string $task Task.
	 * @return bool
	 */
	public static function is_paused( string $task ): bool {
		return isset( self::paused()[ $task ] );
	}

	/**
	 * Paused tasks: task => array( since, by ).
	 *
	 * @return array<string, array{since: int, by: int}>
	 */
	public static function paused(): array {
		$paused = get_option( self::PAUSED_OPTION, array() );
		return is_array( $paused ) ? $paused : array();
	}

	/**
	 * Pauses or resumes a task, and brings its WP-Cron event in line.
	 * Resuming restarts the health check's clock for the task, so a long
	 * pause does not read as a missed window.
	 *
	 * @param string $task    Task.
	 * @param bool   $paused  Pause (true) or resume (false).
	 * @param int    $user_id Who changed it.
	 * @return bool False for an unknown task.
	 */
	public static function set_paused( string $task, bool $paused, int $user_id = 0 ): bool {
		if ( ! isset( self::tasks()[ $task ] ) ) {
			return false;
		}
		$all = self::paused();
		if ( $paused ) {
			$all[ $task ] = array(
				'since' => time(),
				'by'    => $user_id,
			);
		} elseif ( isset( $all[ $task ] ) ) {
			unset( $all[ $task ] );
			Jobs::update_state( $task, array( 'resumed_at' => time() ) );
		}
		update_option( self::PAUSED_OPTION, $all, false );

		if ( self::RATINGS_REFRESH === $task ) {
			Refresher::schedule();
		} else {
			Jobs::schedule();
		}
		return true;
	}

	/**
	 * Queues one extra run and starts WP-Cron so it runs now.
	 *
	 * @param string $task Task.
	 * @return string `queued`, `already_queued`, `running`, `off` (needs a key) or `unknown`.
	 */
	public static function queue_run( string $task ): string {
		if ( ! isset( self::tasks()[ $task ] ) ) {
			return 'unknown';
		}
		if ( ! self::enabled( $task ) ) {
			return 'off';
		}
		if ( self::queued_at( $task ) ) {
			return 'already_queued';
		}
		if ( self::running_since( $task ) ) {
			return 'running';
		}
		wp_schedule_single_event( time(), self::RUN_NOW_HOOK, array( $task ) );
		Jobs::update_state( $task, array( 'queued_by' => get_current_user_id() ) );
		if ( ! wp_doing_cron() && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
		return 'queued';
	}

	/**
	 * When an extra run is queued for, or 0.
	 *
	 * @param string $task Task.
	 * @return int Unix time.
	 */
	public static function queued_at( string $task ): int {
		return (int) wp_next_scheduled( self::RUN_NOW_HOOK, array( $task ) );
	}

	/**
	 * When the current run started, or 0 when none is running.
	 *
	 * @param string $task Task.
	 * @return int Unix time.
	 */
	public static function running_since( string $task ): int {
		$state    = Jobs::state( $task );
		$started  = (int) ( $state['last_started'] ?? 0 );
		$finished = (int) ( $state['last_finished'] ?? 0 );
		return $started > $finished && $started > time() - self::RUNNING_TIMEOUT ? $started : 0;
	}

	/**
	 * Next regular run on WP-Cron, or 0.
	 *
	 * @param string $task Task.
	 * @return int Unix time.
	 */
	public static function next_run( string $task ): int {
		return self::RATINGS_REFRESH === $task
			? (int) wp_next_scheduled( Refresher::HOOK )
			: (int) wp_next_scheduled( Jobs::HOOK, array( $task ) );
	}

	/**
	 * Last successful run, or 0.
	 *
	 * @param string $task Task.
	 * @return int Unix time.
	 */
	public static function last_success( string $task ): int {
		return self::RATINGS_REFRESH === $task
			? (int) ( Jobs::state( $task )['last_success'] ?? 0 )
			: Jobs::last_success( $task );
	}

	/**
	 * WP-Cron callback for a queued extra run.
	 *
	 * @param string $task Task.
	 * @return void
	 */
	public static function run_queued( $task ): void {
		$task = (string) $task;
		if ( self::RATINGS_REFRESH === $task ) {
			self::run_ratings_refresh();
			return;
		}
		if ( ! isset( Jobs::definitions()[ $task ] ) ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) && in_array( $task, array( Jobs::OSM, Jobs::DISCOVER ), true ) ) {
			set_time_limit( 900 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long import in a cron request.
		}
		Jobs::run( $task );
	}

	/**
	 * Runs the ratings refresh and records it like a job, so the screen can
	 * show its last run.
	 *
	 * @return void
	 */
	public static function run_ratings_refresh(): void {
		Jobs::update_state( self::RATINGS_REFRESH, array( 'last_started' => time() ) );
		$error = '';
		try {
			$result = Refresher::run( Ratings::providers(), Budget::for_run() );
			if ( $result['failed'] > 0 ) {
				$error = sprintf( '%d rating refresh(es) failed; see Import runs.', $result['failed'] );
			}
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}
		Jobs::update_state(
			self::RATINGS_REFRESH,
			array(
				'last_finished' => time(),
				'last_ok'       => '' === $error,
				'last_success'  => '' === $error ? time() : ( Jobs::state( self::RATINGS_REFRESH )['last_success'] ?? 0 ),
				'last_error'    => $error,
			)
		);
	}

	/**
	 * Points WP-Cron's request to itself at GUAM_RESTAURANTS_CRON_LOOPBACK_URL
	 * when set. In the local Docker stack the site address is
	 * http://localhost:8080, which nothing listens on inside the container,
	 * so without this WP-Cron never runs there.
	 *
	 * @param array{url: string, key: string, args: array<string, mixed>} $request Cron request.
	 * @return array{url: string, key: string, args: array<string, mixed>}
	 */
	public static function loopback_request( $request ) {
		$base = rtrim( Config::get( Config::CRON_LOOPBACK_URL ), '/' );
		if ( '' === $base || ! is_array( $request ) || empty( $request['url'] ) ) {
			return $request;
		}
		$parts = wp_parse_url( (string) $request['url'] );
		if ( empty( $parts['path'] ) ) {
			return $request;
		}
		$request['url'] = $base . $parts['path'] . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		return $request;
	}
}
