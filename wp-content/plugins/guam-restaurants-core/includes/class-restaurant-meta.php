<?php
/**
 * Restaurant post meta, registered with schemas and REST exposure.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and sanitizes the restaurant's post meta (design/content-model.md §3).
 *
 * Fields marked private appear only in the REST `edit` context, which needs
 * edit rights on the post; the public `view` context never includes them.
 *
 * WordPress REST meta cannot be nullable, so unset coordinates read as 0 over
 * REST. Guam is nowhere near 0,0, so 0 means "not set".
 */
final class Restaurant_Meta {

	public const ADDRESS            = 'gr_address';
	public const LATITUDE           = 'gr_latitude';
	public const LONGITUDE          = 'gr_longitude';
	public const COORD_SOURCE       = 'gr_coord_source';
	public const PHONE              = 'gr_phone';
	public const WEBSITE            = 'gr_website';
	public const EMAIL              = 'gr_email';
	public const FACEBOOK           = 'gr_facebook';
	public const INSTAGRAM          = 'gr_instagram';
	public const HOURS              = 'gr_hours';
	public const STATUS             = 'gr_status';
	public const OPENED_ON          = 'gr_opened_on';
	public const CLOSED_ON          = 'gr_closed_on';
	public const CLAIMED_BY_USER_ID = 'gr_claimed_by_user_id';
	public const GOOGLE_PLACE_ID    = 'gr_google_place_id';
	public const YELP_ID            = 'gr_yelp_id';
	public const FOURSQUARE_ID      = 'gr_foursquare_id';
	public const OSM_ID             = 'gr_osm_id';
	public const OSM_CUISINE        = 'gr_osm_cuisine';
	public const FORMER_NAMES       = 'gr_former_names';

	/**
	 * Match confidence (0 to 1) for each rating source's stored ID.
	 */
	public const GOOGLE_MATCH_CONFIDENCE     = 'gr_google_match_confidence';
	public const YELP_MATCH_CONFIDENCE       = 'gr_yelp_match_confidence';
	public const FOURSQUARE_MATCH_CONFIDENCE = 'gr_foursquare_match_confidence';

	/**
	 * Year, year-month, or full date. Many opening and closing dates are only
	 * known to the year.
	 */
	public const PARTIAL_DATE_PATTERN = '^\d{4}(-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?)?$';

	public const OSM_ID_PATTERN = '^(node|way|relation)/[1-9]\d*$';

	/**
	 * Registers every meta key on the restaurant post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::fields() as $key => $field ) {
			$schema = $field['schema'];
			if ( ! empty( $field['private'] ) ) {
				$schema['context'] = array( 'edit' );
			}

			$args = array(
				'type'              => $field['type'],
				'description'       => $field['description'],
				'single'            => true,
				'sanitize_callback' => $field['sanitize'],
				'auth_callback'     => array( self::class, 'can_edit' ),
				'show_in_rest'      => array( 'schema' => $schema ),
			);
			// Coordinates have no default: a registered default must match the type, and 0 is a real place.
			if ( null !== $field['default'] ) {
				$args['default'] = $field['default'];
			}

			register_post_meta( Content_Types::POST_TYPE, $key, $args );
		}
	}

	/**
	 * Match confidence meta key for a rating source.
	 *
	 * @param string $source google, yelp or foursquare.
	 * @return string
	 */
	public static function match_confidence_key( string $source ): string {
		return 'gr_' . sanitize_key( $source ) . '_match_confidence';
	}

	/**
	 * Only users who can edit the restaurant may change its meta.
	 *
	 * @param bool   $allowed  Whether the user can edit the meta (unused).
	 * @param string $meta_key Meta key.
	 * @param int    $post_id  Post ID.
	 * @return bool
	 */
	public static function can_edit( $allowed, $meta_key, $post_id ): bool {
		return current_user_can( 'edit_post', (int) $post_id );
	}

