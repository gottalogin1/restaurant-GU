<?php
/**
 * Reads a Foursquare Open Source Places extract.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Fsq;

defined( 'ABSPATH' ) || exit;

/**
 * Reads rows from JSON Lines (what bin/fsq-extract-guam.py writes), a JSON
 * array, or CSV with a header row.
 */
final class Reader {

	/**
	 * Rows from a file.
	 *
	 * @param string $file Path.
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException When the file cannot be read or parsed.
	 */
	public static function read( string $file ): array {
		$contents = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			throw new \RuntimeException( esc_html( "Cannot read {$file}." ) );
		}
		return self::parse( $contents, strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) );
	}

	/**
	 * Rows from file contents.
	 *
	 * @param string $contents  Contents.
	 * @param string $extension File extension: csv, json or jsonl.
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException When the contents cannot be parsed.
	 */
	public static function parse( string $contents, string $extension ): array {
		$contents = ltrim( $contents, "\xEF\xBB\xBF" );
		if ( 'csv' === $extension ) {
			return self::csv( $contents );
		}

		$trimmed = ltrim( $contents );
		if ( '' !== $trimmed && '[' === $trimmed[0] ) {
			$rows = json_decode( $trimmed, true );
			if ( ! is_array( $rows ) ) {
				throw new \RuntimeException( 'The file is not a valid JSON array.' );
			}
			return array_values( array_filter( $rows, 'is_array' ) );
		}

		$rows = array();
		foreach ( preg_split( '/\R/', $contents ) as $number => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) ) {
				throw new \RuntimeException( esc_html( sprintf( 'Line %d is not valid JSON.', $number + 1 ) ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Rows from CSV with a header row.
	 *
	 * @param string $contents Contents.
	 * @return array<int, array<string, string>>
	 */
	private static function csv( string $contents ): array {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );

		$header = fgetcsv( $handle, 0, ',', '"', '' );
		$rows   = array();
		while ( is_array( $header ) && false !== ( $line = fgetcsv( $handle, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $line ) {
				continue;
			}
			$rows[] = array_combine( $header, array_pad( array_slice( $line, 0, count( $header ) ), count( $header ), '' ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}
}
