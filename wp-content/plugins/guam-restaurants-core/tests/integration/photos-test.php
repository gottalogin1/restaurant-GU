<?php
/**
 * Cuisine photo and website photo tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Http\Retrying_Client;
use GuamRestaurants\Core\Photos\Cuisine_Photos;
use GuamRestaurants\Core\Photos\Openverse_Importer;
use GuamRestaurants\Core\Photos\Website_Photo_Box;
use GuamRestaurants\Core\Site\Restaurant_View;

/**
 * Picking, storing and showing photos.
 *
 * @covers \GuamRestaurants\Core\Photos\Cuisine_Photos
 * @covers \GuamRestaurants\Core\Photos\Openverse_Importer
 * @covers \GuamRestaurants\Core\Photos\Photo_Download
 * @covers \GuamRestaurants\Core\Photos\Website_Photo_Box
 */
class Photos_Test extends Guam_Test_Case {

	/**
	 * Requests the fake transport received.
	 *
	 * @var string[]
	 */
	private array $urls = array();

	/**
	 * Arguments the fake transport received, in the same order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $args = array();

	/**
	 * A small JPEG.
	 *
	 * @return string
	 */
	private static function jpeg(): string {
		$image = imagecreatetruecolor( 40, 25 );
		imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 200, 120, 40 ) );
		ob_start();
		imagejpeg( $image );
		return (string) ob_get_clean();
	}

	/**
	 * Openverse search result.
	 *
	 * @param string               $id    Image id.
	 * @param array<string, mixed> $extra Fields to change.
	 * @return array<string, mixed>
	 */
	private static function result( string $id, array $extra = array() ): array {
		return $extra + array(
			'id'                  => $id,
			'title'               => 'Plate',
			'url'                 => "https://img.example/{$id}.jpg",
			'creator'             => 'Ana <b>Cruz</b>',
			'creator_url'         => 'https://img.example/ana',
			'license'             => 'by',
			'license_version'     => '2.0',
			'license_url'         => 'https://creativecommons.org/licenses/by/2.0/',
			'foreign_landing_url' => "https://img.example/photos/{$id}",
			'filetype'            => 'jpg',
			'width'               => 1600,
			'height'              => 1000,
			'mature'              => false,
		);
	}

	/**
	 * Client answering Openverse with the given results and images with a JPEG.
	 *
	 * @param array<int, array<string, mixed>> $results Search results.
	 * @param string|null                      $image   Image bytes (default a JPEG).
	 * @return Retrying_Client
	 */
	private function client( array $results, ?string $image = null ): Retrying_Client {
		$bytes = $image ?? self::jpeg();
		return new Retrying_Client(
			function ( string $url, array $args ) use ( $results, $bytes ): array {
				$this->urls[] = $url;
				$this->args[] = $args;
				$body         = 0 === strpos( $url, Openverse_Importer::API ) ? (string) wp_json_encode( array( 'results' => $results ) ) : $bytes;
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => $body,
				);
			},
			static function () {},
			array( 429 ),
			0,
			0
		);
	}

	/**
	 * A cuisine term.
	 *
	 * @param string $name Name.
	 * @return \WP_Term
	 */
	private function cuisine( string $name ): \WP_Term {
		$term = get_term_by( 'name', $name, Content_Types::TAX_CUISINE );
		if ( $term instanceof \WP_Term ) {
			return $term;
		}
		return get_term( wp_insert_term( $name, Content_Types::TAX_CUISINE )['term_id'] );
	}

	/**
	 * Picks the first large landscape photo under an allowed license; a second run changes nothing; refresh replaces.
	 */
	public function test_importer_picks_and_is_idempotent() {
		$cuisine = $this->cuisine( 'Chamorro' );
		$results = array(
			self::result( '11111111-1111-1111-1111-111111111111', array( 'license' => 'by-nc' ) ),
			self::result( '22222222-2222-2222-2222-222222222222', array( 'width' => 600 ) ),
			self::result( '33333333-3333-3333-3333-333333333333', array( 'height' => 2000 ) ),
			self::result( '44444444-4444-4444-4444-444444444444' ),
		);

		$result = ( new Openverse_Importer( $this->client( $results ), 0 ) )->run( array( $cuisine ) );
		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertStringContainsString( 'q=Chamorro%20food', $this->urls[0] );
		$this->assertStringContainsString( 'license=cc0,pdm,by,by-sa', $this->urls[0] );
		$this->assertStringContainsString( 'filter_dead=false', $this->urls[0], 'Openverse does not check every result\'s host before answering.' );
		$this->assertSame( array( '1.1', '1.1' ), array_column( $this->args, 'httpversion' ), 'Openverse stalls on HTTP/1.0, the WordPress default.' );
		$this->assertSame( 'https://img.example/44444444-4444-4444-4444-444444444444.jpg', $this->urls[1] );
		$photo = Cuisine_Photos::get( $cuisine->term_id );
		$this->assertNotNull( $photo );
		$this->assertSame( '44444444-4444-4444-4444-444444444444', $photo['credit']['openverse_id'] );
		$this->assertSame( 'CC BY 2.0', Cuisine_Photos::license_label( $photo['credit'] ) );
		$this->assertGreaterThan( 0, $result['run_id'] );

		$this->urls = array();
		$again      = ( new Openverse_Importer( $this->client( $results ), 0 ) )->run( array( $cuisine ) );
		$this->assertSame( 1, $again['counts']['unchanged'] );
		$this->assertSame( array(), $this->urls );

		$same = ( new Openverse_Importer( $this->client( $results ), 0 ) )->run( array( $cuisine ), true );
		$this->assertSame( 1, $same['counts']['unchanged'], 'Refresh that finds the same image keeps it.' );

		$other = ( new Openverse_Importer( $this->client( array( self::result( '55555555-5555-5555-5555-555555555555', array( 'license' => 'CC0' ) ) ) ), 0 ) )->run( array( $cuisine ), true );
		$this->assertSame( 1, $other['counts']['updated'] );
		$this->assertSame( 'CC0', Cuisine_Photos::license_label( Cuisine_Photos::get( $cuisine->term_id )['credit'] ) );
	}

	/**
	 * No suitable result, or a download that is not an image, counts as failed and saves nothing.
	 */
	public function test_importer_failures() {
		$cuisine = $this->cuisine( 'Fast food' );

		$none = ( new Openverse_Importer( $this->client( array( self::result( '66666666-6666-6666-6666-666666666666', array( 'license' => 'by-nd' ) ) ) ), 0 ) )->run( array( $cuisine ) );
		$this->assertSame( 1, $none['counts']['failed'] );
		$this->assertStringContainsString( 'q=Fast%20food&', $this->urls[0], '"food" is not added twice.' );

		$html = ( new Openverse_Importer( $this->client( array( self::result( '77777777-7777-7777-7777-777777777777' ) ), '<html>not an image</html>' ), 0 ) )->run( array( $cuisine ) );
		$this->assertSame( 1, $html['counts']['failed'] );
		$this->assertNull( Cuisine_Photos::get( $cuisine->term_id ) );

		$dry = ( new Openverse_Importer( $this->client( array( self::result( '88888888-8888-8888-8888-888888888888' ) ) ), 0 ) )->run( array( $cuisine ), false, true );
		$this->assertSame( 1, $dry['counts']['created'] );
		$this->assertNull( Cuisine_Photos::get( $cuisine->term_id ), 'A dry run saves nothing.' );
		$this->assertSame( 0, $dry['run_id'] );
	}

	/**
	 * URL-encoded names are decoded; a photo already saved gets its credit fixed without a new download.
	 */
	public function test_importer_decodes_names() {
		$this->assertSame( 'Krzysztof Puszczyński', Openverse_Importer::decode( 'Krzysztof%20Puszczy%u0144ski' ) );
		$this->assertSame( 'José', Openverse_Importer::decode( 'Jos%C3%A9' ) );
		$this->assertSame( '100% juice', Openverse_Importer::decode( '100% juice' ) );
		$this->assertSame( 'Bad %FF byte', Openverse_Importer::decode( 'Bad %FF byte' ), 'Invalid UTF-8 is left alone.' );

		$cuisine = $this->cuisine( 'Pizza' );
		$id      = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
		( new Openverse_Importer( $this->client( array( self::result( $id ) ) ), 0 ) )->run( array( $cuisine ) );
		$photo = Cuisine_Photos::get( $cuisine->term_id );
		update_post_meta( $photo['id'], Cuisine_Photos::CREDIT, array( 'creator' => 'Krzysztof%20Puszczy%u0144ski' ) + $photo['credit'] );

		$this->urls = array();
		$fix        = ( new Openverse_Importer( $this->client( array( self::result( $id, array( 'creator' => 'Krzysztof%20Puszczy%u0144ski' ) ) ) ), 0 ) )->run( array( $cuisine ), true );
		$this->assertSame( 1, $fix['counts']['updated'] );
		$this->assertCount( 1, $this->urls, 'Only the search; the photo is not downloaded again.' );
		$after = Cuisine_Photos::get( $cuisine->term_id );
		$this->assertSame( $photo['id'], $after['id'] );
		$this->assertSame( 'Krzysztof Puszczyński', $after['credit']['creator'] );

		$again = ( new Openverse_Importer( $this->client( array( self::result( $id, array( 'creator' => 'Krzysztof%20Puszczy%u0144ski' ) ) ) ), 0 ) )->run( array( $cuisine ), true );
		$this->assertSame( 1, $again['counts']['unchanged'] );
	}

	/**
	 * A result whose image is gone gives way to the next suitable one, and its credit is the one saved.
	 */
	public function test_importer_skips_dead_image() {
		$cuisine = $this->cuisine( 'Korean' );
		$results = array(
			self::result( 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' ),
			self::result( 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb' ),
		);
		$bytes   = self::jpeg();
		$client  = new Retrying_Client(
			function ( string $url ) use ( $results, $bytes ): array {
				$this->urls[] = $url;
				if ( 0 === strpos( $url, Openverse_Importer::API ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => array(),
						'body'     => (string) wp_json_encode( array( 'results' => $results ) ),
					);
				}
				$dead = false !== strpos( $url, 'aaaaaaaa' );
				return array(
					'response' => array( 'code' => $dead ? 404 : 200 ),
					'headers'  => array(),
					'body'     => $dead ? 'Not found' : $bytes,
				);
			},
			static function () {},
			array( 429 ),
			0,
			0
		);

		$result = ( new Openverse_Importer( $client, 0 ) )->run( array( $cuisine ) );
		$this->assertSame( 1, $result['counts']['created'] );
		$this->assertCount( 3, $this->urls );
		$this->assertSame( 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', Cuisine_Photos::get( $cuisine->term_id )['credit']['openverse_id'] );
	}

	/**
	 * A restaurant without its own photo shows its cuisine photo, labeled and credited; markup in the credit is stripped.
	 */
	public function test_card_and_page_show_labeled_cuisine_photo() {
		$cuisine = $this->cuisine( 'Filipino' );
		( new Openverse_Importer( $this->client( array( self::result( '99999999-9999-9999-9999-999999999999' ) ) ), 0 ) )->run( array( $cuisine ) );
		$id = $this->restaurant( 'Lumpia House' );
		wp_set_object_terms( $id, array( $cuisine->term_id ), Content_Types::TAX_CUISINE );

		$card = Restaurant_View::card( $id );
		$this->assertStringContainsString( 'gr-card__media--stock', $card );
		$this->assertStringContainsString( '<span class="gr-media__credit">Illustrative photo <span aria-hidden="true">·</span> <a href="https://img.example/photos/99999999-9999-9999-9999-999999999999" rel="nofollow noopener"><span class="notranslate" translate="no">Ana Cruz</span></a>', $card );
		$this->assertStringContainsString( 'rel="license nofollow noopener"><span class="notranslate" translate="no">CC BY 2.0</span></a>', $card );
		$this->assertStringContainsString( 'Illustrative photo, not from this restaurant', Restaurant_View::media( $id, 'large', 'gr-hero__media', true ) );

		$own = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
		set_post_thumbnail( $id, $own );
		$this->assertStringNotContainsString( 'Illustrative', Restaurant_View::card( $id ), 'The restaurant\'s own photo wins.' );
	}

	/**
	 * A website photo needs a permission note; with one it becomes the featured image and the note is kept.
	 */
	public function test_website_photo_needs_permission() {
		$this->login_admin();
		$id = $this->restaurant( 'Sumai Grill' );

		$missing = Website_Photo_Box::attach( $id, 'https://sumai.example/hero.jpg', ' ', $this->client( array() ) );
		$this->assertWPError( $missing );
		$this->assertSame( array(), $this->urls, 'Nothing is downloaded without permission.' );

		$attachment = Website_Photo_Box::attach( $id, 'https://sumai.example/hero.jpg', 'Owner Lisa by email, 2026-10-20', $this->client( array() ) );
		$this->assertIsInt( $attachment );
		$this->assertSame( $attachment, (int) get_post_thumbnail_id( $id ) );
		$this->assertSame( 'Owner Lisa by email, 2026-10-20', get_post_meta( $attachment, Website_Photo_Box::PERMISSION, true )['note'] );
		$this->assertWPError( Website_Photo_Box::attach( $id, 'ftp://sumai.example/x.jpg', 'Owner said yes', $this->client( array() ) ) );
	}

	/**
	 * Saving the box without the permission box ticked downloads nothing.
	 */
	public function test_save_without_tick_downloads_nothing() {
		$this->login_admin();
		$id    = $this->restaurant( 'Pika\'s' );
		$_POST = array(
			'guam_restaurants_website_photo_nonce' => wp_create_nonce( 'guam_restaurants_website_photo' ),
			'guam_restaurants_website_photo_url'   => 'https://pikas.example/a.jpg',
			'guam_restaurants_website_photo_note'  => 'Owner said yes',
		);
		Website_Photo_Box::save( $id );
		$_POST = array();
		$this->assertSame( 0, (int) get_post_thumbnail_id( $id ) );
		$this->assertSame( 'error', get_transient( 'gr_website_photo_notice_' . get_current_user_id() )[0] );
	}
}
