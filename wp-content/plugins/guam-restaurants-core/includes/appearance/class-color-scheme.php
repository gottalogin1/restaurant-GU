<?php
/**
 * Light, dark or system color scheme on the public site and in wp-admin.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Appearance;

defined( 'ABSPATH' ) || exit;

/**
 * People pick light, dark or "system" (follow the device) with the header
 * switch block or the admin bar's Theme menu. The choice is kept in the
 * browser (localStorage), so it needs no account, cached pages stay
 * correct, and the public site and wp-admin share it. head.js applies it
 * before the page is drawn; the theme holds the public site's dark palette
 * and admin-dark.css restyles wp-admin.
 */
final class Color_Scheme {

	public const SCRIPT = 'guam-restaurants-color-scheme';

	public const STYLE = 'guam-restaurants-color-scheme';

	public const ADMIN_STYLE = 'guam-restaurants-admin-dark';

	public const MENU_ID = 'gr-color-scheme';

	/**
	 * Icons for the switch buttons (decorative; each button has a label).
	 */
	private const ICONS = array(
		'light'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>',
		'dark'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>',
		'system' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8m-4-4v4"/></svg>',
	);

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ) );
		add_action( 'wp_head', array( self::class, 'print_head_script' ), 1 );
		add_action( 'admin_head', array( self::class, 'print_head_script' ), 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
	}

	/**
	 * The choices, in display order, with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return array(
			'light'  => __( 'Light', 'guam-restaurants-core' ),
			'dark'   => __( 'Dark', 'guam-restaurants-core' ),
			'system' => __( 'System', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Registers the switch script and styles.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		$base = plugins_url( 'assets/appearance/', GUAM_RESTAURANTS_CORE_FILE );
		$ver  = GUAM_RESTAURANTS_CORE_VERSION;
		wp_register_script( self::SCRIPT, $base . 'color-scheme.js', array(), $ver, array( 'strategy' => 'defer' ) );
		wp_register_style( self::STYLE, $base . 'color-scheme.css', array(), $ver );
		wp_register_style( self::ADMIN_STYLE, $base . 'admin-dark.css', array(), $ver );
	}

	/**
	 * Prints head.js inline, so the scheme is set before anything is drawn.
	 *
	 * @return void
	 */
	public static function print_head_script(): void {
		$source = file_get_contents( GUAM_RESTAURANTS_CORE_DIR . 'assets/appearance/head.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file.
		if ( false !== $source ) {
			wp_print_inline_script_tag( $source );
		}
	}

	/**
	 * Public pages: the switch script and styles.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_script( self::SCRIPT );
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Admin screens: the switch, and the dark styles everywhere except the block
	 * editor, which draws its own interface.
	 *
	 * @return void
	 */
	public static function enqueue_admin(): void {
		self::enqueue();
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! ( $screen instanceof \WP_Screen && $screen->is_block_editor() ) ) {
			wp_enqueue_style( self::ADMIN_STYLE );
		}
	}

	/**
	 * Admin bar: a Theme menu with one link per choice. color-scheme.js
	 * handles the clicks and marks the current choice.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 * @return void
	 */
	public static function admin_bar( $bar ): void {
		$choices = self::choices();
		$bar->add_node(
			array(
				'id'     => self::MENU_ID,
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">'
					/* translators: %s: Light, Dark or System. */
					. sprintf( esc_html__( 'Theme: %s', 'guam-restaurants-core' ), '<span class="gr-scheme-current">' . esc_html( $choices['system'] ) . '</span>' )
					. '</span>',
				'href'   => false,
				'meta'   => array( 'tabindex' => 0 ),
			)
		);
		foreach ( $choices as $value => $label ) {
			$bar->add_node(
				array(
					'id'     => self::MENU_ID . '-' . $value,
					'parent' => self::MENU_ID,
					'title'  => esc_html( $label ),
					'href'   => '#gr-scheme-' . $value,
					'meta'   => array( 'class' => 'gr-scheme-option' ),
				)
			);
		}
	}

	/**
	 * The switch's buttons, for the color-scheme-toggle block.
	 *
	 * @return string
	 */
	public static function buttons(): string {
		$html = '';
		foreach ( self::choices() as $value => $label ) {
			$html .= sprintf(
				'<button type="button" class="gr-scheme__option" data-gr-scheme="%1$s" aria-pressed="false" aria-label="%2$s" title="%3$s">%4$s</button>',
				esc_attr( $value ),
				esc_attr( $label ),
				esc_attr( 'system' === $value ? __( 'Match my device', 'guam-restaurants-core' ) : $label ),
				self::ICONS[ $value ]
			);
		}
		return $html;
	}
}
