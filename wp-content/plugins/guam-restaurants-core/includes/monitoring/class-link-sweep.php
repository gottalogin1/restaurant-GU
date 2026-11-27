<?php
/**
 * Monthly sweep for broken website, menu and order links, and for places
 * our data sources list as closed.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Monitoring;

use GuamRestaurants\Core\Config;
use GuamRestaurants\Core\Content_Types;
use GuamRestaurants\Core\Database\Change_Log;
use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Database\Review_Queue;
use GuamRestaurants\Core\Database\Schema;
use GuamRestaurants\Core\Fsq\Place_Mapper;
use GuamRestaurants\Core\Fsq\Reader as Fsq_Reader;
use GuamRestaurants\Core\Owners\Owner_Content;
use GuamRestaurants\Core\Premium\Premium;
use GuamRestaurants\Core\Restaurant_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing here changes what visitors see. Every problem becomes a review
 * queue item, and only an administrator's decision changes a listing.
 *
 * Links: each published, open restaurant's website (`gr_website`), its
 * owner's menu links (`gr_menu_links`) and its premium call-to-action
 * link (order online, reserve, menu...). Facebook and Instagram pages are
 * not checked: both sites forbid automated access in robots.txt. Each link
 * goes through Link_Checker, which reads robots.txt first and spaces its
 * requests out. A link is queued as `broken_link` when:
 *
 * - it answers 404 or 410 (page gone), on the first sweep; or
 * - it failed some other way (unknown domain, no answer, server error,
 *   certificate error) in SOFT_CHECKS sweeps in a row, since sites have
 *   bad days.
 *
 * A site that blocks automated checks (HTTP 403, 429...) or whose
 * robots.txt says not to fetch the page is left alone, never queued.
 * At most MAX_LINKS links are checked per run, the longest-unchecked
 * first, so a big directory is covered over several months.
 *
 * Decisions: "Remove link" takes the link off the listing (logged as an
 * editor's change) and remembers it, so the OSM and Foursquare imports do
 * not put it back. "Keep link" leaves it and stops asking for KEEP_DAYS.
 * A queued link that works again is closed automatically on the next sweep.
 *
 * Closed places: the Foursquare Open Source Places extract the site
 * already imports (`make fsq-extract`) gives a closing date or a "closed"
 * flag for some places. When a restaurant linked to Foursquare is listed
 * as closed there, a `closure_signal` goes to the queue (Closures). The
 * weekly OSM import and the monthly Google and Yelp status checks already
 * send their own closure signals; this sweep does not repeat them.
 *
 * Only our own results are stored (hidden post meta): when each link was
 * checked, the verdict, the HTTP status and a short reason. Nothing from
 * the pages themselves is kept.
 */
final class Link_Sweep {

	public const STATE_META   = '_gr_link_checks';
	public const REMOVED_META = '_gr_removed_links';
	public const QUEUE_TYPE   = 'broken_link';
	public const RUN_SOURCE   = 'link_sweep';

	public const MAX_LINKS   = 400;
	public const SOFT_CHECKS = 2;
	public const KEEP_DAYS   = 180;

	/**
	 * A link checked this recently (for example by a second run the same
	 * day) is not requested or counted again.
	 */
	public const MIN_HOURS_BETWEEN = 20;

	/**
	 * A Foursquare extract older than this is not used for closures.
	 */
	public const FSQ_MAX_AGE_DAYS = 400;

	/**
	 * Where `make fsq-extract` writes the extract, as the containers see it.
	 */
	public const FSQ_DEFAULT_FILE = '/project/.cache/fsq-guam.jsonl';

	/**
	 * Checker.
	 *
	 * @var Link_Checker
	 */
	private Link_Checker $checker;

	/**
	 * Foursquare extract path, or ''.
	 *
	 * @var string
	 */
	private string $fsq_file;

	/**
	 * Links per run.
	 *
	 * @var int
	 */
	private int $max_links;

	/**
	 * Constructor.
	 *
	 * @param Link_Checker $checker   Checker.
	 * @param string       $fsq_file  Foursquare extract, or '' to skip the closure check.
	 * @param int          $max_links Links per run.
	 */
	public function __construct( Link_Checker $checker, string $fsq_file = '', int $max_links = self::MAX_LINKS ) {
		$this->checker   = $checker;
		$this->fsq_file  = $fsq_file;
		$this->max_links = max( 1, $max_links );
	}

	/**
	 * The sweep with live requests and the configured Foursquare extract.
	 *
	 * @return self
	 */
	public static function from_config(): self {
		return new self( new Link_Checker(), self::fsq_file() );
	}

