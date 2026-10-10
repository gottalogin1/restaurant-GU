<?php
/**
 * Award display tests: restaurant section, archive pages, ranking and structured data.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Awards\Display;
use GuamRestaurants\Core\Awards\Pages;
use GuamRestaurants\Core\Awards\Policy;
use GuamRestaurants\Core\Awards\Repository;
use GuamRestaurants\Core\Content_Types;

/**
 * Showing award results.
 *
 * @covers \GuamRestaurants\Core\Awards\Display
 * @covers \GuamRestaurants\Core\Awards\Pages
 * @covers \GuamRestaurants\Core\Awards\Policy
 * @covers \GuamRestaurants\Core\Awards\Repository
 */
class Awards_Display_Test extends Test_Case {

	/**
	 * Restaurant IDs by label.
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Three restaurants and a handful of results.
	 */
	public function set_up() {
		parent::set_up();
		$this->ids = array(
			'egg'    => $this->restaurant( 'The Kracked Egg' ),
			'harbor' => $this->restaurant( 'Harbor Grill' ),
			'gone'   => $this->restaurant( 'Long Gone Pizza Co', 'draft' ),
		);
		$this->award( 'egg', 2022, 'Best Burger', 'winner', 'Example 2022 results page' );
		$this->award( 'egg', 2023, 'Best Burger', 'winner' );
		$this->award( 'egg', 2023, 'Best Breakfast', 'finalist' );
		$this->award( 'harbor', 2022, 'Best Breakfast', 'winner' );
		$this->award( 'harbor', 2023, 'Best Pizza', 'finalist' );
		$this->award( 'gone', 2023, 'Best Pizza', 'winner' );
	}

