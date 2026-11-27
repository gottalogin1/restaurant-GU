<?php
/**
 * Restaurants > Hotels.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Admin;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Osm\Overpass_Client;
use GuamRestaurants\Core\Site\Hotel_Pages;
use GuamRestaurants\Core\Site\Hotels;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the hotels from OpenStreetMap, hides the ones that should not get a
 * page, and adds one OSM lacks (Site\Hotels). A hotel added by hand needs a
 * source link for its location (CLAUDE.md rules 2 and 3).
 */
final class Hotels_Screen {

	public const PAGE          = 'gr-hotels';
	public const ACTION        = 'gr_hotels_save';
	public const IMPORT_ACTION = 'gr_hotels_import';
	public const CAPABILITY    = 'manage_options';

	private const NOTICE = 'gr_hotels_notice_';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( self::class, 'handle_import' ) );
	}

	/**
	 * Adds the submenu under Restaurants.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Content_Types::POST_TYPE,
			__( 'Hotels', 'guam-restaurants-core' ),
			__( 'Hotels', 'guam-restaurants-core' ),
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
	 * Applies the form: hidden hotels, removed hand-added hotels, and one new
	 * hotel.
	 *
	 * @param array<string, mixed> $input `hide` (slug => 1, for every hotel shown), `remove` (slug => 1) and `add` (name, lat, lng, source).
	 * @return string[] Problems; empty when everything was saved.
	 */
	public static function apply( array $input ): array {
		$hotels = Hotels::all();
		$hide   = array_map( 'strval', array_keys( (array) ( $input['hide'] ?? array() ) ) );
		$remove = array_map( 'strval', array_keys( (array) ( $input['remove'] ?? array() ) ) );
		foreach ( $hotels as $slug => $hotel ) {
			if ( in_array( (string) $slug, $remove, true ) && '' === $hotel['osm'] ) {
				unset( $hotels[ $slug ] );
				continue;
			}
			$hotels[ $slug ]['hidden'] = in_array( (string) $slug, $hide, true );
		}

		$add = (array) ( $input['add'] ?? array() );
		if ( '' !== trim( (string) ( $add['name'] ?? '' ) ) ) {
			$lat    = is_numeric( $add['lat'] ?? null ) ? (float) $add['lat'] : null;
			$lng    = is_numeric( $add['lng'] ?? null ) ? (float) $add['lng'] : null;
			$source = esc_url_raw( trim( (string) ( $add['source'] ?? '' ) ), array( 'http', 'https' ) );
			$hotel  = null === $lat || null === $lng ? null : Hotels::clean(
				array(
					'name'    => (string) $add['name'],
					'lat'     => $lat,
					'lng'     => $lng,
					'village' => (string) Village_Locator::instance()->slug_for( $lat, $lng ),
					'source'  => $source,
				)
			);
			if ( null === $hotel || '' === $source ) {
				/* translators: %s: hotel name. */
				return array( sprintf( __( '%s: give a latitude and longitude on Guam (for example 13.5167 and 144.8051) and the link to where the location came from.', 'guam-restaurants-core' ), sanitize_text_field( (string) $add['name'] ) ) );
			}
			$hotels[ Hotels::unique_slug( $hotel, $hotels ) ] = $hotel;
		}
		Hotels::save( $hotels );
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
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only keys are read from hide and remove; apply() sanitizes add.
		$problems = self::apply(
			array(
				'hide'   => isset( $_POST['hide'] ) && is_array( $_POST['hide'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['hide'] ) ) : array(),
				'remove' => isset( $_POST['remove'] ) && is_array( $_POST['remove'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['remove'] ) ) : array(),
				'add'    => isset( $_POST['add'] ) && is_array( $_POST['add'] ) ? wp_unslash( $_POST['add'] ) : array(),
			)
		);
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::notice( $problems ? array( 'error', __( 'Nothing was saved.', 'guam-restaurants-core' ) . ' ' . implode( ' ', $problems ) ) : array( 'success', __( 'Saved.', 'guam-restaurants-core' ) ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Loads the hotels from OpenStreetMap now (one Overpass request).
	 *
	 * @return void
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'guam-restaurants-core' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::IMPORT_ACTION );
		$client = new Overpass_Client();
		try {
			$counts = Hotels::import( $client->fetch( Overpass_Client::hotels_query() )['elements'] );
			self::notice(
				array(
					'success',
					sprintf(
						/* translators: 1: created, 2: updated, 3: unchanged, 4: removed, 5: hotels with a page. */
						__( 'Loaded from OpenStreetMap: %1$d new, %2$d updated, %3$d unchanged, %4$d removed. %5$d hotels have a page.', 'guam-restaurants-core' ),
						$counts['created'],
						$counts['updated'],
						$counts['unchanged'],
						$counts['removed'],
						count( Hotels::with_pages() )
					),
				)
			);
		} catch ( Request_Failed $e ) {
			Ingestion_Runs::log_failure( Hotels::RUN_SOURCE, 'Overpass: ' . $e->getMessage(), $e->attempt_log() );
			self::notice( array( 'error', __( 'OpenStreetMap did not answer. Try again in a few minutes, or run: wp guam-restaurants hotels import', 'guam-restaurants-core' ) ) );
		} catch ( \RuntimeException $e ) {
			self::notice( array( 'error', $e->getMessage() ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Keeps a notice for the next screen load.
	 *
	 * @param array{0: string, 1: string} $notice Type and text.
	 * @return void
	 */
	private static function notice( array $notice ): void {
		set_transient( self::NOTICE . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
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
		echo '<div class="wrap"><h1>' . esc_html__( 'Hotels', 'guam-restaurants-core' ) . '</h1>';
		$notice = get_transient( self::NOTICE . get_current_user_id() );
		if ( is_array( $notice ) && 2 === count( $notice ) ) {
			delete_transient( self::NOTICE . get_current_user_id() );
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', 'error' === $notice[0] ? 'error' : 'success', esc_html( (string) $notice[1] ) );
		}
		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: 1: number of places, 2: kilometres. */
					__( 'Each hotel with at least %1$d places to eat within %2$s km gets a "Restaurants near" page, listing places nearest first. Hide a hotel to take its page down.', 'guam-restaurants-core' ),
					\GuamRestaurants\Core\Site\Cuisine_Villages::MIN_PLACES,
					number_format_i18n( Hotels::DRIVE_M / 1000 )
				)
			),
			esc_url( Hotel_Pages::url() ),
			esc_html__( 'See the hotel list', 'guam-restaurants-core' )
		);

		$last = Ingestion_Runs::last_success( Hotels::RUN_SOURCE );
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::IMPORT_ACTION );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::IMPORT_ACTION ) );
		echo '<p>';
		submit_button( __( 'Load hotels from OpenStreetMap', 'guam-restaurants-core' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html(
			null === $last
				? __( 'Not loaded yet. Run this once, then every few months.', 'guam-restaurants-core' )
				/* translators: %s: date and time. */
				: sprintf( __( 'Last loaded %s (UTC).', 'guam-restaurants-core' ), (string) ( $last['finished_at'] ?? $last['started_at'] ?? '' ) )
		) . '</span></p></form>';

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Hotel', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Village', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Places within walking distance', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'A short drive away', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Page', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Location from', 'guam-restaurants-core' ) . '</th><th>' . esc_html__( 'Hide', 'guam-restaurants-core' ) . '</th></tr></thead><tbody>';
		$hotels = Hotels::all();
		if ( ! $hotels ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No hotels yet. Load them from OpenStreetMap above.', 'guam-restaurants-core' ) . '</td></tr>';
		}
		foreach ( $hotels as $slug => $hotel ) {
			$places = Hotels::places( $hotel );
			$page   = ! $hotel['hidden'] && Hotels::has_page( $hotel );
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$d</td><td>%4$d</td><td>%5$s</td><td>%6$s</td><td><label><input type="checkbox" name="hide[%7$s]" value="1"%8$s> %9$s</label>%10$s</td></tr>',
				esc_html( $hotel['name'] ),
				esc_html( Hotels::village_name( $hotel ) ),
				count( $places['walk'] ),
				count( $places['drive'] ),
				$page ? '<a href="' . esc_url( Hotel_Pages::url( (string) $slug ) ) . '">' . esc_html__( 'View', 'guam-restaurants-core' ) . '</a>' : esc_html( $hotel['hidden'] ? __( 'Hidden', 'guam-restaurants-core' ) : __( 'Too few places', 'guam-restaurants-core' ) ),
				'' === $hotel['source'] ? '' : '<a href="' . esc_url( $hotel['source'] ) . '" rel="noopener">' . esc_html( '' === $hotel['osm'] ? __( 'Added by hand', 'guam-restaurants-core' ) : 'OpenStreetMap' ) . '</a>',
				esc_attr( (string) $slug ),
				checked( $hotel['hidden'], true, false ),
				esc_html__( 'Hide', 'guam-restaurants-core' ),
				'' === $hotel['osm'] ? sprintf( ' <label><input type="checkbox" name="remove[%1$s]" value="1"> %2$s</label>', esc_attr( (string) $slug ), esc_html__( 'Remove', 'guam-restaurants-core' ) ) : ''
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Add a hotel OpenStreetMap lacks', 'guam-restaurants-core' ) . '</h2><p class="description">' . esc_html__( 'Give the hotel\'s latitude and longitude and the link to where they came from. Better still, add the hotel to OpenStreetMap and load the list again.', 'guam-restaurants-core' ) . '</p>';
		printf(
			'<p><input type="text" class="regular-text" name="add[name]" placeholder="%1$s"> <input type="text" name="add[lat]" placeholder="%2$s" inputmode="decimal"> <input type="text" name="add[lng]" placeholder="%3$s" inputmode="decimal"> <input type="url" class="regular-text" name="add[source]" placeholder="%4$s"></p>',
			esc_attr__( 'Hotel name', 'guam-restaurants-core' ),
			esc_attr__( 'Latitude', 'guam-restaurants-core' ),
			esc_attr__( 'Longitude', 'guam-restaurants-core' ),
			esc_attr__( 'Source link', 'guam-restaurants-core' )
		);
		submit_button( __( 'Save hotels', 'guam-restaurants-core' ) );
		echo '</form></div>';
	}
}
