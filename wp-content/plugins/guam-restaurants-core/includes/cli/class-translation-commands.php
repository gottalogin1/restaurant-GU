<?php
/**
 * WP-CLI command for the Japanese and Korean pages.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Translation\Languages;
use GuamRestaurants\Core\Translation\Source_Text;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp guam-restaurants translations missing`.
 */
final class Translation_Commands {

	/**
	 * Registers the command.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants translations missing', array( self::class, 'missing' ) );
	}

	/**
	 * Lists saved English text (page titles and text, cuisine names, price
	 * levels, the tagline, template headings) that has no translation yet.
	 * Translate the list and add it to data/translations/<lang>.php
	 * (docs/translation.md §4). Reads only; changes nothing.
	 *
	 * ## OPTIONS
	 *
	 * --lang=<lang>
	 * : ja or ko.
	 *
	 * [--format=<format>]
	 * : json (English => "" for each missing text, ready to fill in) or count.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants translations missing --lang=ja > missing-ja.json
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public static function missing( array $args, array $assoc_args ): void {
		$code = (string) ( $assoc_args['lang'] ?? '' );
		if ( ! isset( Languages::LOCALES[ $code ] ) ) {
			WP_CLI::error( 'Use --lang=ja or --lang=ko.' );
		}
		$missing = Source_Text::missing( $code );
		if ( 'count' === ( $assoc_args['format'] ?? 'json' ) ) {
			WP_CLI::log( (string) count( $missing ) );
			return;
		}
		WP_CLI::log( (string) wp_json_encode( array_fill_keys( $missing, '' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
