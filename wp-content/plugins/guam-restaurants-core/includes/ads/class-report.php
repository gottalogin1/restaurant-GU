<?php
/**
 * Monthly advertiser report.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Advertising\Admin as Leads_Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Ads → Reports: impressions, clicks and click-through rate for one
 * calendar month (Guam dates), for one advertiser or all, per ad and page
 * type, with a total. Shown as a table (print it to PDF from the browser)
 * and downloadable as CSV. Built on demand from `gr_ad_daily_stats`.
 *
 * Counts are impressions seen on screen and link clicks, from the beacon
 * (Tracking), with bots filtered out.
 */
final class Report {

	public const PAGE   = 'gr-ad-reports';
	public const ACTION = 'gr_ad_report_csv';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'download' ) );
	}

	/**
	 * Adds the Reports screen under Ads.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Ads::POST_TYPE,
			__( 'Ad reports', 'guam-restaurants-core' ),
			__( 'Reports', 'guam-restaurants-core' ),
			Ads::CAP,
			self::PAGE,
			array( self::class, 'screen' )
		);
	}

	/**
	 * First and last day of a Y-m month, or null.
	 *
	 * @param string $month Y-m.
	 * @return array{0: string, 1: string}|null
	 */
	public static function month_range( string $month ): ?array {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m', $month );
		if ( ! $date || $date->format( 'Y-m' ) !== $month ) {
			return null;
		}
		return array( $date->format( 'Y-m-01' ), $date->format( 'Y-m-t' ) );
	}

	/**
	 * Report rows.
	 *
	 * @param string $month      Y-m.
	 * @param string $advertiser Advertiser name; '' for all.
	 * @return array<int, array{advertiser: string, ad_id: int, ad: string, product: string, page_type: string, impressions: int, clicks: int}>
	 */
	public static function rows( string $month, string $advertiser = '' ): array {
		$range = self::month_range( $month );
		if ( null === $range ) {
			return array();
		}
		$rows = array();
		$ads  = array();
		foreach ( Stats::totals( $range[0], $range[1] ) as $total ) {
			$id = $total['ad_id'];
			if ( ! array_key_exists( $id, $ads ) ) {
				$ads[ $id ] = Ads::get( $id );
			}
			$ad = $ads[ $id ];
			if ( '' !== $advertiser && ( null === $ad || $ad['advertiser'] !== $advertiser ) ) {
				continue;
			}
			$rows[] = array(
				'advertiser'  => null === $ad ? '' : $ad['advertiser'],
				'ad_id'       => $id,
				'ad'          => null === $ad ? __( '(deleted ad)', 'guam-restaurants-core' ) : $ad['title'],
				'product'     => null === $ad ? '' : (string) ( Ads::products()[ $ad['product'] ]['label'] ?? $ad['product'] ),
				'page_type'   => (string) ( Ads::page_types()[ $total['page_type'] ] ?? $total['page_type'] ),
				'impressions' => $total['impressions'],
				'clicks'      => $total['clicks'],
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['advertiser'], $a['ad_id'], $a['page_type'] ) <=> array( $b['advertiser'], $b['ad_id'], $b['page_type'] );
			}
		);
		return $rows;
	}

	/**
	 * Click-through rate as a percentage with two decimals, or '' with no impressions.
	 *
	 * @param int $impressions Impressions.
	 * @param int $clicks      Clicks.
	 * @return string
	 */
	public static function ctr( int $impressions, int $clicks ): string {
		return $impressions > 0 ? number_format( 100 * $clicks / $impressions, 2, '.', '' ) . '%' : '';
	}

	/**
	 * CSV lines: header, one line per ad and page type, and a total.
	 *
	 * @param string $month      Y-m.
	 * @param string $advertiser Advertiser; '' for all.
	 * @return array<int, string[]>
	 */
	public static function csv_rows( string $month, string $advertiser = '' ): array {
		$lines       = array(
			array( 'Month', 'Advertiser', 'Ad ID', 'Ad', 'Product', 'Page type', 'Impressions', 'Clicks', 'Click-through rate' ),
		);
		$impressions = 0;
		$clicks      = 0;
		foreach ( self::rows( $month, $advertiser ) as $row ) {
			$impressions += $row['impressions'];
			$clicks      += $row['clicks'];
			$lines[]      = array_map(
				array( Leads_Admin::class, 'csv_cell' ),
				array( $month, $row['advertiser'], (string) $row['ad_id'], $row['ad'], $row['product'], $row['page_type'], (string) $row['impressions'], (string) $row['clicks'], self::ctr( $row['impressions'], $row['clicks'] ) )
			);
		}
		$lines[] = array( $month, Leads_Admin::csv_cell( '' === $advertiser ? 'All advertisers' : $advertiser ), '', 'Total', '', '', (string) $impressions, (string) $clicks, self::ctr( $impressions, $clicks ) );
		return $lines;
	}

	/**
	 * Advertiser names that have ads.
	 *
	 * @return string[]
	 */
	public static function advertisers(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin screen.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND pm.meta_value <> '' ORDER BY pm.meta_value",
				Ads::META_ADVERTISER,
				Ads::POST_TYPE
			)
		);
		return array_map( 'strval', (array) $names );
	}

	/**
	 * Month and advertiser from a request.
	 *
	 * @param array<string, mixed> $params Request parameters (unslashed).
	 * @return array{0: string, 1: string}
	 */
	public static function params( array $params ): array {
		$month = sanitize_text_field( (string) ( $params['month'] ?? '' ) );
		if ( null === self::month_range( $month ) ) {
			$month = ( new \DateTimeImmutable( Server::today() ) )->modify( 'first day of last month' )->format( 'Y-m' );
		}
		return array( $month, sanitize_text_field( (string) ( $params['advertiser'] ?? '' ) ) );
	}

	/**
	 * The Reports screen.
	 *
	 * @return void
	 */
	public static function screen(): void {
		if ( ! current_user_can( Ads::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view ad reports.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		list( $month, $advertiser ) = self::params( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filters.
		$lines                      = self::csv_rows( $month, $advertiser );

		echo '<div class="wrap"><h1>' . esc_html__( 'Ad reports', 'guam-restaurants-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Impressions are counted when at least half of an ad is on screen; clicks when its link is followed. Bots are not counted, and no visitor data is stored. Use your browser\'s Print command to save this table as a PDF.', 'guam-restaurants-core' ) . '</p>';

		printf( '<form method="get" action="%s">', esc_url( admin_url( 'edit.php' ) ) );
		printf( '<input type="hidden" name="post_type" value="%1$s"><input type="hidden" name="page" value="%2$s">', esc_attr( Ads::POST_TYPE ), esc_attr( self::PAGE ) );
		printf( '<label for="gr-report-month">%1$s</label> <input type="month" id="gr-report-month" name="month" value="%2$s"> ', esc_html__( 'Month', 'guam-restaurants-core' ), esc_attr( $month ) );
		printf( '<label for="gr-report-advertiser">%1$s</label> <select id="gr-report-advertiser" name="advertiser"><option value="">%2$s</option>', esc_html__( 'Advertiser', 'guam-restaurants-core' ), esc_html__( 'All advertisers', 'guam-restaurants-core' ) );
		foreach ( self::advertisers() as $name ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $name ), selected( $name, $advertiser, false ), esc_html( $name ) );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'guam-restaurants-core' ), 'secondary', '', false );
		printf(
			' <a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action'     => self::ACTION,
							'month'      => $month,
							'advertiser' => $advertiser,
						),
						admin_url( 'admin-post.php' )
					),
					self::ACTION
				)
			),
			esc_html__( 'Download CSV', 'guam-restaurants-core' )
		);
		echo '</form>';

		$header = array_shift( $lines );
		echo '<table class="widefat striped" style="margin-top:1rem"><thead><tr>';
		foreach ( $header as $cell ) {
			echo '<th scope="col">' . esc_html( $cell ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $lines as $line ) {
			echo '<tr>';
			foreach ( $line as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Sends the CSV.
	 *
	 * @return void
	 */
	public static function download(): void {
		if ( ! current_user_can( Ads::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to download ad reports.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		list( $month, $advertiser ) = self::params( wp_unslash( $_GET ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ad-report-' . $month . ( '' === $advertiser ? '' : '-' . sanitize_title( $advertiser ) ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads UTF-8.
		foreach ( self::csv_rows( $month, $advertiser ) as $line ) {
			fputcsv( $out, $line, ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
