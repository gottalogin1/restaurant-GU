<?php
/**
 * Invoice number and payment status on each ad.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Billing;

use GuamRestaurants\Core\Ads\Ads;
use GuamRestaurants\Core\Ads\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Manual invoicing (docs/billing.md): each ad carries the invoice it was
 * billed on. The invoice itself is written and sent outside WordPress (or
 * with WowInvoice, once WooCommerce is approved); this records its number,
 * amount, dates, who receives billing email and whether it is paid.
 *
 * Invoice numbers are GR-YYYY-NNNN: the Guam year the number was issued
 * and a sequence that restarts each year. A number is issued once, never
 * reused and never changed, so the sequence has no gaps an accountant would
 * have to explain. A voided invoice keeps its number.
 */
final class Invoices {

	public const META_NUMBER    = 'gr_ad_invoice_number';
	public const META_AMOUNT    = 'gr_ad_invoice_amount';
	public const META_ISSUED    = 'gr_ad_invoice_date';
	public const META_DUE       = 'gr_ad_invoice_due';
	public const META_STATUS    = 'gr_ad_payment_status';
	public const META_PAID_ON   = 'gr_ad_paid_on';
	public const META_EMAIL     = 'gr_ad_billing_email';
	public const META_NOTE      = 'gr_ad_billing_note';
	public const SEQUENCE       = 'guam_restaurants_invoice_sequence';
	public const PREFIX         = 'GR';
	public const DEFAULT_TERMS  = 14;
	public const NUMBER_PATTERN = '/^GR-\d{4}-\d{4,}$/';

	public const UNPAID = 'unpaid';
	public const PAID   = 'paid';
	public const VOID   = 'void';

	/**
	 * Payment statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::UNPAID => __( 'Unpaid', 'guam-restaurants-core' ),
			self::PAID   => __( 'Paid', 'guam-restaurants-core' ),
			self::VOID   => __( 'Void', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Formats an invoice number.
	 *
	 * @param string $year     Four-digit year.
	 * @param int    $sequence Sequence within the year.
	 * @return string
	 */
	public static function format( string $year, int $sequence ): string {
		return sprintf( '%s-%s-%04d', self::PREFIX, $year, $sequence );
	}

	/**
	 * Takes the next number for a Guam year and records it as used.
	 *
	 * @param string $year Four-digit year.
	 * @return string
	 */
	public static function next_number( string $year ): string {
		$sequence = get_option( self::SEQUENCE );
		$sequence = is_array( $sequence ) ? $sequence : array();
		$next     = (int) ( $sequence[ $year ] ?? 0 ) + 1;
		// Never hand out a number already on an ad (for example after the option was lost).
		while ( self::find( self::format( $year, $next ) ) ) {
			++$next;
		}
		$sequence[ $year ] = $next;
		update_option( self::SEQUENCE, $sequence, false );
		return self::format( $year, $next );
	}

	/**
	 * Gives an ad an invoice number, unless it has one. Sets the issue date
	 * (today, Guam), the due date (issue date plus DEFAULT_TERMS days) and
	 * the status (unpaid) where they are empty.
	 *
	 * @param int $ad_id Ad.
	 * @return string|\WP_Error The ad's invoice number.
	 */
	public static function issue( int $ad_id ) {
		if ( Ads::POST_TYPE !== get_post_type( $ad_id ) ) {
			return new \WP_Error( 'gr_invoice_ad', __( 'Invoices are issued on ads only.', 'guam-restaurants-core' ) );
		}
		$existing = (string) get_post_meta( $ad_id, self::META_NUMBER, true );
		if ( '' !== $existing ) {
			return $existing;
		}
		$today  = Server::today();
		$number = self::next_number( substr( $today, 0, 4 ) );
		update_post_meta( $ad_id, self::META_NUMBER, $number );
		if ( '' === (string) get_post_meta( $ad_id, self::META_ISSUED, true ) ) {
			update_post_meta( $ad_id, self::META_ISSUED, $today );
		}
		if ( '' === (string) get_post_meta( $ad_id, self::META_DUE, true ) ) {
			$issued = (string) get_post_meta( $ad_id, self::META_ISSUED, true );
			update_post_meta( $ad_id, self::META_DUE, ( new \DateTimeImmutable( $issued ) )->modify( '+' . self::DEFAULT_TERMS . ' days' )->format( 'Y-m-d' ) );
		}
		if ( '' === (string) get_post_meta( $ad_id, self::META_STATUS, true ) ) {
			update_post_meta( $ad_id, self::META_STATUS, self::UNPAID );
		}
		return $number;
	}

