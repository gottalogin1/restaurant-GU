<?php
/**
 * The CHamoru food glossary (/dishes/glossary/).
 *
 * Written for the site in our own words (CLAUDE.md rule 3), A to Z. Each
 * entry's facts come from its source page, listed in docs/data-sources.md
 * ("Food glossary"). Entries name no restaurants and give no counts.
 * Japanese and Korean are in data/translations/; the terms themselves stay
 * in CHamoru.
 *
 * Keys are the entry's anchor on the page. `names` translates the few
 * English terms; CHamoru terms stay as written in every language. `dishes` are dish names, tried in
 * order: the entry links to the first one with a dish page (Dish_Index), and
 * a dish page whose dish matches one of them links back to the entry.
 *
 * @package GuamRestaurants\Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'achote'            => array(
		'term'   => 'Achote',
		'dishes' => array(),
		'text'   => 'The local name for annatto, the red seeds of the achiote shrub. Soaked in water, they give red rice, empanåda shells and tamales gisu their orange color.',
		'source' => array( 'University of Guam Extension', 'https://www.uog.edu/_resources/files/extension/publications/Achiote.pdf' ),
	),
	'aggon'             => array(
		'term'   => 'Åggon',
		'dishes' => array(),
		'text'   => 'The starch part of a CHamoru meal, such as red rice, titiyas or tamales, eaten alongside the meat and fish dishes. It leads the fiesta table.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/chamorro-food/fiesta-table-overview/' ),
	),
	'apigigi'           => array(
		'term'   => 'Apigigi\'',
		'dishes' => array( 'Apigigi' ),
		'text'   => 'A sweet of grated cassava or tapioca mixed with coconut milk and soft young coconut, wrapped in banana leaves and grilled over hot coals.',
		'source' => array( 'Annie\'s Chamorro Kitchen', 'https://www.annieschamorrokitchen.com/apigigi/' ),
	),
	'bunelos-aga'       => array(
		'term'   => 'Buñelos aga\'',
		'dishes' => array( 'Buñelos aga', 'Buñelos' ),
		'text'   => 'Banana doughnuts. Ripe bananas are mashed into a soft batter with flour and sugar, dropped by the spoonful into hot oil and fried golden.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/bonelos-aga-recipe/' ),
	),
	'bunelos-dagu'      => array(
		'term'   => 'Buñelos dågu',
		'dishes' => array( 'Buñelos dågu', 'Buñelos' ),
		'text'   => 'Yam doughnuts. Dågu means yam: grated yam is mixed with flour, young coconut and sugar, rolled into balls and fried.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/bonelos-dago-recipe/' ),
	),
	'chalakiles'        => array(
		'term'   => 'Chalakiles',
		'dishes' => array( 'Chalakiles' ),
		'text'   => 'A thick chicken soup colored orange with achote and thickened with ground toasted rice, often finished with coconut milk. Eaten as a soup or spooned over white rice.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/chalakiles-recipe/' ),
	),
	'donne'             => array(
		'term'   => 'Donne\'',
		'dishes' => array(),
		'text'   => 'The CHamoru word for hot pepper. It goes into fina\'denne\', kelaguen and kådon pika, and Mangilao holds a Donne\' Festival every September.',
		'source' => array( 'Visit Guam', 'https://www.visitguam.com/events/festivals/donne-festival/' ),
	),
	'empanada'          => array(
		'term'   => 'Empanåda',
		'dishes' => array( 'Empanada' ),
		'text'   => 'A fried turnover with a crisp corn masa shell tinted orange with achote, filled with shredded chicken thickened with cream of rice.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/empanada-recipe/' ),
	),
	'estufao'           => array(
		'term'   => 'Estufao',
		'dishes' => array( 'Estufao', 'Estufado' ),
		'text'   => 'Pork simmered slowly in vinegar, soy sauce, garlic and bay leaf until tender, then browned, with the cooking liquid turned into gravy.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/estufao-recipe/' ),
	),
	'fiesta-table'      => array(
		'term'   => 'Fiesta table',
		'names'  => array(
			'ja' => 'フィエスタの食卓（Fiesta table）',
			'ko' => '피에스타 상차림(Fiesta table)',
		),
		'dishes' => array(),
		'text'   => 'Each village fiesta honors its parish\'s patron saint with a long table of food, laid out in order: starches, meats, fish, kelaguen, vegetables and soups, then desserts. Red rice always comes first.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/chamorro-food/fiesta-table-overview/' ),
	),
	'finadenne'         => array(
		'term'   => 'Fina\'denne\'',
		'dishes' => array( 'Finadene', 'Finadenne' ),
		'text'   => 'The everyday dipping sauce of soy sauce, vinegar or lemon, onion and hot pepper. The soy version goes with meat and chicken; a lighter one without soy goes with fish. Often spelled finadene.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/finadenne-dipping-sauce/' ),
	),
	'gollai-appan'      => array(
		'term'   => 'Gollai åppan',
		'dishes' => array( 'Gollai appan', 'Golai appan' ),
		'text'   => 'Starchy foods such as breadfruit, taro, yam or banana cooked in coconut cream until the cream thickens. Åppan means to dry up. Sweet banana versions are served as dessert.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/gollai-appan-starchy-vegetables-in-reduced-coconut-cream/' ),
	),
	'gollai-hagon-suni' => array(
		'term'   => 'Gollai hågon suni',
		'dishes' => array( 'Gollai hagon suni', 'Golai hagon suni' ),
		'text'   => 'Taro leaves cooked with onion and garlic and finished with coconut cream, a green side dish on most fiesta tables. Spinach is a common stand-in.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/gollai-hagon-suni-taro-leaves-in-coconut-cream/' ),
	),
	'guyuria'           => array(
		'term'   => 'Guyuria',
		'dishes' => array( 'Guyuria' ),
		'text'   => 'Small crunchy cookies of flour, coconut milk and sugar, rolled over a ridged board or a fork, fried and coated in a sugar glaze.',
		'source' => array( 'Wikipedia', 'https://en.wikipedia.org/wiki/Guyuria' ),
	),
	'kadon-pika'        => array(
		'term'   => 'Kådon pika',
		'dishes' => array( 'Kadon pika' ),
		'text'   => 'Spicy chicken stew. Chicken simmers in soy sauce, vinegar, onion, garlic and hot pepper and is finished with coconut milk.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/mannok-kadon-pika-recipe/' ),
	),
	'kalamai'           => array(
		'term'   => 'Kalamai',
		'dishes' => array( 'Kalamai' ),
		'text'   => 'A pudding of corn masa, coconut milk and sugar, set in a tray, dusted with cinnamon and cut into squares.',
		'source' => array( 'Wikipedia', 'https://en.wikipedia.org/wiki/Kalamai_(dessert)' ),
	),
	'kelaguen'          => array(
		'term'   => 'Kelaguen',
		'dishes' => array( 'Kelaguen' ),
		'text'   => 'Chopped chicken, beef, shrimp or fish mixed with lemon juice, salt, onion and hot pepper, often with grated coconut. Chicken is grilled first; the lemon "cooks" raw beef and seafood.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/kelaguen-meat-chicken-or-seafood-with-lemon/' ),
	),
	'latiya'            => array(
		'term'   => 'Latiya',
		'dishes' => array( 'Latiya' ),
		'text'   => 'Vanilla custard poured over sliced sponge cake and dusted with cinnamon, a fiesta dessert that comes from the Spanish natillas.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/latiya-vanilla-custard-spongecake/' ),
	),
	'lemmai'            => array(
		'term'   => 'Lemmai',
		'dishes' => array(),
		'text'   => 'Breadfruit. It is boiled, baked or fried, cooked in coconut cream as gollai åppan lemmai, or sliced and dried so it keeps.',
		'source' => array( 'National Park Service', 'https://home.nps.gov/wapa/learn/nature/breadfruit.htm' ),
	),
	'lumpia'            => array(
		'term'   => 'Lumpia',
		'dishes' => array( 'Lumpia' ),
		'text'   => 'Thin, crisp fried rolls of ground meat and vegetables, Filipino in origin and a regular at Guam parties. Dipped in sweet and sour sauce or fina\'denne\'.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/lumpia-filipino-eggrolls/' ),
	),
	'red-rice'          => array(
		'term'   => 'Red rice (hineksa\' aga\'ga\')',
		'names'  => array(
			'ja' => '赤米（hineksa\' aga\'ga\'）',
			'ko' => '붉은 밥(hineksa\' aga\'ga\')',
		),
		'dishes' => array( 'Red rice' ),
		'text'   => 'Rice cooked in achote water, which turns it orange, usually with onion and garlic. It is the first dish on a fiesta table.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/hineksa-agaga-red-rice/' ),
	),
	'shaved-ice'        => array(
		'term'   => 'Shaved ice',
		'names'  => array(
			'ja' => 'かき氷（Shaved ice）',
			'ko' => '빙수(Shaved ice)',
		),
		'dishes' => array( 'Shaved ice' ),
		'text'   => 'Soft, finely shaved ice soaked in flavored syrups, often over a scoop of ice cream, Hawaiian style. Some stands add island flavors such as latiya.',
		'source' => array( 'Stars and Stripes Guam', 'https://guam.stripes.com/food-drink/taste-of-guam-hafaloha-shaved-ice-mixes-chamorro,-hawaiian-cultures.html' ),
	),
	'tamales-gisu'      => array(
		'term'   => 'Tamales gisu',
		'dishes' => array( 'Tamales gisu', 'Tamales' ),
		'text'   => 'Steamed tamales made in two halves: one orange with achote and bits of bacon or chicken, one plain white. Wrapped in banana leaf or foil and set with the fiesta starches.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/tamales-gisu-red-and-white-tamale/' ),
	),
	'tinaktak'          => array(
		'term'   => 'Tinaktak',
		'dishes' => array( 'Tinaktak' ),
		'text'   => 'Finely chopped meat, usually beef, stewed in coconut milk with tomatoes and green beans. The name echoes the sound of the chopping knife.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/chicken-tinaktak-recipe/' ),
	),
	'titiyas'           => array(
		'term'   => 'Titiyas',
		'dishes' => array( 'Titiyas' ),
		'text'   => 'CHamoru flatbread made from flour, corn or coconut and cooked on a hot pan. Usually cut into triangles; some cooks roll kelaguen inside.',
		'source' => array( 'Guampedia', 'https://www.guampedia.com/titiyas-flatbread/' ),
	),
);
