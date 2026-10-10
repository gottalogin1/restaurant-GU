<?php
/**
 * Allowed values for enum-like fields.
 *
 * Enum columns are VARCHAR in the database (dbDelta handles ENUM changes
 * badly), so the allowed values live here and are validated in PHP.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Value lists shared by post meta, custom tables and importers.
 */
final class Vocabulary {

	public const RESTAURANT_STATUSES = array( 'open', 'temporarily_closed', 'permanently_closed', 'unknown' );

	/**
	 * Where a restaurant's coordinates came from. Google coordinates are
	 * excluded: they may be cached for 30 days only and may not be used for
	 * point-in-polygon tagging (docs/data-sources.md §2).
	 */
	public const COORD_SOURCES = array( 'osm', 'fsq_os', 'owner', 'manual' );

	/**
	 * Sources that may appear in the change log and review queue. Google and
	 * Yelp content may not be stored, so they are not listed.
	 */
	public const FACT_SOURCES = array( 'osm', 'fsq_os', 'owner', 'editor', 'census' );

	/**
	 * Sources that may add review queue items and ingestion runs. Google,
	 * Yelp and Foursquare are allowed here only because their items hold
	 * nothing from the source but its ID, which each source lets us keep
	 * (docs/data-sources.md §2 to §4).
	 */
	public const REVIEW_QUEUE_SOURCES = array( 'osm', 'fsq_os', 'owner', 'editor', 'census', 'google', 'yelp', 'foursquare' );

	public const HOURS_SOURCES = array( 'osm', 'owner', 'manual' );

	public const DAYS = array( 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' );

	public const AWARD_PLACEMENTS = array( 'winner', 'finalist', 'other' );

	public const HIGHLIGHT_TYPES = array( 'owner_quote', 'editor_note', 'user_review', 'owner_response' );

	public const HIGHLIGHT_STATUSES = array( 'pending', 'approved', 'rejected' );

	/**
	 * Published review rules. A rejected highlight must name one; rules
	 * apply equally to positive and negative reviews
	 * (docs/legal-and-policy.md, review policy).
	 */
	public const MODERATION_REASONS = array( 'off_topic', 'wrong_business', 'hate_speech', 'personal_info', 'undisclosed_connection', 'fake' );

	public const REVIEW_QUEUE_TYPES = array( 'new_place', 'match_conflict', 'village_unresolved', 'mall_unresolved', 'closure_signal', 'owner_submission' );

	public const REVIEW_QUEUE_STATUSES = array( 'pending', 'approved', 'rejected', 'merged' );

	public const AD_PAGE_TYPES = array( 'home', 'restaurant', 'village', 'mall', 'cuisine', 'search', 'other' );
}
