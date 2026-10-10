<?php
/**
 * Generates fictional restaurants for local performance and Lighthouse tests.
 *
 * Usage (local stack only):
 *   make wp ARGS="eval-file /project/bin/generate-sample-restaurants.php 1500"
 *   make wp ARGS="eval-file /project/bin/generate-sample-restaurants.php delete"
 *
 * Every generated post is named "Sample Kitchen NNNN", carries the meta
 * key _gr_sample = 1 and is created only when WP_ENVIRONMENT_TYPE is local
 * or development. Coordinates are random points inside Guam's villages,
 * so the village tag is real but nothing else is. Nothing here is a real
 * business; never run it against a production database.
 *
 * @package GuamRestaurants\Core
 */

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Geo\Village_Locator;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand -- seeded with mt_srand() so the same sample set is generated every time.

if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	WP_CLI::error( 'Sample data is for local or development sites only.' );
}

$guam_arg = $args[0] ?? '240'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP-CLI eval-file passes $args.

if ( 'delete' === $guam_arg ) {
	$guam_ids = get_posts(
		array(
			'post_type'      => Content_Types::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_gr_sample', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	foreach ( $guam_ids as $guam_id ) {
		wp_delete_post( (int) $guam_id, true );
	}
	WP_CLI::success( sprintf( 'Deleted %d sample restaurants.', count( $guam_ids ) ) );
	return;
}

$guam_count = max( 1, (int) $guam_arg );
mt_srand( 671 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- repeatable sample data.

$guam_locator  = Village_Locator::instance();
$guam_cuisines = get_terms(
	array(
		'taxonomy'   => Content_Types::TAX_CUISINE,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
$guam_prices   = get_terms(
	array(
		'taxonomy'   => Content_Types::TAX_PRICE,
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
$guam_malls    = \GuamRestaurants\Core\Mall_Verification::with_unverified(
	static function () {
		return get_terms(
			array(
				'taxonomy'   => Content_Types::TAX_MALL,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
	}
);
$guam_days     = array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' );
$guam_patterns = array(
	array( '11:00', '21:00' ),
	array( '07:00', '15:00' ),
	array( '10:00', '22:00' ),
	array( '17:00', '02:00' ),
	array( '06:00', '14:00' ),
);

$guam_existing = (int) wp_count_posts( Content_Types::POST_TYPE )->publish;
$guam_created  = 0;
$guam_progress = WP_CLI\Utils\make_progress_bar( 'Creating sample restaurants', $guam_count );

wp_defer_term_counting( true );
while ( $guam_created < $guam_count ) {
	$guam_lat  = 13.25 + mt_rand( 0, 40000 ) / 100000;
	$guam_lng  = 144.62 + mt_rand( 0, 34000 ) / 100000;
	$guam_slug = $guam_locator->slug_for( $guam_lat, $guam_lng );
	if ( null === $guam_slug ) {
		continue;
	}
	$guam_number = $guam_existing + $guam_created + 1;
	$guam_id     = wp_insert_post(
		array(
			'post_type'    => Content_Types::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => sprintf( 'Sample Kitchen %04d', $guam_number ),
			'post_content' => '<!-- wp:paragraph --><p>A fictional restaurant generated for local testing.</p><!-- /wp:paragraph -->',
			'post_date'    => gmdate( 'Y-m-d H:i:s', time() - mt_rand( 0, 400 ) * DAY_IN_SECONDS ),
		)
	);
	if ( ! $guam_id || is_wp_error( $guam_id ) ) {
		continue;
	}
	update_post_meta( $guam_id, '_gr_sample', '1' );
	update_post_meta( $guam_id, Restaurant_Meta::LATITUDE, $guam_lat );
	update_post_meta( $guam_id, Restaurant_Meta::LONGITUDE, $guam_lng );
	update_post_meta( $guam_id, Restaurant_Meta::ADDRESS, mt_rand( 100, 999 ) . ' Sample Street' );
	update_post_meta( $guam_id, Restaurant_Meta::PHONE, '+1671' . mt_rand( 2000000, 9999999 ) );
	$guam_roll = mt_rand( 0, 40 );
	update_post_meta( $guam_id, Restaurant_Meta::STATUS, 0 === $guam_roll ? 'temporarily_closed' : 'open' );

	$guam_pattern = $guam_patterns[ mt_rand( 0, count( $guam_patterns ) - 1 ) ];
	$guam_weekly  = array();
	foreach ( $guam_days as $guam_day ) {
		if ( 'mo' === $guam_day && 0 === mt_rand( 0, 2 ) ) {
			continue;
		}
		$guam_weekly[] = array(
			'day'   => $guam_day,
			'open'  => $guam_pattern[0],
			'close' => $guam_pattern[1],
		);
	}
	if ( mt_rand( 0, 9 ) > 0 ) {
		update_post_meta(
			$guam_id,
			Restaurant_Meta::HOURS,
			array(
				'weekly' => $guam_weekly,
				'source' => 'manual',
			)
		);
	}

	wp_set_object_terms( $guam_id, array( $guam_slug ), Content_Types::TAX_VILLAGE );
	$guam_pick = (array) array_rand( array_flip( $guam_cuisines ), mt_rand( 1, 2 ) );
	wp_set_object_terms( $guam_id, array_map( 'intval', $guam_pick ), Content_Types::TAX_CUISINE );
	if ( $guam_prices && mt_rand( 0, 5 ) > 0 ) {
		wp_set_object_terms( $guam_id, array( (int) $guam_prices[ mt_rand( 0, count( $guam_prices ) - 1 ) ] ), Content_Types::TAX_PRICE );
	}
	if ( $guam_malls && 0 === mt_rand( 0, 9 ) ) {
		\GuamRestaurants\Core\Mall_Verification::with_unverified(
			static function () use ( $guam_id, $guam_malls ) {
				wp_set_object_terms( $guam_id, array( (int) $guam_malls[ mt_rand( 0, count( $guam_malls ) - 1 ) ] ), Content_Types::TAX_MALL );
			}
		);
	}
	// About one in twenty is a recent opening; the rest are the starting inventory.
	$guam_roll = mt_rand( 0, 19 );
	Change_Tracker::record_first_seen( $guam_id, 'editor', null, 0 !== $guam_roll );
	++$guam_created;
	$guam_progress->tick();
}
wp_defer_term_counting( false );
$guam_progress->finish();

WP_CLI::success( sprintf( 'Created %d sample restaurants.', $guam_created ) );
