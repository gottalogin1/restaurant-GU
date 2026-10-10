<?php
/**
 * A restaurant's opening history, from our change log.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Opened, first listed and reopened dates, newest first. Closures are not
 * listed here: a closed restaurant's status is shown with its details.
 */
final class History {

	/**
	 * Events for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<int, array{type: string, label: string, date: string, sort: string}>
	 */
	public static function events( int $restaurant_id ): array {
		$events = array();

		$opened = (string) get_post_meta( $restaurant_id, Restaurant_Meta::OPENED_ON, true );
		if ( '' !== $opened ) {
			$events[] = array(
				'type'  => 'opened',
				'label' => __( 'Opened', 'guam-restaurants-core' ),
				'date'  => Restaurant_View::partial_date( $opened ),
				'sort'  => str_pad( $opened, 10, '-00' ),
			);
		}

		$first_listed = '';
		foreach ( Queries::changes_for( $restaurant_id ) as $change ) {
			if ( Change_Tracker::FIRST_SEEN === $change['field'] ) {
				// Oldest wins: changes are newest first.
				$first_listed = (string) $change['detected_at'];
			} elseif ( Restaurant_Meta::STATUS === $change['field'] && 'open' === $change['new_value'] && in_array( $change['old_value'], array( 'temporarily_closed', 'permanently_closed' ), true ) ) {
				$events[] = array(
					'type'  => 'reopened',
					'label' => __( 'Reopened', 'guam-restaurants-core' ),
					'date'  => Clock::local_date( (string) $change['detected_at'] ),
					'sort'  => substr( (string) $change['detected_at'], 0, 10 ),
				);
			}
		}
		if ( '' !== $first_listed ) {
			$events[] = array(
				'type'  => 'listed',
				'label' => __( 'First listed here', 'guam-restaurants-core' ),
				'date'  => Clock::local_date( $first_listed ),
				'sort'  => substr( $first_listed, 0, 10 ),
			);
		}

		usort(
			$events,
			static function ( array $a, array $b ): int {
				return strcmp( $b['sort'], $a['sort'] );
			}
		);
		return $events;
	}
}
