<?php
/**
 * The ad post type and its fields.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Ads;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Site\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * An ad is a private `gr_ad` post holding one paid placement: who bought
 * it, which product, the creative, when it runs, where it may show and its
 * priority (docs/monetization.md).
 *
 * The post type is not public, so ads never appear in searches, feeds,
 * sitemaps or restaurant queries. They are printed only by the slot block
 * (Slot), always labeled "Sponsored". Nothing here reads or writes a
 * restaurant's ratings, awards, meta or position in any list (Guard).
 *
 * An ad is served when the post is published, its status is "active",
 * today (Guam time) is within its dates and its creative passes
 * Guard::creative_problems().
 */
final class Ads {

	public const POST_TYPE = 'gr_ad';
	public const CAP       = 'gr_manage_ads';

	public const META_ADVERTISER = 'gr_ad_advertiser';
	public const META_RESTAURANT = 'gr_ad_restaurant_id';
	public const META_PRODUCT    = 'gr_ad_product';
	public const META_CREATIVE   = 'gr_ad_creative_type';
	public const META_IMAGE      = 'gr_ad_image_id';
	public const META_IMAGE_ALT  = 'gr_ad_image_alt';
	public const META_LINK       = 'gr_ad_link';
	public const META_HEADLINE   = 'gr_ad_headline';
	public const META_TEXT       = 'gr_ad_text';
	public const META_START      = 'gr_ad_start';
	public const META_END        = 'gr_ad_end';
	public const META_VILLAGES   = 'gr_ad_villages';
	public const META_MALLS      = 'gr_ad_malls';
	public const META_CUISINES   = 'gr_ad_cuisines';
	public const META_PAGE_TYPES = 'gr_ad_page_types';
	public const META_PRIORITY   = 'gr_ad_priority';
	public const META_STATUS     = 'gr_ad_status';

	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	public const CREATIVE_IMAGE = 'image';
	public const CREATIVE_TEXT  = 'text';

	public const MAX_PRIORITY = 10;
	public const MAX_HEADLINE = 80;
	public const MAX_TEXT     = 200;

	/**
	 * Target meta key => taxonomy.
	 */
	public const TARGET_TAXONOMIES = array(
		'villages' => Content_Types::TAX_VILLAGE,
		'malls'    => Content_Types::TAX_MALL,
		'cuisines' => Content_Types::TAX_CUISINE,
	);

	/**
	 * Products sold through slots (docs/monetization.md). Exclusive
	 * products show at most one ad per page. In a rotating product's slot
	 * the ads take turns in one spot (Slot, assets/ads/rotation.js).
	 *
	 * @return array<string, array{label: string, exclusive: bool, rotating?: bool}>
	 */
	public static function products(): array {
		return array(
			'featured_listing' => array(
				'label'     => __( 'Featured listing (shown as "Sponsored")', 'guam-restaurants-core' ),
				'exclusive' => false,
			),
			'category_sponsor' => array(
				'label'     => __( 'Category sponsor (one per page)', 'guam-restaurants-core' ),
				'exclusive' => true,
			),
			'banner'           => array(
				'label'     => __( 'Banner (rotating)', 'guam-restaurants-core' ),
				'exclusive' => false,
			),
			'leaderboard'      => array(
				'label'     => __( 'Homepage leaderboard (exclusive)', 'guam-restaurants-core' ),
				'exclusive' => true,
			),
			'picker_sponsor'   => array(
				'label'     => __( 'Pick for me sponsor (one above each pick)', 'guam-restaurants-core' ),
				'exclusive' => true,
			),
			'dish_sponsor'     => array(
				'label'     => __( 'Popular dishes sponsor (restaurant pages, sponsors take turns)', 'guam-restaurants-core' ),
				'exclusive' => false,
				'rotating'  => true,
			),
		);
	}

	/**
	 * Whether a product allows one ad per page.
	 *
	 * @param string $product Product key.
	 * @return bool
	 */
	public static function is_exclusive( string $product ): bool {
		return ! empty( self::products()[ $product ]['exclusive'] );
	}

	/**
	 * Whether a product's ads take turns in one spot.
	 *
	 * @param string $product Product key.
	 * @return bool
	 */
	public static function is_rotating( string $product ): bool {
		return ! empty( self::products()[ $product ]['rotating'] );
	}

