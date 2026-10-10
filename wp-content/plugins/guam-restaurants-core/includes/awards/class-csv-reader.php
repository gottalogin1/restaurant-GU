<?php
/**
 * Reads and validates an awards CSV file.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

defined( 'ABSPATH' ) || exit;

/**
 * Parses data/awards-template.csv-shaped files into validated rows.
 *
 * Each row holds facts only: program, year, category, placement,
 * restaurant name, the URL of the page that gave the result, and an
 * optional note in our own words about where it was found. A row without a
 * valid http(s) source_url is rejected.
 */
final class Csv_Reader {

	public const COLUMNS = array( 'program', 'year', 'category', 'placement', 'restaurant_name', 'source_url', 'source_note' );

	public const REQUIRED = array( 'program', 'year', 'category', 'placement', 'restaurant_name', 'source_url' );

	public const FIRST_YEAR = 1950;

	public const NOTE_MAX = 500;

	/**
	 * Spellings accepted for each placement. Second and third place count as
	 * finalists, which is how Pika's Best of Guam names them.
	 */
	public const PLACEMENT_ALIASES = array(
		'winner'              => 'winner',
		'won'                 => 'winner',
		'1st'                 => 'winner',
		'first'               => 'winner',
		'finalist'            => 'finalist',
		'runner up'           => 'finalist',
		'runner-up'           => 'finalist',
		'2nd'                 => 'finalist',
		'3rd'                 => 'finalist',
		'second'              => 'finalist',
		'third'               => 'finalist',
		'other'               => 'other',
		'nominee'             => 'other',
		'top 5'               => 'other',
		'honorable mention'   => 'other',
		'honourable mention'  => 'other',
		'honorable-mention'   => 'other',
		'special recognition' => 'other',
	);

	/**
	 * Reads a file.
	 *
	 * @param string $path CSV file.
	 * @return array{rows: array<int, array<string, mixed>>, rejected: array<int, array{line: int, reason: string, restaurant_name: string}>, ignored_columns: string[]}
	 * @throws \RuntimeException When the file cannot be read or lacks a required column.
	 */
	public static function read_file( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Cannot read %s.', $path ) ) );
		}
		$file = new \SplFileObject( $path, 'r' );
		$file->setFlags( \SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::READ_AHEAD );
		$file->setCsvControl( ',', '"', '' );

		$lines = array();
		foreach ( $file as $fields ) {
			if ( is_array( $fields ) ) {
				$lines[] = $fields;
			}
		}
		return self::parse( $lines );
	}

	/**
	 * Validates parsed CSV lines; the first line is the header.
	 *
	 * @param array<int, array<int, string|null>> $lines Lines as field lists.
	 * @return array{rows: array<int, array<string, mixed>>, rejected: array<int, array{line: int, reason: string, restaurant_name: string}>, ignored_columns: string[]}
	 * @throws \RuntimeException When a required column is missing.
	 */
	public static function parse( array $lines ): array {
		$result = array(
			'rows'            => array(),
			'rejected'        => array(),
			'ignored_columns' => array(),
		);
		if ( empty( $lines ) ) {
			throw new \RuntimeException( esc_html( 'The file is empty; it needs a header line: ' . implode( ',', self::COLUMNS ) ) );
		}

		$header = array();
		foreach ( array_values( (array) array_shift( $lines ) ) as $i => $name ) {
			$name = strtolower( trim( (string) $name ) );
			if ( 0 === $i ) {
				$name = (string) preg_replace( '/^\x{FEFF}/u', '', $name );
			}
			$header[ $i ] = $name;
		}
		$missing = array_diff( self::REQUIRED, $header );
		if ( $missing ) {
			throw new \RuntimeException( esc_html( 'Missing column(s): ' . implode( ', ', $missing ) . '. Expected: ' . implode( ',', self::COLUMNS ) ) );
		}
		$result['ignored_columns'] = array_values( array_filter( array_diff( $header, self::COLUMNS ) ) );

		foreach ( $lines as $index => $fields ) {
			$line   = $index + 2;
			$values = array_fill_keys( self::COLUMNS, '' );
			foreach ( $header as $i => $name ) {
				if ( isset( $values[ $name ] ) ) {
					$values[ $name ] = trim( (string) ( $fields[ $i ] ?? '' ) );
				}
			}
			if ( '' === implode( '', $values ) ) {
				continue;
			}

			$checked = self::validate( $values );
			if ( is_string( $checked ) ) {
				$result['rejected'][] = array(
					'line'            => $line,
					'reason'          => $checked,
					'restaurant_name' => sanitize_text_field( $values['restaurant_name'] ),
				);
				continue;
			}
			$result['rows'][] = $checked + array( 'line' => $line );
		}
		return $result;
	}

	/**
	 * Validates one row.
	 *
	 * @param array<string, string> $values Raw values keyed by column.
	 * @return array{program: string, year: int, category: string, placement: string, restaurant_name: string, source_url: string, source_note: string}|string Clean row, or the rejection reason.
	 */
	public static function validate( array $values ): array|string {
		$source_url = trim( (string) ( $values['source_url'] ?? '' ) );
		if ( '' === $source_url ) {
			return 'missing source_url';
		}
		if ( ! self::is_http_url( $source_url ) ) {
			return 'source_url is not an http(s) URL';
		}

		$program  = self::clean_label( (string) ( $values['program'] ?? '' ) );
		$category = self::clean_label( (string) ( $values['category'] ?? '' ) );
		$name     = mb_substr( sanitize_text_field( (string) ( $values['restaurant_name'] ?? '' ) ), 0, 200 );
		$year     = trim( (string) ( $values['year'] ?? '' ) );
		$max_year = (int) gmdate( 'Y' ) + 1;

		if ( '' === $program ) {
			return 'missing program';
		}
		if ( '' === $category ) {
			return 'missing category';
		}
		if ( '' === $name ) {
			return 'missing restaurant_name';
		}
		if ( 1 !== preg_match( '/^\d{4}$/', $year ) || (int) $year < self::FIRST_YEAR || (int) $year > $max_year ) {
			return sprintf( 'year must be a four-digit year from %d to %d', self::FIRST_YEAR, $max_year );
		}
		$placement = self::placement( (string) ( $values['placement'] ?? '' ) );
		if ( null === $placement ) {
			return 'placement must be winner, finalist or other';
		}

		return array(
			'program'         => $program,
			'year'            => (int) $year,
			'category'        => $category,
			'placement'       => $placement,
			'restaurant_name' => $name,
			'source_url'      => esc_url_raw( $source_url, array( 'http', 'https' ) ),
			'source_note'     => mb_substr( sanitize_text_field( (string) ( $values['source_note'] ?? '' ) ), 0, self::NOTE_MAX ),
		);
	}

	/**
	 * Normalized placement, or null when not recognized.
	 *
	 * @param string $value Raw placement.
	 * @return string|null
	 */
	public static function placement( string $value ): ?string {
		$value = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $value ) ) );
		return self::PLACEMENT_ALIASES[ $value ] ?? null;
	}

	/**
	 * Program and category names: plain text, single spaces, straight apostrophes,
	 * so "Pika’s  Best" and "Pika's Best" are the same program.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_label( string $value ): string {
		$value = str_replace( array( '’', '‘', '`' ), "'", $value );
		$value = sanitize_text_field( $value );
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', $value ) ), 0, 191 );
	}

	/**
	 * Whether a value is an absolute http or https URL with a host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_http_url( string $url ): bool {
		if ( preg_match( '/\s/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& ! empty( $parts['host'] )
			&& str_contains( (string) $parts['host'], '.' );
	}
}
