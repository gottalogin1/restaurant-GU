<?php
/**
 * Restaurants → Import runs screen.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;

defined( 'ABSPATH' ) || exit;

/**
 * Lists ingestion runs with their counts, notes and errors, and links to
 * Scheduled jobs for each job's schedule. Read-only; administrators only.
 */
final class Ingestion_Runs_Screen {

	public const PAGE       = 'gr-import-runs';
	public const CAPABILITY = 'manage_options';
	public const PER_PAGE   = 50;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
	}

	/**
	 * Adds the submenu under Restaurants.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			__( 'Import runs', 'guam-restaurants-core' ),
			__( 'Import runs', 'guam-restaurants-core' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view filters.
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$sources = Ingestion_Runs::sources();
		if ( ! in_array( $source, $sources, true ) ) {
			$source = '';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Import runs', 'guam-restaurants-core' ) . '</h1>';
		/* translators: %s: link to the Scheduled jobs screen. */
		echo '<p>' . wp_kses_post( sprintf( __( 'Last and next runs of each job, and controls to turn a job off or run it now: %s.', 'guam-restaurants-core' ), '<a href="' . esc_url( Scheduled_Jobs_Screen::url() ) . '">' . esc_html__( 'Scheduled jobs', 'guam-restaurants-core' ) . '</a>' ) ) . '</p>';

		echo '<h2>' . esc_html__( 'Runs', 'guam-restaurants-core' ) . '</h2>';
		echo '<form method="get"><input type="hidden" name="post_type" value="' . esc_attr( Content_Types::POST_TYPE ) . '" /><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		echo '<label for="gr-run-source">' . esc_html__( 'Source', 'guam-restaurants-core' ) . '</label> <select id="gr-run-source" name="source"><option value="">' . esc_html__( 'All', 'guam-restaurants-core' ) . '</option>';
		foreach ( $sources as $option ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $option ), selected( $source, $option, false ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'guam-restaurants-core' ), 'secondary', '', false );
		echo '</form>';

		$total = Ingestion_Runs::count( $source );
		$runs  = Ingestion_Runs::recent( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE, $source );

		echo '<table class="widefat striped" style="margin-top:1em"><thead><tr>';
		foreach ( array(
			__( 'Run', 'guam-restaurants-core' ),
			__( 'Source', 'guam-restaurants-core' ),
			__( 'Started (UTC)', 'guam-restaurants-core' ),
			__( 'Duration', 'guam-restaurants-core' ),
			__( 'Created', 'guam-restaurants-core' ),
			__( 'Updated', 'guam-restaurants-core' ),
			__( 'Unchanged', 'guam-restaurants-core' ),
			__( 'Failed', 'guam-restaurants-core' ),
			__( 'Retried requests', 'guam-restaurants-core' ),
			__( 'Notes and errors', 'guam-restaurants-core' ),
		) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $runs ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No runs yet.', 'guam-restaurants-core' ) . '</td></tr>';
		}
		foreach ( $runs as $run ) {
			echo '<tr>';
			echo '<td>#' . esc_html( (string) $run['id'] ) . '</td>';
			echo '<td>' . esc_html( (string) $run['source'] ) . '</td>';
			echo '<td>' . esc_html( (string) $run['started_at'] ) . '</td>';
			echo '<td>' . esc_html( self::duration( $run ) ) . '</td>';
			foreach ( array( 'created', 'updated', 'unchanged', 'failed' ) as $count ) {
				$style = 'failed' === $count && (int) $run[ $count ] > 0 ? ' style="color:#b32d2e;font-weight:600"' : '';
				echo '<td' . $style . '>' . esc_html( number_format_i18n( (int) $run[ $count ] ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
			}
			echo '<td>' . esc_html( number_format_i18n( (int) ( $run['retries'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) $run['notes'] );
			if ( $run['errors'] ) {
				/* translators: %d: number of errors. */
				echo '<details><summary style="color:#b32d2e">' . esc_html( sprintf( _n( '%d error', '%d errors', count( $run['errors'] ), 'guam-restaurants-core' ), count( $run['errors'] ) ) ) . '</summary><ul style="margin:.5em 0 0 1.2em;list-style:disc">';
				foreach ( $run['errors'] as $error ) {
					echo '<li><code>' . esc_html( $error ) . '</code></li>';
				}
				echo '</ul></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * Human duration of a run.
	 *
	 * @param array<string, mixed> $run Run.
	 * @return string
	 */
	private static function duration( array $run ): string {
		if ( empty( $run['finished_at'] ) ) {
			return __( 'running or stopped', 'guam-restaurants-core' );
		}
		$seconds = max( 0, strtotime( $run['finished_at'] . ' UTC' ) - strtotime( $run['started_at'] . ' UTC' ) );
		return $seconds < 60 ? sprintf( '%ds', $seconds ) : sprintf( '%dm %ds', intdiv( $seconds, 60 ), $seconds % 60 );
	}
}
