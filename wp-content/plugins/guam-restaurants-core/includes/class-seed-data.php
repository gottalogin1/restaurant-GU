<?php
/**
 * Seed lists for the taxonomies.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Seed terms (design/content-model.md §4). Names are proper nouns and are not translated.
 */
final class Seed_Data {

	/**
	 * Guam's 19 villages. `geoid` joins to data/guam-villages.geojson; `chamorro`
	 * is the official name the Census uses where it differs from the common name.
	 *
	 * @return array<string, array{name: string, geoid: string, chamorro: string, aliases: string[]}>
	 */
	public static function villages(): array {
		return array(
			'agana-heights'       => array(
				'name'     => 'Agana Heights',
				'geoid'    => '6601004650',
				'chamorro' => '',
				'aliases'  => array(),
			),
			'agat'                => array(
				'name'     => 'Agat',
				'geoid'    => '6601034653',
				'chamorro' => 'Hågat',
				'aliases'  => array( 'Hågat' ),
			),
			'asan-maina'          => array(
				'name'     => 'Asan-Maina',
				'geoid'    => '6601013100',
				'chamorro' => 'Asan',
				'aliases'  => array( 'Asan', 'Maina' ),
			),
			'barrigada'           => array(
				'name'     => 'Barrigada',
				'geoid'    => '6601017650',
				'chamorro' => '',
				'aliases'  => array( 'Barrigada Heights' ),
			),
			'chalan-pago-ordot'   => array(
				'name'     => 'Chalan Pago-Ordot',
				'geoid'    => '6601026100',
				'chamorro' => '',
				'aliases'  => array( 'Chalan Pago', 'Ordot' ),
			),
			'dededo'              => array(
				'name'     => 'Dededo',
				'geoid'    => '6601028050',
				'chamorro' => '',
				'aliases'  => array( 'Liguan', 'Finegayan' ),
			),
			'hagatna'             => array(
				'name'     => 'Hagåtña',
				'geoid'    => '6601034800',
				'chamorro' => '',
				'aliases'  => array( 'Agana', 'Hagatna' ),
			),
			'inarajan'            => array(
				'name'     => 'Inarajan',
				'geoid'    => '6601035503',
				'chamorro' => 'Inalåhan',
				'aliases'  => array( 'Inalåhan' ),
			),
			'mangilao'            => array(
				'name'     => 'Mangilao',
				'geoid'    => '6601046250',
				'chamorro' => '',
				'aliases'  => array(),
			),
			'merizo'              => array(
				'name'     => 'Merizo',
				'geoid'    => '6601044403',
				'chamorro' => "Malesso'",
				'aliases'  => array( "Malesso'" ),
			),
			'mongmong-toto-maite' => array(
				'name'     => 'Mongmong-Toto-Maite',
				'geoid'    => '6601051450',
				'chamorro' => '',
				'aliases'  => array( 'Mongmong', 'Toto', 'Maite' ),
			),
			'piti'                => array(
				'name'     => 'Piti',
				'geoid'    => '6601059250',
				'chamorro' => '',
				'aliases'  => array(),
			),
			'santa-rita'          => array(
				'name'     => 'Santa Rita',
				'geoid'    => '6601062828',
				'chamorro' => 'Sånta Rita-Sumai',
				'aliases'  => array( 'Sumay', 'Sånta Rita-Sumai' ),
			),
			'sinajana'            => array(
				'name'     => 'Sinajana',
				'geoid'    => '6601065750',
				'chamorro' => '',
				'aliases'  => array(),
			),
			'talofofo'            => array(
				'name'     => 'Talofofo',
				'geoid'    => '6601069650',
				'chamorro' => "Talo'fo'fo",
				'aliases'  => array( "Talo'fo'fo" ),
			),
			'tamuning'            => array(
				'name'     => 'Tamuning',
				'geoid'    => '6601071600',
				'chamorro' => '',
				'aliases'  => array( 'Tumon', 'Upper Tumon', 'Harmon' ),
			),
			'umatac'              => array(
				'name'     => 'Umatac',
				'geoid'    => '6601035528',
				'chamorro' => 'Humåtak',
				'aliases'  => array( 'Humåtak' ),
			),
			'yigo'                => array(
				'name'     => 'Yigo',
				'geoid'    => '6601083300',
				'chamorro' => '',
				'aliases'  => array(),
			),
			'yona'                => array(
				'name'     => 'Yona',
				'geoid'    => '6601084600',
				'chamorro' => '',
				'aliases'  => array(),
			),
		);
	}

