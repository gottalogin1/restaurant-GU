<?php
/**
 * Cuisine-in-village pages ("Korean Restaurants in Tumon, Guam").
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Cuisine_Villages;
use GuamRestaurants\Core\Site\Filters;
use GuamRestaurants\Core\Site\Related_Links;
use GuamRestaurants\Core\Site\Seo;
use GuamRestaurants\Core\Site\Sitemaps;
use GuamRestaurants\Core\Structured_Data;

/**
 * A village page narrowed to one cuisine is a page of its own at five or
 * more open restaurants, and a noindex filter below that (SEO plan gap G1).
 *
 * @covers \GuamRestaurants\Core\Site\Cuisine_Villages
 * @covers \GuamRestaurants\Core\Site\Seo
 * @covers \GuamRestaurants\Core\Site\Related_Links
 */
class Cuisine_Villages_Test extends Guam_Test_Case {

	/**
	 * Terms by slug.
	 *
	 * @var array<string, \WP_Term>
	 */
	private array $t = array();

	/**
	 * Tumon: five open Korean places (and a closed one), four open Japanese
	 * places (and a closed one). Hagåtña: one Korean place.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		Content_Types::register();
		flush_rewrite_rules( false );

		foreach ( array(
			'tumon'    => array( Content_Types::TAX_VILLAGE, 'Tumon' ),
			'hagatna'  => array( Content_Types::TAX_VILLAGE, 'Hagåtña' ),
			'korean'   => array( Content_Types::TAX_CUISINE, 'Korean' ),
			'japanese' => array( Content_Types::TAX_CUISINE, 'Japanese' ),
		) as $slug => $term ) {
			// Villages (and some cuisines) are seeded on activation.
			$found            = get_term_by( 'slug', $slug, $term[0] );
			$this->t[ $slug ] = $found instanceof \WP_Term ? $found : get_term(
				self::factory()->term->create(
					array(
						'taxonomy' => $term[0],
						'name'     => $term[1],
						'slug'     => $slug,
					)
				),
				$term[0]
			);
		}

		$add = function ( string $name, string $village, string $cuisine, bool $closed = false ): int {
			$id = $this->restaurant( $name, $closed ? array( Restaurant_Meta::STATUS => 'permanently_closed' ) : array() );
			wp_set_object_terms( $id, array( $this->t[ $village ]->term_id ), Content_Types::TAX_VILLAGE );
			wp_set_object_terms( $id, array( $this->t[ $cuisine ]->term_id ), Content_Types::TAX_CUISINE );
			return $id;
		};
		foreach ( array( 'Seoul Garden', 'Bulgogi House', 'Kimchi Corner', 'Han River', 'Jeju Grill' ) as $name ) {
			$add( $name, 'tumon', 'korean' );
		}
		$add( 'Old Seoul', 'tumon', 'korean', true );
		foreach ( array( 'Sushi Bay', 'Ramen Ya', 'Izakaya Ten', 'Tonkatsu Taro' ) as $name ) {
			$add( $name, 'tumon', 'japanese' );
		}
		$add( 'Closed Sushi', 'tumon', 'japanese', true );
		$add( 'Capital Kimchi', 'hagatna', 'korean' );
		foreach ( $this->t as $slug => $term ) {
			$this->t[ $slug ] = get_term( $term->term_id, $term->taxonomy );
		}
	}

	/**
	 * Village page address with a cuisine chosen.
	 *
	 * @param string $cuisine Cuisine slug.
	 * @param array  $more    More parameters.
	 * @return string
	 */
	private function pair_url( string $cuisine, array $more = array() ): string {
		return add_query_arg( array( 'cuisine' => $cuisine ) + $more, get_term_link( $this->t['tumon'] ) );
	}

	/**
	 * Only pairs with five or more open places have a page; closed places don't count.
	 */
	public function test_pairs() {
		$this->assertSame(
			array(
				array(
					'village' => $this->t['tumon']->term_id,
					'cuisine' => $this->t['korean']->term_id,
					'count'   => 5,
				),
			),
			Cuisine_Villages::all()
		);
		$this->assertNull( Cuisine_Villages::find( $this->t['tumon']->term_id, $this->t['japanese']->term_id ), 'Four open places is too few' );
		$this->assertNull( Cuisine_Villages::find( $this->t['hagatna']->term_id, $this->t['korean']->term_id ) );

		$this->assertSame( $this->pair_url( 'korean' ), Cuisine_Villages::url( $this->t['tumon'], $this->t['korean'] ) );
		$this->assertSame( 'Korean Restaurants in Tumon, Guam', Cuisine_Villages::title( $this->t['tumon'], $this->t['korean'] ) );
		$this->assertContains( $this->pair_url( 'korean' ), wp_list_pluck( Sitemaps::urls( Sitemaps::PAGES ), 'loc' ) );
		$this->assertNotContains( $this->pair_url( 'japanese' ), wp_list_pluck( Sitemaps::urls( Sitemaps::PAGES ), 'loc' ) );
	}

