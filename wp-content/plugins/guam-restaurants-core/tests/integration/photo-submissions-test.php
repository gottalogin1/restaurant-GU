<?php
/**
 * Visitor and owner photo upload tests.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Tests;

use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Legal\Pages as Legal_Pages;
use GuamRestaurants\Core\Monitoring\Jobs;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Photos\Agreement;
use GuamRestaurants\Core\Photos\Photo_Gallery;
use GuamRestaurants\Core\Photos\Photo_Submissions;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Security\Private_Uploads;
use GuamRestaurants\Core\Site\Blocks;
use GuamRestaurants\Core\Site\Restaurant_View;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Config reads the environment.

/**
 * Uploading, confirming, approving, showing and cleaning up photos.
 *
 * @covers \GuamRestaurants\Core\Photos\Photo_Submissions
 * @covers \GuamRestaurants\Core\Photos\Photo_Gallery
 * @covers \GuamRestaurants\Core\Photos\Agreement
 */
class Photo_Submissions_Test extends Guam_Test_Case {

	/**
	 * A copy of an image as an upload.
	 *
	 * @param int         $width  Width (ignored when $source is set).
	 * @param int         $height Height.
	 * @param string|null $source Existing image to copy instead.
	 * @return array<string, mixed>
	 */
	private function upload( int $width = 3000, int $height = 2000, ?string $source = null ): array {
		$tmp = wp_tempnam( 'gr-test-photo' );
		if ( null !== $source ) {
			copy( $source, $tmp );
		} else {
			$image = imagecreatetruecolor( $width, $height );
			imagefill( $image, 0, 0, (int) imagecolorallocate( $image, 180, 90, 30 ) );
			imagejpeg( $image, $tmp, 95 );
		}
		return array(
			'name'     => 'IMG_0001.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
		);
	}

	/**
	 * Valid form fields.
	 *
	 * @param array<string, mixed> $extra Changes.
	 * @return array<string, mixed>
	 */
	private static function fields( array $extra = array() ): array {
		return $extra + array(
			'dish'        => 'Chicken kelaguen',
			'rating'      => 4,
			'author_name' => 'Tasha',
			'email'       => 'tasha@example.com',
			'agree'       => true,
			'elapsed_ms'  => 9000,
		);
	}

