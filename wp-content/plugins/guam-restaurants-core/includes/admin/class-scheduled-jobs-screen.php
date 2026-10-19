<?php
/**
 * Restaurants → Scheduled jobs screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Monitoring\Job_Controls;
use GuamRestaurants\Core\Monitoring\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Lists every scheduled task with how often it runs, its last and next
 * run, and its status. Each one can be turned off and on, and "Run now"
 * queues one extra run (Monitoring\Job_Controls). Administrators only.
 */
final class Scheduled_Jobs_Screen {

	public const PAGE       = 'gr-scheduled-jobs';
	public const CAPABILITY = 'manage_options';
	public const ACTION     = 'gr_scheduled_job';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Adds the submenu under Restaurants.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			__( 'Scheduled jobs', 'guam-restaurants-core' ),
			__( 'Scheduled jobs', 'guam-restaurants-core' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string, string> $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => Content_Types::POST_TYPE,
					'page'      => self::PAGE,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Applies a pause, resume or run-now request.
	 *
	 * @param string $task    Task.
	 * @param string $command `pause`, `resume` or `run`.
	 * @param int    $user_id Who asked.
	 * @return string Notice key.
	 */
	public static function apply( string $task, string $command, int $user_id ): string {
		switch ( $command ) {
			case 'pause':
				return Job_Controls::set_paused( $task, true, $user_id ) ? 'paused' : 'unknown';
			case 'resume':
				return Job_Controls::set_paused( $task, false, $user_id ) ? 'resumed' : 'unknown';
			case 'run':
				return Job_Controls::queue_run( $task );
			default:
				return 'unknown';
		}
	}

	/**
	 * Handles a row's buttons.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Only administrators can change scheduled jobs.', 'guam-restaurants-core' ), 403 );
		}
		$task = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $task );
		$command = isset( $_POST['command'] ) ? sanitize_key( wp_unslash( $_POST['command'] ) ) : '';

		$notice = self::apply( $task, $command, get_current_user_id() );
		wp_safe_redirect(
			self::url(
				array(
					'gr_notice' => $notice,
					'task'      => $task,
				)
			)
		);
		exit;
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Notice flag only.
		$notice = isset( $_GET['gr_notice'] ) ? sanitize_key( wp_unslash( $_GET['gr_notice'] ) ) : '';
		$task   = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
		// phpcs:enable
		$tasks      = Job_Controls::tasks();
		$server     = Jobs::MODE_SERVER === Jobs::mode();
		$no_wp_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		echo '<div class="wrap"><h1>' . esc_html__( 'Scheduled jobs', 'guam-restaurants-core' ) . '</h1>';
		self::render_notice( $notice, isset( $tasks[ $task ] ) ? $tasks[ $task ]['label'] : '', $no_wp_cron );

		echo '<p>' . esc_html(
			$server
				? __( 'Jobs run from the server crontab (wp guam-restaurants run-job). The daily health check also runs on WP-Cron as a backstop, and the ratings refresh runs on WP-Cron.', 'guam-restaurants-core' )
				: __( 'Jobs run on WP-Cron, which runs when the site gets visits. A server cron is more reliable: see docs/scheduling.md.', 'guam-restaurants-core' )
		) . '</p>';
		echo '<p>' . esc_html__( 'Turning a job off stops its regular runs (WP-Cron and the server crontab) and its missed-window emails until you turn it back on. Run now queues one extra run in the background, also for a job that is off; the regular schedule stays as it is.', 'guam-restaurants-core' ) . '</p>';
		if ( $no_wp_cron ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WP-Cron is disabled here (DISABLE_WP_CRON), so Run now waits for the crontab line that runs wp cron event run --due-now (docs/scheduling.md, step 3).', 'guam-restaurants-core' ) . '</p></div>';
		}
		if ( Job_Controls::is_paused( Jobs::HEALTH_CHECK ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The health check is off, so nobody is emailed when a job misses its window or an import source keeps failing.', 'guam-restaurants-core' ) . '</p></div>';
		}

		echo '<table class="widefat striped" style="margin-top:1em"><thead><tr>';
		foreach ( array(
			__( 'Job', 'guam-restaurants-core' ),
			__( 'Runs', 'guam-restaurants-core' ),
			__( 'Last success', 'guam-restaurants-core' ),
			__( 'Next run', 'guam-restaurants-core' ),
			__( 'Status', 'guam-restaurants-core' ),
			__( 'On / off', 'guam-restaurants-core' ),
			__( 'Extra run', 'guam-restaurants-core' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $tasks as $slug => $definition ) {
			self::render_row( $slug, $definition, $server );
		}
		echo '</tbody></table>';
		/* translators: %s: timezone name. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Times are in the site timezone (%s).', 'guam-restaurants-core' ), wp_timezone_string() ) ) . '</p>';
		echo '</div>';
	}

	/**
	 * One task's row.
	 *
	 * @param string                              $slug       Task.
	 * @param array{label: string, every: string} $definition Task definition.
	 * @param bool                                $server     Server cron mode.
	 * @return void
	 */
	private static function render_row( string $slug, array $definition, bool $server ): void {
		$enabled = Job_Controls::enabled( $slug );
		$paused  = Job_Controls::is_paused( $slug );
		$state   = Jobs::state( $slug );
		$last    = Job_Controls::last_success( $slug );
		$queued  = Job_Controls::queued_at( $slug );
		$running = Job_Controls::running_since( $slug );
		$error   = (string) ( $state['last_error'] ?? '' );
		$is_job  = isset( Jobs::definitions()[ $slug ] );
		$crontab = $server && $is_job && Jobs::HEALTH_CHECK !== $slug;

		$every = $definition['every'];
		if ( $crontab ) {
			$every .= ' · ' . __( 'crontab', 'guam-restaurants-core' ) . ' ' . Jobs::definitions()[ $slug ]['cron'];
		}

		if ( $running ) {
			/* translators: %s: time. */
			$next = sprintf( __( 'Running since %s', 'guam-restaurants-core' ), self::time( $running ) );
		} elseif ( $queued ) {
			$next = __( 'Extra run queued', 'guam-restaurants-core' );
		} elseif ( ! $enabled || $paused ) {
			$next = '—';
		} elseif ( $crontab ) {
			$next = __( 'Per the server crontab', 'guam-restaurants-core' );
		} else {
			$next_run = Job_Controls::next_run( $slug );
			$next     = $next_run ? self::time( $next_run ) : __( 'Not scheduled yet', 'guam-restaurants-core' );
		}

		if ( ! $enabled ) {
			$status = __( 'Off (needs an API key or setting)', 'guam-restaurants-core' );
		} elseif ( $paused ) {
			/* translators: %s: time. */
			$status = sprintf( __( 'Turned off %s', 'guam-restaurants-core' ), self::time( (int) Job_Controls::paused()[ $slug ]['since'] ) );
		} elseif ( $is_job && Jobs::HEALTH_CHECK !== $slug && time() > Jobs::deadline( $slug ) ) {
			$status = __( 'Missed its window', 'guam-restaurants-core' );
		} else {
			$status = __( 'OK', 'guam-restaurants-core' );
		}
		if ( '' !== $error ) {
			$status .= ' · ' . __( 'last attempt failed:', 'guam-restaurants-core' ) . ' ' . $error;
		}

		echo '<tr id="gr-job-' . esc_attr( $slug ) . '">';
		printf( '<td><strong>%1$s</strong><br /><code>%2$s</code></td>', esc_html( $definition['label'] ), esc_html( $slug ) );
		echo '<td>' . esc_html( $every ) . '</td>';
		echo '<td>' . esc_html( $last ? self::time( $last ) : __( 'never', 'guam-restaurants-core' ) ) . '</td>';
		echo '<td>' . esc_html( $next ) . '</td>';
		echo '<td>' . esc_html( $status ) . '</td>';
		echo '<td>';
		if ( $enabled ) {
			self::render_button( $slug, $paused ? 'resume' : 'pause', $paused ? __( 'Turn on', 'guam-restaurants-core' ) : __( 'Turn off', 'guam-restaurants-core' ), $paused ? 'primary' : 'secondary', false );
		} else {
			echo '—';
		}
		echo '</td><td>';
		self::render_button( $slug, 'run', __( 'Run now', 'guam-restaurants-core' ), 'secondary', ! $enabled || $queued || $running );
		echo '</td></tr>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string $slug     Task.
	 * @param string $command  Command.
	 * @param string $label    Button label.
	 * @param string $type     `primary` or `secondary`.
	 * @param bool   $disabled Disabled.
	 * @return void
	 */
	private static function render_button( string $slug, string $command, string $label, string $type, bool $disabled ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="task" value="' . esc_attr( $slug ) . '" />';
		echo '<input type="hidden" name="command" value="' . esc_attr( $command ) . '" />';
		wp_nonce_field( self::ACTION . '_' . $slug );
		submit_button( $label, $type . ' small', 'submit', false, $disabled ? array( 'disabled' => 'disabled' ) : array() );
		echo '</form>';
	}

	/**
	 * Notice after a button.
	 *
	 * @param string $notice     Notice key.
	 * @param string $label      Task label.
	 * @param bool   $no_wp_cron WP-Cron is disabled.
	 * @return void
	 */
	private static function render_notice( string $notice, string $label, bool $no_wp_cron ): void {
		$messages = array(
			/* translators: %s: job name. */
			'queued'         => array( 'success', $no_wp_cron ? __( '%s: one extra run is queued for the next WP-Cron run from the crontab.', 'guam-restaurants-core' ) : __( '%s: one extra run is queued and starting in the background. Reload this page to see when it finishes.', 'guam-restaurants-core' ) ),
			/* translators: %s: job name. */
			'already_queued' => array( 'info', __( '%s already has an extra run queued.', 'guam-restaurants-core' ) ),
			/* translators: %s: job name. */
			'running'        => array( 'info', __( '%s is running now; try again when it finishes.', 'guam-restaurants-core' ) ),
			/* translators: %s: job name. */
			'off'            => array( 'error', __( '%s cannot run here: it needs an API key or setting first.', 'guam-restaurants-core' ) ),
			/* translators: %s: job name. */
			'paused'         => array( 'success', __( '%s is off. Its regular runs and missed-window emails stop until you turn it back on.', 'guam-restaurants-core' ) ),
			/* translators: %s: job name. */
			'resumed'        => array( 'success', __( '%s is on again.', 'guam-restaurants-core' ) ),
			'unknown'        => array( 'error', __( 'That job was not found.', 'guam-restaurants-core' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		list( $type, $message ) = $messages[ $notice ];
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( sprintf( $message, $label ) ) );
	}

	/**
	 * A time in the site timezone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function time( int $timestamp ): string {
		return (string) wp_date( 'Y-m-d H:i', $timestamp );
	}
}