	/**
	 * The pair's page is indexable, with its own title, heading, description, canonical and list.
	 */
	public function test_pair_page() {
		$this->go_to( $this->pair_url( 'korean' ) );
		$pair = Cuisine_Villages::current();
		$this->assertNotNull( $pair );
		$this->assertSame( 5, $pair['count'] );

		$this->assertFalse( Seo::is_filtered_listing() );
		$this->assertArrayNotHasKey( 'noindex', Seo::robots( array() ) );
		$this->assertSame( 'Korean Restaurants in Tumon, Guam', Seo::title_parts( array( 'title' => 'Tumon' ) )['title'] );
		$this->assertSame( 'Korean Restaurants in Tumon, Guam - ' . get_bloginfo( 'name' ), Seo::yoast_title( 'Tumon' ) );
		$this->assertSame( '5 Korean restaurants in Tumon, Guam. See hours, maps, awards and reviews for each.', Seo::description() );
		$this->assertSame( $this->pair_url( 'korean' ), Seo::yoast_canonical( get_term_link( $this->t['tumon'] ) ) );
		$this->assertStringContainsString( 'Korean Restaurants in', get_the_archive_title() );
		$this->assertStringContainsString( 'Tumon</span>', get_the_archive_title(), 'The village name keeps its spelling' );

		ob_start();
		Seo::canonical();
		$this->assertSame( '<link rel="canonical" href="' . esc_url( $this->pair_url( 'korean' ) ) . '">' . "\n", (string) ob_get_clean() );

		ob_start();
		Structured_Data::print_item_list();
		$html = (string) ob_get_clean();
		preg_match( '#<script type="application/ld\+json">(.+)</script>#s', $html, $match );
		$data = json_decode( $match[1], true );
		$this->assertSame( 'Korean Restaurants in Tumon, Guam', $data['name'] );
		$this->assertSame( $this->pair_url( 'korean' ), $data['url'] );
		$this->assertSame( 5, $data['numberOfItems'] );
	}

	/**
	 * Too few places, or any other filter, leaves the page a noindex filter.
	 */
	public function test_other_filters_stay_noindex() {
		$this->go_to( $this->pair_url( 'japanese' ) );
		$this->assertNull( Cuisine_Villages::current() );
		$this->assertTrue( Seo::is_filtered_listing() );
		$this->assertTrue( Seo::robots( array() )['noindex'] );
		$this->assertStringEndsWith( 'Tumon', wp_strip_all_tags( get_the_archive_title() ) );

		foreach ( array(
			array( 'open' => Filters::OPEN_NOW ),
			array( 'sort' => 'newest' ),
			array( 'q' => 'grill' ),
		) as $more ) {
			$this->go_to( $this->pair_url( 'korean', $more ) );
			$this->assertNull( Cuisine_Villages::current(), wp_json_encode( $more ) );
			$this->assertTrue( Seo::is_filtered_listing() );
		}

		$this->go_to( $this->pair_url( 'korean,japanese' ) );
		$this->assertNull( Cuisine_Villages::current(), 'Two cuisines' );

		$this->go_to( add_query_arg( 'village', 'tumon', get_term_link( $this->t['korean'] ) ) );
		$this->assertNull( Cuisine_Villages::current(), 'Only village pages' );
	}

	/**
	 * Village and cuisine pages link to the pair's page; the results heading follows the filter.
	 */
	public function test_links_and_heading() {
		$links = static function ( array $groups, string $title ): array {
			foreach ( $groups as $group ) {
				if ( $group['title'] === $title ) {
					return array_column( $group['links'], 'url', 'label' );
				}
			}
			return array();
		};

		$village = $links( Related_Links::for_term( $this->t['tumon'] ), 'Popular cuisines in Tumon' );
		$this->assertSame( $this->pair_url( 'korean' ), $village['Korean'] );
		$this->assertSame( get_term_link( $this->t['japanese'] ), $village['Japanese'] );

		$cuisine = $links( Related_Links::for_term( $this->t['korean'] ), 'Where to find Korean food' );
		$this->assertSame( $this->pair_url( 'korean' ), $cuisine['Tumon'] );
		$this->assertSame( get_term_link( $this->t['hagatna'] ), $cuisine['Hagåtña'] );

		$locked = array( 'village' => 'tumon' );
		$this->assertSame( 'Korean Restaurants in Tumon, Guam', Cuisine_Villages::heading( Filters::from_params( array( 'cuisine' => 'korean' ), $locked ), $locked ) );
		$this->assertSame( 'Tumon', Cuisine_Villages::heading( Filters::from_params( array( 'cuisine' => 'japanese' ), $locked ), $locked ) );
		$this->assertSame( '', Cuisine_Villages::heading( Filters::from_params( array(), array() ), array() ) );
	}
}
