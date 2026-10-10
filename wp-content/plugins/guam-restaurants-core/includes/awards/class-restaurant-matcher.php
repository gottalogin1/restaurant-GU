<?php
/**
 * Matches restaurant names in award results to our restaurant posts.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Awards;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Names;
use GuamRestaurants\Core\Ratings\Matcher;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Compares a name against every restaurant that is not in the trash
 * (published, draft or closed alike) using its current title and its
 * former names (`gr_former_names`), so a winner that has since been renamed
 * or closed still matches.
 *
 * Names are normalized with Names::key() ("The Kracked Egg" equals
 * "kracked egg") and scored with the same edit-distance and word-overlap
 * similarity as rating matching. Award pages give no coordinates, so the
 * name alone decides and the bar for an automatic match is higher.
 */
final class Restaurant_Matcher {

	public const MATCHED = 'matched';
	public const REVIEW  = 'review';
	public const NONE    = 'none';

	/**
	 * Score at or above which a clear match is accepted without review.
	 */
	public const AUTO_ACCEPT = 0.9;

	/**
	 * Score at or above which a candidate goes to the review queue; below it a new draft is created.
	 */
	public const REVIEW_THRESHOLD = 0.6;

	/**
	 * How far the best restaurant must lead the next one to count as clear.
	 */
	public const MARGIN = 0.1;

	/**
	 * Restaurant names: list of [restaurant_id, name, key, via].
	 *
	 * @var array<int, array{0: int, 1: string, 2: string, 3: string}>
	 */
	private array $index = array();

	/**
	 * Builds the index from the database.
	 *
	 * @return self
	 */
	public static function from_database(): self {
		$matcher = new self();
		$ids     = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$matcher->add( $id, get_post_field( 'post_title', $id, 'raw' ) );
			foreach ( Restaurant_Meta::former_names( $id ) as $former ) {
				$matcher->add( $id, $former, 'former_name' );
			}
		}
		return $matcher;
	}

	/**
	 * Adds a name for a restaurant.
	 *
	 * @param int    $restaurant_id Restaurant (negative for a dry-run placeholder).
	 * @param string $name          Name.
	 * @param string $via           name or former_name.
	 * @return void
	 */
	public function add( int $restaurant_id, string $name, string $via = 'name' ): void {
		$key = Names::key( $name );
		if ( '' !== $key ) {
			$this->index[] = array( $restaurant_id, $name, $key, $via );
		}
	}

	/**
	 * Finds the restaurant for a name.
	 *
	 * @param string $name Name from the award result.
	 * @return array{status: string, restaurant_id: int|null, confidence: float, matched_name: string, via: string, runner_up_id: int|null, runner_up_confidence: float}
	 */
	public function match( string $name ): array {
		$key  = Names::key( $name );
		$best = array();

		// Best score per restaurant, so a former name and a current name of the same place are not rivals.
		foreach ( $this->index as list( $id, $candidate, $candidate_key, $via ) ) {
			$score = $key === $candidate_key ? 1.0 : Matcher::name_similarity( $key, $candidate_key );
			if ( ! isset( $best[ $id ] ) || $score > $best[ $id ]['score'] ) {
				$best[ $id ] = array(
					'score' => $score,
					'name'  => $candidate,
					'via'   => $via,
				);
			}
		}
		uasort(
			$best,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$ids       = array_keys( $best );
		$top_id    = $ids[0] ?? null;
		$second_id = $ids[1] ?? null;
		$top       = null === $top_id ? null : $best[ $top_id ];
		$second    = null === $second_id ? null : $best[ $second_id ];

		$result = array(
			'status'               => self::NONE,
			'restaurant_id'        => null,
			'confidence'           => null === $top ? 0.0 : round( $top['score'], 3 ),
			'matched_name'         => null === $top ? '' : $top['name'],
			'via'                  => null === $top ? '' : $top['via'],
			'runner_up_id'         => null,
			'runner_up_confidence' => null === $second ? 0.0 : round( $second['score'], 3 ),
		);

		if ( null === $top || $top['score'] < self::REVIEW_THRESHOLD ) {
			return $result;
		}

		$result['restaurant_id'] = (int) $top_id;
		$clear                   = null === $second || $top['score'] - $second['score'] >= self::MARGIN;

		if ( $top['score'] >= self::AUTO_ACCEPT && $clear ) {
			$result['status'] = self::MATCHED;
			return $result;
		}

		$result['status'] = self::REVIEW;
		if ( null !== $second && $second['score'] >= self::REVIEW_THRESHOLD ) {
			$result['runner_up_id'] = (int) $second_id;
		}
		return $result;
	}
}
