<?php
/**
 * What a claimed owner may change, and how it is shown.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Owners;

use GuamRestaurants\Core\Ads\Guard;
use GuamRestaurants\Core\Ads\Server;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Highlights\Roles;
use GuamRestaurants\Core\Monitoring\Change_Tracker;
use GuamRestaurants\Core\Restaurant_Meta;
use GuamRestaurants\Core\Site\Assets;
use GuamRestaurants\Core\Vocabulary;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * A claimed owner (Roles::can_speak_for) may change exactly these fields:
 *
 * - `hours`: the weekly hours (gr_hours, source "owner", so imports never
 *   overwrite them; Osm\Importer).
 * - `menu_links`: up to five labeled links (gr_menu_links).
 * - `specials`: up to five specials with an optional end date (gr_specials).
 * - `photos`: up to eight images they uploaded (gr_owner_photos).
 *
 * Owners never get `edit_post` on a restaurant, so the post editor, REST
 * API and meta APIs stay closed to them. apply() is the only way in and
 * ignores every other key: ratings, awards, status, opening and closing
 * dates, the change log and reviews cannot be reached from here. Each
 * change goes live at once and is written to the change log as "owner".
 *
 * Specials and menu link labels follow the ad rules (Ads\Guard): no
 * "Featured", "Top pick", "Recommended", "editor's choice" or award claims.
 * The block labels everything "From the owner".
 */
final class Owner_Content {

	public const MENU_LINKS = 'gr_menu_links';
	public const SPECIALS   = 'gr_specials';
	public const PHOTOS     = 'gr_owner_photos';

	public const FIELDS = array( 'hours', 'menu_links', 'specials', 'photos' );

	public const MAX_LINKS    = 5;
	public const MAX_SPECIALS = 5;
	public const MAX_PHOTOS   = 8;
	public const MAX_TITLE    = 80;
	public const MAX_DETAILS  = 200;