	/**
	 * The ad holding an invoice number, or 0.
	 *
	 * @param string $number Invoice number.
	 * @return int
	 */
	public static function find( string $number ): int {
		$ids = get_posts(
			array(
				'post_type'        => Ads::POST_TYPE,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => self::META_NUMBER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $number, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Cleans billing input from the ad edit screen. The invoice number is
	 * not editable; it comes from issue().
	 *
	 * @param array<string, mixed> $input Raw (unslashed) input.
	 * @return array{amount: string, issued: string, due: string, status: string, paid_on: string, email: string, note: string}
	 */
	public static function sanitize( array $input ): array {
		$status = sanitize_key( (string) ( $input['status'] ?? '' ) );
		return array(
			'amount'  => self::amount( (string) ( $input['amount'] ?? '' ) ),
			'issued'  => self::date( (string) ( $input['issued'] ?? '' ) ),
			'due'     => self::date( (string) ( $input['due'] ?? '' ) ),
			'status'  => isset( self::statuses()[ $status ] ) ? $status : '',
			'paid_on' => self::date( (string) ( $input['paid_on'] ?? '' ) ),
			'email'   => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'note'    => mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 200 ),
		);
	}

	/**
	 * Stores sanitized billing fields. Marking an invoice paid with no
	 * payment date records today (Guam).
	 *
	 * @param int                   $ad_id  Ad.
	 * @param array<string, string> $fields From sanitize().
	 * @return void
	 */
	public static function save( int $ad_id, array $fields ): void {
		if ( self::PAID === $fields['status'] && '' === $fields['paid_on'] ) {
			$fields['paid_on'] = Server::today();
		}
		if ( self::PAID !== $fields['status'] ) {
			$fields['paid_on'] = '';
		}
		$map = array(
			'amount'  => self::META_AMOUNT,
			'issued'  => self::META_ISSUED,
			'due'     => self::META_DUE,
			'status'  => self::META_STATUS,
			'paid_on' => self::META_PAID_ON,
			'email'   => self::META_EMAIL,
			'note'    => self::META_NOTE,
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $ad_id, $key, $fields[ $field ] );
			}
		}
	}

	/**
	 * An ad's billing fields.
	 *
	 * @param int $ad_id Ad.
	 * @return array{number: string, amount: string, issued: string, due: string, status: string, paid_on: string, email: string, note: string}
	 */
	public static function get( int $ad_id ): array {
		$meta = static function ( string $key ) use ( $ad_id ): string {
			return (string) get_post_meta( $ad_id, $key, true );
		};
		return array(
			'number'  => $meta( self::META_NUMBER ),
			'amount'  => $meta( self::META_AMOUNT ),
			'issued'  => $meta( self::META_ISSUED ),
			'due'     => $meta( self::META_DUE ),
			'status'  => $meta( self::META_STATUS ),
			'paid_on' => $meta( self::META_PAID_ON ),
			'email'   => $meta( self::META_EMAIL ),
			'note'    => $meta( self::META_NOTE ),
		);
	}

