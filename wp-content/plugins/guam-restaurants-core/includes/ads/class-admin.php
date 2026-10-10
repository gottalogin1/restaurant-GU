<?php
/**
 * Ad edit screen and list.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Content_Types;

defined( 'ABSPATH' ) || exit;

/**
 * Ads → list with product, advertiser, dates, status and whether the ad
 * is serving today; and the edit screen with every field. An ad that
 * fails validation (missing creative, bad dates, banned words) is saved
 * as paused, with the reasons shown, so it is never served by mistake.
 * Exclusive ads that overlap another one are reported on save.
 *
 * Everything here needs Ads::CAP, which only administrators have.
 */
final class Admin {

	public const SAVE_NONCE = 'gr_save_ad';
	public const NOTICE     = 'gr_ad_notice_';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		$type = Ads::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( self::class, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		add_action( "add_meta_boxes_{$type}", array( self::class, 'add_meta_boxes' ) );
		add_action( "save_post_{$type}", array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * List columns.
	 *
	 * @param array<string, string> $columns Default columns.
	 * @return array<string, string>
	 */
	public static function columns( $columns ): array {
		return array(
			'cb'            => $columns['cb'] ?? '<input type="checkbox" />',
			'title'         => __( 'Ad', 'guam-restaurants-core' ),
			'gr_advertiser' => __( 'Advertiser', 'guam-restaurants-core' ),
			'gr_product'    => __( 'Product', 'guam-restaurants-core' ),
			'gr_dates'      => __( 'Runs', 'guam-restaurants-core' ),
			'gr_priority'   => __( 'Priority', 'guam-restaurants-core' ),
			'gr_serving'    => __( 'Serving today', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Prints a column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Ad.
	 * @return void
	 */
	public static function column( $column, $post_id ): void {
		$ad = Ads::get( (int) $post_id );
		if ( null === $ad ) {
			return;
		}
		switch ( $column ) {
			case 'gr_advertiser':
				echo esc_html( $ad['advertiser'] );
				break;
			case 'gr_product':
				echo esc_html( Ads::products()[ $ad['product'] ]['label'] ?? '' );
				break;
			case 'gr_dates':
				echo esc_html( $ad['start'] . ' – ' . ( '' === $ad['end'] ? __( 'open-ended', 'guam-restaurants-core' ) : $ad['end'] ) );
				break;
			case 'gr_priority':
				echo esc_html( (string) $ad['priority'] );
				break;
			case 'gr_serving':
				echo Server::is_servable( $ad, Server::today() )
					? esc_html__( 'Yes', 'guam-restaurants-core' )
					: esc_html( Ads::statuses()[ $ad['status'] ] ?? '' ) . ( Ads::STATUS_ACTIVE === $ad['status'] ? ' ' . esc_html__( '(outside its dates)', 'guam-restaurants-core' ) : '' );
				break;
		}
	}

	/**
	 * Ads have no public view and no quick edit.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ): array {
		if ( $post instanceof \WP_Post && Ads::POST_TYPE === $post->post_type ) {
			unset( $actions['view'], $actions['inline hide-if-no-js'] );
		}
		return (array) $actions;
	}

	/**
	 * Media picker on the ad edit screen.
	 *
	 * @param string $hook Admin page.
	 * @return void
	 */
	public static function enqueue( $hook ): void {
		$screen = get_current_screen();
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || Ads::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script(
			'media-editor',
			"jQuery(function($){\$('#gr-ad-image-pick').on('click',function(e){e.preventDefault();var f=wp.media({library:{type:'image'},multiple:false});f.on('select',function(){var a=f.state().get('selection').first().toJSON();\$('#gr-ad-image').val(a.id);\$('#gr-ad-image-name').text(a.filename||a.title);});f.open();});});"
		);
	}

	/**
	 * Meta boxes.
	 *
	 * @return void
	 */
	public static function add_meta_boxes(): void {
		add_meta_box( 'gr-ad-details', __( 'Ad', 'guam-restaurants-core' ), array( self::class, 'details_box' ), Ads::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'gr-ad-targeting', __( 'Where it shows', 'guam-restaurants-core' ), array( self::class, 'targeting_box' ), Ads::POST_TYPE, 'normal', 'default' );
	}

	/**
	 * Advertiser, product, creative, dates, priority and status.
	 *
	 * @param \WP_Post $post Ad.
	 * @return void
	 */
	public static function details_box( $post ): void {
		$ad = Ads::get( (int) $post->ID ) ?? array();
		wp_nonce_field( self::SAVE_NONCE, '_gr_ad_nonce' );

		echo '<p>' . esc_html__( 'Every ad is shown with the label "Sponsored", in its own box outside the regular results. Ads never change ratings, awards or the order of restaurants. Do not use "Featured", "Top pick", "Recommended" or awards in the creative.', 'guam-restaurants-core' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::text_row( 'advertiser', __( 'Advertiser', 'guam-restaurants-core' ), (string) ( $ad['advertiser'] ?? '' ), 150 );

		$restaurants = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 2000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin picker.
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$options     = array( '0' => __( 'None', 'guam-restaurants-core' ) );
		foreach ( $restaurants as $id ) {
			$options[ (string) $id ] = get_the_title( $id );
		}
		self::select_row( 'restaurant_id', __( 'Restaurant', 'guam-restaurants-core' ), $options, (string) ( $ad['restaurant_id'] ?? 0 ), __( 'Optional. Used as the headline and link when those are empty.', 'guam-restaurants-core' ) );

		$products = array( '' => __( 'Choose…', 'guam-restaurants-core' ) );
		foreach ( Ads::products() as $key => $product ) {
			$products[ $key ] = $product['label'];
		}
		self::select_row( 'product', __( 'Product', 'guam-restaurants-core' ), $products, (string) ( $ad['product'] ?? '' ) );

		self::select_row(
			'creative_type',
			__( 'Creative', 'guam-restaurants-core' ),
			array(
				Ads::CREATIVE_TEXT  => __( 'Text', 'guam-restaurants-core' ),
				Ads::CREATIVE_IMAGE => __( 'Image and link', 'guam-restaurants-core' ),
			),
			(string) ( $ad['creative_type'] ?? Ads::CREATIVE_TEXT )
		);

		$image = (int) ( $ad['image_id'] ?? 0 );
		printf(
			'<tr><th scope="row"><label for="gr-ad-image">%1$s</label></th><td><input type="hidden" id="gr-ad-image" name="gr_ad[image_id]" value="%2$s"><button type="button" class="button" id="gr-ad-image-pick">%3$s</button> <span id="gr-ad-image-name">%4$s</span></td></tr>',
			esc_html__( 'Image', 'guam-restaurants-core' ),
			$image ? esc_attr( (string) $image ) : '',
			esc_html__( 'Choose image', 'guam-restaurants-core' ),
			$image ? esc_html( (string) wp_basename( (string) get_attached_file( $image ) ) ) : ''
		);
		self::text_row( 'image_alt', __( 'Image description (alt text)', 'guam-restaurants-core' ), (string) ( $ad['image_alt'] ?? '' ), 150 );
		self::text_row( 'link', __( 'Link', 'guam-restaurants-core' ), (string) ( $ad['link'] ?? '' ), 2048, 'url' );
		self::text_row( 'headline', __( 'Headline', 'guam-restaurants-core' ), (string) ( $ad['headline'] ?? '' ), Ads::MAX_HEADLINE );
		printf(
			'<tr><th scope="row"><label for="gr-ad-text">%1$s</label></th><td><textarea class="large-text" rows="3" id="gr-ad-text" name="gr_ad[text]" maxlength="%2$d">%3$s</textarea></td></tr>',
			esc_html__( 'Text', 'guam-restaurants-core' ),
			(int) Ads::MAX_TEXT,
			esc_textarea( (string) ( $ad['text'] ?? '' ) )
		);
		self::text_row( 'start', __( 'Start date', 'guam-restaurants-core' ), (string) ( $ad['start'] ?? '' ), 10, 'date' );
		self::text_row( 'end', __( 'End date', 'guam-restaurants-core' ), (string) ( $ad['end'] ?? '' ), 10, 'date', __( 'Inclusive, Guam time. Leave empty for no end date.', 'guam-restaurants-core' ) );
		printf(
			'<tr><th scope="row"><label for="gr-ad-priority">%1$s</label></th><td><input type="number" min="0" max="%2$d" class="small-text" id="gr-ad-priority" name="gr_ad[priority]" value="%3$d"><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Priority', 'guam-restaurants-core' ),
			(int) Ads::MAX_PRIORITY,
			(int) ( $ad['priority'] ?? 0 ),
			esc_html__( 'Higher shows first. Ads with the same priority rotate: the one with the fewest impressions today goes first.', 'guam-restaurants-core' )
		);
		self::select_row( 'status', __( 'Status', 'guam-restaurants-core' ), Ads::statuses(), (string) ( $ad['status'] ?? Ads::STATUS_PAUSED ) );
		echo '</tbody></table>';
	}

	/**
	 * Targeting.
	 *
	 * @param \WP_Post $post Ad.
	 * @return void
	 */
	public static function targeting_box( $post ): void {
		$ad = Ads::get( (int) $post->ID ) ?? array();
		echo '<p>' . esc_html__( 'Leave a list empty to show the ad anywhere its slot appears. Choosing several values in one list matches any of them; the lists together must all match.', 'guam-restaurants-core' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::multi_row( 'page_types', __( 'Page types', 'guam-restaurants-core' ), Ads::page_types(), (array) ( $ad['page_types'] ?? array() ) );
		$labels = array(
			'villages' => __( 'Villages', 'guam-restaurants-core' ),
			'malls'    => __( 'Malls', 'guam-restaurants-core' ),
			'cuisines' => __( 'Cuisines', 'guam-restaurants-core' ),
		);
		foreach ( Ads::TARGET_TAXONOMIES as $key => $taxonomy ) {
			$terms   = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			$options = array();
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$options[ (string) $term->term_id ] = $term->name;
			}
			self::multi_row( $key, $labels[ $key ], $options, array_map( 'strval', (array) ( $ad[ $key ] ?? array() ) ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Saves the edit screen.
	 *
	 * @param int      $ad_id Ad.
	 * @param \WP_Post $post  Post.
	 * @return void
	 */
	public static function save( $ad_id, $post ): void {
		unset( $post );
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $ad_id ) ) {
			return;
		}
		if ( ! isset( $_POST['_gr_ad_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_gr_ad_nonce'] ) ), self::SAVE_NONCE ) ) {
			return;
		}
		$input    = isset( $_POST['gr_ad'] ) && is_array( $_POST['gr_ad'] ) ? wp_unslash( $_POST['gr_ad'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Ads::sanitize() cleans each field.
		$messages = self::save_ad( (int) $ad_id, $input );
		if ( $messages ) {
			set_transient( self::NOTICE . get_current_user_id(), $messages, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Sanitizes, validates and stores an edit. Used by save() and the tests.
	 *
	 * @param int                  $ad_id Ad.
	 * @param array<string, mixed> $input Fields from the edit screen.
	 * @return string[] Messages for the editor; empty when all is well.
	 */
	public static function save_ad( int $ad_id, array $input ): array {
		if ( ! current_user_can( 'edit_post', $ad_id ) ) {
			return array( __( 'You are not allowed to edit this ad.', 'guam-restaurants-core' ) );
		}
		$fields   = Ads::sanitize( $input );
		$valid    = Ads::validate( $fields );
		$messages = array();
		if ( is_wp_error( $valid ) ) {
			$messages = $valid->get_error_messages();
			if ( Ads::STATUS_ACTIVE === $fields['status'] ) {
				$fields['status'] = Ads::STATUS_PAUSED;
				$messages[]       = __( 'The ad was saved as paused until these are fixed.', 'guam-restaurants-core' );
			}
		}
		Ads::save_fields( $ad_id, $fields );

		$ad = Ads::get( $ad_id );
		if ( null !== $ad && 'publish' === $ad['post_status'] ) {
			foreach ( Guard::conflicts( $ad ) as $other ) {
				/* translators: 1: other ad's title, 2: its ID. */
				$messages[] = sprintf( __( 'This exclusive ad can land on the same page and day as "%1$s" (ID %2$d). Only one is shown per page: the higher priority, then the one with fewer impressions today.', 'guam-restaurants-core' ), get_the_title( $other ), $other );
			}
		}
		return $messages;
	}

	/**
	 * Shows messages from the last save.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$key      = self::NOTICE . get_current_user_id();
		$messages = get_transient( $key );
		if ( ! is_array( $messages ) || ! $messages ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-warning"><p>%1$s</p><ul><li>%2$s</li></ul></div>',
			esc_html__( 'Check this ad:', 'guam-restaurants-core' ),
			implode( '</li><li>', array_map( 'esc_html', $messages ) )
		);
	}

	/**
	 * A text input row.
	 *
	 * @param string $field       Field.
	 * @param string $label       Label.
	 * @param string $value       Value.
	 * @param int    $max         Max length.
	 * @param string $type        Input type.
	 * @param string $description Help text.
	 * @return void
	 */
	private static function text_row( string $field, string $label, string $value, int $max, string $type = 'text', string $description = '' ): void {
		printf(
			'<tr><th scope="row"><label for="gr-ad-%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="gr-ad-%1$s" name="gr_ad[%1$s]" value="%4$s" maxlength="%5$d">%6$s</td></tr>',
			esc_attr( $field ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			(int) $max,
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>'
		);
	}

	/**
	 * A select row.
	 *
	 * @param string                $field       Field.
	 * @param string                $label       Label.
	 * @param array<string, string> $options     Value => label.
	 * @param string                $value       Selected.
	 * @param string                $description Help text.
	 * @return void
	 */
	private static function select_row( string $field, string $label, array $options, string $value, string $description = '' ): void {
		printf( '<tr><th scope="row"><label for="gr-ad-%1$s">%2$s</label></th><td><select id="gr-ad-%1$s" name="gr_ad[%1$s]">', esc_attr( $field ), esc_html( $label ) );
		foreach ( $options as $key => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $key ), selected( (string) $key, $value, false ), esc_html( $text ) );
		}
		echo '</select>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * A multiple select row.
	 *
	 * @param string                $field   Field.
	 * @param string                $label   Label.
	 * @param array<string, string> $options Value => label.
	 * @param string[]              $values  Selected.
	 * @return void
	 */
	private static function multi_row( string $field, string $label, array $options, array $values ): void {
		printf( '<tr><th scope="row"><label for="gr-ad-%1$s">%2$s</label></th><td><select multiple size="6" id="gr-ad-%1$s" name="gr_ad[%1$s][]" style="min-width:16rem">', esc_attr( $field ), esc_html( $label ) );
		foreach ( $options as $key => $text ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( (string) $key ), in_array( (string) $key, $values, true ) ? ' selected' : '', esc_html( $text ) );
		}
		echo '</select></td></tr>';
	}
}
