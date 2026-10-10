<?php
/**
 * Weekly newsletter draft.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Newsletter;

use GuamRestaurants\Core\Analytics\Listing_Views;
use GuamRestaurants\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Once a week (the `newsletter` job in Monitoring\Jobs, or by hand from
 * Restaurants > Newsletter) the plugin assembles an issue from the last
 * seven days (Issue), renders it (Renderer), and:
 *
 * - with a SendFox token, creates a draft campaign in SendFox
 *   (Sendfox_Client). It is never scheduled or sent: a person reviews it
 *   and presses Send in SendFox;
 * - without one, or when SendFox refuses (its API needs a paid plan), keeps
 *   the HTML so the Newsletter screen can show it for copying into a new
 *   SendFox campaign.
 *
 * Either way the latest draft is stored in an option and the site owner
 * gets an email saying it is ready to review.
 *
 * The sponsor for the next issue is entered on the Newsletter screen and
 * shown in a box labeled "Sponsored". It is cleared after the issue that
 * used it, so a sponsor never repeats by accident.
 */
final class Newsletter {

	public const SPONSOR_OPTION = 'guam_restaurants_newsletter_sponsor';
	public const LATEST_OPTION  = 'guam_restaurants_newsletter_latest';

	public const MAX_SPONSOR_NAME = 80;
	public const MAX_SPONSOR_TEXT = 300;

	/**
	 * The sponsor for the next issue, or null.
	 *
	 * @return array{name: string, text: string, url: string}|null
	 */
	public static function sponsor(): ?array {
		$sponsor = get_option( self::SPONSOR_OPTION );
		if ( ! is_array( $sponsor ) || '' === (string) ( $sponsor['name'] ?? '' ) || '' === (string) ( $sponsor['text'] ?? '' ) ) {
			return null;
		}
		return array(
			'name' => (string) $sponsor['name'],
			'text' => (string) $sponsor['text'],
			'url'  => (string) ( $sponsor['url'] ?? '' ),
		);
	}

