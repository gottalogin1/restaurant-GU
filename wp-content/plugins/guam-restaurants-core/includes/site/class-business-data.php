<?php
/**
 * Schema.org Restaurant properties from our own facts.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Site;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Term_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Address (with the ZIP code when the address has one), coordinates,
 * phone, website, cuisine, price range, opening hours and the owner's menu
 * link for the Restaurant node (Structured_Data). Third-party ratings are
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
				'postalCode'      => self::postal_code( Restaurant_View::address( $id ) ),
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

		// Menu links come from the verified owner, so they count only while the listing is claimed (as on the page).
		if ( (int) get_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) > 0 ) {
			$menus = Owner_Content::get( $id )['menu_links'];
			if ( $menus && ! empty( $menus[0]['url'] ) ) {
				$data['hasMenu'] = (string) $menus[0]['url'];
			}
		}

		if ( has_post_thumbnail( $id ) ) {
			$data['image'] = (string) get_the_post_thumbnail_url( $id, 'large' );
		}
		return $data;
	}

	/**
	 * Guam ZIP code written in an address ("..., Tamuning, GU 96913"), or ''.
	 * Guam's ZIP codes all start with 969.
	 *
	 * @param string $address Address.
	 * @return string
	 */
	public static function postal_code( string $address ): string {
		return preg_match( '/\b(969\d{2})(?:-\d{4})?\b/', $address, $match ) ? $match[1] : '';
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
