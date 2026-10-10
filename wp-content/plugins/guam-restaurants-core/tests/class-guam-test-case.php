<?php
/**
 * Shared helpers for the tagging and monitoring tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Osm\Cuisine_Map;
use GuamRestaurants\Core\Osm\Element_Mapper;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seeder;
use GuamRestaurants\Core\Tagging\Term_Slugs;

/**
 * Seeds the taxonomies, imports Guam_Fixtures, and captures email.
 */
abstract class Guam_Test_Case extends Test_Case {

	/**
	 * Emails sent during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	protected array $mails = array();

	/**
	 * Seeds terms and captures wp_mail().
	 */
	public function set_up() {
		parent::set_up();
		Seeder::seed_all();
		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	/**
	 * Records an email instead of sending it.
	 *
	 * @param null|bool            $short_circuit Unused.
	 * @param array<string, mixed> $atts          Mail arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, array $atts ): bool {
		$this->mails[] = $atts;
		return true;
	}

	/**
	 * Runs the OSM importer.
	 *
	 * @param array<int, array<string, mixed>>|null $elements Elements; defaults to Guam_Fixtures::elements().
	 * @return array<string, mixed>
	 */
	protected function import( ?array $elements = null ): array {
		return ( new Importer( new Element_Mapper( Cuisine_Map::from_file() ) ) )->run( $elements ?? Guam_Fixtures::elements() );
	}

	/**
	 * Post for an OSM node id.
	 *
	 * @param int $node Node id.
	 * @return int
	 */
	protected function post_for( int $node ): int {
		return Importer::existing_ids()[ 'node/' . $node ] ?? 0;
	}

	/**
	 * Term slugs on a post.
	 *
	 * @param int    $post_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @return string[]
	 */
	protected function terms( int $post_id, string $taxonomy ): array {
		return Term_Slugs::for_post( $post_id, $taxonomy );
	}

	/**
	 * A restaurant created by hand (or by another source).
	 *
	 * @param string               $title  Name.
	 * @param array<string, mixed> $meta   Meta.
	 * @param string               $status Post status.
	 * @return int
	 */
	protected function restaurant( string $title, array $meta = array(), string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		return $id;
	}

	/**
	 * Logs in an administrator.
	 *
	 * @return int User ID.
	 */
	protected function login_admin(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * Rows of a custom table.
	 *
	 * @param string $table Table constant.
	 * @param string $where SQL condition (test-only, literal).
	 * @return array<int, array<string, mixed>>
	 */
	protected function rows( string $table, string $where = '1=1' ): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Schema::table( $table ) . ' WHERE ' . $where . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Moves a restaurant's closure-check dates back, as if the last check
	 * was a week ago.
	 *
	 * @param int $post_id Restaurant.
	 * @return void
	 */
	protected function age_closure_checks( int $post_id ): void {
		$streaks = get_post_meta( $post_id, \GuamRestaurants\Core\Monitoring\Closures::STREAK_META, true );
		if ( is_array( $streaks ) ) {
			foreach ( $streaks as $source => $streak ) {
				$streaks[ $source ]['last'] = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
			}
			update_post_meta( $post_id, \GuamRestaurants\Core\Monitoring\Closures::STREAK_META, $streaks );
		}
	}

	/**
	 * Restaurant phone helper.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	protected function phone( int $post_id ): string {
		return (string) get_post_meta( $post_id, Restaurant_Meta::PHONE, true );
	}
}
