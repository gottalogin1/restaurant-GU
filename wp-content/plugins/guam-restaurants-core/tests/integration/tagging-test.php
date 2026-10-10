<?php
/**
 * Cuisine and mall tagging: sources, rules, manual overrides and origins.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Osm\Importer;
use GuamRestaurants\Core\Osm\Mall_Locator;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Seed_Data;
use GuamRestaurants\Core\Tagging\Cuisine_Tagger;
use GuamRestaurants\Core\Tagging\Mall_Tagger;
use GuamRestaurants\Core\Tagging\Tag_Store;

/**
 * Guam examples: Shirley's in Micronesia Mall, Proa in Tumon, Meskla in Hagåtña.
 *
 * @covers \GuamRestaurants\Core\Tagging\Cuisine_Tagger
 * @covers \GuamRestaurants\Core\Tagging\Mall_Tagger
 * @covers \GuamRestaurants\Core\Tagging\Tag_Store
 */
class Tagging_Test extends Guam_Test_Case {

	/**
	 * The bundled rules only name seeded cuisines and every pattern compiles.
	 */
	public function test_bundled_rules_use_seeded_slugs() {
		$slugs = Cuisine_Tagger::from_file()->slugs();

		$this->assertNotEmpty( $slugs );
		$this->assertSame( array(), array_diff( $slugs, array_keys( Seed_Data::cuisines() ) ) );
	}

