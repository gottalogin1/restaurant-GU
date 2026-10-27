<?php
/**
 * Email HTML for a newsletter issue.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Newsletter;

use GuamRestaurants\Core\Awards\Policy as Awards_Policy;
use GuamRestaurants\Core\Site\Fresh_Pages;
use GuamRestaurants\Core\Site\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Issue::build() into a subject, preview text and simple email HTML
 * (one table, inline styles), ready for SendFox or any other sender.
 *
 * The sponsor block sits after the issue's own content, inside its own
 * bordered box labeled "Sponsored", and its link uses rel="sponsored"
 * (CLAUDE.md rule 5; docs/monetization.md, product 8). With no sponsor, a
 * plain house line invites businesses to sponsor an issue; it is not
 * labeled "Sponsored" because nobody paid for it. Unsubscribe links and
 * the postal address are added by the sending service.
 */
final class Renderer {

	/**
	 * Subject line.
	 *
	 * @param array<string, mixed> $issue Issue.
	 * @return string
	 */
	public static function subject( array $issue ): string {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: 1: site name, 2: date of the issue. */
		return sprintf( __( '%1$s: this week in Guam restaurants (%2$s)', 'guam-restaurants-core' ), $site, self::date( (string) $issue['end'] ) );
	}

	/**
	 * Preview text shown after the subject in inboxes.
	 *
	 * @param array<string, mixed> $issue Issue.
	 * @return string
	 */
	public static function preview_text( array $issue ): string {
		$parts = array();
		if ( $issue['openings'] ) {
			/* translators: %d: number of restaurants. */
			$parts[] = sprintf( _n( '%d new or reopened restaurant', '%d new or reopened restaurants', count( $issue['openings'] ), 'guam-restaurants-core' ), count( $issue['openings'] ) );
		}
		if ( $issue['awards'] ) {
			/* translators: %d: number of award results. */
			$parts[] = sprintf( _n( '%d new award result', '%d new award results', count( $issue['awards'] ), 'guam-restaurants-core' ), count( $issue['awards'] ) );
		}
		if ( $issue['top'] ) {
			$parts[] = __( 'the most viewed places this week', 'guam-restaurants-core' );
		}
		return $parts ? ucfirst( wp_sprintf_l( '%l', $parts ) ) . '.' : __( 'What changed this week in the directory.', 'guam-restaurants-core' );
	}