	/**
	 * Saves or clears the sponsor. Name and text are both needed; empty
	 * name and text clear it.
	 *
	 * @param array<string, mixed> $input `name`, `text`, `url`.
	 * @return true|\WP_Error
	 */
	public static function save_sponsor( array $input ) {
		$name = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$text = trim( sanitize_textarea_field( (string) ( $input['text'] ?? '' ) ) );
		$url  = trim( esc_url_raw( (string) ( $input['url'] ?? '' ), array( 'https', 'http' ) ) );

		if ( '' === $name && '' === $text ) {
			delete_option( self::SPONSOR_OPTION );
			return true;
		}
		$errors = new \WP_Error();
		if ( '' === $name || mb_strlen( $name ) > self::MAX_SPONSOR_NAME ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_sponsor_name', sprintf( __( 'Enter the sponsor\'s name (up to %d characters).', 'guam-restaurants-core' ), self::MAX_SPONSOR_NAME ) );
		}
		if ( '' === $text || mb_strlen( $text ) > self::MAX_SPONSOR_TEXT ) {
			/* translators: %d: maximum characters. */
			$errors->add( 'gr_sponsor_text', sprintf( __( 'Enter the sponsor\'s message (up to %d characters).', 'guam-restaurants-core' ), self::MAX_SPONSOR_TEXT ) );
		}
		if ( $errors->has_errors() ) {
			return $errors;
		}
		update_option(
			self::SPONSOR_OPTION,
			array(
				'name' => $name,
				'text' => $text,
				'url'  => $url,
			),
			false
		);
		return true;
	}

	/**
	 * Builds this week's issue and makes the draft.
	 *
	 * @param array<string, mixed> $options `now` (Unix time), `client` (a Sendfox_Client, for tests), `notify` (bool, default true).
	 * @return array{built_at: int, start: string, end: string, subject: string, preview_text: string, html: string, has_content: bool, sponsor: string, sendfox_id: int, sendfox_error: string}
	 */
	public static function assemble( array $options = array() ): array {
		$issue = Issue::build( isset( $options['now'] ) ? (int) $options['now'] : null );
		$draft = array(
			'built_at'      => time(),
			'start'         => $issue['start'],
			'end'           => $issue['end'],
			'subject'       => Renderer::subject( $issue ),
			'preview_text'  => Renderer::preview_text( $issue ),
			'html'          => Renderer::html( $issue ),
			'has_content'   => Issue::has_content( $issue ),
			'sponsor'       => null === $issue['sponsor'] ? '' : $issue['sponsor']['name'],
			'sendfox_id'    => 0,
			'sendfox_error' => '',
		);

		$token  = Config::get( Config::SENDFOX_TOKEN );
		$client = $options['client'] ?? ( '' !== $token ? new Sendfox_Client( $token ) : null );
		if ( $client instanceof Sendfox_Client ) {
			$result                 = $client->create_draft(
				array(
					'title'        => $draft['subject'],
					'subject'      => $draft['subject'],
					'html'         => $draft['html'],
					'preview_text' => $draft['preview_text'],
					'from_name'    => self::from_name(),
					'from_email'   => self::from_email(),
					'lists'        => array_filter( array( absint( Config::get( Config::SENDFOX_LIST_ID ) ) ) ),
				)
			);
			$draft['sendfox_id']    = $result['id'];
			$draft['sendfox_error'] = $result['error'];
		} else {
			$draft['sendfox_error'] = __( 'No SendFox token is set, so no draft was created in SendFox. Copy the HTML below into a new SendFox campaign.', 'guam-restaurants-core' );
		}

		update_option( self::LATEST_OPTION, $draft, false );
		if ( null !== $issue['sponsor'] ) {
			delete_option( self::SPONSOR_OPTION );
		}
		Listing_Views::prune();

		if ( $options['notify'] ?? true ) {
			self::notify( $draft );
		}
		return $draft;
	}

	/**
	 * The latest draft, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function latest(): ?array {
		$draft = get_option( self::LATEST_OPTION );
		return is_array( $draft ) && isset( $draft['html'] ) ? $draft : null;
	}

	/**
	 * One-line summary for the job log.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return string
	 */
	public static function summary( array $draft ): string {
		return $draft['sendfox_id']
			? sprintf( 'SendFox draft %d created (not sent).', (int) $draft['sendfox_id'] )
			: 'HTML draft saved for copying; ' . (string) $draft['sendfox_error'];
	}

	/**
	 * Sender name.
	 *
	 * @return string
	 */
	public static function from_name(): string {
		return Config::get( Config::NEWSLETTER_FROM_NAME, wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * Sender address.
	 *
	 * @return string
	 */
	public static function from_email(): string {
		return Config::get( Config::NEWSLETTER_FROM_EMAIL, (string) get_option( 'admin_email' ) );
	}

	/**
	 * Tells the site owner the draft is ready to review.
	 *
	 * @param array<string, mixed> $draft Draft.
	 * @return bool
	 */
	private static function notify( array $draft ): bool {
		$to = Config::get( Config::ALERT_EMAIL, (string) get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return false;
		}
		$lines = array(
			$draft['sendfox_id']
				/* translators: %d: SendFox campaign ID. */
				? sprintf( __( 'A draft campaign (#%d) is waiting in SendFox. It has not been sent: review it, choose the list and press Send there.', 'guam-restaurants-core' ), (int) $draft['sendfox_id'] )
				: (string) $draft['sendfox_error'],
			'',
			/* translators: %s: subject line. */
			sprintf( __( 'Subject: %s', 'guam-restaurants-core' ), (string) $draft['subject'] ),
			/* translators: %s: sponsor name. */
			'' !== $draft['sponsor'] ? sprintf( __( 'Sponsor: %s', 'guam-restaurants-core' ), (string) $draft['sponsor'] ) : __( 'Sponsor: none', 'guam-restaurants-core' ),
			$draft['has_content'] ? '' : __( 'Nothing changed in the directory this week; consider skipping this issue.', 'guam-restaurants-core' ),
			'',
			/* translators: %s: link to the Newsletter screen. */
			sprintf( __( 'Preview and HTML: %s', 'guam-restaurants-core' ), Admin::url() ),
		);
		return wp_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Newsletter draft ready to review', 'guam-restaurants-core' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			implode( "\n", $lines )
		);
	}
}
