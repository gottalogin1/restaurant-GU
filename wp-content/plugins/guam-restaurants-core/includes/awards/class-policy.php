<?php
/**
 * Wording rules for award results.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

defined( 'ABSPATH' ) || exit;

/**
 * What the site may say about an award, in one place.
 *
 * We record results only: program, year, category, placement, restaurant
 * and the URL of the page that gave the result. The program's logos,
 * badges, graphics, descriptions, photos and write-ups are never used, no
 * badge or embed is offered to restaurants, and awards are never part of an
 * ad package, featured listing or sponsor offer (CLAUDE.md rules 3 and 5).
 * The program's name appears only inside a citation such as
 * "Pika's Best of Guam 2019, Best Burger: Winner".
 */
final class Policy {

	/**
	 * Publisher of Pika's Best of Guam (docs/awards-research.md §1).
	 */
	public const PUBLISHER_NAME = 'Pacific Daily News';

	/**
	 * Publishers named in the notice. Add one here (or with the filter) when
	 * results from another program are recorded.
	 *
	 * @return string[]
	 */
	public static function publishers(): array {
		/**
		 * Filters the publishers named in the "not affiliated" notice.
		 *
		 * @param string[] $publishers Publisher names.
		 */
		$publishers = (array) apply_filters( 'guam_restaurants_award_publishers', array( self::PUBLISHER_NAME ) );
		return array_values( array_filter( array_map( 'strval', $publishers ) ) );
	}

	/**
	 * The notice shown on every page that lists award results.
	 *
	 * @return string Plain text.
	 */
	public static function notice(): string {
		return sprintf(
			/* translators: %s: publisher name or names, for example "Pacific Daily News". */
			__( 'This site is not affiliated with %s. Results are listed with links to the original publication.', 'guam-restaurants-core' ),
			wp_sprintf_l( '%l', self::publishers() )
		);
	}

	/**
	 * Display label for a placement.
	 *
	 * @param string $placement winner, finalist or other.
	 * @return string
	 */
	public static function placement_label( string $placement ): string {
		switch ( $placement ) {
			case 'winner':
				return __( 'Winner', 'guam-restaurants-core' );
			case 'finalist':
				return __( 'Finalist', 'guam-restaurants-core' );
			default:
				return __( 'Other placing', 'guam-restaurants-core' );
		}
	}

	/**
	 * Factual citation, for example "Pika's Best of Guam 2019, Best Burger: Winner".
	 *
	 * @param array{program: string, year: int|string, category: string, placement: string} $award Award row.
	 * @return string Plain text.
	 */
	public static function citation( array $award ): string {
		$label = self::placement_label( (string) $award['placement'] );
		if ( '' === (string) $award['category'] ) {
			/* translators: 1: program name, 2: year, 3: placement. */
			return sprintf( __( '%1$s %2$s: %3$s', 'guam-restaurants-core' ), $award['program'], $award['year'], $label );
		}
		/* translators: 1: program name, 2: year, 3: category, 4: placement. */
		return sprintf( __( '%1$s %2$s, %3$s: %4$s', 'guam-restaurants-core' ), $award['program'], $award['year'], $award['category'], $label );
	}

	/**
	 * Link text for a source: our own note when there is one, otherwise the site's host name.
	 *
	 * @param array{source_url: string, source_note?: string|null} $award Award row.
	 * @return string Plain text.
	 */
	public static function source_label( array $award ): string {
		$note = trim( (string) ( $award['source_note'] ?? '' ) );
		if ( '' !== $note ) {
			return $note;
		}
		$host = (string) wp_parse_url( (string) $award['source_url'], PHP_URL_HOST );
		return '' === $host ? __( 'Source', 'guam-restaurants-core' ) : (string) preg_replace( '/^www\./', '', $host );
	}
}
