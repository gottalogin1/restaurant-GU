<?php
/**
 * Highlights section on restaurant pages, and review structured data.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Reviews\Display as Reviews_Display;
use GuamRestaurants\Core\Reviews\Visitor_Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * A "Highlights" section on each published restaurant page:
 *
 * 1. "From the owner" (the latest approved owner quote and its signature
 *    dishes) and "Editor's note" (the newest approved notes), each in its
 *    own labeled box, server-rendered.
 * 2. "User reviews": the visitor reviews section (Reviews\Display), which
 *    loads in the browser and shows owner responses under each review.
 *
 * The section is appended to the restaurant's content; place it elsewhere
 * with the `guam-restaurants/highlights` block or `[guam_restaurant_highlights]`
 * (either turns the automatic section off on that page), or turn it off
 * with the `guam_restaurants_highlights_auto_section` filter. When a page
 * already has the visitor reviews block, the section leaves reviews out so
 * they are never shown twice.
 *
 * Structured data: schema.org Review and AggregateRating are given for
 * approved user reviews only. Owner quotes and responses are the business
 * speaking about itself (self-serving under Google's review snippet
 * guidelines) and editor's notes carry no rating, so they get none; reviews
 * whose writers disclosed a connection to the restaurant are left out too.
 */
final class Display {

	public const SHORTCODE    = 'guam_restaurant_highlights';
	public const STYLE        = 'guam-restaurants-highlights';
	public const BLOCK_FOLDER = 'blocks/highlights';

	/**
	 * Reviews given in structured data, matching the first page the block shows.
	 */
	public const STRUCTURED_REVIEWS = 10;

	/**
	 * Hooks registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets_and_block' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'append_section' ), 21 );
	}

	/**
	 * Registers the stylesheet and block.
	 *
	 * @return void
	 */
	public static function register_assets_and_block(): void {
		wp_register_style( self::STYLE, plugins_url( 'assets/highlights/highlights.css', GUAM_RESTAURANTS_CORE_FILE ), array(), GUAM_RESTAURANTS_CORE_VERSION );
		register_block_type(
			GUAM_RESTAURANTS_CORE_DIR . self::BLOCK_FOLDER,
			array( 'render_callback' => array( self::class, 'render_block' ) )
		);
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @return string
	 */
	public static function render_block( $attributes ): string {
		return self::render(
			(int) ( $attributes['restaurantId'] ?? 0 ),
			(bool) ( $attributes['showReviews'] ?? true ),
			(bool) ( $attributes['showForm'] ?? true ),
			(bool) ( $attributes['showTrend'] ?? true )
		);
	}

	/**
	 * Shortcode: [guam_restaurant_highlights id="123" reviews="yes" form="yes" trend="yes"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'reviews' => 'yes',
				'form'    => 'yes',
				'trend'   => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		$yes  = static function ( $value ) {
			return ! in_array( strtolower( (string) $value ), array( 'no', '0', 'false' ), true );
		};
		return self::render( (int) $atts['id'], $yes( $atts['reviews'] ), $yes( $atts['form'] ), $yes( $atts['trend'] ) );
	}

	/**
	 * Appends the section to the main restaurant's content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function append_section( $content ): string {
		$content = (string) $content;
		if ( ! is_singular( Content_Types::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || get_the_ID() !== get_queried_object_id() ) {
			return $content;
		}
		if ( has_shortcode( $content, self::SHORTCODE ) || str_contains( $content, '<section class="gr-highlights"' ) ) {
			return $content;
		}
		/**
		 * Filters whether the Highlights section is appended to restaurant pages.
		 *
		 * @param bool $append        Whether to append.
		 * @param int  $restaurant_id Restaurant.
		 */
		if ( ! apply_filters( 'guam_restaurants_highlights_auto_section', true, (int) get_the_ID() ) ) {
			return $content;
		}
		$has_reviews = str_contains( $content, 'data-gr-visitor-reviews=' ) || has_shortcode( $content, Reviews_Display::SHORTCODE );
		return $content . self::render( (int) get_the_ID(), ! $has_reviews, true, true );
	}

	/**
	 * The section's markup, or '' when there is nothing to show.
	 *
	 * @param int  $restaurant_id Restaurant, or 0 for the current post.
	 * @param bool $reviews       Include the user reviews section.
	 * @param bool $form          Include the review form.
	 * @param bool $trend         Include the trend chart.
	 * @return string
	 */
	public static function render( int $restaurant_id, bool $reviews = true, bool $form = true, bool $trend = true ): string {
		if ( $restaurant_id <= 0 ) {
			$restaurant_id = (int) get_the_ID();
		}
		if ( ! Highlights::is_published_restaurant( $restaurant_id ) ) {
			return '';
		}

		$first_party = '';
		$quote       = Highlights::approved( $restaurant_id, Policy::OWNER_QUOTE, 1 );
		if ( $quote ) {
			$first_party .= self::owner_quote( $quote[0] );
		}
		foreach ( Highlights::approved( $restaurant_id, Policy::EDITOR_NOTE, Highlights::EDITOR_NOTES_SHOWN ) as $note ) {
			$first_party .= self::editor_note( $note );
		}
		$review_section = $reviews ? Reviews_Display::render( $restaurant_id, $form, $trend, 3 ) : '';
		if ( '' === $first_party && '' === $review_section ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );

		return sprintf(
			'<section class="gr-highlights" aria-labelledby="gr-highlights-title-%1$d"><h2 class="gr-highlights__title" id="gr-highlights-title-%1$d">%2$s</h2>%3$s%4$s<p class="gr-highlights__policy"><a href="%5$s">%6$s</a></p></section>',
			$restaurant_id,
			esc_html__( 'Highlights', 'guam-restaurants-core' ),
			'' === $first_party ? '' : '<div class="gr-highlights__first-party">' . $first_party . '</div>',
			$review_section,
			esc_url( Policy_Page::url() ),
			esc_html__( 'How we choose and moderate highlights', 'guam-restaurants-core' )
		);
	}

