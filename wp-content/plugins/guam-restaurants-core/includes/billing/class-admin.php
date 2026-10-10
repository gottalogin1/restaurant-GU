<?php
/**
 * Billing box on the ad edit screen, invoice column and Ads > Billing.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Billing;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;

defined( 'ABSPATH' ) || exit;

/**
 * - A "Billing" box on each ad: invoice number (issued with a button, then
 *   fixed), amount, issue and due dates, payment status and date, billing
 *   email and a short note such as a check number.
 * - An "Invoice" column in the ads list.
 * - Ads > Billing: unpaid invoices (overdue first), ads ending in the next
 *   30 days with their reminder state, and ads serving without an invoice.
 *
 * Everything needs Ads::CAP (administrators).
 */
final class Admin {

	public const PAGE          = 'gr-ad-billing';
	public const NONCE         = 'gr_save_ad_billing';
	public const EXPIRING_DAYS = 30;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = Ads::POST_TYPE;
		add_action( "add_meta_boxes_{$type}", array( self::class, 'add_meta_box' ) );
		add_action( "save_post_{$type}", array( self::class, 'save' ), 20 );
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ), 20 );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		add_action( 'admin_menu', array( self::class, 'menu' ) );
	}

	/**
	 * Adds the box.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box( 'gr-ad-billing', __( 'Billing', 'guam-restaurants-core' ), array( self::class, 'box' ), Ads::POST_TYPE, 'side', 'default' );
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post $post Ad.
	 * @return void
	 */
	public static function box( $post ): void {
		$billing = Invoices::get( (int) $post->ID );
		wp_nonce_field( self::NONCE, '_gr_billing_nonce' );

		if ( '' === $billing['number'] ) {
			echo '<p>' . esc_html__( 'No invoice number yet.', 'guam-restaurants-core' ) . '</p>';
			printf( '<p><label><input type="checkbox" name="gr_billing[issue]" value="1"> %s</label></p>', esc_html__( 'Issue the next invoice number when saving', 'guam-restaurants-core' ) );
		} else {
			printf( '<p><strong>%1$s</strong> %2$s</p>', esc_html__( 'Invoice', 'guam-restaurants-core' ), esc_html( $billing['number'] ) );
		}
		$field = static function ( string $name, string $label, string $value, string $type = 'text', string $extra = '' ): void {
			printf(
				'<p><label for="gr-billing-%1$s">%2$s</label><br><input type="%3$s" class="widefat" id="gr-billing-%1$s" name="gr_billing[%1$s]" value="%4$s"%5$s></p>',
				esc_attr( $name ),
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $value ),
				$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string from this file.
			);
		};
		$field( 'amount', __( 'Amount (USD)', 'guam-restaurants-core' ), $billing['amount'], 'text', ' inputmode="decimal"' );
		$field( 'issued', __( 'Invoice date', 'guam-restaurants-core' ), $billing['issued'], 'date' );
		/* translators: %d: number of days. */
		$field( 'due', sprintf( __( 'Due date (default: invoice date + %d days)', 'guam-restaurants-core' ), Invoices::DEFAULT_TERMS ), $billing['due'], 'date' );
		printf( '<p><label for="gr-billing-status">%s</label><br><select class="widefat" id="gr-billing-status" name="gr_billing[status]"><option value="">%s</option>', esc_html__( 'Payment', 'guam-restaurants-core' ), esc_html__( 'Not invoiced', 'guam-restaurants-core' ) );
		foreach ( Invoices::statuses() as $key => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $key, $billing['status'], false ), esc_html( $label ) );
		}
		echo '</select></p>';
		$field( 'paid_on', __( 'Paid on (default: today when marked paid)', 'guam-restaurants-core' ), $billing['paid_on'], 'date' );
		$field( 'email', __( 'Billing email (gets renewal reminders)', 'guam-restaurants-core' ), $billing['email'], 'email' );
		$field( 'note', __( 'Note, e.g. check or transfer reference', 'guam-restaurants-core' ), $billing['note'] );
		$sent = Reminders::sent( (int) $post->ID, (string) get_post_meta( (int) $post->ID, Ads::META_END, true ) );
		if ( $sent ) {
			/* translators: %s: list such as "14, 3". */
			echo '<p class="description">' . esc_html( sprintf( __( 'Renewal reminders sent for this end date: %s days before.', 'guam-restaurants-core' ), implode( ', ', $sent ) ) ) . '</p>';
		}
	}

	/**
	 * Saves the box.
	 *
	 * @param int $ad_id Ad.
	 * @return void
	 */
	public static function save( $ad_id ): void {
		$ad_id = (int) $ad_id;
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $ad_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_gr_billing_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gr_billing_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		$input = isset( $_POST['gr_billing'] ) && is_array( $_POST['gr_billing'] ) ? wp_unslash( $_POST['gr_billing'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Invoices::sanitize() cleans each field.
		self::save_billing( $ad_id, $input );
	}

	/**
	 * Saves billing input. Used by save() and the tests.
	 *
	 * @param int                  $ad_id Ad.
	 * @param array<string, mixed> $input Fields from the box.
	 * @return bool False when the user may not edit the ad.
	 */
	public static function save_billing( int $ad_id, array $input ): bool {
		if ( ! current_user_can( Ads::CAP ) || ! current_user_can( 'edit_post', $ad_id ) ) {
			return false;
		}
		Invoices::save( $ad_id, Invoices::sanitize( $input ) );
		if ( ! empty( $input['issue'] ) ) {
			Invoices::issue( $ad_id );
		}
		return true;
	}

	/**
	 * Adds the Invoice column before "Serving today".
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			if ( 'gr_serving' === $key ) {
				$out['gr_invoice'] = __( 'Invoice', 'guam-restaurants-core' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['gr_invoice'] ) ) {
			$out['gr_invoice'] = __( 'Invoice', 'guam-restaurants-core' );
		}
		return $out;
	}

	/**
	 * Prints the Invoice column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Ad.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		if ( 'gr_invoice' !== $column ) {
			return;
		}
		$billing = Invoices::get( (int) $post_id );
		if ( '' === $billing['number'] ) {
			echo '—';
			return;
		}
		echo esc_html( $billing['number'] . ' · ' . ( Invoices::statuses()[ $billing['status'] ] ?? '' ) );
	}

	/**
	 * Adds Ads > Billing.
	 *
	 * @return void
	 */
	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Ads::POST_TYPE,
			__( 'Billing', 'guam-restaurants-core' ),
			__( 'Billing', 'guam-restaurants-core' ),
			Ads::CAP,
			self::PAGE,
			array( self::class, 'screen' )
		);
	}

	/**
	 * Ads > Billing.
	 *
	 * @return void
	 */
	public static function screen(): void {
		if ( ! current_user_can( Ads::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view billing.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		$today = Server::today();
		$link  = static function ( array $ad ): string {
			return sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( (int) $ad['id'] ) ), esc_html( '' === $ad['title'] ? '#' . $ad['id'] : $ad['title'] ) );
		};

		echo '<div class="wrap"><h1>' . esc_html__( 'Billing', 'guam-restaurants-core' ) . '</h1>';
		echo '<p>' . esc_html__( 'Invoices are written and sent outside this site; each ad records its invoice number, amount and whether it is paid. Before the first invoice, check the questions in docs/billing.md with the Department of Revenue and Taxation and an accountant.', 'guam-restaurants-core' ) . '</p>';

		echo '<h2>' . esc_html__( 'Unpaid invoices', 'guam-restaurants-core' ) . '</h2>';
		$rows = array();
		foreach ( Invoices::unpaid( $today ) as $row ) {
			$rows[] = array(
				esc_html( $row['billing']['number'] ),
				esc_html( $row['ad']['advertiser'] ),
				$link( $row['ad'] ),
				esc_html( '' === $row['billing']['amount'] ? '' : '$' . number_format_i18n( (float) $row['billing']['amount'], 2 ) ),
				esc_html( $row['billing']['issued'] ),
				esc_html( $row['billing']['due'] ),
				$row['days_overdue'] > 0 ? '<strong>' . esc_html( (string) $row['days_overdue'] ) . '</strong>' : esc_html__( 'Not yet due', 'guam-restaurants-core' ),
			);
		}
		self::table( array( __( 'Invoice', 'guam-restaurants-core' ), __( 'Advertiser', 'guam-restaurants-core' ), __( 'Ad', 'guam-restaurants-core' ), __( 'Amount', 'guam-restaurants-core' ), __( 'Invoice date', 'guam-restaurants-core' ), __( 'Due', 'guam-restaurants-core' ), __( 'Days overdue', 'guam-restaurants-core' ) ), $rows, __( 'No unpaid invoices.', 'guam-restaurants-core' ) );

		/* translators: %d: number of days. */
		echo '<h2>' . esc_html( sprintf( __( 'Ending in the next %d days', 'guam-restaurants-core' ), self::EXPIRING_DAYS ) ) . '</h2>';
		echo '<p>' . esc_html__( 'Renewal reminders go to the billing email 14 and 3 days before the end date, from the daily renewal-reminders job.', 'guam-restaurants-core' ) . '</p>';
		$rows = array();
		foreach ( Invoices::expiring( $today, self::EXPIRING_DAYS ) as $row ) {
			$sent   = Reminders::sent( (int) $row['ad']['id'], $row['ad']['end'] );
			$rows[] = array(
				$link( $row['ad'] ),
				esc_html( $row['ad']['advertiser'] ),
				esc_html( $row['ad']['end'] ),
				esc_html( (string) $row['days_left'] ),
				'' === $row['billing']['email'] ? '<em>' . esc_html__( 'None: no reminders', 'guam-restaurants-core' ) . '</em>' : esc_html( $row['billing']['email'] ),
				esc_html( $sent ? implode( ', ', $sent ) : '—' ),
				esc_html( '' === $row['billing']['number'] ? __( 'Not invoiced', 'guam-restaurants-core' ) : $row['billing']['number'] . ' · ' . ( Invoices::statuses()[ $row['billing']['status'] ] ?? '' ) ),
			);
		}
		self::table( array( __( 'Ad', 'guam-restaurants-core' ), __( 'Advertiser', 'guam-restaurants-core' ), __( 'Ends', 'guam-restaurants-core' ), __( 'Days left', 'guam-restaurants-core' ), __( 'Billing email', 'guam-restaurants-core' ), __( 'Reminders sent (days before)', 'guam-restaurants-core' ), __( 'Invoice', 'guam-restaurants-core' ) ), $rows, __( 'No ads end soon.', 'guam-restaurants-core' ) );

		echo '<h2>' . esc_html__( 'Serving today with no invoice number', 'guam-restaurants-core' ) . '</h2>';
		$rows = array();
		foreach ( Invoices::serving_without_invoice( $today ) as $ad ) {
			$rows[] = array( $link( $ad ), esc_html( $ad['advertiser'] ), esc_html( $ad['start'] . ' – ' . $ad['end'] ) );
		}
		self::table( array( __( 'Ad', 'guam-restaurants-core' ), __( 'Advertiser', 'guam-restaurants-core' ), __( 'Runs', 'guam-restaurants-core' ) ), $rows, __( 'Every serving ad has an invoice number.', 'guam-restaurants-core' ) );
		echo '</div>';
	}

	/**
	 * Prints a table of already-escaped cells.
	 *
	 * @param string[]   $header Header labels.
	 * @param string[][] $rows   Escaped HTML cells.
	 * @param string     $none   Message when there are no rows.
	 * @return void
	 */
	private static function table( array $header, array $rows, string $none ): void {
		if ( ! $rows ) {
			echo '<p>' . esc_html( $none ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $header as $cell ) {
			echo '<th scope="col">' . esc_html( $cell ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . implode( '</td><td>', $row ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells escaped by the caller.
		}
		echo '</tbody></table>';
	}
}
