<?php
/**
 * Coverage and gaps report for the awards table.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

defined( 'ABSPATH' ) || exit;

/**
 * Says which years and categories are recorded and what is missing,
 * using our table only.
 *
 * Gaps per program:
 * - years between the first year (the program's known start year, or the
 *   earliest recorded one) and last year with no results at all;
 * - categories recorded in some year but not in another recorded year;
 * - categories in a year with no winner recorded.
 */
final class Report {

	/**
	 * Known first years, from docs/awards-research.md §2.
	 */
	public const START_YEARS = array(
		"Pika's Best of Guam" => 2014,
	);

	/**
	 * Builds the report.
	 *
	 * @param string   $program Only this program; '' for all.
	 * @param int|null $since   First year to check for gaps; defaults to the known start year or the earliest recorded year.
	 * @param int|null $until   Last year to check; defaults to last calendar year, or the latest recorded year if later.
	 * @return array<string, array{years: array<int, array<string, array{winner: int, finalist: int, other: int}>>, results: int, first_year: int, last_year: int, missing_years: int[], missing_categories: array<int, string[]>, no_winner: array<int, string[]>}>
	 */
	public static function build( string $program = '', ?int $since = null, ?int $until = null ): array {
		$coverage = Repository::coverage();
		if ( '' !== $program ) {
			$coverage = array( $program => $coverage[ $program ] ?? array() );
			if ( empty( $coverage[ $program ] ) && ! isset( self::START_YEARS[ $program ] ) && null === $since ) {
				return array();
			}
		}

		if ( '' === $program ) {
			foreach ( array_keys( self::START_YEARS ) as $known ) {
				$coverage += array( $known => array() );
			}
		}

		$report = array();
		foreach ( $coverage as $name => $years ) {
			ksort( $years );
			$recorded = array_keys( $years );
			$first    = $since ?? self::START_YEARS[ $name ] ?? ( $recorded[0] ?? (int) gmdate( 'Y' ) - 1 );
			$last     = $until ?? max( (int) gmdate( 'Y' ) - 1, $recorded ? (int) max( $recorded ) : 0 );

			$missing_years = array();
			for ( $year = $first; $year <= $last; $year++ ) {
				if ( ! isset( $years[ $year ] ) ) {
					$missing_years[] = $year;
				}
			}

			$all_categories = array();
			$results        = 0;
			foreach ( $years as $categories ) {
				foreach ( $categories as $category => $counts ) {
					$all_categories[ $category ] = true;
					$results                    += array_sum( $counts );
				}
			}
			$all_categories = array_keys( $all_categories );
			sort( $all_categories );

			$missing_categories = array();
			$no_winner          = array();
			foreach ( $years as $year => $categories ) {
				$absent = array_values( array_diff( $all_categories, array_keys( $categories ) ) );
				if ( $absent ) {
					$missing_categories[ $year ] = $absent;
				}
				foreach ( $categories as $category => $counts ) {
					if ( 0 === $counts['winner'] ) {
						$no_winner[ $year ][] = $category;
					}
				}
			}

			$report[ $name ] = array(
				'years'              => $years,
				'results'            => $results,
				'first_year'         => $first,
				'last_year'          => $last,
				'missing_years'      => $missing_years,
				'missing_categories' => $missing_categories,
				'no_winner'          => $no_winner,
			);
		}
		return $report;
	}

	/**
	 * The report as Markdown, for docs/progress.md or a reply.
	 *
	 * @param array<string, array<string, mixed>> $report From build().
	 * @return string
	 */
	public static function markdown( array $report ): string {
		if ( empty( $report ) ) {
			return "No award results are recorded yet. Every year and category is a gap.\n";
		}
		$out = '';
		foreach ( $report as $program => $data ) {
			$out .= sprintf( "### %s\n\n", $program );
			$out .= sprintf( "Results recorded: %d. Years checked: %d to %d.\n\n", $data['results'], $data['first_year'], $data['last_year'] );
			if ( empty( $data['years'] ) ) {
				$out .= "No years recorded.\n\n";
			} else {
				$out .= "| Year | Categories | Winners | Finalists | Other |\n|---|---|---|---|---|\n";
				foreach ( $data['years'] as $year => $categories ) {
					$sum  = static function ( string $placement ) use ( $categories ): int {
						return (int) array_sum( array_column( $categories, $placement ) );
					};
					$out .= sprintf( "| %d | %d | %d | %d | %d |\n", $year, count( $categories ), $sum( 'winner' ), $sum( 'finalist' ), $sum( 'other' ) );
				}
				$out .= "\n";
			}
			$out .= sprintf( "Missing years: %s\n", $data['missing_years'] ? self::ranges( $data['missing_years'] ) : 'none' );
			foreach ( $data['missing_categories'] as $year => $categories ) {
				$out .= sprintf( "- %d is missing %d categor%s recorded in other years: %s\n", $year, count( $categories ), 1 === count( $categories ) ? 'y' : 'ies', implode( ', ', $categories ) );
			}
			foreach ( $data['no_winner'] as $year => $categories ) {
				$out .= sprintf( "- %d has no winner recorded for: %s\n", $year, implode( ', ', $categories ) );
			}
			$out .= "\n";
		}
		return $out;
	}

	/**
	 * Collapses consecutive years: 2014, 2015, 2016, 2018 becomes "2014–2016, 2018".
	 *
	 * @param int[] $years Sorted years.
	 * @return string
	 */
	public static function ranges( array $years ): string {
		$parts = array();
		$start = null;
		$prev  = null;
		foreach ( $years as $year ) {
			if ( null === $start ) {
				$start = $year;
			} elseif ( $year !== $prev + 1 ) {
				$parts[] = $start === $prev ? (string) $start : $start . '–' . $prev;
				$start   = $year;
			}
			$prev = $year;
		}
		if ( null !== $start ) {
			$parts[] = $start === $prev ? (string) $start : $start . '–' . $prev;
		}
		return implode( ', ', $parts );
	}
}
