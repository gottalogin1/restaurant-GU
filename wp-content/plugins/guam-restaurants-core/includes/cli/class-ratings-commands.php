<?php
/**
 * WP-CLI commands: `wp guam-restaurants match-ratings` and `wp guam-restaurants refresh-ratings`.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Cli;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Ratings\Budget;
use GuamRestaurants\Core\Ratings\Matcher;
use GuamRestaurants\Core\Ratings\Pricing;
use GuamRestaurants\Core\Ratings\Ratings;
use GuamRestaurants\Core\Ratings\Refresher;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Rating source commands. Both print a cost estimate first and stop at
 * the GUAM_RESTAURANTS_RATINGS_RUN_CAP_USD hard cap.
 */
final class Ratings_Commands {

	/**
	 * Registers the commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'guam-restaurants match-ratings', array( self::class, 'match_ratings' ) );
		WP_CLI::add_command( 'guam-restaurants refresh-ratings', array( self::class, 'refresh_ratings' ) );
	}

	/**
	 * Finds each restaurant's Google, Yelp or Foursquare record by name and
	 * distance (within 150 metres of its OSM coordinates).
	 *
	 * Clear matches store the source's ID and a confidence score on the
	 * restaurant. Weak or ambiguous ones go to the review queue as
	 * `match_conflict`. Restaurants that already have an ID, or a pending
	 * review for the source, are skipped, so running it again is safe.
	 * Nothing from the source is stored except its ID.
	 *
	 * ## OPTIONS
	 *
	 * --source=<source>
	 * : Source to match.
	 * ---
	 * options:
	 *   - google
	 *   - yelp
	 *   - foursquare
	 * ---
	 *
	 * [--limit=<n>]
	 * : Match at most this many restaurants (0 for all).
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--rematch]
	 * : Also search restaurants that already have an ID for the source.
	 *
	 * [--dry-run]
	 * : Search and score, but write nothing. Still billed by the source.
	 *
	 * [--estimate-only]
	 * : Print the cost estimate and stop, with no API calls.
	 *
	 * [--delay=<seconds>]
	 * : Wait between requests.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants match-ratings --source=google --estimate-only
	 *     wp guam-restaurants match-ratings --source=google --limit=20
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function match_ratings( array $args, array $assoc_args ): void {
		$source   = (string) WP_CLI\Utils\get_flag_value( $assoc_args, 'source', '' );
		$limit    = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$rematch  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'rematch', false );
		$dry_run  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$estimate = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'estimate-only', false );
		$delay    = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'delay', 1 );

		$providers = Ratings::providers();
		if ( ! isset( $providers[ $source ] ) ) {
			WP_CLI::error( sprintf( 'No API key for %s. Set %s in wp-config.php or the environment.', $source, self::key_name( $source ) ) );
			return;
		}
		$provider = $providers[ $source ];
		$budget   = Budget::for_run();

		$ids  = Matcher::pending( $provider, $rematch, max( 0, $limit ) );
		$cost = Matcher::estimate( $provider, count( $ids ) );
		self::print_estimate( sprintf( '%d restaurant(s) x 1 %s search at %s', count( $ids ), $source, '$' . $provider->search_cost() ), $cost, $budget );

		if ( $estimate || ! $ids ) {
			return;
		}

		$result = ( new Matcher( $provider, $budget, $delay ) )->run( $ids, $dry_run );

		foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( $error );
		}
		if ( $result['stopped_at_cap'] ) {
			WP_CLI::warning( sprintf( 'Stopped at the %s cap. Raise %s or run again later to continue.', Pricing::format( $budget->cap() ), Config::RUN_CAP_USD ) );
		}
		$counts = $result['counts'];
		WP_CLI::log(
			sprintf(
				'%s: created %d, updated %d, unchanged %d, failed %d. Queued for review %d, no match %d, skipped %d. %d request(s), about %s.%s',
				$dry_run ? 'Dry run' : 'Done',
				$counts['created'],
				$counts['updated'],
				$counts['unchanged'],
				$counts['failed'],
				$counts['queued'],
				$counts['no_match'],
				$counts['skipped'],
				$result['requests'],
				Pricing::format( $result['spent'] ),
				$result['run_id'] ? " Ingestion run #{$result['run_id']}." : ''
			)
		);
	}

	/**
	 * Refreshes cached ratings now (the same job WP-Cron runs twice a day).
	 *
	 * Only Yelp may be cached (24 hours); Google and Foursquare are fetched
	 * live on each view and are never refreshed here.
	 *
	 * ## OPTIONS
	 *
	 * [--estimate-only]
	 * : Print the cost estimate and stop.
	 *
	 * ## EXAMPLES
	 *
	 *     wp guam-restaurants refresh-ratings --estimate-only
	 *
	 * @param string[]             $args       Positional arguments (unused).
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return void
	 */
	public static function refresh_ratings( array $args, array $assoc_args ): void {
		$estimate  = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'estimate-only', false );
		$providers = Ratings::providers();
		$budget    = Budget::for_run();

		$cost  = 0.0;
		$parts = array();
		foreach ( Refresher::planned( $providers ) as $source => $count ) {
			$cost   += $count * $providers[ $source ]->fetch_cost( true );
			$parts[] = sprintf( '%d %s restaurant(s)', $count, $source );
		}
		if ( ! $parts ) {
			WP_CLI::log( 'No cacheable source is configured (only Yelp may be cached). Nothing to refresh.' );
			return;
		}
		self::print_estimate( implode( ', ', $parts ), $cost, $budget );
		if ( $estimate ) {
			return;
		}

		$result = Refresher::run( $providers, $budget );
		foreach ( array_slice( $result['errors'], 0, 20 ) as $error ) {
			WP_CLI::warning( $error );
		}
		if ( $result['stopped_at_cap'] ) {
			WP_CLI::warning( sprintf( 'Stopped at the %s cap.', Pricing::format( $budget->cap() ) ) );
		}
		WP_CLI::log( sprintf( 'Refreshed %d, failed %d, about %s.', $result['refreshed'], $result['failed'], Pricing::format( $result['spent'] ) ) );
	}

	/**
	 * Prints the estimate and the cap.
	 *
	 * @param string $what   What will be called.
	 * @param float  $cost   Estimated dollars.
	 * @param Budget $budget Run budget.
	 * @return void
	 */
	private static function print_estimate( string $what, float $cost, Budget $budget ): void {
		WP_CLI::log( sprintf( 'Cost estimate: %s = %s (worst case; free monthly allowances not subtracted).', $what, Pricing::format( $cost ) ) );
		WP_CLI::log( sprintf( 'Hard cap for this run: %s (%s). The run stops before passing it.', Pricing::format( $budget->cap() ), Config::RUN_CAP_USD ) );
		if ( $cost > $budget->cap() ) {
			WP_CLI::warning( 'The estimate is above the cap, so this run will stop part way.' );
		}
	}

	/**
	 * Constant name for a source's key.
	 *
	 * @param string $source Source.
	 * @return string
	 */
	private static function key_name( string $source ): string {
		$names = array(
			'google'     => Config::GOOGLE_PLACES_KEY,
			'yelp'       => Config::YELP_KEY,
			'foursquare' => Config::FOURSQUARE_KEY,
		);
		return $names[ $source ] ?? 'the API key';
	}
}