	public const BLOCK_FOLDER = 'blocks/owner-content';

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_block' ) );
	}

	/**
	 * Registers the block.
	 *
	 * @return void
	 */
	public static function register_block(): void {
		register_block_type( GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER, array( 'render_callback' => array( self::class, 'render_block' ) ) );
	}

	/**
	 * Applies an owner's edit. Keys other than FIELDS are ignored and
	 * listed in `ignored`; a field left out of $input is left alone.
	 *
	 * @param int                  $user_id       Owner.
	 * @param int                  $restaurant_id Restaurant.
	 * @param array<string, mixed> $input         Field => value.
	 * @return array{changed: string[], ignored: string[]}|WP_Error
	 */
	public static function apply( int $user_id, int $restaurant_id, array $input ) {
		if ( Content_Types::POST_TYPE !== get_post_type( $restaurant_id ) || ! Roles::can_speak_for( $user_id, $restaurant_id ) ) {
			return new WP_Error( 'gr_owner_forbidden', __( 'Only the verified owner of this restaurant can change it.', 'guam-restaurants-core' ), array( 'status' => 403 ) );
		}
		$ignored = array_values( array_diff( array_keys( $input ), self::FIELDS ) );
		$values  = array();
		$errors  = new WP_Error();

		if ( array_key_exists( 'hours', $input ) ) {
			$hours = self::hours_from_input( (array) $input['hours'] );
			if ( is_wp_error( $hours ) ) {
				$errors->merge_from( $hours );
			} else {
				$values[ Restaurant_Meta::HOURS ] = $hours;
			}
		}
		if ( array_key_exists( 'menu_links', $input ) ) {
			$links = self::menu_links_from_input( (array) $input['menu_links'] );
			if ( is_wp_error( $links ) ) {
				$errors->merge_from( $links );
			} else {
				$values[ self::MENU_LINKS ] = $links;
			}
		}
		if ( array_key_exists( 'specials', $input ) ) {
			$specials = self::specials_from_input( (array) $input['specials'] );
			if ( is_wp_error( $specials ) ) {
				$errors->merge_from( $specials );
			} else {
				$values[ self::SPECIALS ] = $specials;
			}
		}
		if ( array_key_exists( 'photos', $input ) ) {
			$values[ self::PHOTOS ] = self::photos_from_input( (array) $input['photos'], $restaurant_id, $user_id );
		}
		if ( $errors->has_errors() ) {
			$errors->add_data( array( 'status' => 400 ) );
			return $errors;
		}

		$changed = array();
		Change_Tracker::suppress(
			static function () use ( $values, $restaurant_id, &$changed ) {
				foreach ( $values as $key => $value ) {
					$old = get_post_meta( $restaurant_id, $key, true );
					if ( $old === $value ) {
						continue;
					}
					update_post_meta( $restaurant_id, $key, $value );
					Change_Log::record( $restaurant_id, $key, '' === $old ? null : $old, $value, 'owner' );
					$changed[] = $key;
				}
			}
		);
		if ( $changed ) {
			clean_post_cache( $restaurant_id );
		}
		return array(
			'changed' => $changed,
			'ignored' => $ignored,
		);
	}

	/**
	 * Builds gr_hours from a form: day => "11:00-14:00, 17:00-21:00" (empty
	 * for closed), plus `always_open` and `note`.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function hours_from_input( array $input ) {
		$weekly = array();
		$bad    = array();
		foreach ( Vocabulary::DAYS as $day ) {
			$text = trim( (string) ( $input[ $day ] ?? '' ) );
			if ( '' === $text || in_array( strtolower( $text ), array( 'closed', '-' ), true ) ) {
				continue;
			}
			foreach ( array_map( 'trim', explode( ',', $text ) ) as $range ) {
				if ( ! preg_match( '/^(\d{1,2}):([0-5]\d)\s*[-–]\s*(\d{1,2}):([0-5]\d)$/u', $range, $m ) || (int) $m[1] > 23 || (int) $m[3] > 24 || ( 24 === (int) $m[3] && '00' !== $m[4] ) ) {
					$bad[] = $day;
					continue;
				}
				$weekly[] = array(
					'day'   => $day,
					'open'  => sprintf( '%02d:%s', (int) $m[1], $m[2] ),
					'close' => sprintf( '%02d:%s', (int) $m[3], $m[4] ),
				);
			}
		}
		if ( $bad ) {
			return new WP_Error( 'gr_owner_hours', __( 'Write hours like 11:00-14:00, 17:00-21:00 (24-hour clock), or leave a day empty for closed.', 'guam-restaurants-core' ) );
		}
		$hours = array(
			'weekly'      => $weekly,
			'always_open' => ! empty( $input['always_open'] ),
			'source'      => 'owner',
			'verified_on' => Server::today(),
		);
		$note  = mb_substr( sanitize_text_field( (string) ( $input['note'] ?? '' ) ), 0, 200 );
		if ( '' !== $note ) {
			$hours['note'] = $note;
		}
		return Restaurant_Meta::sanitize_hours( $hours );
	}

	/**
	 * Menu links from a form: rows of `label` and `url`; empty rows dropped.
	 *
	 * @param array<int, mixed> $rows Rows.
	 * @return array<int, array{label: string, url: string}>|WP_Error
	 */
	public static function menu_links_from_input( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$label = mb_substr( sanitize_text_field( (string) ( $row['label'] ?? '' ) ), 0, self::MAX_TITLE );
			$url   = esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'label' => '' === $label ? __( 'Menu', 'guam-restaurants-core' ) : $label,
				'url'   => $url,
			);
		}
		if ( count( $out ) > self::MAX_LINKS ) {
			/* translators: %d: maximum. */
			return new WP_Error( 'gr_owner_links', sprintf( __( 'Add at most %d menu links.', 'guam-restaurants-core' ), self::MAX_LINKS ) );
		}
		$problems = Guard::creative_problems( array( 'headline' => implode( ' ', wp_list_pluck( $out, 'label' ) ) ) );
		return $problems ? new WP_Error( 'gr_owner_words', implode( ' ', $problems ) ) : $out;
	}

	/**
	 * Specials from a form: rows of `title`, `details` and `ends` (Y-m-d or
	 * empty); rows without a title are dropped.
	 *
	 * @param array<int, mixed> $rows Rows.
	 * @return array<int, array{title: string, details: string, ends: string}>|WP_Error
	 */
	public static function specials_from_input( array $rows ) {
		$out    = array();
		$errors = new WP_Error();
		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$title = mb_substr( sanitize_text_field( (string) ( $row['title'] ?? '' ) ), 0, self::MAX_TITLE );
			if ( '' === $title ) {
				continue;
			}
			$details = mb_substr( sanitize_textarea_field( (string) ( $row['details'] ?? '' ) ), 0, self::MAX_DETAILS );
			$ends    = trim( (string) ( $row['ends'] ?? '' ) );
			$date    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ends );
			if ( '' !== $ends && ( ! $date || $date->format( 'Y-m-d' ) !== $ends ) ) {
				$errors->add( 'gr_owner_special_date', __( 'Use a valid end date for each special, or leave it empty.', 'guam-restaurants-core' ) );
				continue;
			}
			foreach ( Guard::creative_problems(
				array(
					'headline' => $title,
					'text'     => $details,
				)
			) as $problem ) {
				$errors->add( 'gr_owner_words', $problem );
			}
			$out[] = array(
				'title'   => $title,
				'details' => $details,
				'ends'    => $ends,
			);
		}
		if ( count( $out ) > self::MAX_SPECIALS ) {
			/* translators: %d: maximum. */
			$errors->add( 'gr_owner_specials', sprintf( __( 'Add at most %d specials.', 'guam-restaurants-core' ), self::MAX_SPECIALS ) );
		}
		return $errors->has_errors() ? $errors : $out;
	}

	/**
	 * Photo IDs to keep: images attached to this restaurant and uploaded by
	 * this owner, at most MAX_PHOTOS. Anything else is dropped.
	 *
	 * @param array<int, mixed> $ids           Attachment IDs.
	 * @param int               $restaurant_id Restaurant.
	 * @param int               $user_id       Owner.
	 * @return int[]
	 */
	public static function photos_from_input( array $ids, int $restaurant_id, int $user_id ): array {
		$out = array();
		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && 'attachment' === $post->post_type && wp_attachment_is_image( $id ) && (int) $post->post_parent === $restaurant_id && (int) $post->post_author === $user_id ) {
				$out[] = $id;
			}
		}
		return array_slice( $out, 0, self::MAX_PHOTOS );
	}

	/**
	 * The owner fields for a restaurant.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array{menu_links: array<int, array<string, string>>, specials: array<int, array<string, string>>, photos: int[]}
	 */
	public static function get( int $restaurant_id ): array {
		$list = static function ( $value ): array {
			return is_array( $value ) ? array_values( $value ) : array();
		};
		return array(
			'menu_links' => $list( get_post_meta( $restaurant_id, self::MENU_LINKS, true ) ),
			'specials'   => $list( get_post_meta( $restaurant_id, self::SPECIALS, true ) ),
			'photos'     => array_map( 'intval', $list( get_post_meta( $restaurant_id, self::PHOTOS, true ) ) ),
		);
	}

	/**
	 * Specials not past their end date (Guam).
	 *
	 * @param int         $restaurant_id Restaurant.
	 * @param string|null $today         Y-m-d.
	 * @return array<int, array<string, string>>
	 */
	public static function current_specials( int $restaurant_id, ?string $today = null ): array {
		$today = $today ?? Server::today();
		return array_values(
			array_filter(
				self::get( $restaurant_id )['specials'],
				static function ( array $special ) use ( $today ): bool {
					return '' === ( $special['ends'] ?? '' ) || $special['ends'] >= $today;
				}
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block.
	 * @return string
	 */
	public static function render_block( $attributes, $content = '', $block = null ): string {
		$id = $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_queried_object_id();
		if ( Content_Types::POST_TYPE !== get_post_type( $id ) || (int) get_post_meta( $id, Restaurant_Meta::CLAIMED_BY_USER_ID, true ) <= 0 ) {
			return '';
		}
		$fields   = self::get( $id );
		$specials = self::current_specials( $id );
		$photos   = array_values( array_filter( $fields['photos'], 'wp_attachment_is_image' ) );
		if ( ! $fields['menu_links'] && ! $specials && ! $photos ) {
			return '';
		}
		Assets::enqueue();
		$html = '<h2 class="gr-section-title">' . esc_html__( 'From the owner', 'guam-restaurants-core' ) . '</h2>';
		if ( $fields['menu_links'] ) {
			$html .= '<ul class="gr-owner__menus">';
			foreach ( $fields['menu_links'] as $link ) {
				$html .= sprintf( '<li><a href="%1$s" rel="noopener nofollow">%2$s</a></li>', esc_url( $link['url'] ), esc_html( $link['label'] ) );
			}
			$html .= '</ul>';
		}
		if ( $specials ) {
			$html .= '<h3>' . esc_html__( 'Specials', 'guam-restaurants-core' ) . '</h3><ul class="gr-owner__specials">';
			foreach ( $specials as $special ) {
				$ends = '' === $special['ends'] ? '' : ' <span class="gr-owner__ends">' . esc_html(
					/* translators: %s: date. */
					sprintf( __( 'Until %s', 'guam-restaurants-core' ), wp_date( get_option( 'date_format' ), (int) strtotime( $special['ends'] . ' 12:00:00' ) ) )
				) . '</span>';
				$html .= '<li><strong>' . esc_html( $special['title'] ) . '</strong>' . $ends . ( '' === $special['details'] ? '' : '<br>' . esc_html( $special['details'] ) ) . '</li>';
			}
			$html .= '</ul>';
		}
		if ( $photos ) {
			$html .= '<div class="gr-owner__photos">';
			foreach ( $photos as $photo ) {
				$html .= wp_get_attachment_image( $photo, 'medium', false, array( 'loading' => 'lazy' ) );
			}
			$html .= '</div>';
		}
		$html .= '<p class="gr-owner__note">' . esc_html__( 'Added by the restaurant\'s verified owner. It does not change ratings, awards or where the restaurant appears in lists.', 'guam-restaurants-core' ) . '</p>';
		return sprintf( '<section %1$s>%2$s</section>', get_block_wrapper_attributes( array( 'class' => 'gr-owner' ) ), $html );
	}
}
