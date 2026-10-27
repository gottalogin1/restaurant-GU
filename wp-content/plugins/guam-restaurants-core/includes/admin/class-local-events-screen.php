<?php
/**
 * Restaurants > Markets and fiestas.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Local_Events;
use GuamRestaurants\Core\Site\Restaurant_View;

defined( 'ABSPATH' ) || exit;

/**
 * Edits the weekly markets and village fiesta dates (Site\Local_Events).
 * Every row needs its source link: these are facts we publish, so each one
 * says where it came from (CLAUDE.md rules 2 and 3). "Use the shipped list"
 * drops the saved lists and goes back to data/markets.php and
 * data/fiestas.php.
 */
final class Local_Events_Screen {

	public const PAGE       = 'gr-local-events';
	public const ACTION     = 'gr_local_events_save';
	public const CAPABILITY = 'manage_options';

	private const NOTICE = 'gr_local_events_notice_';

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
			__( 'Markets and fiestas', 'guam-restaurants-core' ),
			__( 'Markets and fiestas', 'guam-restaurants-core' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Screen URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return add_query_arg(
			array(
				'post_type' => Content_Types::POST_TYPE,
				'page'      => self::PAGE,
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Saves both lists from the form, or goes back to the shipped ones.
	 *
	 * @param array<string, mixed> $input `markets`, `fiestas` and `reset`.
	 * @return string[] Problems; empty when everything was saved.
	 */
	public static function apply( array $input ): array {
		if ( ! empty( $input['reset'] ) ) {
			delete_option( Local_Events::MARKETS_OPTION );
			delete_option( Local_Events::FIESTAS_OPTION );
			return array();
		}
		$villages = self::villages();
		$problems = array();
		$markets  = array();
		foreach ( (array) ( $input['markets'] ?? array() ) as $row ) {
			$row = (array) $row;
			if ( '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
				continue;
			}
			$market = Local_Events::clean_market( $row );
			if ( null === $market || ! isset( $villages[ $market['village'] ] ) || '' === $market['source'] ) {
				/* translators: %s: market name. */
				$problems[] = sprintf( __( '%s: choose a village and at least one day, and give the source link.', 'guam-restaurants-core' ), sanitize_text_field( (string) $row['name'] ) );
				continue;
			}
			$markets[] = $market;
		}
		$fiestas = array();
		foreach ( (array) ( $input['fiestas'] ?? array() ) as $row ) {
			$row = (array) $row;
			if ( '' === trim( (string) ( $row['name'] ?? '' ) ) && '' === trim( (string) ( $row['date'] ?? '' ) ) ) {
				continue;
			}
			$fiesta = Local_Events::clean_fiesta( $row );
			if ( null === $fiesta || ! isset( $villages[ $fiesta['village'] ] ) || '' === $fiesta['source'] ) {
				/* translators: %s: fiesta name. */
				$problems[] = sprintf( __( '%s: give the date, village, patron saint and source link.', 'guam-restaurants-core' ), sanitize_text_field( (string) ( $row['name'] ?? $row['date'] ?? '' ) ) );
				continue;
			}
			$fiestas[] = $fiesta;
		}
		if ( $problems ) {
			return $problems;
		}
		update_option( Local_Events::MARKETS_OPTION, $markets, false );
		update_option( Local_Events::FIESTAS_OPTION, $fiestas, false );
		return array();
	}

	/**
	 * Handles the form.
	 *
	 * @return void
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- apply() sanitizes each row.
		$problems = self::apply(
			array(
				'markets' => isset( $_POST['markets'] ) && is_array( $_POST['markets'] ) ? wp_unslash( $_POST['markets'] ) : array(),
				'fiestas' => isset( $_POST['fiestas'] ) && is_array( $_POST['fiestas'] ) ? wp_unslash( $_POST['fiestas'] ) : array(),
				'reset'   => ! empty( $_POST['reset'] ),
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		set_transient(
			self::NOTICE . get_current_user_id(),
			$problems ? array( 'error', __( 'Nothing was saved.', 'guam-restaurants-core' ) . ' ' . implode( ' ', $problems ) ) : array( 'success', __( 'Saved. The This week page and village pages use these lists now.', 'guam-restaurants-core' ) ),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Markets and fiestas', 'guam-restaurants-core' ) . '</h1>';
		$notice = get_transient( self::NOTICE . get_current_user_id() );
		if ( is_array( $notice ) && 2 === count( $notice ) ) {
			delete_transient( self::NOTICE . get_current_user_id() );
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', 'error' === $notice[0] ? 'error' : 'success', esc_html( (string) $notice[1] ) );
		}
		echo '<p>' . esc_html__( 'Weekly food markets and village fiestas, shown on the This week on Guam page and on village pages. Each row needs the link to the page it came from. Write names and places as the source gives them; do not copy descriptions.', 'guam-restaurants-core' ) . '</p>';
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION ) );

		$villages = self::villages();
		$days     = Restaurant_View::day_names();
		echo '<h2>' . esc_html__( 'Weekly markets', 'guam-restaurants-core' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Village and place', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Days', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Hours (leave empty if the source gives none)', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Source link and date checked', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		$markets = Local_Events::markets();
		$count   = count( $markets ) + 2;
		for ( $i = 0; $i < $count; $i++ ) {
			$market = $markets[ $i ] ?? array(
				'name'    => '',
				'village' => '',
				'days'    => array(),
				'opens'   => '',
				'closes'  => '',
				'place'   => '',
				'source'  => '',
				'checked' => '',
			);
			$boxes  = '';
			foreach ( $days as $day => $label ) {
				$boxes .= sprintf( '<label><input type="checkbox" name="markets[%1$d][days][]" value="%2$s"%3$s> %4$s</label> ', (int) $i, esc_attr( $day ), checked( in_array( $day, $market['days'], true ), true, false ), esc_html( $label ) );
			}
			printf(
				'<tr><td><input type="text" class="regular-text" name="markets[%1$d][name]" value="%2$s"></td><td>%3$s<br><input type="text" name="markets[%1$d][place]" value="%4$s" placeholder="%5$s"></td><td>%6$s</td><td><input type="time" name="markets[%1$d][opens]" value="%7$s"> – <input type="time" name="markets[%1$d][closes]" value="%8$s"></td><td><input type="url" class="regular-text" name="markets[%1$d][source]" value="%9$s" placeholder="https://"><br><input type="date" name="markets[%1$d][checked]" value="%10$s"></td></tr>',
				(int) $i,
				esc_attr( $market['name'] ),
				self::village_select( 'markets[' . $i . '][village]', $market['village'], $villages ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
				esc_attr( $market['place'] ),
				esc_attr__( 'Place', 'guam-restaurants-core' ),
				$boxes, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
				esc_attr( $market['opens'] ),
				esc_attr( $market['closes'] ),
				esc_attr( $market['source'] ),
				esc_attr( $market['checked'] )
			);
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Clear a row\'s name to remove it.', 'guam-restaurants-core' ) . '</p>';

		echo '<h2>' . esc_html__( 'Village fiestas', 'guam-restaurants-core' ) . '</h2><p class="description">' . esc_html__( 'Add each year\'s dates when Guampedia or the Archdiocese publishes them. Village pages show the latest year listed.', 'guam-restaurants-core' ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Village and community', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Patron saint or feast', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Source link', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		$fiestas = Local_Events::fiestas();
		$count   = count( $fiestas ) + 3;
		for ( $i = 0; $i < $count; $i++ ) {
			$fiesta = $fiestas[ $i ] ?? array(
				'date'    => '',
				'village' => '',
				'place'   => '',
				'name'    => '',
				'source'  => '' === ( $fiestas[0]['source'] ?? '' ) ? '' : $fiestas[0]['source'],
			);
			printf(
				'<tr><td><input type="date" name="fiestas[%1$d][date]" value="%2$s"></td><td>%3$s <input type="text" name="fiestas[%1$d][place]" value="%4$s" placeholder="%5$s"></td><td><input type="text" class="regular-text" name="fiestas[%1$d][name]" value="%6$s"></td><td><input type="url" class="regular-text" name="fiestas[%1$d][source]" value="%7$s"></td></tr>',
				(int) $i,
				esc_attr( $fiesta['date'] ),
				self::village_select( 'fiestas[' . $i . '][village]', $fiesta['village'], $villages ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
				esc_attr( $fiesta['place'] ),
				esc_attr__( 'Community (optional)', 'guam-restaurants-core' ),
				esc_attr( $fiesta['name'] ),
				esc_attr( $fiesta['source'] )
			);
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Clear a row\'s date and name to remove it.', 'guam-restaurants-core' ) . '</p>';
		submit_button( __( 'Save markets and fiestas', 'guam-restaurants-core' ) );
		echo '</form>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="reset" value="1">', esc_attr( self::ACTION ) );
		submit_button( __( 'Use the shipped lists instead', 'guam-restaurants-core' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Village slugs => names.
	 *
	 * @return array<string, string>
	 */
	private static function villages(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_VILLAGE,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$out   = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$out[ $term->slug ] = $term->name;
		}
		return $out;
	}

	/**
	 * A village dropdown.
	 *
	 * @param string                $name     Field name.
	 * @param string                $selected Slug.
	 * @param array<string, string> $villages Slug => name.
	 * @return string
	 */
	private static function village_select( string $name, string $selected, array $villages ): string {
		$options = '<option value="">' . esc_html__( 'Village', 'guam-restaurants-core' ) . '</option>';
		foreach ( $villages as $slug => $label ) {
			$options .= sprintf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $slug ), selected( $selected, $slug, false ), esc_html( $label ) );
		}
		return sprintf( '<select name="%1$s">%2$s</select>', esc_attr( $name ), $options );
	}
}