	/**
	 * Malls awaiting the owner's confirmation. All are seeded unverified, and
	 * the village is a guess to be checked during verification.
	 *
	 * @return array<string, array{name: string, village: string, aliases: string[]}>
	 */
	public static function malls(): array {
		return array(
			'micronesia-mall'       => array(
				'name'    => 'Micronesia Mall',
				'village' => 'dededo',
				'aliases' => array(),
			),
			'guam-premier-outlets'  => array(
				'name'    => 'Guam Premier Outlets',
				'village' => 'tamuning',
				'aliases' => array( 'GPO' ),
			),
			'agana-shopping-center' => array(
				'name'    => 'Agana Shopping Center',
				'village' => 'hagatna',
				'aliases' => array( 'ASC' ),
			),
			'tumon-sands-plaza'     => array(
				'name'    => 'Tumon Sands Plaza',
				'village' => 'tamuning',
				'aliases' => array(),
			),
			'the-plaza'             => array(
				'name'    => 'The Plaza',
				'village' => 'tamuning',
				'aliases' => array( 'The Plaza Shopping Center' ),
			),
			'pacific-place'         => array(
				'name'    => 'Pacific Place',
				'village' => 'tamuning',
				'aliases' => array(),
			),
		);
	}

	/**
	 * Cuisines, all top level for now. `osm` lists OpenStreetMap `cuisine=`
	 * values (or `amenity=`/`shop=` tags) the importer maps to the term.
	 *
	 * @return array<string, array{name: string, osm: string[]}>
	 */
	public static function cuisines(): array {
		return array(
			'chamorro'           => array(
				'name' => 'Chamorro',
				'osm'  => array( 'chamorro' ),
			),
			'filipino'           => array(
				'name' => 'Filipino',
				'osm'  => array( 'filipino' ),
			),
			'japanese'           => array(
				'name' => 'Japanese',
				'osm'  => array( 'japanese', 'sushi', 'ramen' ),
			),
			'korean'             => array(
				'name' => 'Korean',
				'osm'  => array( 'korean' ),
			),
			'chinese'            => array(
				'name' => 'Chinese',
				'osm'  => array( 'chinese' ),
			),
			'american'           => array(
				'name' => 'American',
				'osm'  => array( 'american', 'burger' ),
			),
			'mexican'            => array(
				'name' => 'Mexican',
				'osm'  => array( 'mexican', 'tex-mex' ),
			),
			'italian'            => array(
				'name' => 'Italian',
				'osm'  => array( 'italian' ),
			),
			'bbq-and-grill'      => array(
				'name' => 'BBQ and grill',
				'osm'  => array( 'barbecue', 'grill' ),
			),
			'seafood'            => array(
				'name' => 'Seafood',
				'osm'  => array( 'seafood', 'fish' ),
			),
			'steakhouse'         => array(
				'name' => 'Steakhouse',
				'osm'  => array( 'steak_house' ),
			),
			'pizza'              => array(
				'name' => 'Pizza',
				'osm'  => array( 'pizza' ),
			),
			'bakery-and-dessert' => array(
				'name' => 'Bakery and dessert',
				'osm'  => array( 'dessert', 'donut', 'amenity=ice_cream', 'shop=bakery' ),
			),
			'coffee-and-cafe'    => array(
				'name' => 'Coffee and cafe',
				'osm'  => array( 'coffee_shop', 'amenity=cafe' ),
			),
			'fast-food'          => array(
				'name' => 'Fast food',
				'osm'  => array( 'amenity=fast_food' ),
			),
			'bar-and-pub'        => array(
				'name' => 'Bar and pub',
				'osm'  => array( 'amenity=bar', 'amenity=pub' ),
			),
			'local-plate-lunch'  => array(
				'name' => 'Local plate lunch',
				'osm'  => array(),
			),
			'thai'               => array(
				'name' => 'Thai',
				'osm'  => array( 'thai' ),
			),
			'vietnamese'         => array(
				'name' => 'Vietnamese',
				'osm'  => array( 'vietnamese' ),
			),
			'asian'              => array(
				'name' => 'Asian',
				'osm'  => array( 'asian', 'noodles' ),
			),
		);
	}

	/**
	 * Price levels, per person.
	 *
	 * @return array<string, array{name: string, rank: int, description: string}>
	 */
	public static function price_levels(): array {
		return array(
			'p1' => array(
				'name'        => '$',
				'rank'        => 1,
				'description' => 'Under $15 per person',
			),
			'p2' => array(
				'name'        => '$$',
				'rank'        => 2,
				'description' => '$15 to $30 per person',
			),
			'p3' => array(
				'name'        => '$$$',
				'rank'        => 3,
				'description' => '$30 to $60 per person',
			),
			'p4' => array(
				'name'        => '$$$$',
				'rank'        => 4,
				'description' => 'Over $60 per person',
			),
		);
	}
}
