<?php
/**
 * Schema.org Restaurant properties from our own facts.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Address, coordinates, phone, website, cuisine, price range and opening
 * hours for the Restaurant node (Structured_Data). Third-party ratings are
 * never included: they are fetched live and may not be stored or restated.
 */
final class Business_Data {

	/**
	 * Schema.org day names, keyed like Vocabulary::DAYS.
	 */
	private const DAYS = array(
		'mo' => 'Monday',
		'tu' => 'Tuesday',
		'we' => 'Wednesday',
		'th' => 'Thursday',
		'fr' => 'Friday',
		'sa' => 'Saturday',
		'su' => 'Sunday',
	);

	/**
	 * Properties for a published restaurant.
	 *
	 * @param int $id Restaurant.
	 * @return array<string, mixed>
	 */
	public static function properties( int $id ): array {
		if ( Content_Types::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return array();
		}
		$data = array();

		$village = Restaurant_View::terms( $id, Content_Types::TAX_VILLAGE );
		$address = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => Restaurant_View::address( $id ),
				'addressLocality' => $village ? $village[0]->name : '',
				// ISO 3166-1 code for Guam.
				'addressCountry'  => 'GU',
			)
		);
		if ( isset( $address['streetAddress'] ) || isset( $address['addressLocality'] ) ) {
			$data['address'] = $address;
		}

		$point = Restaurant_View::coordinates( $id );
		if ( null !== $point ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $point['lat'],
				'longitude' => $point['lng'],
			);
		}

		$phone = (string) get_post_meta( $id, Restaurant_Meta::PHONE, true );
		if ( '' !== $phone ) {
			$data['telephone'] = $phone;
		}
		$website = (string) get_post_meta( $id, Restaurant_Meta::WEBSITE, true );
		if ( '' !== $website ) {
			$data['sameAs'] = $website;
		}

		$cuisines = wp_list_pluck( Restaurant_View::terms( $id, Content_Types::TAX_CUISINE ), 'name' );
		if ( $cuisines ) {
			$data['servesCuisine'] = array_values( array_map( 'html_entity_decode', $cuisines ) );
		}
		$price = Restaurant_View::price( $id );
		if ( null !== $price ) {
			$data['priceRange'] = $price->name;
		}

		$hours = self::opening_hours( $id );
		if ( $hours ) {
			$data['openingHoursSpecification'] = $hours;
		}

		if ( has_post_thumbnail( $id ) ) {
			$data['image'] = (string) get_the_post_thumbnail_url( $id, 'large' );
		}
		return $data;
	}

	/**
	 * OpeningHoursSpecification entries; an interval past midnight closes at
	 * its time on the next day, which schema.org reads from closes < opens.
	 *
	 * @param int $id Restaurant.
	 * @return array<int, array<string, mixed>>
	 */
	private static function opening_hours( int $id ): array {
		$hours = get_post_meta( $id, Restaurant_Meta::HOURS, true );
		if ( ! is_array( $hours ) ) {
			return array();
		}
		if ( ! empty( $hours['always_open'] ) ) {
			return array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array_values( self::DAYS ),
					'opens'     => '00:00',
					'closes'    => '23:59',
				),
			);
		}
		$out = array();
		foreach ( (array) ( $hours['weekly'] ?? array() ) as $interval ) {
			if ( ! isset( self::DAYS[ $interval['day'] ?? '' ] ) ) {
				continue;
			}
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => self::DAYS[ $interval['day'] ],
				'opens'     => (string) $interval['open'],
				'closes'    => '24:00' === $interval['close'] ? '23:59' : (string) $interval['close'],
			);
		}
		return $out;
	}
}