	/**
	 * Creates a restaurant.
	 *
	 * @param string $title  Title.
	 * @param string $status Post status.
	 * @return int
	 */
	private function restaurant( string $title, string $status = 'publish' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => Content_Types::POST_TYPE,
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Stores a result.
	 *
	 * @param string $label     Restaurant label.
	 * @param int    $year      Year.
	 * @param string $category  Category.
	 * @param string $placement Placement.
	 * @param string $note      Source note.
	 */
	private function award( string $label, int $year, string $category, string $placement, string $note = '' ): void {
		Repository::upsert(
			$this->ids[ $label ],
			array(
				'program'     => 'Test Awards',
				'year'        => $year,
				'category'    => $category,
				'placement'   => $placement,
				'source_url'  => "https://www.example.com/{$year}-results",
				'source_note' => $note,
			)
		);
	}

	/**
	 * The notice names the publisher from docs/awards-research.md.
	 */
	public function test_notice_names_publisher() {
		$this->assertSame( 'Pacific Daily News', Policy::PUBLISHER_NAME );
		$this->assertSame( 'This site is not affiliated with Pacific Daily News. Results are listed with links to the original publication.', Policy::notice() );
	}

	/**
	 * Citations use our wording.
	 */
	public function test_citation() {
		$this->assertSame(
			"Pika's Best of Guam 2019, Best Burger: Winner",
			Policy::citation(
				array(
					'program'   => "Pika's Best of Guam",
					'year'      => 2019,
					'category'  => 'Best Burger',
					'placement' => 'winner',
				)
			)
		);
	}

	/**
	 * The restaurant section lists year, category, placement and a source link in plain text, plus the notice.
	 */
	public function test_restaurant_section() {
		$html = Display::render( $this->ids['egg'] );

		$this->assertStringContainsString( '<h2 class="gr-awards__title"', $html );
		$this->assertStringContainsString( 'Test Awards 2023, Best Burger: Winner', $html );
		$this->assertStringContainsString( 'Test Awards 2023, Best Breakfast: Finalist', $html );
		$this->assertStringContainsString( '<a href="https://www.example.com/2022-results" rel="nofollow noopener">Example 2022 results page</a>', $html );
		$this->assertStringContainsString( '>example.com</a>', $html, 'Without a note, the link text is the host name.' );
		$this->assertStringContainsString( esc_html( Policy::notice() ), $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertLessThan( strpos( $html, '2022, Best Burger' ), strpos( $html, '2023, Best Burger' ), 'Newest first.' );

		$this->assertSame( '', Display::render( $this->ids['gone'] ), 'Draft restaurants have no public page.' );
		$this->assertSame( '', Display::render( $this->restaurant( 'No Awards Cafe' ) ) );
	}

	/**
	 * The section is appended to the main restaurant content, unless the shortcode is already there.
	 */
	public function test_section_appended_on_restaurant_page() {
		$this->go_to( get_permalink( $this->ids['egg'] ) );
		$this->assertTrue( is_singular( Content_Types::POST_TYPE ) );
		the_post();

		$content = apply_filters( 'the_content', 'About this place.' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$this->assertStringContainsString( 'gr-awards__title', $content );

		$content = apply_filters( 'the_content', '[guam_restaurant_awards]' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$this->assertSame( 1, substr_count( $content, 'gr-awards__title' ) );
	}

	/**
	 * Structured data uses schema.org's award property on the Restaurant, with our citations.
	 */
	public function test_structured_data() {
		$data = Display::structured_data( $this->ids['egg'] );

		$this->assertSame( 'Restaurant', $data['@type'] );
		$this->assertSame( 'The Kracked Egg', $data['name'] );
		$this->assertSame(
			array( 'Test Awards 2023, Best Breakfast: Finalist', 'Test Awards 2023, Best Burger: Winner', 'Test Awards 2022, Best Burger: Winner' ),
			$data['award']
		);
		$this->assertSame( array( '@context', '@type', '@id', 'name', 'url', 'award' ), array_keys( $data ) );
		$this->assertNull( Display::structured_data( $this->ids['gone'] ) );
	}

	/**
	 * The archive defaults to the latest year and has year and category selectors.
	 */
	public function test_archive_page() {
		$html = Pages::render_archive();

		$this->assertStringContainsString( '<h1 class="gr-awards__heading">Restaurant awards</h1>', $html );
		$this->assertStringContainsString( '<select name="award_year">', $html );
		$this->assertStringContainsString( '<select name="award_category">', $html );
		$this->assertStringContainsString( '<option value="2023" selected=\'selected\'>2023</option>', $html );
		$this->assertStringContainsString( 'Results recorded for 2023', $html );
		$this->assertStringNotContainsString( 'Results recorded for 2022', $html );
		$this->assertStringContainsString( 'Long Gone Pizza Co', $html, 'Past winners without a published page are still listed.' );
		$this->assertStringNotContainsString( 'href="' . get_permalink( $this->ids['gone'] ), $html );
		$this->assertStringContainsString( esc_html( Policy::notice() ), $html );
		$this->assertStringNotContainsString( 'name="award_program"', $html, 'The program selector appears only with more than one program.' );

		$category = Pages::render_archive( 0, 'best-burger' );
		$this->assertStringContainsString( 'Results recorded for 2022', $category, 'A category with no year shows every year.' );
		$this->assertStringContainsString( 'Results recorded for 2023', $category );
		$this->assertStringNotContainsString( 'Best Pizza</a></td>', $category );
	}

	/**
	 * A category page shows that category's results across all years.
	 */
	public function test_category_page() {
		$html = Pages::render_category( 'best-breakfast' );

		$this->assertStringContainsString( 'Best Breakfast: results by year', $html );
		$this->assertStringContainsString( '<td>2023</td><td>Finalist</td>', $html );
		$this->assertStringContainsString( '<td>2022</td><td>Winner</td>', $html );
		$this->assertStringNotContainsString( 'Best Burger', $html );
		$this->assertStringContainsString( esc_html( Policy::notice() ), $html );

		$this->assertStringContainsString( 'No results are recorded for this category.', Pages::render_category( 'best-nothing' ) );
	}

	/**
	 * The ranking orders by wins, then finalist placings, and ties share a rank.
	 */
	public function test_most_awarded_ranking() {
		$overall = Repository::ranking();
		$this->assertSame( array( $this->ids['egg'], $this->ids['harbor'], $this->ids['gone'] ), array_column( $overall, 'restaurant_id' ) );
		$this->assertSame( array( 1, 2, 3 ), array_column( $overall, 'rank' ) );
		$this->assertSame( 2, $overall[0]['wins'] );
		$this->assertSame( 3, $overall[0]['total'] );

		$pizza = Repository::ranking( 'Best Pizza' );
		$this->assertSame( array( $this->ids['gone'], $this->ids['harbor'] ), array_column( $pizza, 'restaurant_id' ) );

		$this->award( 'harbor', 2024, 'Best Burger', 'winner' );
		$tied = Repository::ranking();
		$this->assertSame( array( 1, 1 ), array_slice( array_column( $tied, 'rank' ), 0, 2 ), 'Same wins, finalists and total share a rank.' );

		$html = Pages::render_ranking();
		$this->assertStringContainsString( 'Most-awarded restaurants', $html );
		$this->assertStringContainsString( 'Counted only from the results recorded on this site', $html );
		$this->assertStringContainsString( esc_html( Policy::notice() ), $html );

		$this->assertStringContainsString( 'Most-awarded restaurants: Best Pizza', Pages::render_ranking( 'best-pizza' ) );
	}

	/**
	 * Trashed restaurants drop out of every list.
	 */
	public function test_trashed_restaurants_are_hidden() {
		wp_trash_post( $this->ids['harbor'] );

		$this->assertNotContains( $this->ids['harbor'], array_column( Repository::ranking(), 'restaurant_id' ) );
		$this->assertNull( Repository::category_by_slug( 'best-breakfast-x' ) );
		$this->assertStringNotContainsString( 'Harbor Grill', Pages::render_archive( 2022 ) );
	}

	/**
	 * Page titles never use a program's name.
	 */
	public function test_titles_use_our_wording() {
		foreach ( Pages::PAGES as $page ) {
			$this->assertStringNotContainsString( 'Test Awards', Pages::title( $page, array( 'category' => 'best-burger' ) ) );
		}
	}
}
