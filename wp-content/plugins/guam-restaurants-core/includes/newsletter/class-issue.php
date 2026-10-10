<?php
/**
 * Content of one weekly newsletter issue.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Newsletter;

use GuamRestaurants\Core\Analytics\Listing_Views;
use GuamRestaurants\Core\Awards\Policy as Awards_Policy;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Monitoring\Queries;
use GuamRestaurants\Core\Site\Clock;
use GuamRestaurants\Core\Site\Restaurant_View;

defined( 'ABSPATH' ) || exit;

/**
 * Gathers the last DAYS days: new restaurants and reopenings, closures,
 * newly recorded awards, and the most viewed restaurants (aggregate counts
 * only, Analytics\Listing_Views). Every entry is a plain fact from our own
 * records with a link to the listing; closures are a plain status, as on
 * the site. The sponsor, if any, comes from Newsletter::sponsor() and is
 * kept apart from this content.
 */
final class Issue {

	public const DAYS      = 7;
	public const PER_LIST  = 10;
	public const TOP_COUNT = 5;

	/**
	 * Builds the issue.
	 *
	 * @param int|null $now Unix time; defaults to Clock::now().
	 * @return array{start: string, end: string, openings: array<int, array<string, string>>, closures: array<int, array<string, string>>, awards: array<int, array<string, string>>, top: array<int, array<string, string>>, sponsor: array{name: string, text: string, url: string}|null}
	 */
	public static function build( ?int $now = null ): array {
		$now   = $now ?? Clock::now();
		$end   = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( Clock::zone() );
		$start = $end->modify( '-' . ( self::DAYS - 1 ) . ' days' );

		$openings = array();
		foreach ( array_merge( Queries::recent_openings( self::DAYS, self::PER_LIST ), Queries::recent_reopenings( self::DAYS, self::PER_LIST ) ) as $row ) {
			if ( '' === $row['url'] || isset( $openings[ $row['restaurant_id'] ] ) ) {
				continue;
			}
			$openings[ $row['restaurant_id'] ] = self::entry( (int) $row['restaurant_id'], isset( $row['reopened_at'] ) ? __( 'Reopened', 'guam-restaurants-core' ) : '' );
		}

		$closures = array();
		foreach ( Queries::recent_closures( self::DAYS, self::PER_LIST ) as $row ) {
			if ( '' !== $row['url'] ) {
				$closures[] = self::entry( (int) $row['restaurant_id'], '' );
			}
		}

		$top = array();
		foreach ( Listing_Views::top( self::DAYS, self::TOP_COUNT ) as $row ) {
			$top[] = self::entry( (int) $row['restaurant_id'], '' );
		}

		return array(
			'start'    => $start->format( 'Y-m-d' ),
			'end'      => $end->format( 'Y-m-d' ),
			'openings' => array_slice( array_values( $openings ), 0, self::PER_LIST ),
			'closures' => $closures,
			'awards'   => self::new_awards( $now ),
			'top'      => $top,
			'sponsor'  => Newsletter::sponsor(),
		);
	}

	/**
	 * Whether the issue has anything of its own to say (a sponsor alone is not enough).
	 *
	 * @param array<string, mixed> $issue Output of build().
	 * @return bool
	 */
	public static function has_content( array $issue ): bool {
		return (bool) ( $issue['openings'] || $issue['closures'] || $issue['awards'] || $issue['top'] );
	}

	/**
	 * Awards recorded in the last DAYS days for published restaurants, as
	 * citations with their source.
	 *
	 * @param int $now Unix time.
	 * @return array<int, array<string, string>>
	 */
	private static function new_awards( int $now ): array {
		global $wpdb;
		$table = Schema::table( Schema::AWARDS );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT a.* FROM {$table} a INNER JOIN {$wpdb->posts} p ON p.ID = a.restaurant_id AND p.post_type = %s AND p.post_status = 'publish' WHERE a.created_at >= %s ORDER BY a.year DESC, a.created_at DESC, a.id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Content_Types::POST_TYPE,
				gmdate( 'Y-m-d H:i:s', $now - self::DAYS * DAY_IN_SECONDS ),
				self::PER_LIST
			),
			ARRAY_A
		);
		$out   = array();
		foreach ( $rows ? $rows : array() as $row ) {
			$out[] = self::entry( (int) $row['restaurant_id'], Awards_Policy::citation( $row ) ) + array(
				'source_url'   => (string) $row['source_url'],
				'source_label' => Awards_Policy::source_label( $row ),
			);
		}
		return $out;
	}

	/**
	 * One listed restaurant.
	 *
	 * @param int    $id   Restaurant.
	 * @param string $note Short note after the name ('' for none).
	 * @return array{name: string, url: string, place: string, cuisine: string, note: string}
	 */
	private static function entry( int $id, string $note ): array {
		return array(
			'name'    => html_entity_decode( (string) get_post_field( 'post_title', $id, 'raw' ), ENT_QUOTES, 'UTF-8' ),
			'url'     => (string) get_permalink( $id ),
			'place'   => implode( ', ', array_filter( array( Restaurant_View::term_names( $id, Content_Types::TAX_MALL ), Restaurant_View::term_names( $id, Content_Types::TAX_VILLAGE ) ) ) ),
			'cuisine' => Restaurant_View::term_names( $id, Content_Types::TAX_CUISINE ),
			'note'    => $note,
		);
	}
}