	/**
	 * Ads with an issued, unpaid invoice, oldest due date first, with
	 * `days_overdue` (0 when not yet due).
	 *
	 * @param string $today Y-m-d (Guam).
	 * @return array<int, array<string, mixed>>
	 */
	public static function unpaid( string $today ): array {
		$rows = array();
		foreach ( self::ad_ids_with( self::META_STATUS, self::UNPAID ) as $id ) {
			$ad      = Ads::get( $id );
			$billing = self::get( $id );
			if ( null === $ad || '' === $billing['number'] || 'trash' === $ad['post_status'] ) {
				continue;
			}
			$rows[] = array(
				'ad'           => $ad,
				'billing'      => $billing,
				'days_overdue' => '' !== $billing['due'] && $billing['due'] < $today ? self::days_between( $billing['due'], $today ) : 0,
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['billing']['due'], $a['billing']['number'] ) <=> array( $b['billing']['due'], $b['billing']['number'] );
			}
		);
		return $rows;
	}

	/**
	 * Published, active ads whose end date is between today and today plus
	 * $days (inclusive), soonest first, with `days_left`.
	 *
	 * @param string $today Y-m-d (Guam).
	 * @param int    $days  Window.
	 * @return array<int, array<string, mixed>>
	 */
	public static function expiring( string $today, int $days ): array {
		$last = ( new \DateTimeImmutable( $today ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		$rows = array();
		foreach ( self::ad_ids_with( Ads::META_STATUS, Ads::STATUS_ACTIVE, 'publish' ) as $id ) {
			$ad = Ads::get( $id );
			if ( null === $ad || '' === $ad['end'] || $ad['end'] < $today || $ad['end'] > $last ) {
				continue;
			}
			$rows[] = array(
				'ad'        => $ad,
				'billing'   => self::get( $id ),
				'days_left' => self::days_between( $today, $ad['end'] ),
			);
		}
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $a['ad']['end'], $a['ad']['id'] ) <=> array( $b['ad']['end'], $b['ad']['id'] );
			}
		);
		return $rows;
	}

	/**
	 * Ads serving today with no invoice number.
	 *
	 * @param string $today Y-m-d (Guam).
	 * @return array<int, array<string, mixed>>
	 */
	public static function serving_without_invoice( string $today ): array {
		$rows = array();
		foreach ( self::ad_ids_with( Ads::META_STATUS, Ads::STATUS_ACTIVE, 'publish' ) as $id ) {
			$ad = Ads::get( $id );
			if ( null !== $ad && Server::is_running( $ad, $today ) && '' === (string) get_post_meta( $id, self::META_NUMBER, true ) ) {
				$rows[] = $ad;
			}
		}
		return $rows;
	}

	/**
	 * Whole days from one Y-m-d date to another.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return int
	 */
	public static function days_between( string $from, string $to ): int {
		return (int) ( new \DateTimeImmutable( $from ) )->diff( new \DateTimeImmutable( $to ) )->format( '%r%a' );
	}

	/**
	 * Ad IDs with a meta value.
	 *
	 * @param string $key         Meta key.
	 * @param string $value       Meta value.
	 * @param string $post_status Post status, or 'any'.
	 * @return int[]
	 */
	private static function ad_ids_with( string $key, string $value, string $post_status = 'any' ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => Ads::POST_TYPE,
					'post_status'      => $post_status,
					'fields'           => 'ids',
					'posts_per_page'   => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ids only, admin report and daily job.
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
					'meta_key'         => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * A non-negative amount with two decimals, or ''.
	 *
	 * @param string $value Input such as "1,250" or "$300.5".
	 * @return string
	 */
	private static function amount( string $value ): string {
		$value = str_replace( array( '$', ',', ' ' ), '', trim( $value ) );
		return is_numeric( $value ) && (float) $value >= 0 ? number_format( (float) $value, 2, '.', '' ) : '';
	}

	/**
	 * A valid Y-m-d date, or ''.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	private static function date( string $value ): string {
		$value = trim( $value );
		$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}
}