	/**
	 * Required fields, the agreement, the honeypot, and no ratings from people connected to the restaurant.
	 */
	public function test_validation() {
		$id = $this->restaurant( 'Meskla' );

		$result = Photo_Submissions::submit( $id, self::fields( array( 'dish' => ' ' ) ), $this->upload( 50, 50 ), 'k1' );
		$this->assertSame( array( 'gr_photo_dish' ), $result->get_error_codes() );

		$result = Photo_Submissions::submit( $id, self::fields( array( 'agree' => false ) ), $this->upload( 50, 50 ), 'k1' );
		$this->assertSame( array( 'gr_photo_agree' ), $result->get_error_codes() );

		$result = Photo_Submissions::submit( $id, self::fields( array( 'connected' => true ) ), $this->upload( 50, 50 ), 'k1' );
		$this->assertSame( array( 'gr_photo_rating_connected' ), $result->get_error_codes() );

		$result = Photo_Submissions::submit( $id, self::fields( array( 'email' => '' ) ), $this->upload( 50, 50 ), 'k1' );
		$this->assertSame( array( 'gr_photo_email' ), $result->get_error_codes() );

		$result = Photo_Submissions::submit( $id, self::fields( array( 'website' => 'spam' ) ), $this->upload( 50, 50 ), 'k1' );
		$this->assertSame( 'gr_photo_rejected', $result->get_error_code() );

		$text            = wp_tempnam( 'gr-test-text' );
		$bad             = $this->upload( 50, 50 );
		$bad['tmp_name'] = $text;
		file_put_contents( $text, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test file.
		$this->assertSame( 'gr_photo_type', Photo_Submissions::submit( $id, self::fields(), $bad, 'k1' )->get_error_code() );

		$big         = $this->upload( 50, 50 );
		$big['size'] = 20 * MB_IN_BYTES + 1;
		$this->assertSame( 'gr_photo_size', Photo_Submissions::submit( $id, self::fields(), $big, 'k1' )->get_error_code() );
		$this->assertSame( array(), Photo_Submissions::ids( Photo_Submissions::UNCONFIRMED ) );
	}

	/**
	 * A logged-in visitor's photo is reduced, keeps its original for 30 days, and waits for approval.
	 */
	public function test_logged_in_upload_is_reduced_and_pending() {
		wp_set_current_user( self::factory()->user->create( array( 'display_name' => 'Ana' ) ) );
		$id = $this->restaurant( 'Proa' );

		$result = Photo_Submissions::submit( $id, self::fields( array( 'author_name' => '' ) ), $this->upload(), 'k2' );
		$this->assertSame( Photo_Submissions::PENDING, $result['status'] );
		$photo = $result['id'];
		$this->assertSame( $id, wp_get_post_parent_id( $photo ) );
		$this->assertSame( 'Ana', get_post_meta( $photo, Photo_Submissions::NAME, true ) );
		$this->assertSame( 4, (int) get_post_meta( $photo, Photo_Submissions::RATING, true ) );
		$this->assertSame( Agreement::VERSION, get_post_meta( $photo, Photo_Submissions::AGREEMENT, true )['version'] );

		$size = wp_getimagesize( (string) get_attached_file( $photo ) );
		$this->assertSame( array( 2048, 1365 ), array( $size[0], $size[1] ) );
		$this->assertSame( 'image/jpeg', get_post_mime_type( $photo ) );
		$original = (string) get_post_meta( $photo, Photo_Submissions::ORIGINAL, true );
		$this->assertFileExists( $original );
		$this->assertStringNotContainsString( 'IMG_0001', $original, 'The original gets a random name.' );
		$this->assertTrue( Private_Uploads::contains( $original ), 'The original, with its camera and location data, is kept where browsers cannot open it.' );
		$this->assertFalse( Private_Uploads::contains( (string) get_attached_file( $photo ) ), 'The public copy stays in the normal uploads folder.' );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) get_post_meta( $photo, Photo_Submissions::KEEP_UNTIL, true ), 5 );
		$this->assertSame( 0, Restaurant_View::photo_id( $id ), 'Not shown before approval.' );
		$this->assertSame( 1, Photo_Submissions::count( Photo_Submissions::PENDING ) );
	}

	/**
	 * Camera details are removed from the reduced copy with either image editor.
	 */
	public function test_camera_data_removed() {
		$this->login_admin();
		$id     = $this->restaurant( 'Terraza' );
		$source = DIR_TESTDATA . '/images/a2-small.jpg';
		$this->assertSame( 'FUJIFILM', exif_read_data( $source )['Make'] );
		foreach ( array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) as $editor ) {
			if ( ! call_user_func( array( $editor, 'test' ) ) || ! call_user_func( array( $editor, 'supports_mime_type' ), 'image/jpeg' ) ) {
				continue;
			}
			$only = static function () use ( $editor ): array {
				return array( $editor );
			};
			add_filter( 'wp_image_editors', $only );
			$result = Photo_Submissions::submit( $id, self::fields(), $this->upload( 0, 0, $source ), 'k-' . $editor );
			remove_filter( 'wp_image_editors', $only );
			$exif = @exif_read_data( (string) get_attached_file( $result['id'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no EXIF warns.
			$this->assertFalse( isset( $exif['Make'] ), $editor );
		}
	}

	/**
	 * A visitor who is not logged in confirms by email; the link works once.
	 */
	public function test_email_confirmation() {
		$id     = $this->restaurant( 'Jamaican Grill' );
		$result = Photo_Submissions::submit( $id, self::fields(), $this->upload( 400, 300 ), 'k3' );
		$this->assertSame( Photo_Submissions::UNCONFIRMED, $result['status'] );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'tasha@example.com', $this->mails[0]['to'] );
		$this->assertMatchesRegularExpression( '/token=([A-Za-z0-9]{32})/', $this->mails[0]['message'] );
		preg_match( '/token=([A-Za-z0-9]{32})/', $this->mails[0]['message'], $match );
		$this->assertSame( array(), Photo_Submissions::ids( Photo_Submissions::PENDING ), 'Not in the queue until confirmed.' );

		$this->assertSame( $id, Photo_Submissions::confirm( $match[1] ) );
		$this->assertSame( Photo_Submissions::PENDING, Photo_Submissions::status( $result['id'] ) );
		$this->assertWPError( Photo_Submissions::confirm( $match[1] ) );
		$this->assertStringNotContainsString( 'tasha@example.com', (string) wp_json_encode( get_post_meta( $result['id'] ) ), 'The email address is not stored.' );
	}

	/**
	 * Approval shows the photo with its dish and stars; declining needs a published rule and deletes the files.
	 */
	public function test_moderation_and_gallery() {
		$admin = $this->login_admin();
		$id    = $this->restaurant( 'Capricciosa' );
		$keep  = Photo_Submissions::submit( $id, self::fields( array( 'dish' => 'Squid ink pasta' ) ), $this->upload( 600, 400 ), 'k4' )['id'];
		$drop  = Photo_Submissions::submit( $id, self::fields( array( 'rating' => 1 ) ), $this->upload( 600, 400 ), 'k4' )['id'];

		$this->assertTrue( Photo_Submissions::moderate( $keep, 'approve', '', $admin ) );
		$this->assertWPError( Photo_Submissions::moderate( $drop, 'reject', 'too_negative', $admin ) );
		$files = array( (string) get_attached_file( $drop ), (string) get_post_meta( $drop, Photo_Submissions::ORIGINAL, true ) );
		$this->assertTrue( Photo_Submissions::moderate( $drop, 'reject', 'unclear', $admin ) );
		$this->assertNull( get_post( $drop ) );
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}

		$this->assertSame( $keep, Restaurant_View::photo_id( $id ) );
		$html = Photo_Gallery::render( $id );
		$this->assertStringContainsString( '<strong class="gr-photos__dish">Squid ink pasta</strong>', $html );
		$this->assertStringContainsString( 'aria-label="4 out of 5 stars"', $html );
		$this->assertStringContainsString( 'by Tasha', $html );
		$this->assertStringContainsString( 'name="dish" maxlength="80" required', $html );
		$this->assertStringContainsString( 'The photo becomes the property of', $html );
		$this->assertStringContainsString( 'data-max-bytes="' . Photo_Submissions::max_bytes() . '"', $html );

		$site_cap = getenv( 'GUAM_RESTAURANTS_MAX_UPLOAD_MB' );
		putenv( 'GUAM_RESTAURANTS_MAX_UPLOAD_MB' );
		$this->assertSame( 20 * MB_IN_BYTES, Photo_Submissions::max_bytes(), 'The default cap is 20 MB.' );
		putenv( false === $site_cap ? 'GUAM_RESTAURANTS_MAX_UPLOAD_MB' : 'GUAM_RESTAURANTS_MAX_UPLOAD_MB=' . $site_cap );
	}

	/**
	 * Cards of a list read each restaurant's newest approved upload from one
	 * query, and a later approval is seen at once.
	 */
	public function test_primed_cards_use_newest_approved_upload() {
		$admin = $this->login_admin();
		$id    = $this->restaurant( 'Capricciosa' );
		$other = $this->restaurant( 'Jamaican Grill' );
		$older = Photo_Submissions::submit( $id, self::fields(), $this->upload( 600, 400 ), 'k5' )['id'];
		$newer = Photo_Submissions::submit( $id, self::fields(), $this->upload( 600, 400 ), 'k5' )['id'];
		wp_update_post(
			array(
				'ID'            => $older,
				'post_date'     => '2026-01-01 10:00:00',
				'post_date_gmt' => '2026-01-01 00:00:00',
			)
		);
		// Administrators' uploads are approved at once; this one waits.
		update_post_meta( $newer, Photo_Submissions::STATUS, Photo_Submissions::PENDING );
		$this->assertSame( array( $id => $older ), Photo_Submissions::newest_approved( array( $id, $other ) ), 'Only approved uploads count.' );

		Restaurant_View::prime( array( $id, $other ) );
		global $wpdb;
		$queries = $wpdb->num_queries;
		$this->assertSame( $older, Restaurant_View::photo_id( $id ) );
		$this->assertSame( 0, Restaurant_View::photo_id( $other ) );
		$this->assertSame( $queries, $wpdb->num_queries, 'Primed cards need no photo queries.' );

		Photo_Submissions::moderate( $newer, 'approve', '', $admin );
		$this->assertSame( $newer, Restaurant_View::photo_id( $id ), 'An approval after priming shows at once.' );
		Restaurant_View::prime( array( $id ) );
		$this->assertSame( $newer, Restaurant_View::photo_id( $id ), 'Newest first, as in ids().' );
	}

	/**
	 * The owner's photos say "From the owner" and cannot carry a rating.
	 */
	public function test_owner_upload() {
		$owner = self::factory()->user->create( array( 'display_name' => 'Owner Rey' ) );
		$id    = $this->restaurant( 'Rey\'s Place', array( Restaurant_Meta::CLAIMED_BY_USER_ID => $owner ) );
		wp_set_current_user( $owner );

		$this->assertSame( 'gr_photo_rating_connected', Photo_Submissions::submit( $id, self::fields(), $this->upload( 300, 200 ), 'k5' )->get_error_code() );
		$photo = Photo_Submissions::submit( $id, self::fields( array( 'rating' => 0 ) ), $this->upload( 300, 200 ), 'k5' )['id'];
		$this->assertSame( '1', get_post_meta( $photo, Photo_Submissions::FROM_OWNER, true ) );
		$this->assertStringNotContainsString( 'data-gr-photo-rating', Photo_Gallery::render( $id ), 'Owners see no rating field.' );

		update_post_meta( $photo, Photo_Submissions::STATUS, Photo_Submissions::APPROVED );
		$this->assertStringContainsString( 'From the owner', Photo_Gallery::render( $id ) );
	}

	/**
	 * A trusted owner's photos go live at once; an ordinary owner's wait.
	 */
	public function test_trusted_owner_skips_approval() {
		Roles::install();
		$role = get_role( Roles::TRUSTED_OWNER_ROLE );
		$this->assertTrue( $role->has_cap( Roles::OWNER ) );
		$this->assertTrue( $role->has_cap( Roles::PUBLISH_PHOTOS ) );
		$this->assertFalse( get_role( Roles::OWNER_ROLE )->has_cap( Roles::PUBLISH_PHOTOS ) );

		$plain   = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		$trusted = self::factory()->user->create( array( 'role' => Roles::TRUSTED_OWNER_ROLE ) );
		$a       = $this->restaurant( 'Plain Owner Cafe', array( Restaurant_Meta::CLAIMED_BY_USER_ID => $plain ) );
		$b       = $this->restaurant( 'Trusted Owner Cafe', array( Restaurant_Meta::CLAIMED_BY_USER_ID => $trusted ) );

		wp_set_current_user( $plain );
		$this->assertSame( Photo_Submissions::PENDING, Photo_Submissions::submit( $a, self::fields( array( 'rating' => 0 ) ), $this->upload( 300, 200 ), 'k8' )['status'] );
		wp_set_current_user( $trusted );
		$this->assertSame( Photo_Submissions::APPROVED, Photo_Submissions::submit( $b, self::fields( array( 'rating' => 0 ) ), $this->upload( 300, 200 ), 'k9' )['status'] );
		$this->assertCount( 1, Photo_Submissions::approved( $b ) );
		$this->assertWPError( Owner_Content::apply( $trusted, $a, array( 'specials' => array() ) ), 'Trusted owners still edit only their own restaurant.' );
	}

	/**
	 * Owner dashboard photos wait for approval, then show in the owner's section (not the gallery); declined ones leave the owner's list.
	 */
	public function test_owner_dashboard_photos_wait() {
		$admin   = $this->login_admin();
		$plain   = self::factory()->user->create( array( 'role' => Roles::OWNER_ROLE ) );
		$trusted = self::factory()->user->create( array( 'role' => Roles::TRUSTED_OWNER_ROLE ) );
		$id      = $this->restaurant( 'Dashboard Cafe', array( Restaurant_Meta::CLAIMED_BY_USER_ID => $plain ) );
		$photos  = array();
		foreach ( array( 'kept', 'dropped' ) as $name ) {
			$photo = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id );
			wp_update_post(
				array(
					'ID'          => $photo,
					'post_author' => $plain,
				)
			);
			update_post_meta( $photo, '_wp_attachment_image_alt', 'Plate of ' . $name );
			$this->assertTrue( Photo_Submissions::hold_owner_photo( $photo, $plain ) );
			$photos[ $name ] = $photo;
		}
		Owner_Content::apply( $plain, $id, array( 'photos' => array_values( $photos ) ) );
		$this->assertSame( array(), Owner_Content::live_photos( Owner_Content::get( $id )['photos'] ) );
		$this->assertSame( 0, Restaurant_View::photo_id( $id ) );
		$this->assertSame( 'Plate of kept', get_post_meta( $photos['kept'], Photo_Submissions::DISH, true ) );

		$this->assertTrue( Photo_Submissions::moderate( $photos['kept'], 'approve', '', $admin ) );
		$this->assertTrue( Photo_Submissions::moderate( $photos['dropped'], 'reject', 'not_food', $admin ) );
		$this->assertSame( array( $photos['kept'] ), Owner_Content::get( $id )['photos'] );
		$this->assertSame( array( $photos['kept'] ), Owner_Content::live_photos( Owner_Content::get( $id )['photos'] ) );
		$this->assertSame( $photos['kept'], Restaurant_View::photo_id( $id ) );
		$this->assertSame( array(), Photo_Submissions::approved( $id ), 'Owner dashboard photos are not repeated in the gallery.' );

		$this->assertFalse( Photo_Submissions::hold_owner_photo( self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $id ), $trusted ) );
	}

	/**
	 * The daily job deletes originals after 30 days and unconfirmed uploads after a day.
	 */
	public function test_cleanup_job() {
		$this->login_admin();
		$id       = $this->restaurant( 'Kings' );
		$photo    = Photo_Submissions::submit( $id, self::fields(), $this->upload( 300, 200 ), 'k6' )['id'];
		$original = (string) get_post_meta( $photo, Photo_Submissions::ORIGINAL, true );
		wp_set_current_user( 0 );
		$stale = Photo_Submissions::submit( $id, self::fields(), $this->upload( 300, 200 ), 'k7' )['id'];
		wp_update_post(
			array(
				'ID'            => $stale,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
			)
		);

		$this->assertSame(
			array(
				'originals'   => 0,
				'unconfirmed' => 1,
			),
			Photo_Submissions::cleanup()
		);
		$this->assertFileExists( $original );
		$this->assertNull( get_post( $stale ) );

		$this->assertSame( 1, Photo_Submissions::cleanup( time() + 31 * DAY_IN_SECONDS )['originals'] );
		$this->assertFileDoesNotExist( $original );
		$this->assertFileExists( (string) get_attached_file( $photo ), 'The reduced copy stays.' );

		$run = Jobs::run( Jobs::PHOTO_CLEANUP );
		$this->assertTrue( $run['ok'] );
	}

	/**
	 * The agreement page names the company (site name until the formal name is set) and the footer links it.
	 */
	public function test_agreement_page() {
		$content = Legal_Pages::content( 'photo-upload-agreement.md' );
		$this->assertStringContainsString( '[guam_legal_name]', $content );
		$this->assertStringContainsString( 'transfer to [guam_legal_name] all of your rights', $content );
		$this->assertFalse( Agreement::has_legal_name() );
		$this->assertSame( get_bloginfo( 'name' ), do_shortcode( '[guam_legal_name]' ) );

		putenv( 'GUAM_RESTAURANTS_LEGAL_NAME=Island Eats LLC' );
		$this->assertSame( 'Island Eats LLC', Agreement::legal_name() );
		putenv( 'GUAM_RESTAURANTS_LEGAL_NAME' );

		Legal_Pages::ensure();
		$this->assertNotSame( '', Agreement::url() );
		$this->assertStringContainsString( 'Photo upload agreement', Blocks::policy_links() );
	}
}
