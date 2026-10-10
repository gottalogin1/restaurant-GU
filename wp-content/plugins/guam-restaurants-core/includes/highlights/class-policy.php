<?php
/**
 * What highlights are, who may write them, and the published moderation rules.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Highlights;

use GuamRestaurants\Core\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Single source for the wording shown to visitors, moderators and the
 * editorial policy page, so the rules a moderator picks from are exactly the
 * rules we publish.
 *
 * Rules are about content (spam, profanity, attacks on people, conflicts of
 * interest and so on), never about sentiment: an honest critical review is
 * approved like a positive one (docs/legal-and-policy.md §2.3 and §9).
 * Highlights are never sold, never part of an ad package, and never change
 * ratings or the order of any list (CLAUDE.md rule 5).
 */
final class Policy {

	public const OWNER_QUOTE    = 'owner_quote';
	public const EDITOR_NOTE    = 'editor_note';
	public const USER_REVIEW    = 'user_review';
	public const OWNER_RESPONSE = 'owner_response';

	public const OWNER_QUOTE_MAX    = 500;
	public const EDITOR_NOTE_MAX    = 600;
	public const OWNER_RESPONSE_MAX = 1000;
	public const MIN_LENGTH         = 20;
	public const MAX_DISHES         = 5;
	public const MAX_DISH_LENGTH    = 60;

	/**
	 * Published rules, keyed by Vocabulary::MODERATION_REASONS. A highlight
	 * of any type is rejected only under one of these.
	 *
	 * @return array<string, string>
	 */
	public static function rules(): array {
		$rules = array(
			'spam'                   => __( 'Spam: advertising, links to other sites, or the same text posted again and again.', 'guam-restaurants-core' ),
			'profanity'              => __( 'Profanity or obscene language.', 'guam-restaurants-core' ),
			'personal_attack'        => __( 'A personal attack: insults aimed at a person (staff, the owner or another reviewer) instead of describing the visit.', 'guam-restaurants-core' ),
			'hate_speech'            => __( 'Hate speech, threats or harassment.', 'guam-restaurants-core' ),
			'personal_info'          => __( 'Personal information about someone, such as a phone number or home address.', 'guam-restaurants-core' ),
			'undisclosed_connection' => __( 'Conflict of interest: written by someone connected to the restaurant (owner, staff, relative) or to a competitor without saying so.', 'guam-restaurants-core' ),
			'fake'                   => __( 'Clear signs of being fake, paid for, or written in return for a discount or gift.', 'guam-restaurants-core' ),
			'off_topic'              => __( 'Not about this restaurant or a visit to it (off topic).', 'guam-restaurants-core' ),
			'wrong_business'         => __( 'About a different business.', 'guam-restaurants-core' ),
		);
		// Keep the list and the stored vocabulary in step.
		return array_intersect_key( $rules, array_flip( Vocabulary::MODERATION_REASONS ) );
	}

	/**
	 * Whether a rejection reason is a published rule.
	 *
	 * @param string $reason Reason.
	 * @return bool
	 */
	public static function is_rule( string $reason ): bool {
		return isset( self::rules()[ $reason ] );
	}

	/**
	 * Public label for each type, saying who wrote it.
	 *
	 * @param string $type Highlight type.
	 * @return string
	 */
	public static function type_label( string $type ): string {
		switch ( $type ) {
			case self::OWNER_QUOTE:
				return __( 'From the owner', 'guam-restaurants-core' );
			case self::EDITOR_NOTE:
				return __( "Editor's note", 'guam-restaurants-core' );
			case self::OWNER_RESPONSE:
				return __( 'Response from the owner', 'guam-restaurants-core' );
			default:
				return __( 'User review', 'guam-restaurants-core' );
		}
	}

	/**
	 * Badge text for a review whose writer confirmed an email address.
	 *
	 * Called "Verified email", not "Verified diner": clicking a link proves
	 * control of an inbox, not a visit.
	 *
	 * @return string
	 */
	public static function badge_label(): string {
		return __( 'Verified email', 'guam-restaurants-core' );
	}

	/**
	 * What the badge means, shown with it and on the policy page.
	 *
	 * @return string
	 */
	public static function badge_explanation(): string {
		return __( 'The writer confirmed an email address by clicking a link we sent. We have not checked that they ate here.', 'guam-restaurants-core' );
	}

	/**
	 * Splits and cleans a signature-dish list (one per line or comma separated).
	 *
	 * @param string|string[] $dishes Raw input.
	 * @return string[]
	 */
	public static function clean_dishes( $dishes ): array {
		if ( ! is_array( $dishes ) ) {
			$dishes = preg_split( '/[\r\n,]+/', (string) $dishes );
		}
		$clean = array();
		foreach ( (array) $dishes as $dish ) {
			$dish = trim( sanitize_text_field( (string) $dish ) );
			if ( '' !== $dish ) {
				$clean[] = $dish;
			}
		}
		return array_values( array_unique( $clean ) );
	}
}
