<?php
/**
 * Village fiesta dates, from Guampedia's fiesta calendar.
 *
 * Facts only (date, village, patron saint) with the source, read by hand on
 * 2026-10-27 (docs/data-sources.md §8d). The Catholic Church sets each
 * year's dates, so add the next year's calendar when Guampedia publishes it;
 * Restaurants > Markets and fiestas edits the list on a live site. Feast
 * days with no village fiesta (Easter, Christmas) are left out. `place` is
 * the community the calendar names, when it is part of a larger village.
 *
 * @package GuamRestaurants\Core
 */

defined( 'ABSPATH' ) || exit;

$guam_source = 'https://www.guampedia.com/guam-fiesta-calendar';

return array_map(
	static function ( array $row ) use ( $guam_source ): array {
		return array(
			'date'    => $row[0],
			'village' => $row[1],
			'place'   => $row[2],
			'name'    => $row[3],
			'source'  => $guam_source,
		);
	},
	array(
		array( '2026-01-04', 'barrigada', '', 'Santo Niño (San Vicente Church)' ),
		array( '2026-01-10', 'tamuning', 'Tumon', 'Blessed Diego Luis de San Vitores' ),
		array( '2026-01-17', 'chalan-pago-ordot', 'Chalan Pago', 'Nuestra Señora de la Paz y Buen Viaje' ),
		array( '2026-01-24', 'mongmong-toto-maite', 'Mongmong', 'Nuestra Señora de las Aguas' ),
		array( '2026-01-31', 'asan-maina', 'Maina', 'Our Lady of Purification' ),
		array( '2026-02-14', 'yigo', '', 'Our Lady of Lourdes' ),
		array( '2026-03-19', 'inarajan', '', 'St. Joseph, Husband of Mary' ),
		array( '2026-04-04', 'tamuning', 'Tumon Shrine', 'Martyrdom of Blessed Diego Luis de San Vitores and San Pedro Calungsod' ),
		array( '2026-04-11', 'yigo', '', 'Santa Bernadita' ),
		array( '2026-04-18', 'merizo', '', 'San Dimas and Our Lady of the Rosary' ),
		array( '2026-04-18', 'barrigada', '', 'San Vicente Ferrer' ),
		array( '2026-05-02', 'inarajan', '', 'St. Joseph the Worker' ),
		array( '2026-05-16', 'inarajan', 'Malojloj', 'San Isidro' ),
		array( '2026-05-24', 'santa-rita', '', 'Santa Rita de Casia' ),
		array( '2026-06-12', 'chalan-pago-ordot', 'Chalan Pago', 'Sacred Heart of Jesus' ),
		array( '2026-06-13', 'mongmong-toto-maite', 'Toto', 'Immaculate Heart of Mary' ),
		array( '2026-06-13', 'tamuning', '', 'St. Anthony of Padua' ),
		array( '2026-06-27', 'chalan-pago-ordot', 'Ordot', 'San Juan Bautista' ),
		array( '2026-07-11', 'agat', '', 'Our Lady of Mount Carmel' ),
		array( '2026-07-12', 'mangilao', '', 'St. Louis and St. Zélie Martin' ),
		array( '2026-07-25', 'agat', '', 'Santa Ana' ),
		array( '2026-07-28', 'tamuning', '', 'St. Victor' ),
		array( '2026-08-15', 'piti', '', 'Assumption of the Blessed Virgin Mary' ),
		array( '2026-08-15', 'barrigada', '', 'San Roke' ),
		array( '2026-08-29', 'agat', '', 'Santa Rosa' ),
		array( '2026-09-05', 'barrigada', '', 'San Ramon' ),
		array( '2026-09-12', 'hagatna', '', 'Dulce Nombre de Maria' ),
		array( '2026-09-20', 'dededo', '', 'St. Andrew Kim' ),
		array( '2026-09-26', 'talofofo', '', 'San Miguel' ),
		array( '2026-09-26', 'mangilao', '', 'Santa Teresita' ),
		array( '2026-10-04', 'yona', '', 'St. Francis of Assisi' ),
		array( '2026-10-10', 'umatac', '', 'San Dionisio' ),
		array( '2026-10-18', 'inarajan', 'Malojloj', 'St. John Paul II' ),
		array( '2026-10-28', 'sinajana', '', 'St. Jude' ),
		array( '2026-11-27', 'tamuning', '', 'Our Lady of the Miraculous Medal' ),
		array( '2026-11-28', 'agana-heights', '', 'Our Lady of the Blessed Sacrament' ),
		array( '2026-12-05', 'dededo', '', 'Santa Barbara' ),
		array( '2026-12-08', 'hagatna', '', 'Immaculate Conception (Santa Marian Kamalen)' ),
		array( '2026-12-13', 'santa-rita', 'Sumai', 'Our Lady of Guadalupe' ),
		array( '2026-12-26', 'asan-maina', 'Asan', 'Niño Perdido y Sagrada Familia' ),
	)
);