	/**
	 * Email HTML.
	 *
	 * @param array<string, mixed> $issue Issue.
	 * @return string
	 */
	public static function html( array $issue ): string {
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$home  = home_url( '/' );
		$intro = sprintf(
			/* translators: 1: start date, 2: end date. */
			__( 'What changed in the directory from %1$s to %2$s.', 'guam-restaurants-core' ),
			self::date( (string) $issue['start'] ),
			self::date( (string) $issue['end'] )
		);

		$body  = self::row( '<h1 style="margin:0;font-size:24px;line-height:1.3"><a href="' . esc_url( $home ) . '" style="color:#0b6e8a;text-decoration:none">' . esc_html( $site ) . '</a></h1><p style="margin:8px 0 0;color:#4a4a4a">' . esc_html( $intro ) . '</p>' );
		$body .= self::section( __( 'New and reopened', 'guam-restaurants-core' ), $issue['openings'] );
		$body .= self::section( __( 'Most viewed this week', 'guam-restaurants-core' ), $issue['top'] );
		$body .= self::awards( $issue['awards'] );
		$body .= self::section( __( 'Closed', 'guam-restaurants-core' ), $issue['closures'] );
		if ( ! Issue::has_content( $issue ) ) {
			$body .= self::row( '<p style="margin:0">' . esc_html__( 'No openings, closures or new award results were recorded this week.', 'guam-restaurants-core' ) . '</p>' );
		}
		$body .= self::fresh_links();
		$body .= self::sponsor( $issue['sponsor'] );

		$corrections = Pages::url( 'correction' );
		$footer      = esc_html__( 'Facts come from our directory records. Spotted a mistake?', 'guam-restaurants-core' );
		if ( '' !== $corrections ) {
			$footer .= ' <a href="' . esc_url( $corrections ) . '" style="color:#0b6e8a">' . esc_html__( 'Suggest a correction', 'guam-restaurants-core' ) . '</a>.';
		}
		$body .= self::row( '<p style="margin:0;font-size:13px;color:#4a4a4a">' . $footer . '</p>' );

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( self::subject( $issue ) ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f1ec">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1ec"><tr><td align="center" style="padding:16px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1b1b1b">'
			. $body
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Links to This week on Guam and New on Guam.
	 *
	 * @return string
	 */
	private static function fresh_links(): string {
		return self::row(
			'<p style="margin:0">' . sprintf(
				/* translators: 1: link to the This week on Guam page, 2: link to the New on Guam page. */
				esc_html__( 'Plans for the week ahead: %1$s lists night markets, food truck stops, village fiestas and holiday hours. %2$s lists new places month by month.', 'guam-restaurants-core' ),
				'<a href="' . esc_url( Fresh_Pages::url( 'week' ) ) . '" style="color:#0b6e8a">' . esc_html__( 'This week on Guam', 'guam-restaurants-core' ) . '</a>',
				'<a href="' . esc_url( Fresh_Pages::url( 'new' ) ) . '" style="color:#0b6e8a">' . esc_html__( 'New on Guam', 'guam-restaurants-core' ) . '</a>'
			) . '</p>'
		);
	}

	/**
	 * A list of restaurants under a heading; '' when empty.
	 *
	 * @param string                            $heading Heading.
	 * @param array<int, array<string, string>> $entries Entries from Issue.
	 * @return string
	 */
	private static function section( string $heading, array $entries ): string {
		if ( ! $entries ) {
			return '';
		}
		$items = '';
		foreach ( $entries as $entry ) {
			$items .= '<li style="margin:0 0 8px">' . self::restaurant( $entry ) . '</li>';
		}
		return self::row( self::heading( $heading ) . '<ul style="margin:0;padding-left:20px">' . $items . '</ul>' );
	}

	/**
	 * New award results, each with its source.
	 *
	 * @param array<int, array<string, string>> $entries Award entries.
	 * @return string
	 */
	private static function awards( array $entries ): string {
		if ( ! $entries ) {
			return '';
		}
		$items = '';
		foreach ( $entries as $entry ) {
			$items .= '<li style="margin:0 0 8px"><a href="' . esc_url( $entry['url'] ) . '" style="color:#0b6e8a;font-weight:bold">' . esc_html( $entry['name'] ) . '</a><br>'
				. esc_html( $entry['note'] ) . ' (<a href="' . esc_url( $entry['source_url'] ) . '" style="color:#4a4a4a">' . esc_html( $entry['source_label'] ) . '</a>)</li>';
		}
		return self::row( self::heading( __( 'New award results', 'guam-restaurants-core' ) ) . '<ul style="margin:0;padding-left:20px">' . $items . '</ul><p style="margin:8px 0 0;font-size:13px;color:#4a4a4a">' . esc_html( Awards_Policy::notice() ) . '</p>' );
	}

	/**
	 * The sponsor box, or the house invitation when there is no sponsor.
	 *
	 * @param array{name: string, text: string, url: string}|null $sponsor Sponsor.
	 * @return string
	 */
	private static function sponsor( ?array $sponsor ): string {
		if ( null === $sponsor ) {
			$advertise = Pages::url( 'advertise' );
			if ( '' === $advertise ) {
				return '';
			}
			return self::row( '<p style="margin:0;font-size:13px;color:#4a4a4a">' . esc_html__( 'Want to reach people choosing where to eat on Guam?', 'guam-restaurants-core' ) . ' <a href="' . esc_url( $advertise ) . '" style="color:#0b6e8a">' . esc_html__( 'Sponsor an issue', 'guam-restaurants-core' ) . '</a>.</p>' );
		}
		$name = esc_html( $sponsor['name'] );
		if ( '' !== $sponsor['url'] ) {
			$name = '<a href="' . esc_url( $sponsor['url'] ) . '" rel="sponsored" style="color:#1b1b1b">' . $name . '</a>';
		}
		return self::row(
			'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #c9c3b8;background:#fbfaf7"><tr><td style="padding:16px">'
			. '<p style="margin:0 0 4px;font-size:12px;font-weight:bold;letter-spacing:.05em;text-transform:uppercase;color:#4a4a4a">' . esc_html__( 'Sponsored', 'guam-restaurants-core' ) . '</p>'
			. '<p style="margin:0 0 4px;font-weight:bold">' . $name . '</p>'
			. '<p style="margin:0">' . esc_html( $sponsor['text'] ) . '</p>'
			. '</td></tr></table>'
		);
	}

	/**
	 * Restaurant line: linked name, then cuisine, place and note.
	 *
	 * @param array<string, string> $entry Entry.
	 * @return string
	 */
	private static function restaurant( array $entry ): string {
		$details = array_filter( array( $entry['cuisine'], $entry['place'], $entry['note'] ) );
		return '<a href="' . esc_url( $entry['url'] ) . '" style="color:#0b6e8a;font-weight:bold">' . esc_html( $entry['name'] ) . '</a>'
			. ( $details ? '<br><span style="color:#4a4a4a">' . esc_html( implode( ' · ', $details ) ) . '</span>' : '' );
	}

	/**
	 * Section heading.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function heading( string $text ): string {
		return '<h2 style="margin:0 0 8px;font-size:18px">' . esc_html( $text ) . '</h2>';
	}

	/**
	 * Table row with padding.
	 *
	 * @param string $html Cell HTML.
	 * @return string
	 */
	private static function row( string $html ): string {
		return '<tr><td style="padding:16px 24px">' . $html . '</td></tr>';
	}

	/**
	 * A date for readers, for example "October 10, 2026".
	 *
	 * @param string $ymd Y-m-d.
	 * @return string
	 */
	private static function date( string $ymd ): string {
		$time = strtotime( $ymd . ' 12:00:00 UTC' );
		return false === $time ? $ymd : wp_date( 'F j, Y', $time, new \DateTimeZone( 'UTC' ) );
	}
}