	/**
	 * A rules file with a broken pattern is refused, not half-applied.
	 */
	public function test_invalid_rule_pattern_is_rejected() {
		$file = wp_tempnam( 'rules.json' );
		file_put_contents( $file, '{"name_rules":[{"id":"broken","pattern":"(sushi","cuisines":["japanese"]}]}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->expectException( \RuntimeException::class );
		Cuisine_Tagger::from_file( $file );
	}

	/**
	 * Name rules: Chamoru spelling, Kelaguen, and the coffee rule that only
	 * applies when no source gave a cuisine.
	 */
	public function test_name_rules() {
		$tagger = Cuisine_Tagger::from_file();

		$this->assertSame( array( 'chamorro' => 'rule:chamorro-dishes' ), $tagger->rule_cuisines( 'Meskla Chamoru Fusion Bistro', false ) );
		$this->assertSame( array( 'chamorro' => 'rule:chamorro-dishes' ), $tagger->rule_cuisines( "Annie's Kelaguen Stand", false ) );
		$this->assertSame( array( 'coffee-and-cafe' => 'rule:coffee' ), $tagger->rule_cuisines( 'Tumon Bay Coffee', false ) );
		$this->assertSame( array(), $tagger->rule_cuisines( 'Hard Rock Cafe Guam', true ), 'A source cuisine wins over the coffee rule.' );
	}

	/**
	 * Each tag records whether it came from OSM or a rule.
	 */
	public function test_import_records_tag_origins() {
		$this->import();

		$proa   = $this->post_for( 5002 );
		$meskla = $this->post_for( 5003 );

		$this->assertSame( array( 'chamorro' ), $this->terms( $proa, Content_Types::TAX_CUISINE ) );
		$this->assertSame(
			array(
				'origin' => 'source',
				'detail' => 'osm:cuisine=chamorro',
			),
			Tag_Store::origins( $proa )[ Content_Types::TAX_CUISINE ]['chamorro']
		);

		$this->assertSame( array( 'chamorro' ), $this->terms( $meskla, Content_Types::TAX_CUISINE ), 'OSM has no cuisine; the name rule supplies it.' );
		$this->assertSame( 'rule', Tag_Store::origins( $meskla )[ Content_Types::TAX_CUISINE ]['chamorro']['origin'] );

		$shirleys = $this->post_for( 5001 );
		$this->assertSame( array( 'american', 'filipino' ), $this->terms( $shirleys, Content_Types::TAX_CUISINE ) );
	}

	/**
	 * An editor's additions and removals survive every later import, and are recorded as manual.
	 */
	public function test_manual_cuisine_edits_are_never_overwritten() {
		$this->import();
		$shirleys = $this->post_for( 5001 );
		$user     = $this->login_admin();

		// The editor says Shirley's is not Filipino, and adds local plate lunch.
		wp_set_object_terms( $shirleys, array( 'american', 'local-plate-lunch' ), Content_Types::TAX_CUISINE );

		$this->import();

		$this->assertSame( array( 'american', 'local-plate-lunch' ), $this->terms( $shirleys, Content_Types::TAX_CUISINE ) );
		$this->assertSame(
			array(
				'add'    => array( 'local-plate-lunch' ),
				'remove' => array( 'filipino' ),
			),
			Tag_Store::cuisine_manual( $shirleys )
		);
		$origins = Tag_Store::origins( $shirleys )[ Content_Types::TAX_CUISINE ];
		$this->assertSame( 'manual', $origins['local-plate-lunch']['origin'] );
		$this->assertSame( 'user:' . $user, $origins['local-plate-lunch']['detail'] );
		$this->assertSame( 'source', $origins['american']['origin'] );
	}

	/**
	 * A source dropping a category removes only that source's tag.
	 */
	public function test_source_tag_follows_the_source() {
		$this->import();
		$proa = $this->post_for( 5002 );

		$elements                       = Guam_Fixtures::elements();
		$elements[2]['tags']['cuisine'] = 'chamorro;seafood';
		$this->import( $elements );
		$this->assertSame( array( 'chamorro', 'seafood' ), $this->terms( $proa, Content_Types::TAX_CUISINE ) );

		$elements[2]['tags']['cuisine'] = 'chamorro';
		$result                         = $this->import( $elements );
		$this->assertSame( array( 'chamorro' ), $this->terms( $proa, Content_Types::TAX_CUISINE ) );
		$this->assertSame( 1, $result['counts']['updated'] );
	}

	/**
	 * Restaurants tagged before origins existed keep tags no source or rule explains.
	 */
	public function test_legacy_tags_are_kept_as_manual() {
		$this->import();
		$proa = $this->post_for( 5002 );
		foreach ( array( Tag_Store::ORIGINS_META, Tag_Store::CUISINE_SOURCES_META, Tag_Store::CUISINE_MANUAL_META ) as $key ) {
			delete_post_meta( $proa, $key );
		}
		Tag_Store::applying(
			static function () use ( $proa ) {
				wp_set_object_terms( $proa, array( 'chamorro', 'steakhouse' ), Content_Types::TAX_CUISINE );
			}
		);

		$this->import();

		$this->assertSame( array( 'chamorro', 'steakhouse' ), $this->terms( $proa, Content_Types::TAX_CUISINE ) );
		$this->assertSame( array( 'steakhouse' ), Tag_Store::cuisine_manual( $proa )['add'] );
	}

	/**
	 * Mall by outline: Shirley's is inside Micronesia Mall; Proa in Tumon is in no mall.
	 */
	public function test_mall_by_boundary() {
		$this->import();

		$shirleys = $this->post_for( 5001 );
		$this->assertSame( array( 'micronesia-mall' ), $this->terms( $shirleys, Content_Types::TAX_MALL ) );
		$this->assertSame(
			array(
				'origin' => 'source',
				'detail' => 'osm:way/' . Guam_Fixtures::MALL_OSM_ID,
			),
			Tag_Store::origins( $shirleys )[ Content_Types::TAX_MALL ]['micronesia-mall']
		);
		$this->assertSame( array(), $this->terms( $this->post_for( 5002 ), Content_Types::TAX_MALL ) );
	}

	/**
	 * Mall by address text, longest name first and whole words only.
	 */
	public function test_mall_by_address_text() {
		$tagger = new Mall_Tagger( Mall_Locator::from_geometries( array() ), Importer::mall_terms() );

		$this->assertSame( 'micronesia-mall', $tagger->slug_in_address( "Shirley's, Micronesia Mall, 1088 W Marine Corps Dr, Dededo" ) );
		$this->assertSame( 'tumon-sands-plaza', $tagger->slug_in_address( 'Tumon Sands Plaza, 1082 Pale San Vitores Rd, Tumon' ) );
		$this->assertSame( 'the-plaza', $tagger->slug_in_address( 'The Plaza, 1255 Pale San Vitores Rd, Tumon' ) );
		$this->assertSame( 'agana-shopping-center', $tagger->slug_in_address( 'ASC, 302 S Route 4, Hagåtña' ), 'Alias.' );
		$this->assertNull( $tagger->slug_in_address( '429 Pale San Vitores Road, Tumon' ) );

		$draft = $this->restaurant( 'Food court stall', array( Restaurant_Meta::ADDRESS => 'Micronesia Mall food court, Dededo' ), 'draft' );
		$this->assertSame( 'micronesia-mall', $tagger->apply( $draft )['new'], 'No coordinates: the address decides.' );
		$this->assertSame( 'rule', Tag_Store::origins( $draft )[ Content_Types::TAX_MALL ]['micronesia-mall']['origin'] );
	}

	/**
	 * A mall set or cleared by hand stays, whatever the outline says.
	 */
	public function test_manual_mall_override() {
		$this->import();
		$shirleys = $this->post_for( 5001 );
		$proa     = $this->post_for( 5002 );
		$this->login_admin();

		wp_set_object_terms( $shirleys, array(), Content_Types::TAX_MALL );
		wp_set_object_terms( $proa, 'tumon-sands-plaza', Content_Types::TAX_MALL );

		$this->import();

		$this->assertSame( array(), $this->terms( $shirleys, Content_Types::TAX_MALL ) );
		$this->assertSame( Tag_Store::NO_MALL, Tag_Store::mall_override( $shirleys ) );
		$this->assertSame( array( 'tumon-sands-plaza' ), $this->terms( $proa, Content_Types::TAX_MALL ) );
		$this->assertSame( 'manual', Tag_Store::origins( $proa )[ Content_Types::TAX_MALL ]['tumon-sands-plaza']['origin'] );

		Tag_Store::reset_overrides( $shirleys );
		Mall_Tagger::from_saved()->apply( $shirleys );
		$this->assertSame( array( 'micronesia-mall' ), $this->terms( $shirleys, Content_Types::TAX_MALL ), 'Saved outline used after reset.' );
	}
}
