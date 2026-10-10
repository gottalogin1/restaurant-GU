<?php
/**
 * Converts the plugin's policy texts to block markup.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Legal;

defined( 'ABSPATH' ) || exit;

/**
 * The policy texts live in data/legal/*.md so counsel can review them as
 * plain files. This turns the small subset of Markdown they use into core
 * blocks: `## ` and `### ` headings, `- ` lists, `|` tables (first row is
 * the header, second the separator), paragraphs, `**bold**`, `[text](url)`,
 * and lines that are already block comments (`<!-- wp:... /-->`), passed
 * through. `{name}` placeholders are replaced from $vars. Everything else
 * is escaped.
 */
final class Markdown {

	/**
	 * Converts a text to block markup.
	 *
	 * @param string                $text Markdown.
	 * @param array<string, string> $vars Placeholder => plain value (URLs or text).
	 * @return string
	 */
	public static function to_blocks( string $text, array $vars = array() ): string {
		$blocks = array();
		foreach ( preg_split( "/\n\s*\n/", trim( str_replace( "\r\n", "\n", $text ) ) ) as $chunk ) {
			$lines = array_map( 'rtrim', explode( "\n", trim( $chunk ) ) );
			$first = $lines[0];
			if ( preg_match( '/^<!-- wp:[a-z0-9\/-]+ \/-->$/', $first ) ) {
				$blocks[] = $first;
			} elseif ( preg_match( '/^(#{2,3}) (.+)$/', $first, $m ) ) {
				$level    = strlen( $m[1] );
				$attrs    = 3 === $level ? ' {"level":3}' : '';
				$blocks[] = sprintf( '<!-- wp:heading%1$s --><h%2$d class="wp-block-heading">%3$s</h%2$d><!-- /wp:heading -->', $attrs, $level, self::inline( $m[2], $vars ) );
			} elseif ( 0 === strpos( $first, '- ' ) ) {
				$items = '';
				foreach ( $lines as $line ) {
					$items .= '<!-- wp:list-item --><li>' . self::inline( (string) preg_replace( '/^- /', '', $line ), $vars ) . '</li><!-- /wp:list-item -->';
				}
				$blocks[] = '<!-- wp:list --><ul class="wp-block-list">' . $items . '</ul><!-- /wp:list -->';
			} elseif ( 0 === strpos( $first, '|' ) && count( $lines ) >= 2 ) {
				$blocks[] = self::table( $lines, $vars );
			} else {
				$blocks[] = '<!-- wp:paragraph --><p>' . self::inline( implode( ' ', $lines ), $vars ) . '</p><!-- /wp:paragraph -->';
			}
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Escapes a line and applies bold, links and placeholders.
	 *
	 * @param string                $text Line.
	 * @param array<string, string> $vars Placeholders.
	 * @return string HTML.
	 */
	public static function inline( string $text, array $vars ): string {
		$html = esc_html( $text );
		$html = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( array $m ) use ( $vars ): string {
				$url = self::fill( wp_specialchars_decode( $m[2], ENT_QUOTES ), $vars );
				return sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), self::fill( $m[1], array_map( 'esc_html', $vars ) ) );
			},
			$html
		);
		$html = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		return self::fill( $html, array_map( 'esc_html', $vars ) );
	}

	/**
	 * Replaces {name} placeholders.
	 *
	 * @param string                $text Text.
	 * @param array<string, string> $vars Placeholders.
	 * @return string
	 */
	private static function fill( string $text, array $vars ): string {
		foreach ( $vars as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}
		return $text;
	}

	/**
	 * Table block.
	 *
	 * @param string[]              $lines Table lines.
	 * @param array<string, string> $vars  Placeholders.
	 * @return string
	 */
	private static function table( array $lines, array $vars ): string {
		$cells = static function ( string $line ) use ( $vars ): array {
			return array_map(
				static function ( string $cell ) use ( $vars ): string {
					return self::inline( trim( $cell ), $vars );
				},
				explode( '|', trim( $line, " |\t" ) )
			);
		};
		$head  = '<tr><th>' . implode( '</th><th>', $cells( $lines[0] ) ) . '</th></tr>';
		$body  = '';
		foreach ( array_slice( $lines, 2 ) as $line ) {
			$body .= '<tr><td>' . implode( '</td><td>', $cells( $line ) ) . '</td></tr>';
		}
		return '<!-- wp:table --><figure class="wp-block-table"><table><thead>' . $head . '</thead><tbody>' . $body . '</tbody></table></figure><!-- /wp:table -->';
	}
}
