<?php
/**
 * Light / dark / system switch tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Appearance\Color_Scheme;
use WP_UnitTestCase;

/**
 * The switch block, the admin bar menu and the head script.
 *
 * @covers \GuamRestaurants\Core\Appearance\Color_Scheme
 */
class Color_Scheme_Test extends WP_UnitTestCase {

	/**
	 * The block renders one button per choice, hidden until the script runs.
	 */
	public function test_block_has_a_button_per_choice() {
		$html = do_blocks( '<!-- wp:guam-restaurants/color-scheme-toggle /-->' );

		$this->assertMatchesRegularExpression( '/^<div class="gr-scheme wp-block-guam-restaurants-color-scheme-toggle"/', $html );
		$this->assertStringContainsString( 'role="group"', $html );
		$this->assertStringContainsString( 'hidden="hidden"', $html );
		foreach ( array( 'light', 'dark', 'system' ) as $choice ) {
			$this->assertSame( 1, substr_count( $html, 'data-gr-scheme="' . $choice . '"' ) );
		}
		$this->assertSame( 3, substr_count( $html, 'aria-pressed="false"' ) );
	}

	/**
	 * The admin bar gets a Theme menu with Light, Dark and System links.
	 */
	public function test_admin_bar_menu() {
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new \WP_Admin_Bar();
		$bar->add_node( array( 'id' => 'top-secondary' ) );

		Color_Scheme::admin_bar( $bar );

		$menu = $bar->get_node( Color_Scheme::MENU_ID );
		$this->assertNotNull( $menu );
		$this->assertStringContainsString( 'gr-scheme-current', $menu->title );
		foreach ( array( 'light', 'dark', 'system' ) as $choice ) {
			$node = $bar->get_node( Color_Scheme::MENU_ID . '-' . $choice );
			$this->assertSame( '#gr-scheme-' . $choice, $node->href );
			$this->assertSame( Color_Scheme::MENU_ID, $node->parent );
		}
	}

	/**
	 * The head script is printed inline on public pages and in wp-admin.
	 */
	public function test_head_script_is_inline() {
		$this->assertSame( 1, has_action( 'wp_head', array( Color_Scheme::class, 'print_head_script' ) ) );
		$this->assertSame( 1, has_action( 'admin_head', array( Color_Scheme::class, 'print_head_script' ) ) );

		ob_start();
		Color_Scheme::print_head_script();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<script', $html );
		$this->assertStringContainsString( 'gr-color-scheme', $html );
		$this->assertStringContainsString( 'window.guamColorScheme', $html );
	}
}