	/**
	 * GUAM_RESTAURANTS_FSQ_EXTRACT, else the `make fsq-extract` file when it is there.
	 *
	 * @return string
	 */
	public static function fsq_file(): string {
		$file = Config::get( Config::FSQ_EXTRACT );
		if ( '' === $file && is_readable( self::FSQ_DEFAULT_FILE ) ) {
			$file = self::FSQ_DEFAULT_FILE;
		}
		return $file;
	}

	/**
	 * Restaurants to sweep: published and not permanently closed.
	 *
	 * @return int[]
	 */
	public static function restaurants(): array {
		$ids = get_posts(
			array(
				'post_type'      => Content_Types::POST_TYPE,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( int $id ): bool {
					return 'permanently_closed' !== get_post_meta( $id, Restaurant_Meta::STATUS, true );
				}
			)
		);
	}

	/**
	 * A restaurant's links to check.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<string, array{field: string, label: string, url: string}> Keyed by state key.
	 */
	public static function links( int $restaurant_id ): array {
		$links   = array();
		$website = trim( (string) get_post_meta( $restaurant_id, Restaurant_Meta::WEBSITE, true ) );
		if ( '' !== $website && null === Restaurant_Meta::social_network( $website ) ) {
			$links[] = array(
				'field' => 'website',
				'label' => 'Website',
				'url'   => $website,
			);
		}
		$menu = get_post_meta( $restaurant_id, Owner_Content::MENU_LINKS, true );
		foreach ( is_array( $menu ) ? $menu : array() as $row ) {
			$url = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' !== $url && null === Restaurant_Meta::social_network( $url ) ) {
				$links[] = array(
					'field' => 'menu',
					'label' => (string) ( $row['label'] ?? 'Menu' ),
					'url'   => $url,
				);
			}
		}
		$cta = Premium::is_active( $restaurant_id ) ? Premium::cta( $restaurant_id ) : null;
		if ( $cta && null === Restaurant_Meta::social_network( $cta['url'] ) ) {
			$links[] = array(
				'field' => 'cta',
				'label' => Premium::cta_actions()[ $cta['action'] ] ?? 'Order online',
				'url'   => $cta['url'],
			);
		}

