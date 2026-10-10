<?php
/**
 * Correction form block, shortcode and page text.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Corrections;

use GuamRestaurants\Core\Advertising\Display as Advertising_Display;
use GuamRestaurants\Core\Advertising\Rest_Controller as Advertising_Rest;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Site\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `guam-restaurants/correction-form` block and the
 * `[guam_correction_form]` shortcode. Both print a placeholder that
 * assets/corrections/corrections.js turns into the form, after loading a
 * fresh nonce and the restaurant from `?restaurant=` over REST, so the page
 * can be cached. The form reuses the advertiser form's styles.
 *
 * Every restaurant page links to the "Suggest a correction" page with
 * `?restaurant=<id>` (Site\Blocks::listing_actions()) once it is published.
 */
final class Display {

	public const SHORTCODE    = 'guam_correction_form';
	public const SCRIPT       = 'guam-restaurants-correction-form';
	public const STYLE        = 'guam-restaurants-correction-form';
	public const BLOCK_FOLDER = 'blocks/correction-form';

	/**
	 * Marks that the correction page has been given the form (see upgrade_page()).
	 */
	public const PAGE_OPTION = 'guam_restaurants_correction_page_version';

	/**
	 * The editor placeholder in the original draft page.
	 */
	private const PLACEHOLDER = '[Editor: add the correction form here, then publish this page.]';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets_and_block' ) );
		add_action( 'init', array( self::class, 'upgrade_page' ), 30 );
		add_shortcode( self::SHORTCODE, array( self::class, 'render' ) );
	}

	/**
	 * Registers the script and the block.
	 *
	 * @return void
	 */
	public static function register_assets_and_block(): void {
		wp_register_script( self::SCRIPT, plugins_url( 'assets/corrections/corrections.js', GUAM_RESTAURANTS_CORE_FILE ), array( 'wp-i18n' ), GUAM_RESTAURANTS_CORE_VERSION, array( 'strategy' => 'defer' ) );
		wp_set_script_translations( self::SCRIPT, 'guam-restaurants-core' );
		wp_register_style( self::STYLE, plugins_url( 'assets/corrections/corrections.css', GUAM_RESTAURANTS_CORE_FILE ), array( Advertising_Display::STYLE ), GUAM_RESTAURANTS_CORE_VERSION );

		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER,
			array( 'render_callback' => array( self::class, 'render' ) )
		);
	}

	/**
	 * Placeholder markup.
	 *
	 * @return string
	 */
	public static function render(): string {
		Assets::enqueue();
		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );

		return sprintf(
			'<div class="gr-lead-form gr-correction-form" id="correction-form" data-gr-correction-form data-form="%1$s" data-search="%2$s" data-submit="%3$s" aria-live="polite"><p class="gr-lead-form__status">%4$s</p><noscript><p>%5$s</p></noscript></div>',
			esc_url( Rest_Controller::url( 'form' ) ),
			esc_url( Advertising_Rest::url( 'restaurants' ) ),
			esc_url( Rest_Controller::url() ),
			esc_html__( 'Loading the form…', 'guam-restaurants-core' ),
			esc_html__( 'The correction form needs JavaScript.', 'guam-restaurants-core' )
		);
	}

	/**
	 * Page content: the listing context, how requests are handled with our
	 * response times, and the form.
	 *
	 * @return string Block markup.
	 */
	public static function page_content(): string {
		$p     = static function ( string $text ): string {
			return '<!-- wp:paragraph --><p>' . esc_html( $text ) . '</p><!-- /wp:paragraph -->';
		};
		$items = array(
			sprintf(
				/* translators: %d: number of days. */
				__( 'Corrections to facts (hours, phone, address, website, cuisine, closed or moved): we reply within %d days.', 'guam-restaurants-core' ),
				Corrections::RESPONSE_DAYS_CORRECTION
			),
			sprintf(
				/* translators: %d: number of days. */
				__( 'Owners and managers asking to change or remove a listing: we reply within %d days.', 'guam-restaurants-core' ),
				Corrections::RESPONSE_DAYS_LISTING
			),
			__( 'We check every change against a source, such as the restaurant\'s website or signage, before making it. A note on where to check helps us act faster.', 'guam-restaurants-core' ),
			__( 'We remove a listing that shows personal information, is not open to the public, is a duplicate or is not a real restaurant. A closed restaurant is marked closed rather than removed.', 'guam-restaurants-core' ),
			__( 'Ratings, reviews and award results are not changed on request, and advertising never affects what we list.', 'guam-restaurants-core' ),
		);
		$list  = '<!-- wp:list --><ul class="wp-block-list">';
		foreach ( $items as $item ) {
			$list .= '<!-- wp:list-item --><li>' . esc_html( $item ) . '</li><!-- /wp:list-item -->';
		}
		$list .= '</ul><!-- /wp:list -->';

		return '<!-- wp:guam-restaurants/listing-context /-->'
			. "\n\n" . $p( __( 'Spotted wrong hours, a new phone number or a closure? Own this restaurant and want its listing changed or removed? Tell us here.', 'guam-restaurants-core' ) )
			. "\n\n" . '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'How we handle requests', 'guam-restaurants-core' ) . '</h2><!-- /wp:heading -->'
			. "\n\n" . $list
			. "\n\n" . '<!-- wp:guam-restaurants/correction-form /-->';
	}

	/**
	 * Gives the correction page its form, once. A draft that still holds the
	 * original editor placeholder is replaced with page_content() and
	 * published, so every listing links to it. A page someone has already
	 * edited is left alone (add the "Correction form" block to it by hand).
	 *
	 * @return void
	 */
	public static function upgrade_page(): void {
		if ( '1' === get_option( self::PAGE_OPTION ) ) {
			return;
		}
		$definitions = Pages::definitions();
		$page        = get_page_by_path( $definitions['correction']['slug'] ?? 'suggest-a-correction', OBJECT, 'page' );
		if ( $page instanceof \WP_Post && 'draft' === $page->post_status && false !== strpos( $page->post_content, self::PLACEHOLDER ) ) {
			wp_update_post(
				array(
					'ID'           => $page->ID,
					'post_content' => self::page_content(),
					'post_status'  => 'publish',
				)
			);
		}
		update_option( self::PAGE_OPTION, '1' );
	}
}
