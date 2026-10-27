<?php
/**
 * Weekly food markets, as published by the market or a Guam news or visitor
 * site.
 *
 * Facts only (day, hours, place) with the page they came from and the date
 * someone checked it (docs/data-sources.md §8d). Hours a source does not
 * give are left empty. Restaurants > Markets and fiestas edits the list on
 * a live site; check each one every few months.
 *
 * @package GuamRestaurants\Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	array(
		'name'    => 'Chamorro Village Wednesday Night Market',
		'village' => 'hagatna',
		'days'    => array( 'we' ),
		'opens'   => '17:30',
		'closes'  => '21:30',
		'place'   => 'Chamorro Village, West Marine Drive',
		'source'  => 'https://www.visitguam.com/event/chamorro-village:-wednesday-night-market/518361/',
		'checked' => '2026-10-27',
	),
	array(
		'name'    => 'Hågat Tuesday Night Market',
		'village' => 'agat',
		'days'    => array( 'tu' ),
		'opens'   => '',
		'closes'  => '',
		'place'   => 'Agat Market',
		'source'  => "https://guam.stripes.com/food-drink/'buy-local'-at-guam's-agat-tuesday-night-market.html",
		'checked' => '2026-10-27',
	),
	array(
		'name'    => 'Mangilao Night Market',
		'village' => 'mangilao',
		'days'    => array( 'th' ),
		'opens'   => '17:00',
		'closes'  => '',
		'place'   => 'Route 10, next to Santa Teresita Church',
		'source'  => 'https://guam.stripes.com/travel/guam-marketplaces-full-of-life-energy-fun.html',
		'checked' => '2026-10-27',
	),
	array(
		'name'    => 'Dededo Flea Market',
		'village' => 'dededo',
		'days'    => array( 'sa', 'su' ),
		'opens'   => '06:00',
		'closes'  => '11:00',
		'place'   => 'Marine Corps Drive at the Route 1 and Fatima Road intersection',
		'source'  => 'https://guam.stripes.com/travel/guam-marketplaces-full-of-life-energy-fun.html',
		'checked' => '2026-10-27',
	),
);