	/**
	 * Field definitions.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			self::ADDRESS                     => array(
				'type'        => 'string',
				'description' => __( 'Street address line; the village comes from the village taxonomy.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_address' ),
				'schema'      => array( 'maxLength' => 255 ),
			),
			self::LATITUDE                    => array(
				'type'        => 'number',
				'description' => __( 'Latitude (WGS84).', 'guam-restaurants-core' ),
				'default'     => null,
				'sanitize'    => array( self::class, 'sanitize_latitude' ),
				'schema'      => array(
					'minimum' => -90,
					'maximum' => 90,
				),
			),
			self::LONGITUDE                   => array(
				'type'        => 'number',
				'description' => __( 'Longitude (WGS84).', 'guam-restaurants-core' ),
				'default'     => null,
				'sanitize'    => array( self::class, 'sanitize_longitude' ),
				'schema'      => array(
					'minimum' => -180,
					'maximum' => 180,
				),
			),
			self::COORD_SOURCE                => array(
				'type'        => 'string',
				'description' => __( 'Where the coordinates came from.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_coord_source' ),
				'private'     => true,
				'schema'      => array( 'enum' => array_merge( array( '' ), Vocabulary::COORD_SOURCES ) ),
			),
			self::PHONE                       => array(
				'type'        => 'string',
				'description' => __( 'Phone number, stored as E.164 (+1671XXXXXXX).', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_phone' ),
				'schema'      => array(),
			),
			self::WEBSITE                     => array(
				'type'        => 'string',
				'description' => __( 'Website URL.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => 'esc_url_raw',
				'schema'      => array(),
			),
			self::EMAIL                       => array(
				'type'        => 'string',
				'description' => __( 'Business email address, for administrators following up with the restaurant; not shown on the site.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => 'sanitize_email',
				'private'     => true,
				'schema'      => array(),
			),
			self::FACEBOOK                    => array(
				'type'        => 'string',
				'description' => __( 'Facebook page URL.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_social_url' ),
				'schema'      => array(),
			),
			self::INSTAGRAM                   => array(
				'type'        => 'string',
				'description' => __( 'Instagram profile URL.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_social_url' ),
				'schema'      => array(),
			),
			self::HOURS                       => array(
				'type'        => 'object',
				'description' => __( 'Structured weekly opening hours.', 'guam-restaurants-core' ),
				'default'     => array(),
				'sanitize'    => array( self::class, 'sanitize_hours' ),
				'schema'      => self::hours_schema(),
			),
			self::STATUS                      => array(
				'type'        => 'string',
				'description' => __( 'Business status.', 'guam-restaurants-core' ),
				'default'     => 'unknown',
				'sanitize'    => array( self::class, 'sanitize_status' ),
				'schema'      => array( 'enum' => Vocabulary::RESTAURANT_STATUSES ),
			),
			self::OPENED_ON                   => array(
				'type'        => 'string',
				'description' => __( 'Opening date: YYYY, YYYY-MM or YYYY-MM-DD.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_partial_date' ),
				'schema'      => array( 'pattern' => '^$|' . self::PARTIAL_DATE_PATTERN ),
			),
			self::CLOSED_ON                   => array(
				'type'        => 'string',
				'description' => __( 'Closing date: YYYY, YYYY-MM or YYYY-MM-DD.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_partial_date' ),
				'schema'      => array( 'pattern' => '^$|' . self::PARTIAL_DATE_PATTERN ),
			),
			self::CLAIMED_BY_USER_ID          => array(
				'type'        => 'integer',
				'description' => __( 'User who claimed this listing; 0 when unclaimed.', 'guam-restaurants-core' ),
				'default'     => 0,
				'sanitize'    => 'absint',
				'private'     => true,
				'schema'      => array( 'minimum' => 0 ),
			),
			self::GOOGLE_PLACE_ID             => array(
				'type'        => 'string',
				'description' => __( 'Google place ID, the only Google value that may be stored (docs/data-sources.md §2).', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_identifier' ),
				'private'     => true,
				'schema'      => array(),
			),
			self::YELP_ID                     => array(
				'type'        => 'string',
				'description' => __( 'Yelp business ID, for back-end matching only (Yelp API Terms §5(a)).', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_identifier' ),
				'private'     => true,
				'schema'      => array(),
			),
			self::FOURSQUARE_ID               => array(
				'type'        => 'string',
				'description' => __( 'Foursquare place ID (shared by Open Source Places and the Places API).', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_identifier' ),
				'schema'      => array(),
			),
			self::OSM_ID                      => array(
				'type'        => 'string',
				'description' => __( 'OpenStreetMap element, for example node/123.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_osm_id' ),
				'schema'      => array( 'pattern' => '^$|' . self::OSM_ID_PATTERN ),
			),
			self::OSM_CUISINE                 => array(
				'type'        => 'string',
				'description' => __( 'OpenStreetMap cuisine tag as of the last import, unmapped (for checking the cuisine map; not shown on the site).', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => 'sanitize_text_field',
				'private'     => true,
				'schema'      => array( 'maxLength' => 255 ),
			),
			self::FORMER_NAMES                => array(
				'type'        => 'string',
				'description' => __( 'Earlier names of the restaurant, one per line. Award imports match these as well as the current name.', 'guam-restaurants-core' ),
				'default'     => '',
				'sanitize'    => array( self::class, 'sanitize_former_names' ),
				'schema'      => array( 'maxLength' => 2000 ),
			),
			self::GOOGLE_MATCH_CONFIDENCE     => array(
				'type'        => 'number',
				'description' => __( 'Confidence (0 to 1) of the Google match, from name similarity and distance.', 'guam-restaurants-core' ),
				'default'     => 0,
				'sanitize'    => array( self::class, 'sanitize_confidence' ),
				'private'     => true,
				'schema'      => array(
					'minimum' => 0,
					'maximum' => 1,
				),
			),
			self::YELP_MATCH_CONFIDENCE       => array(
				'type'        => 'number',
				'description' => __( 'Confidence (0 to 1) of the Yelp match, from name similarity and distance.', 'guam-restaurants-core' ),
				'default'     => 0,
				'sanitize'    => array( self::class, 'sanitize_confidence' ),
				'private'     => true,
				'schema'      => array(
					'minimum' => 0,
					'maximum' => 1,
				),
			),
			self::FOURSQUARE_MATCH_CONFIDENCE => array(
				'type'        => 'number',
				'description' => __( 'Confidence (0 to 1) of the Foursquare match, from name similarity and distance.', 'guam-restaurants-core' ),
				'default'     => 0,
				'sanitize'    => array( self::class, 'sanitize_confidence' ),
				'private'     => true,
				'schema'      => array(
					'minimum' => 0,
					'maximum' => 1,
				),
			),
		);
	}

	/**
	 * JSON schema for the hours object.
	 *
	 * @return array<string, mixed>
	 */
	public static function hours_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'weekly'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'day', 'open', 'close' ),
						'properties'           => array(
							'day'   => array(
								'type' => 'string',
								'enum' => Vocabulary::DAYS,
							),
							'open'  => array(
								'type'    => 'string',
								'pattern' => '^([01]\d|2[0-3]):[0-5]\d$',
							),
							// A close time earlier than the open time means the interval runs past midnight.
							'close' => array(
								'type'    => 'string',
								'pattern' => '^(([01]\d|2[0-3]):[0-5]\d|24:00)$',
							),
						),
					),
				),
				'always_open' => array( 'type' => 'boolean' ),
				'note'        => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'osm_raw'     => array( 'type' => 'string' ),
				'source'      => array(
					'type' => 'string',
					'enum' => Vocabulary::HOURS_SOURCES,
				),
				'verified_on' => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
			),
		);
	}

	/**
	 * Sanitizes the street address.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_address( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, 255 );
	}

	/**
	 * Sanitizes a latitude; out-of-range or non-numeric values become null.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function sanitize_latitude( $value ): ?float {
		return self::sanitize_coordinate( $value, 90.0 );
	}

	/**
	 * Sanitizes a longitude; out-of-range or non-numeric values become null.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	public static function sanitize_longitude( $value ): ?float {
		return self::sanitize_coordinate( $value, 180.0 );
	}

	/**
	 * Shared coordinate sanitizer.
	 *
	 * @param mixed $value Raw value.
	 * @param float $limit Absolute maximum.
	 * @return float|null
	 */
	private static function sanitize_coordinate( $value, float $limit ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( ! is_finite( $number ) || abs( $number ) > $limit ) {
			return null;
		}
		return round( $number, 7 );
	}

	/**
	 * Sanitizes the coordinate source.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_coord_source( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Vocabulary::COORD_SOURCES, true ) ? $value : '';
	}

	/**
	 * Normalizes a Guam phone number to E.164. Unrecognized numbers become ''.
	 *
	 * Accepts 7-digit local numbers, 10-digit numbers with the 671 area code,
	 * and 11-digit numbers with the country code 1.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_phone( $value ): string {
		$digits = (string) preg_replace( '/\D+/', '', (string) $value );

		if ( 7 === strlen( $digits ) ) {
			$digits = '1671' . $digits;
		} elseif ( 10 === strlen( $digits ) ) {
			$digits = '1' . $digits;
		}

		return 1 === preg_match( '/^1671\d{7}$/', $digits ) ? '+' . $digits : '';
	}

	/**
	 * Sanitizes the hours object against the schema; invalid parts are dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, mixed>
	 */
	public static function sanitize_hours( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		if ( isset( $value['weekly'] ) && is_array( $value['weekly'] ) ) {
			$clean['weekly'] = array();
			foreach ( $value['weekly'] as $interval ) {
				if ( ! is_array( $interval ) || ! isset( $interval['day'], $interval['open'], $interval['close'] ) ) {
					continue;
				}
				$day   = (string) $interval['day'];
				$open  = (string) $interval['open'];
				$close = (string) $interval['close'];
				if ( in_array( $day, Vocabulary::DAYS, true )
					&& preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $open )
					&& preg_match( '/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/', $close ) ) {
					$clean['weekly'][] = array(
						'day'   => $day,
						'open'  => $open,
						'close' => $close,
					);
				}
			}
		}

		if ( isset( $value['always_open'] ) ) {
			$clean['always_open'] = (bool) $value['always_open'];
		}
		if ( isset( $value['note'] ) ) {
			$clean['note'] = mb_substr( sanitize_text_field( (string) $value['note'] ), 0, 200 );
		}
		if ( isset( $value['osm_raw'] ) ) {
			$clean['osm_raw'] = sanitize_text_field( (string) $value['osm_raw'] );
		}
		if ( isset( $value['source'] ) && in_array( $value['source'], Vocabulary::HOURS_SOURCES, true ) ) {
			$clean['source'] = $value['source'];
		}
		if ( isset( $value['verified_on'] ) && '' !== self::sanitize_partial_date( $value['verified_on'] ) && 10 === strlen( (string) $value['verified_on'] ) ) {
			$clean['verified_on'] = (string) $value['verified_on'];
		}

		return $clean;
	}

	/**
	 * Sanitizes former names: one plain-text name per line, blank and repeated lines dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_former_names( $value ): string {
		$names = array();
		foreach ( preg_split( '/\R/', (string) $value ) as $line ) {
			$line = mb_substr( sanitize_text_field( $line ), 0, 191 );
			if ( '' !== $line ) {
				$names[ strtolower( $line ) ] = $line;
			}
		}
		return mb_substr( implode( "\n", $names ), 0, 2000 );
	}

	/**
	 * Former names as a list.
	 *
	 * @param int $post_id Restaurant.
	 * @return string[]
	 */
	public static function former_names( int $post_id ): array {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) get_post_meta( $post_id, self::FORMER_NAMES, true ) ) ) ) );
	}

	/**
	 * Sanitizes the business status; unknown values become 'unknown'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_status( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, Vocabulary::RESTAURANT_STATUSES, true ) ? $value : 'unknown';
	}

	/**
	 * Sanitizes a partial date (YYYY, YYYY-MM or YYYY-MM-DD); anything else becomes ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_partial_date( $value ): string {
		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/' . self::PARTIAL_DATE_PATTERN . '/', $value ) ) {
			return '';
		}
		if ( 10 === strlen( $value ) ) {
			list( $year, $month, $day ) = array_map( 'intval', explode( '-', $value ) );
			if ( ! checkdate( $month, $day, $year ) ) {
				return '';
			}
		}
		return $value;
	}

	/**
	 * Sanitizes an external identifier (letters, digits, - _ : .).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_identifier( $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-:.]/', '', (string) $value );
	}

	/**
	 * Sanitizes a Facebook or Instagram URL: https only, on that network's
	 * own domain; anything else is dropped.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_social_url( $value ): string {
		$url = esc_url_raw( trim( (string) $value ), array( 'https' ) );
		return null === self::social_network( $url ) ? '' : $url;
	}

	/**
	 * The social network a URL belongs to (facebook or instagram), or null.
	 * Used to keep social pages out of the website field: a business whose
	 * only web presence is a Facebook page has no website of its own.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	public static function social_network( string $url ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^(www|m|web|business)\./', '', $host );
		if ( in_array( $host, array( 'facebook.com', 'fb.com', 'fb.me' ), true ) ) {
			return 'facebook';
		}
		if ( in_array( $host, array( 'instagram.com', 'instagr.am' ), true ) ) {
			return 'instagram';
		}
		return null;
	}

	/**
	 * Sanitizes a match confidence to 0..1 with three decimals.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_confidence( $value ): float {
		return is_numeric( $value ) ? round( max( 0.0, min( 1.0, (float) $value ) ), 3 ) : 0.0;
	}

	/**
	 * Sanitizes an OSM element id such as node/123; anything else becomes ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_osm_id( $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return 1 === preg_match( '#' . self::OSM_ID_PATTERN . '#', $value ) ? $value : '';
	}
}