	/**
	 * "From the owner" box.
	 *
	 * @param array<string, mixed> $row Owner quote.
	 * @return string
	 */
	private static function owner_quote( array $row ): string {
		$dishes = '' === (string) $row['dishes'] ? array() : explode( "\n", (string) $row['dishes'] );
		$list   = '';
		if ( $dishes ) {
			$list = '<h4 class="gr-highlight__subtitle">' . esc_html__( 'Signature dishes', 'guam-restaurants-core' ) . '</h4><ul class="gr-highlight__dishes">';
			foreach ( $dishes as $dish ) {
				$list .= '<li>' . esc_html( $dish ) . '</li>';
			}
			$list .= '</ul>';
		}
		return sprintf(
			'<article class="gr-highlight gr-highlight--owner"><h3 class="gr-highlight__label">%1$s</h3><blockquote class="gr-highlight__text"><p>%2$s</p></blockquote>%3$s<p class="gr-highlight__byline">%4$s</p></article>',
			esc_html( Policy::type_label( Policy::OWNER_QUOTE ) ),
			nl2br( esc_html( (string) $row['body'] ) ),
			$list,
			/* translators: %s: name of the owner or manager. */
			esc_html( sprintf( __( '%s, for the restaurant', 'guam-restaurants-core' ), (string) $row['author_name'] ) )
		);
	}

	/**
	 * "Editor's note" box.
	 *
	 * @param array<string, mixed> $row Editor's note.
	 * @return string
	 */
	private static function editor_note( array $row ): string {
		$disclosure = '' === (string) $row['disclosure'] ? '' : '<p class="gr-highlight__disclosure">' . esc_html( (string) $row['disclosure'] ) . '</p>';
		$date       = (string) ( $row['moderated_at'] ?? $row['created_at'] );
		return sprintf(
			'<article class="gr-highlight gr-highlight--editor"><h3 class="gr-highlight__label">%1$s</h3><div class="gr-highlight__text"><p>%2$s</p></div>%3$s<p class="gr-highlight__byline">%4$s <time datetime="%5$s">%6$s</time></p></article>',
			esc_html( Policy::type_label( Policy::EDITOR_NOTE ) ),
			nl2br( esc_html( (string) $row['body'] ) ),
			$disclosure,
			/* translators: %s: staff member's name. */
			esc_html( sprintf( __( '%s, staff writer ·', 'guam-restaurants-core' ), (string) $row['author_name'] ) ),
			esc_attr( mysql2date( 'Y-m-d', $date ) ),
			esc_html( mysql2date( (string) get_option( 'date_format' ), $date ) )
		);
	}

	/**
	 * Review and AggregateRating properties for the restaurant's
	 * structured data, or null when there is nothing to give.
	 *
	 * Only approved user reviews whose writers disclosed no connection to
	 * the restaurant are given. The aggregate is given only when it equals
	 * the average shown on the page, which counts every approved review, so
	 * it is left out while any approved review discloses a connection.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<string, mixed>|null
	 */
	public static function structured_data( int $restaurant_id ): ?array {
		if ( ! Highlights::is_published_restaurant( $restaurant_id ) ) {
			return null;
		}
		$data    = array();
		$reviews = array();
		foreach ( Visitor_Reviews::approved( $restaurant_id, self::STRUCTURED_REVIEWS ) as $review ) {
			if ( '' !== $review['disclosure'] ) {
				continue;
			}
			$reviews[] = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name'  => $review['author'],
				),
				'datePublished' => $review['date'],
				'reviewBody'    => $review['text'],
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => $review['rating'],
					'bestRating'  => 5,
					'worstRating' => 1,
				),
			);
		}
		if ( $reviews ) {
			$data['review'] = $reviews;
		}

		$summary = Visitor_Reviews::summary( $restaurant_id );
		if ( $summary['count'] > 0 && 0 === self::connected_count( $restaurant_id ) ) {
			$data['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $summary['average'],
				'reviewCount' => $summary['count'],
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}
		return $data ? $data : null;
	}

	/**
	 * Approved reviews whose writers disclosed a connection.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return int
	 */
	private static function connected_count( int $restaurant_id ): int {
		global $wpdb;
		$table = Schema::table( Schema::HIGHLIGHTS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s AND disclosure IS NOT NULL AND disclosure <> ''", $restaurant_id, Policy::USER_REVIEW, 'approved' ) ); // phpcs:ignore WordPress.DB
	}
}