		$out = array();
		foreach ( $links as $link ) {
			$out[ self::key( $link['field'], $link['url'] ) ] = $link;
		}
		return $out;
	}

	/**
	 * State key for a link.
	 *
	 * @param string $field website, menu or cta.
	 * @param string $url   Link.
	 * @return string
	 */
	public static function key( string $field, string $url ): string {
		return $field . ':' . substr( md5( $url ), 0, 12 );
	}

	/**
	 * Runs the sweep.
	 *
	 * @param int[]|null $restaurant_ids Restaurants; null for all.
	 * @param bool       $dry_run        Check links but record and queue nothing.
	 * @return array<string, mixed>
	 */
	public function run( ?array $restaurant_ids = null, bool $dry_run = false ): array {
		$result           = array(
			'counts'   => array(
				'created'   => 0,
				'updated'   => 0,
				'unchanged' => 0,
				'failed'    => 0,
			),
			'links'    => array_fill_keys( array( 'checked', 'ok', 'broken', 'unknown', 'robots', 'kept', 'recent', 'left' ), 0 ),
			'queued'   => array(),
			'fixed'    => array(),
			'problems' => array(),
			'closures' => array(
				'checked' => 0,
				'closed'  => array(),
				'queued'  => array(),
				'note'    => '',
			),
			'requests' => 0,
			'errors'   => array(),
			'run_id'   => null,
		);
		$started          = $dry_run ? 0 : Ingestion_Runs::start( self::RUN_SOURCE );
		$run_id           = $started > 0 ? $started : null;
		$result['run_id'] = $run_id;

		$due = array();
		foreach ( $restaurant_ids ?? self::restaurants() as $restaurant_id ) {
			$links = self::links( (int) $restaurant_id );
			$state = self::state( (int) $restaurant_id );
			$kept  = array_intersect_key( $state, $links );
			if ( ! $dry_run && count( $kept ) !== count( $state ) ) {
				self::save_state( (int) $restaurant_id, $kept );
			}
			foreach ( $links as $key => $link ) {
				$last = (int) ( $kept[ $key ]['checked'] ?? 0 );
				if ( $last > time() - self::MIN_HOURS_BETWEEN * HOUR_IN_SECONDS ) {
					++$result['links']['recent'];
					continue;
				}
				$due[] = array( (int) $restaurant_id, $key, $link, $last );
			}
		}
		usort(
			$due,
			static function ( array $a, array $b ): int {
				return array( $a[3], $a[0] ) <=> array( $b[3], $b[0] );
			}
		);
		$result['links']['left'] = max( 0, count( $due ) - $this->max_links );

		foreach ( array_slice( $due, 0, $this->max_links ) as list( $restaurant_id, $key, $link ) ) {
			$this->check_link( $restaurant_id, $key, $link, $run_id, $dry_run, $result );
		}

		$result['closures'] = $this->fsq_closures( $run_id, $dry_run );
		$result['requests'] = $this->checker->requests();

		$result['counts']['created']   = count( $result['queued'] ) + count( $result['closures']['queued'] );
		$result['counts']['updated']   = $result['links']['broken'] - count( $result['queued'] );
		$result['counts']['unchanged'] = $result['links']['checked'] - $result['links']['broken'] + $result['links']['kept'];

		if ( $run_id ) {
			Ingestion_Runs::finish( $run_id, $result['counts'], self::notes( $result ), $result['errors'] );
		}
		return $result;
	}

	/**
	 * Checks one link, updates its state and queues or closes its item.
	 *
	 * @param int                                              $restaurant_id Restaurant.
	 * @param string                                           $key           State key.
	 * @param array{field: string, label: string, url: string} $link         Link.
	 * @param int|null                                         $run_id        Run.
	 * @param bool                                             $dry_run       Dry run.
	 * @param array<string, mixed>                             $result        Run result, updated.
	 * @return void
	 */
	private function check_link( int $restaurant_id, string $key, array $link, ?int $run_id, bool $dry_run, array &$result ): void {
		$state = self::state( $restaurant_id );
		$entry = $state[ $key ] ?? array( 'fails' => 0 );
		if ( (int) ( $entry['kept_until'] ?? 0 ) > time() ) {
			++$result['links']['kept'];
			return;
		}

		$verdict = $this->checker->check( $link['url'] );
		++$result['links']['checked'];
		++$result['links'][ $verdict['state'] ];

		$now              = current_time( 'mysql', true );
		$entry['checked'] = time();
		$entry['state']   = $verdict['state'];
		$entry['code']    = $verdict['code'];
		$entry['detail']  = $verdict['detail'];
		$entry['url']     = $link['url'];
		$pending          = self::pending_item( $restaurant_id, $link['field'], $link['url'] );
		$queue            = false;
		if ( Link_Checker::OK === $verdict['state'] ) {
			$entry['fails'] = 0;
			unset( $entry['first_failed'] );
			if ( $pending && ! $dry_run ) {
				Review_Queue::annotate( $pending, array( 'closed_because' => 'The link worked again on ' . $now . ' UTC.' ) );
				Review_Queue::resolve( $pending, 'rejected', 0 );
				$result['fixed'][] = $pending;
			}
		} elseif ( Link_Checker::BROKEN === $verdict['state'] ) {
			$entry['fails']        = (int) $entry['fails'] + 1;
			$entry['first_failed'] = $entry['first_failed'] ?? $now;
			$queue                 = ! $pending && ( $verdict['hard'] || $entry['fails'] >= self::SOFT_CHECKS );
			$result['problems'][]  = sprintf( '#%d %s %s: %s', $restaurant_id, $link['field'], $link['url'], $verdict['detail'] );
		}

		if ( $dry_run ) {
			return;
		}
		$state[ $key ] = $entry;
		self::save_state( $restaurant_id, $state );

		if ( $queue ) {
			$item = Review_Queue::enqueue(
				self::QUEUE_TYPE,
				array(
					'field'           => $link['field'],
					'label'           => $link['label'],
					'link'            => $link['url'],
					'problem'         => $verdict['detail'],
					'http_code'       => $verdict['code'],
					'checks'          => $entry['fails'],
					'first_failed_at' => $entry['first_failed'],
					'last_failed_at'  => $now,
					'domain_gone'     => 'domain not found' === $verdict['detail'],
				),
				'system',
				$restaurant_id,
				null,
				$run_id
			);
			if ( $item ) {
				$result['queued'][] = $item;
			}
		}
	}

	/**
	 * Closure signals from the Foursquare extract.
	 *
	 * @param int|null $run_id  Run.
	 * @param bool     $dry_run Dry run.
	 * @return array{checked: int, closed: int[], queued: int[], note: string}
	 */
	private function fsq_closures( ?int $run_id, bool $dry_run ): array {
		$out = array(
			'checked' => 0,
			'closed'  => array(),
			'queued'  => array(),
			'note'    => '',
		);
		if ( '' === $this->fsq_file || ! is_readable( $this->fsq_file ) ) {
			$out['note'] = 'no Foursquare extract, so no Foursquare closure check';
			return $out;
		}
		if ( (int) filemtime( $this->fsq_file ) < time() - self::FSQ_MAX_AGE_DAYS * DAY_IN_SECONDS ) {
			$out['note'] = 'the Foursquare extract is over a year old (run make fsq-extract), so no Foursquare closure check';
			return $out;
		}
		try {
			$rows = Fsq_Reader::read( $this->fsq_file );
		} catch ( \Throwable $e ) {
			$out['note'] = 'the Foursquare extract could not be read: ' . $e->getMessage();
			return $out;
		}
		$by_id = array();
		foreach ( $rows as $row ) {
			$id = Place_Mapper::id( $row );
			if ( '' !== $id ) {
				$by_id[ $id ] = $row;
			}
		}
		unset( $rows );

		foreach ( self::restaurants() as $restaurant_id ) {
			$fsq_id = (string) get_post_meta( $restaurant_id, Restaurant_Meta::FOURSQUARE_ID, true );
			if ( '' === $fsq_id || ! isset( $by_id[ $fsq_id ] ) ) {
				continue;
			}
			++$out['checked'];
			$statement = Place_Mapper::closed_statement( $by_id[ $fsq_id ] );
			if ( '' === $statement ) {
				continue;
			}
			$out['closed'][] = $restaurant_id;
			if ( ! $dry_run && Closures::record_statement( $restaurant_id, 'fsq_os', $statement, $run_id, array( 'fsq_place_id' => $fsq_id ) ) ) {
				$out['queued'][] = $restaurant_id;
			}
		}
		return $out;
	}

	/**
	 * Run notes.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return string
	 */
	public static function notes( array $result ): string {
		$links    = $result['links'];
		$notes    = sprintf(
			'links: %d checked (%d ok, %d broken, %d blocked automated checks, %d skipped for robots.txt), %d queued, %d fixed themselves, %d kept by an administrator, %d checked in the last day, %d left for next time; %d requests',
			$links['checked'],
			$links['ok'],
			$links['broken'],
			$links['unknown'],
			$links['robots'],
			count( $result['queued'] ),
			count( $result['fixed'] ),
			$links['kept'],
			$links['recent'],
			$links['left'],
			$result['requests']
		);
		$closures = $result['closures'];
		if ( '' !== $closures['note'] ) {
			return $notes . '; ' . $closures['note'];
		}
		return $notes . sprintf( '; Foursquare: %d linked places checked, %d listed as closed, %d queued for approval', $closures['checked'], count( $closures['closed'] ), count( $closures['queued'] ) );
	}

	/**
	 * Takes a broken link off the listing (administrator's decision).
	 *
	 * @param int $item_id Item.
	 * @param int $user_id Administrator.
	 * @return bool
	 */
	public static function remove_link( int $item_id, int $user_id ): bool {
		$item = self::item( $item_id, $user_id );
		if ( ! $item ) {
			return false;
		}
		$restaurant_id = (int) $item['restaurant_id'];
		$field         = (string) ( $item['payload']['field'] ?? '' );
		$url           = (string) ( $item['payload']['link'] ?? '' );
		$run_id        = isset( $item['run_id'] ) ? (int) $item['run_id'] : null;

		Change_Tracker::suppress(
			static function () use ( $restaurant_id, $field, $url, $run_id ) {
				if ( 'website' === $field && (string) get_post_meta( $restaurant_id, Restaurant_Meta::WEBSITE, true ) === $url ) {
					delete_post_meta( $restaurant_id, Restaurant_Meta::WEBSITE );
					Change_Log::record( $restaurant_id, Restaurant_Meta::WEBSITE, $url, '', 'editor', $run_id );
				} elseif ( 'menu' === $field ) {
					$rows = get_post_meta( $restaurant_id, Owner_Content::MENU_LINKS, true );
					$rows = is_array( $rows ) ? $rows : array();
					$left = array_values(
						array_filter(
							$rows,
							static function ( $row ) use ( $url ): bool {
								return (string) ( $row['url'] ?? '' ) !== $url;
							}
						)
					);
					if ( count( $left ) !== count( $rows ) ) {
						if ( $left ) {
							update_post_meta( $restaurant_id, Owner_Content::MENU_LINKS, $left );
						} else {
							delete_post_meta( $restaurant_id, Owner_Content::MENU_LINKS );
						}
						Change_Log::record( $restaurant_id, Owner_Content::MENU_LINKS, $url, '', 'editor', $run_id );
					}
				} elseif ( 'cta' === $field && ( Premium::cta( $restaurant_id )['url'] ?? '' ) === $url ) {
					delete_post_meta( $restaurant_id, Premium::CTA );
					Change_Log::record( $restaurant_id, Premium::CTA, $url, '', 'editor', $run_id );
				}

				$removed   = get_post_meta( $restaurant_id, self::REMOVED_META, true );
				$removed   = is_array( $removed ) ? $removed : array();
				$removed[] = self::normalize( $url );
				update_post_meta( $restaurant_id, self::REMOVED_META, array_values( array_unique( $removed ) ) );

				$state = self::state( $restaurant_id );
				unset( $state[ self::key( $field, $url ) ] );
				self::save_state( $restaurant_id, $state );
			}
		);
		return Review_Queue::resolve( $item_id, 'approved', $user_id );
	}

	/**
	 * Keeps a link the sweep found broken, and stops asking about it for KEEP_DAYS.
	 *
	 * @param int $item_id Item.
	 * @param int $user_id Administrator.
	 * @return bool
	 */
	public static function keep_link( int $item_id, int $user_id ): bool {
		$item = self::item( $item_id, $user_id );
		if ( ! $item ) {
			return false;
		}
		$restaurant_id = (int) $item['restaurant_id'];
		$key           = self::key( (string) ( $item['payload']['field'] ?? '' ), (string) ( $item['payload']['link'] ?? '' ) );
		$state         = self::state( $restaurant_id );
		$entry         = $state[ $key ] ?? array();

		$entry['kept_until'] = time() + self::KEEP_DAYS * DAY_IN_SECONDS;
		$entry['fails']      = 0;
		unset( $entry['first_failed'] );
		$state[ $key ] = $entry;
		self::save_state( $restaurant_id, $state );
		return Review_Queue::resolve( $item_id, 'rejected', $user_id );
	}

	/**
	 * Whether an administrator removed this link from the restaurant as
	 * broken, so an import should not put it back.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $url           Link.
	 * @return bool
	 */
	public static function was_removed( int $restaurant_id, string $url ): bool {
		$removed = get_post_meta( $restaurant_id, self::REMOVED_META, true );
		return is_array( $removed ) && in_array( self::normalize( $url ), $removed, true );
	}

	/**
	 * A link compared without case, scheme, `www.` or a trailing slash.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	private static function normalize( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '#^https?://(www\.)?#', '', $url );
		return rtrim( $url, '/' );
	}

	/**
	 * Saved check results for a restaurant's links.
	 *
	 * @param int $restaurant_id Restaurant.
	 * @return array<string, array<string, mixed>>
	 */
	public static function state( int $restaurant_id ): array {
		$state = get_post_meta( $restaurant_id, self::STATE_META, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Saves check results.
	 *
	 * @param int                                 $restaurant_id Restaurant.
	 * @param array<string, array<string, mixed>> $state         Results.
	 * @return void
	 */
	private static function save_state( int $restaurant_id, array $state ): void {
		Change_Tracker::suppress(
			static function () use ( $restaurant_id, $state ) {
				if ( $state ) {
					update_post_meta( $restaurant_id, self::STATE_META, $state );
				} else {
					delete_post_meta( $restaurant_id, self::STATE_META );
				}
			}
		);
	}

	/**
	 * The pending broken-link item for this link, or 0.
	 *
	 * @param int    $restaurant_id Restaurant.
	 * @param string $field         Field.
	 * @param string $url           Link.
	 * @return int
	 */
	private static function pending_item( int $restaurant_id, string $field, string $url ): int {
		global $wpdb;
		$table = Schema::table( Schema::REVIEW_QUEUE );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM {$table} WHERE restaurant_id = %d AND type = %s AND status = %s", $restaurant_id, self::QUEUE_TYPE, 'pending' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) && ( $payload['field'] ?? '' ) === $field && ( $payload['link'] ?? '' ) === $url ) {
				return (int) $row['id'];
			}
		}
		return 0;
	}

	/**
	 * A pending broken-link item an administrator may decide, or null.
	 *
	 * @param int $item_id Item.
	 * @param int $user_id User.
	 * @return array<string, mixed>|null
	 */
	private static function item( int $item_id, int $user_id ): ?array {
		$item = Review_Queue::get( $item_id );
		if ( ! $item || self::QUEUE_TYPE !== $item['type'] || 'pending' !== $item['status'] || ! $item['restaurant_id'] || ! user_can( $user_id, Closures::CAPABILITY ) ) {
			return null;
		}
		return $item;
	}
}