	/**
	 * Page types a slot can sit on.
	 *
	 * @return array<string, string>
	 */
	public static function page_types(): array {
		return array(
			'home'       => __( 'Home page', 'guam-restaurants-core' ),
			'village'    => __( 'Village pages', 'guam-restaurants-core' ),
			'mall'       => __( 'Mall pages', 'guam-restaurants-core' ),
			'cuisine'    => __( 'Cuisine pages', 'guam-restaurants-core' ),
			'directory'  => __( 'Restaurant directory', 'guam-restaurants-core' ),
			'restaurant' => __( 'Restaurant pages', 'guam-restaurants-core' ),
			'picker'     => __( 'Pick for me (random place picker)', 'guam-restaurants-core' ),
			'other'      => __( 'Other pages', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Statuses.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_ACTIVE => __( 'Active', 'guam-restaurants-core' ),
			self::STATUS_PAUSED => __( 'Paused', 'guam-restaurants-core' ),
		);
	}

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_post_type' ) );
	}

	/**
	 * Registers the post type, managed only with CAP (administrators).
	 *
	 * @return void
	 */
	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Ads', 'guam-restaurants-core' ),
					'singular_name'      => __( 'Ad', 'guam-restaurants-core' ),
					'menu_name'          => __( 'Ads', 'guam-restaurants-core' ),
					'add_new_item'       => __( 'Add ad', 'guam-restaurants-core' ),
					'edit_item'          => __( 'Edit ad', 'guam-restaurants-core' ),
					'search_items'       => __( 'Search ads', 'guam-restaurants-core' ),
					'not_found'          => __( 'No ads found.', 'guam-restaurants-core' ),
					'not_found_in_trash' => __( 'No ads found in the trash.', 'guam-restaurants-core' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'menu_icon'           => 'dashicons-format-image',
				'menu_position'       => 27,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => true,
				'capabilities'        => array(
					'edit_post'              => 'edit_' . self::POST_TYPE,
					'read_post'              => 'read_' . self::POST_TYPE,
					'delete_post'            => 'delete_' . self::POST_TYPE,
					'edit_posts'             => self::CAP,
					'edit_others_posts'      => self::CAP,
					'edit_private_posts'     => self::CAP,
					'edit_published_posts'   => self::CAP,
					'publish_posts'          => self::CAP,
					'read_private_posts'     => self::CAP,
					'read'                   => self::CAP,
					'delete_posts'           => self::CAP,
					'delete_others_posts'    => self::CAP,
					'delete_private_posts'   => self::CAP,
					'delete_published_posts' => self::CAP,
					'create_posts'           => self::CAP,
				),
			)
		);
	}

	/**
	 * Cleans edit-screen input into storable values. Unknown products,
	 * terms, page types and restaurants are dropped.
	 *
	 * @param array<string, mixed> $input Raw (unslashed) input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$product = sanitize_key( (string) ( $input['product'] ?? '' ) );
		if ( ! isset( self::products()[ $product ] ) ) {
			$product = '';
		}
		$restaurant = absint( $input['restaurant_id'] ?? 0 );
		if ( $restaurant && Content_Types::POST_TYPE !== get_post_type( $restaurant ) ) {
			$restaurant = 0;
		}
		$image = absint( $input['image_id'] ?? 0 );
		if ( $image && ! wp_attachment_is_image( $image ) ) {
			$image = 0;
		}
		$targets = array();
		foreach ( self::TARGET_TAXONOMIES as $key => $taxonomy ) {
			$targets[ $key ] = self::term_ids( $input[ $key ] ?? array(), $taxonomy );
		}
		return array(
			'advertiser'    => self::clip( sanitize_text_field( (string) ( $input['advertiser'] ?? '' ) ), 150 ),
			'restaurant_id' => $restaurant,
			'product'       => $product,
			'creative_type' => self::CREATIVE_IMAGE === ( $input['creative_type'] ?? '' ) ? self::CREATIVE_IMAGE : self::CREATIVE_TEXT,
			'image_id'      => $image,
			'image_alt'     => self::clip( sanitize_text_field( (string) ( $input['image_alt'] ?? '' ) ), 150 ),
			'link'          => esc_url_raw( trim( (string) ( $input['link'] ?? '' ) ), array( 'http', 'https' ) ),
			'headline'      => self::clip( sanitize_text_field( (string) ( $input['headline'] ?? '' ) ), self::MAX_HEADLINE ),
			'text'          => self::clip( sanitize_textarea_field( (string) ( $input['text'] ?? '' ) ), self::MAX_TEXT ),
			'start'         => self::date( (string) ( $input['start'] ?? '' ) ),
			'end'           => self::date( (string) ( $input['end'] ?? '' ) ),
			'villages'      => $targets['villages'],
			'malls'         => $targets['malls'],
			'cuisines'      => $targets['cuisines'],
			'page_types'    => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['page_types'] ?? array() ) ), array_keys( self::page_types() ) ) ),
			'priority'      => max( 0, min( self::MAX_PRIORITY, (int) ( $input['priority'] ?? 0 ) ) ),
			'status'        => self::STATUS_ACTIVE === ( $input['status'] ?? '' ) ? self::STATUS_ACTIVE : self::STATUS_PAUSED,
		);
	}

	/**
	 * Checks sanitized fields. An ad with errors must not be active.
	 *
	 * @param array<string, mixed> $fields From sanitize().
	 * @return true|\WP_Error
	 */
	public static function validate( array $fields ) {
		$errors = new \WP_Error();
		if ( '' === $fields['advertiser'] ) {
			$errors->add( 'gr_ad_advertiser', __( 'Enter the advertiser.', 'guam-restaurants-core' ) );
		}
		if ( '' === $fields['product'] ) {
			$errors->add( 'gr_ad_product', __( 'Choose a product.', 'guam-restaurants-core' ) );
		}
		if ( self::CREATIVE_IMAGE === $fields['creative_type'] && ! $fields['image_id'] ) {
			$errors->add( 'gr_ad_image', __( 'An image ad needs an image.', 'guam-restaurants-core' ) );
		}
		if ( self::CREATIVE_TEXT === $fields['creative_type'] && '' === $fields['headline'] && ! $fields['restaurant_id'] ) {
			$errors->add( 'gr_ad_headline', __( 'A text ad needs a headline or a restaurant.', 'guam-restaurants-core' ) );
		}
		if ( '' === $fields['link'] && ! $fields['restaurant_id'] ) {
			$errors->add( 'gr_ad_link', __( 'Add a link, or choose the restaurant to link to.', 'guam-restaurants-core' ) );
		}
		if ( '' === $fields['start'] ) {
			$errors->add( 'gr_ad_start', __( 'Enter a start date.', 'guam-restaurants-core' ) );
		} elseif ( '' !== $fields['end'] && $fields['end'] < $fields['start'] ) {
			$errors->add( 'gr_ad_dates', __( 'The end date is before the start date.', 'guam-restaurants-core' ) );
		}
		// Words the editorial policy bans (Guard).
		foreach ( Guard::creative_problems( $fields ) as $problem ) {
			$errors->add( 'gr_ad_creative', $problem );
		}
		return $errors->has_errors() ? $errors : true;
	}

	/**
	 * Stores validated fields.
	 *
	 * @param int                  $ad_id  Ad.
	 * @param array<string, mixed> $fields From sanitize().
	 * @return void
	 */
	public static function save_fields( int $ad_id, array $fields ): void {
		$map = array(
			'advertiser'    => self::META_ADVERTISER,
			'restaurant_id' => self::META_RESTAURANT,
			'product'       => self::META_PRODUCT,
			'creative_type' => self::META_CREATIVE,
			'image_id'      => self::META_IMAGE,
			'image_alt'     => self::META_IMAGE_ALT,
			'link'          => self::META_LINK,
			'headline'      => self::META_HEADLINE,
			'text'          => self::META_TEXT,
			'start'         => self::META_START,
			'end'           => self::META_END,
			'villages'      => self::META_VILLAGES,
			'malls'         => self::META_MALLS,
			'cuisines'      => self::META_CUISINES,
			'page_types'    => self::META_PAGE_TYPES,
			'priority'      => self::META_PRIORITY,
			'status'        => self::META_STATUS,
		);
		foreach ( $map as $field => $key ) {
			if ( array_key_exists( $field, $fields ) ) {
				$value = $fields[ $field ];
				update_post_meta( $ad_id, $key, is_array( $value ) ? implode( ',', $value ) : $value );
			}
		}
		Cache::flush();
	}

	/**
	 * Creates a published ad from valid input. Used by the tests.
	 *
	 * @param string               $title Internal name.
	 * @param array<string, mixed> $input Fields as for sanitize().
	 * @return int|\WP_Error Ad ID.
	 */
	public static function create( string $title, array $input ) {
		$fields = self::sanitize( $input );
		$valid  = self::validate( $fields );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::save_fields( (int) $id, $fields );
		return (int) $id;
	}

	/**
	 * An ad as a normalized array, or null when it is not an ad.
	 *
	 * @param int $ad_id Ad.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $ad_id ): ?array {
		$post = get_post( $ad_id );
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$meta = static function ( string $key ) use ( $ad_id ): string {
			return (string) get_post_meta( $ad_id, $key, true );
		};
		$ids  = static function ( string $key ) use ( $meta ): array {
			return array_values( array_filter( array_map( 'intval', explode( ',', $meta( $key ) ) ) ) );
		};
		$list = static function ( string $key ) use ( $meta ): array {
			return array_values( array_filter( explode( ',', $meta( $key ) ) ) );
		};
		return array(
			'id'            => $ad_id,
			'title'         => $post->post_title,
			'post_status'   => $post->post_status,
			'advertiser'    => $meta( self::META_ADVERTISER ),
			'restaurant_id' => (int) $meta( self::META_RESTAURANT ),
			'product'       => $meta( self::META_PRODUCT ),
			'creative_type' => self::CREATIVE_IMAGE === $meta( self::META_CREATIVE ) ? self::CREATIVE_IMAGE : self::CREATIVE_TEXT,
			'image_id'      => (int) $meta( self::META_IMAGE ),
			'image_alt'     => $meta( self::META_IMAGE_ALT ),
			'link'          => $meta( self::META_LINK ),
			'headline'      => $meta( self::META_HEADLINE ),
			'text'          => $meta( self::META_TEXT ),
			'start'         => $meta( self::META_START ),
			'end'           => $meta( self::META_END ),
			'villages'      => $ids( self::META_VILLAGES ),
			'malls'         => $ids( self::META_MALLS ),
			'cuisines'      => $ids( self::META_CUISINES ),
			'page_types'    => $list( self::META_PAGE_TYPES ),
			'priority'      => (int) $meta( self::META_PRIORITY ),
			'status'        => self::STATUS_ACTIVE === $meta( self::META_STATUS ) ? self::STATUS_ACTIVE : self::STATUS_PAUSED,
		);
	}

	/**
	 * Published, active ads of one product, whatever their dates.
	 *
	 * @param string $product Product key.
	 * @return array<int, array<string, mixed>>
	 */
	public static function active( string $product ): array {
		$ids = Cache::remember(
			'ads-active',
			array( $product ),
			static function () use ( $product ) {
				return get_posts(
					array(
						'post_type'        => self::POST_TYPE,
						'post_status'      => 'publish',
						'fields'           => 'ids',
						'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ids only, cached.
						'orderby'          => 'ID',
						'order'            => 'ASC',
						'no_found_rows'    => true,
						'suppress_filters' => true,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- few ads, cached.
						'meta_query'       => array(
							'relation' => 'AND',
							array(
								'key'   => self::META_PRODUCT,
								'value' => $product,
							),
							array(
								'key'   => self::META_STATUS,
								'value' => self::STATUS_ACTIVE,
							),
						),
					)
				);
			}
		);
		$ads = array();
		foreach ( (array) $ids as $id ) {
			$ad = self::get( (int) $id );
			if ( null !== $ad ) {
				$ads[] = $ad;
			}
		}
		return $ads;
	}

	/**
	 * Term IDs of one taxonomy from input.
	 *
	 * @param mixed  $value    Array or comma list.
	 * @param string $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function term_ids( $value, string $taxonomy ): array {
		$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
		$out = array();
		foreach ( $ids as $id ) {
			$id   = absint( $id );
			$term = $id ? get_term( $id, $taxonomy ) : null;
			if ( $term instanceof \WP_Term ) {
				$out[] = $id;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out;
	}

	/**
	 * A valid Y-m-d date, or ''.
	 *
	 * @param string $value Input.
	 * @return string
	 */
	private static function date( string $value ): string {
		$value = trim( $value );
		$date  = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	/**
	 * Cuts a string to a length.
	 *
	 * @param string $value  Value.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private static function clip( string $value, int $length ): string {
		return mb_substr( $value, 0, $length );
	}
}
