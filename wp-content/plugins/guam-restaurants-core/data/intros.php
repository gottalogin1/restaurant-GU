<?php
/**
 * Introductions for village, mall, cuisine and Guam favorite dish pages.
 *
 * Written for the site in our own words and approved by Sanjay on
 * 2026-10-26. Facts come from the sources in docs/data-sources.md
 * ("Page intros"). Site\Intro::fill() copies the village, mall and cuisine
 * intros into each term's Description once, where the Description is
 * still empty, so an intro edited in wp-admin is kept. Dish intros are read
 * from here by Dishes\Dish_Pages. Japanese and Korean are in
 * data/translations/.
 *
 * @package GuamRestaurants\Core
 */

defined( 'ABSPATH' ) || exit;

return array(
	'village' => array(
		'agana-heights'       => 'Agana Heights (Tutuhan) sits on the hills just above Hagåtña. It is home to Government House, the U.S. Naval Hospital and Fort Santa Agueda, a Spanish battery from the 1790s with a wide view over the capital and the Philippine Sea. The village fiesta, for Our Lady of the Blessed Sacrament, is in November.',
		'agat'                => 'Hågat (Agat) is the commercial center of southern Guam, with a small boat marina for fishing charters and a Tuesday night market. Since 2007 the Hågat Mango Festival has brought a mango cooking contest to Nimitz Beach each year. The 1944 landing beaches and two Spanish stone bridges are close by.',
		'asan-maina'          => 'Asan-Maina is on the west coast, where U.S. forces came ashore on 21 July 1944. The Asan Beach unit of the War in the Pacific National Historical Park and the Asan Bay Overlook on Nimitz Hill remember that history. Before the war, families here grew taro, rice and sugar cane on the flats behind the beach.',
		'barrigada'           => 'Barrigada is a landlocked village in the middle of Guam, home to the island\'s main airport and the Guam National Guard. Barrigada Heights looks out from Mount Barrigada over central Guam to the Philippine Sea. The village keeps three feast days, for San Vicente, San Roke and San Ramon.',
		'chalan-pago-ordot'   => 'Chalan Pago-Ordot runs along Route 4 across Guam\'s narrow waist to Pago Bay on the east coast. Chalan Pago means Pago Road, and Ordot comes from otdot, the CHamoru word for ant. The area was known for ranching and corn, and Ordot celebrates San Juan Bautista every June 24.',
		'dededo'              => 'Dededo is Guam\'s most populous village, covering about 30 square miles of the north. On weekend mornings the Dededo Flea Market fills with stalls, and Micronesia Mall, the largest mall in the region, has a supermarket, movie theaters and many places to eat. Santa Barbara\'s feast comes in the first week of December.',
		'hagatna'             => 'Hagåtña is Guam\'s capital and one of its oldest settlements, with the Dulce Nombre de Maria Cathedral-Basilica and the Plaza de España, where the Chocolate House once served drinks and pastries for merienda. On Wednesday nights the Chamorro Village market on Paseo Loop draws crowds for barbecue, local produce and dancing.',
		'inarajan'            => 'Inalåhan (Inarajan) on the southeast coast was listed as a historic district in 1977 for its old houses and St. Joseph Church. At Gef Pa\'go Cultural Village, elders show traditional CHamoru cooking, crafts and arts, and the Salaglula Pools are natural seawater pools at the edge of the village. Malojloj, uphill, is part of the municipality.',
		'mangilao'            => 'Mangilao is known as Guam\'s capital of education, home to the University of Guam and Guam Community College. After World War II it was the island\'s main farming area, growing tapioca, corn, mongo beans, tomatoes and peppers. The east coast holds Pagat Cave and the site of Pågat, an ancient village more than 1,000 years old.',
		'merizo'              => 'Malesso\' (Merizo) faces Cocos Lagoon and Cocos Island on Guam\'s south coast. Its name comes from lesso\', young rabbitfish that run into its bays at certain times of year and are netted as a local delicacy. The 1910 bell tower stands by the old convento, and the San Dimas fiesta is on the third weekend of April.',
		'mongmong-toto-maite' => 'Mongmong-Toto-Maite joins three communities: leafy Mongmong, Toto with its winding roads, and Maite along a cliffline with sunset views. The stretch of Route 8 through Maite and Toto is mostly businesses and small family markets. Mongmong\'s fiesta for Our Lady of the Waters is on the last Saturday of January.',
		'piti'                => 'Piti sits on Apra Harbor, with the commercial port on Cabras Island and Fish Eye Marine Park, Micronesia\'s only underwater observatory. The Piti Bomb Holes preserve, where fishing is banned, draws snorkelers and divers. Rice grew in Piti\'s paddies from the 1830s until after World War II.',
		'santa-rita'          => 'Sånta Rita-Sumai was founded in 1945 for families from Sumai, the prewar village on Orote Peninsula that is now part of Naval Base Guam. Naval Station and Naval Magazine lie within the village, and many homes look over Apra Harbor. St. Rita\'s fiesta is in May, and the fiesta of Our Lady of Guadalupe, Sumai\'s patron, is in December.',
		'sinajana'            => 'Sinajana is a small hilltop village between Agana Springs and Agana Heights. Its name is thought to come from chinåhan, cooking wild yams in earth ovens, and before the war it was known as the island\'s coffee capital. The St. Jude Thaddeus fiesta is on the last weekend of October.',
		'talofofo'            => 'Talo\'fo\'fo, nicknamed God\'s Country, takes its name from a phrase meaning between the cliffs. Talofofo Falls on the Ugum River drops in two tiers to pools popular for swimming, and nearby is the cave where a Japanese soldier hid for 28 years until 1972. Ipan Beach Park has pavilions and barbecue pits.',
		'tamuning'            => 'Tamuning includes Tumon, Harmon and Upper Tumon. Tumon is Guam\'s tourist district: its white sand bay is lined with hotels, and Pleasure Island is full of shops, restaurants and bars. Guam Premier Outlets has a food court, and Two Lovers Point looks over the bay. Long before the hotels, Tumon was a fishing and salt-making area.',
		'umatac'              => 'Humåtak (Umatac) is the bay where, by long tradition, Magellan landed in 1521, and each March residents reenact the landing. Fort Soledad overlooks the bay, and the ruins of San Dionisio Church, first built in 1681, are on the National Register. Once the main port for Spanish galleons, it is now Guam\'s smallest village by population.',
		'yigo'                => 'Yigo is Guam\'s largest and northernmost village, covering 35 square miles, and home to Andersen Air Force Base. Its rich soil once grew cocoa, coffee, avocado and citrus, and it sits over the Northern Aquifer, which supplies most of the island\'s drinking water. The Our Lady of Lourdes fiesta is on the second Saturday of February.',
		'yona'                => 'Yona sits in the hills of southeastern Guam above Ylig Bay. Before the war it was rich farmland known for its coconut plantation. Manenggon Valley, where about 18,000 people were held at the end of World War II, now borders LeoPalace Resort. Tagachang Beach is a known fishing spot, and the St. Francis fiesta is the first weekend of October.',
	),
	'mall'    => array(
		'micronesia-mall'       => 'Micronesia Mall has been open since 1988 at the corner of Routes 1 and 16 in Dededo, and it is the largest shopping mall in the region. Its two floors hold a food court with more than twenty counters, a supermarket, Macy\'s and a 12-screen movie theater.',
		'guam-premier-outlets'  => 'Guam Premier Outlets opened in 1997 on Chalan San Antonio in Tamuning, just off Marine Corps Drive, with about 40 stores. Fast food counters sit among the shops, and restaurants, grocery stores and a movie theater are close around it.',
		'agana-shopping-center' => 'Agana Shopping Center opened in 1978 on Route 4 in Hagåtña, the capital\'s main mall. Restaurants are spread through the building, from international chains to small local counters, next to clothing and everyday stores.',
		'tumon-sands-plaza'     => 'Tumon Sands Plaza opened in October 1972 on Pale San Vitores Road in Tumon and is known for luxury brand boutiques. Since 2018 it has added sit-down restaurants with patio seating along the road, aimed at local families as well as visitors.',
		'the-plaza'             => 'Dusit Place, formerly The Plaza, sits in the middle of Tumon\'s hotel row on Pale San Vitores Road, between the Dusit Thani and Dusit Beach resorts. It has more than 60 boutiques and over 20 cafes, bars and restaurants, and its restaurants and bars stay open later than the shops.',
		'pacific-place'         => 'Pacific Place is a dining stop in Tumon, with a steakhouse, an Italian restaurant and a pizza and izakaya spot among its tenants.',
	),
	'cuisine' => array(
		'chamorro'           => 'CHamoru food is Guam\'s own: red rice colored with achote seeds, kelaguen of chicken, beef or seafood cured in lemon, salt and hot pepper, and barbecue with fina\'denne\' sauce. Much of it grew up around the village fiesta table, where red rice always goes first. These are the places that serve it any day of the week.',
		'filipino'           => 'About a quarter of Guam\'s people are Filipino, and Filipino cooking has shaped island food since Filipino soldiers came under Spanish rule in the 1600s. Pancit and lumpia are fiesta table regulars. This list covers Filipino restaurants, bakeries and take-out counters across the island.',
		'japanese'           => 'Japan is one of Guam\'s two largest visitor markets, and sashimi has a place on the local fiesta table. This list covers sushi bars, ramen shops, izakaya and set-meal restaurants, from Tumon\'s hotel row to the villages.',
		'korean'             => 'Korea is now Guam\'s largest visitor market, and cucumber or daikon kimchee is a regular on the fiesta table. This list covers Korean barbecue, soups and stews, fried chicken and casual cafes.',
		'chinese'            => 'Chinese cooking is one of the influences on Guam\'s fiesta table, and lumpia itself is a version of the Chinese egg roll. This list covers Chinese restaurants, from dim sum and noodle houses to take-out counters.',
		'american'           => 'Burgers, steaks, breakfast plates and diner classics. This list covers American restaurants, diners and burger places; the large drive-through chains are on the Fast food page.',
		'mexican'            => 'Mexico left its mark on CHamoru cooking: the achote seeds that color red rice probably came from Mexico in the Spanish era, and titiyas takes its name from the tortilla. This list covers taquerias, burrito shops and Tex-Mex restaurants.',
		'italian'            => 'Pasta, wood-fired dishes and Italian-American classics. This list covers Italian restaurants and trattorias; places that mostly sell pizza have their own Pizza page.',
		'bbq-and-grill'      => 'Barbecue is the heart of a Guam gathering: chicken, beef, pork and fish over the coals, eaten with fina\'denne\', a sauce of soy and vinegar or lemon with onions and hot peppers. This list covers grills and barbecue places, from local barbecue stands to Korean and American grills.',
		'seafood'            => 'Fish has its own section on the fiesta table, and kelaguen is often made with fish, shrimp, clams or octopus. In Malesso\', young rabbitfish are still netted as a local delicacy. This list covers restaurants where seafood is the main thing on the menu.',
		'steakhouse'         => 'Steak dinners for a celebration or a big night out. This list covers restaurants where steak is the main course, with each one\'s hours, price level and ratings.',
		'pizza'              => 'Pizza by the slice, by the pie or delivered. This list covers pizzerias and pizza chains across the island; use Open now to find one still serving.',
		'bakery-and-dessert' => 'Bakeries, donut shops, ice cream and dessert cafes. On Guam that also means island sweets such as latiya, a custard over sponge cake with cinnamon, and apigigi\', which have dish pages of their own.',
		'coffee-and-cafe'    => 'Coffee shops and cafes for a morning cup, a work table or a light lunch. Before World War II, Sinajana grew enough coffee to be known as the island\'s coffee capital.',
		'fast-food'          => 'Fast food and quick counters, from the big American chains to local take-out windows. Use Open now to find one serving late.',
		'bar-and-pub'        => 'Bars, pubs and lounges, from Pleasure Island in Tumon to neighborhood spots in the villages. Places that serve full meals are listed with their food as well.',
		'local-plate-lunch'  => 'The local plate: a main such as barbecue chicken or short ribs with red or white rice and a side, usually in a take-out box. This list covers the counters and family places that sell plates at lunch time.',
		'thai'               => 'Thai restaurants for curries, noodles, soups and salads. Each listing shows hours, price level and ratings so you can compare them.',
		'vietnamese'         => 'Vietnamese restaurants for pho, banh mi, fresh rolls and rice plates.',
		'asian'              => 'Restaurants that mix several Asian cuisines or fit no single one, along with noodle houses. A place with a clear specialty also appears on that cuisine\'s page.',
	),
	'dish'    => array(
		'kelaguen'   => 'Kelaguen is chopped chicken, beef, shrimp, fish or even Spam, "cooked" in lemon juice with salt, onions and hot peppers, often with fresh grated coconut. Chicken is grilled or boiled first, while beef is cured raw, much like ceviche. It is a signature local dish, served at fiestas and as a starter at many restaurants.',
		'red rice'   => 'Red rice, or hineksa\' aga\'ga\', is short-grain rice cooked in water colored deep orange with achote (annatto) seeds, with onion and garlic and sometimes bacon or peas. It is the first dish placed on a fiesta table and a symbol of CHamoru food.',
		'kadon pika' => 'Kådon pika is a spicy chicken stew. The chicken simmers in soy sauce, vinegar, onion, garlic and hot pepper, then is finished with coconut milk and served warm.',
		'empanada'   => 'The CHamoru empanåda is a fried turnover with a corn masa shell tinted with achote. Inside is a savory filling of shredded chicken thickened with cream of rice and seasoned with onion, garlic and hot pepper.',
		'lumpia'     => 'Lumpia are thin deep-fried rolls of ground pork or beef with cabbage, carrots and onion, a Filipino version of the Chinese egg roll. They sit next to the kelaguen on the fiesta table and are dipped in sweet and sour sauce or fina\'denne\'.',
		'tinaktak'   => 'Tinaktak is finely chopped meat, often ground beef, stewed in coconut milk with tomatoes and green beans. The name echoes the sound of meat being chopped. It is cooked at home and served at parties and restaurants, usually with rice.',
		'titiya'     => 'Titiyas is CHamoru flatbread, named after the Spanish tortilla and made from corn, wheat flour or coconut. It is cooked on a hot pan, cut into triangles and set near the start of the fiesta table, right after the red rice. Some cooks wrap chicken kelaguen in it.',
		'apigigi'    => 'Apigigi\' is a sweet made of grated cassava or tapioca, coconut milk and soft young coconut, wrapped in banana leaves and grilled over hot coals.',
		'latiya'     => 'Latiya is vanilla custard poured over thin slices of sponge cake and dusted with cinnamon. It comes from the Spanish natillas and appears at fiestas, parties, rosaries and novenas, usually on the dessert table.',
		'shaved ice' => 'Shaved ice on Guam is soft, Hawaiian-style ice soaked in flavored syrup, often over a scoop of vanilla ice cream. Stands may offer dozens of syrups to mix, including local flavors such as latiya.',
	),
);
