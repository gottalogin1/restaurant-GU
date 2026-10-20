<?php
/**
 * Picks an openly licensed photo for each cuisine from Openverse.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Photos;

use GuamRestaurants\Core\Database\Ingestion_Runs;
use GuamRestaurants\Core\Http\Request_Failed;
use GuamRestaurants\Core\Http\Retrying_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Searches Openverse (api.openverse.org, which indexes Wikimedia Commons,
 * Flickr and other open collections) for "<cuisine> food", keeps the first
 * large landscape photo under a license in Cuisine_Photos::LICENSES that
 * downloads, and stores it with its credit. A cuisine that already has a photo is left
 * alone unless refresh is on, so running it again changes nothing.
 */
final class Openverse_Importer {

	public const SOURCE     = 'openverse';
	public const API        = 'https://api.openverse.org/v1/images/';
	public const TERM_QUERY = 'gr_photo_query';
	public const MIN_WIDTH  = 800;
	public const CANDIDATES = 3;
	public const TIMEOUT    = 30;
	public const RETRIES    = 2;

	/**
	 * HTTP client.
	 *
	 * @var Retrying_Client
	 */
	private Retrying_Client $client;

	/**
	 * Seconds to wait between cuisines.
	 *
	 * @var int
	 */
	private int $delay;

	/**
	 * Sleep function.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param Retrying_Client|null $client  HTTP client (default: RETRIES retries, so a host that never answers costs minutes, not most of an hour).
	 * @param int                  $delay   Seconds between cuisines (anonymous Openverse use is rate limited).
	 * @param callable|null        $sleeper Defaults to sleep().
	 */
	public function __construct( ?Retrying_Client $client = null, int $delay = 3, ?callable $sleeper = null ) {
		$this->client  = $client ?? new Retrying_Client( null, null, array( 429 ), 30, self::RETRIES );
		$this->delay   = max( 0, $delay );
		$this->sleeper = $sleeper ?? 'sleep';
	}

	/**
	 * Runs over some cuisines.
	 *
	 * @param \WP_Term[]  $cuisines     Cuisines.
	 * @param bool        $refresh      Replace photos the cuisines already have.
	 * @param bool        $dry_run      Search, but download and save nothing.
	 * @param string      $openverse_id Use this Openverse image (one cuisine only).
	 * @param string|null $query        Search words to use and remember (one cuisine only).
	 * @return array{counts: array<string, int>, rows: array<int, array<string, string>>, errors: string[], run_id: int}
	 */
	public function run( array $cuisines, bool $refresh = false, bool $dry_run = false, string $openverse_id = '', ?string $query = null ): array {
		$counts = array(
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		);
		$rows   = array();
		$errors = array();
		$run_id = $dry_run ? 0 : Ingestion_Runs::start( self::SOURCE );
		$first  = true;

		foreach ( $cuisines as $cuisine ) {
			if ( null !== $query && ! $dry_run ) {
				update_term_meta( $cuisine->term_id, self::TERM_QUERY, sanitize_text_field( $query ) );
			}
			$current = Cuisine_Photos::get( $cuisine->term_id );
			$row     = array(
				'cuisine' => $cuisine->slug,
				'action'  => 'unchanged',
			);
			if ( null !== $current && ! $refresh && '' === $openverse_id ) {
				++$counts['unchanged'];
				$rows[] = $row + self::credit_columns( $current['credit'] );
				continue;
			}

			if ( ! $first ) {
				call_user_func( $this->sleeper, $this->delay );
			}
			$first = false;

			$problem = __( 'no suitable openly licensed photo found', 'guam-restaurants-core' );
			try {
				$images = '' !== $openverse_id ? $this->image( $openverse_id ) : $this->search( $query ?? self::query_for( $cuisine ) );
			} catch ( Request_Failed $e ) {
				$images  = array();
				$problem = $e->getMessage();
			}
			if ( array() === $images ) {
				++$counts['failed'];
				$errors[] = $cuisine->slug . ': ' . $problem;
				$rows[]   = array(
					'cuisine' => $cuisine->slug,
					'action'  => 'failed',
				);
				continue;
			}

			$credit = self::credit_from( $images[0] );
			if ( null !== $current && ( $current['credit']['openverse_id'] ?? '' ) === $credit['openverse_id'] ) {
				++$counts['unchanged'];
				$rows[] = $row + self::credit_columns( $credit );
				continue;
			}

			$action = null === $current ? 'created' : 'updated';
			if ( ! $dry_run ) {
				// Search results are not checked for dead links (see search()), so a photo whose host is gone or slow gives way to the next one.
				$problems = array();
				foreach ( $images as $image ) {
					$credit     = self::credit_from( $image );
					$attachment = Photo_Download::fetch( $this->client, (string) $image['url'], 0, $cuisine->slug . '-food' );
					if ( ! is_wp_error( $attachment ) ) {
						break;
					}
					$problems[] = $attachment->get_error_message();
				}
				if ( is_wp_error( $attachment ) ) {
					++$counts['failed'];
					$errors[] = $cuisine->slug . ': ' . implode( '; ', array_unique( $problems ) );
					$rows[]   = array(
						'cuisine' => $cuisine->slug,
						'action'  => 'failed',
					);
					continue;
				}
				update_post_meta( $attachment, '_wp_attachment_image_alt', '' );
				Cuisine_Photos::set( $cuisine->term_id, $attachment, $credit );
			}
			++$counts[ $action ];
			$rows[] = array(
				'cuisine' => $cuisine->slug,
				'action'  => $action,
			) + self::credit_columns( $credit );
		}

		if ( $run_id ) {
			Ingestion_Runs::record_retries( $run_id, $this->client->attempt_log() );
			Ingestion_Runs::finish( $run_id, $counts, '', $errors );
		}

		return array(
			'counts' => $counts,
			'rows'   => $rows,
			'errors' => $errors,
			'run_id' => $run_id,
		);
	}

	/**
	 * Search words for a cuisine: the remembered ones, else "<name> food".
	 *
	 * @param \WP_Term $cuisine Cuisine.
	 * @return string
	 */
	public static function query_for( \WP_Term $cuisine ): string {
		$saved = (string) get_term_meta( $cuisine->term_id, self::TERM_QUERY, true );
		if ( '' !== $saved ) {
			return $saved;
		}
		return false !== stripos( $cuisine->name, 'food' ) ? $cuisine->name : $cuisine->name . ' food';
	}

	/**
	 * Up to CANDIDATES suitable search results, best first.
	 *
	 * Dead-link filtering is turned off: with it on, Openverse checks every
	 * result's image host before it answers, which can take longer than any
	 * sensible timeout (an anonymous run timed out on 18 of 19 cuisines; the
	 * one that answered had no results to check). The download step skips
	 * dead links instead.
	 *
	 * @param string $query Search words.
	 * @return array<int, array<string, mixed>>
	 * @throws Request_Failed When Openverse does not answer.
	 */
	private function search( string $query ): array {
		$url      = add_query_arg(
			array(
				'q'           => rawurlencode( $query ),
				'license'     => implode( ',', Cuisine_Photos::LICENSES ),
				'category'    => 'photograph',
				'mature'      => 'false',
				'filter_dead' => 'false',
				'page_size'   => 20,
			),
			self::API
		);
		$response = $this->client->request( $url, $this->args() );
		$data     = json_decode( $response['body'], true );
		$images   = array();
		foreach ( (array) ( $data['results'] ?? array() ) as $image ) {
			if ( is_array( $image ) && self::suitable( $image ) ) {
				$images[] = $image;
				if ( count( $images ) >= self::CANDIDATES ) {
					break;
				}
			}
		}
		return $images;
	}

	/**
	 * One image by its Openverse id.
	 *
	 * @param string $id Openverse id.
	 * @return array<int, array<string, mixed>> The image, or nothing.
	 * @throws Request_Failed When Openverse does not answer.
	 */
	private function image( string $id ): array {
		if ( ! preg_match( '/^[0-9a-f-]{36}$/i', $id ) ) {
			return array();
		}
		$response = $this->client->request( self::API . $id . '/', $this->args() );
		$image    = json_decode( $response['body'], true );
		// An image picked by hand may be portrait or small; the license still has to fit.
		return is_array( $image ) && self::license_ok( $image ) && '' !== (string) ( $image['url'] ?? '' ) ? array( $image ) : array();
	}

	/**
	 * Request arguments.
	 *
	 * HTTP/1.1 on purpose: WordPress sends HTTP/1.0 by default, and on
	 * Sanjay's machine Openverse answered HTTP/1.0 and HTTP/2 requests with
	 * a full page of results with nothing for 30 s, while the same request
	 * over HTTP/1.1 answered in 0.1 s (`make photo-source-check`).
	 *
	 * @return array<string, mixed>
	 */
	private function args(): array {
		return array(
			'method'      => 'GET',
			'httpversion' => '1.1',
			'timeout'     => self::TIMEOUT,
			'user-agent'  => Photo_Download::user_agent(),
			'headers'     => array( 'Accept' => 'application/json' ),
		);
	}

	/**
	 * Whether a search result is usable: allowed license, not marked mature,
	 * landscape, at least MIN_WIDTH wide, JPEG or PNG.
	 *
	 * @param array<string, mixed> $image Openverse image.
	 * @return bool
	 */
	public static function suitable( array $image ): bool {
		$width  = (int) ( $image['width'] ?? 0 );
		$height = (int) ( $image['height'] ?? 0 );
		$type   = strtolower( (string) ( $image['filetype'] ?? '' ) );
		return self::license_ok( $image )
			&& empty( $image['mature'] )
			&& '' !== (string) ( $image['url'] ?? '' )
			&& $width >= self::MIN_WIDTH && $width >= $height
			&& ( '' === $type || in_array( $type, array( 'jpg', 'jpeg', 'png' ), true ) );
	}

	/**
	 * Whether the image's license is one we accept.
	 *
	 * @param array<string, mixed> $image Openverse image.
	 * @return bool
	 */
	private static function license_ok( array $image ): bool {
		return in_array( strtolower( (string) ( $image['license'] ?? '' ) ), Cuisine_Photos::LICENSES, true );
	}

	/**
	 * Credit fields from an Openverse image.
	 *
	 * @param array<string, mixed> $image Openverse image.
	 * @return array<string, string>
	 */
	private static function credit_from( array $image ): array {
		return array(
			'title'           => (string) ( $image['title'] ?? '' ),
			'creator'         => (string) ( $image['creator'] ?? '' ),
			'creator_url'     => (string) ( $image['creator_url'] ?? '' ),
			'license'         => strtolower( (string) ( $image['license'] ?? '' ) ),
			'license_version' => (string) ( $image['license_version'] ?? '' ),
			'license_url'     => (string) ( $image['license_url'] ?? '' ),
			'source_url'      => (string) ( $image['foreign_landing_url'] ?? '' ),
			'openverse_id'    => (string) ( $image['id'] ?? '' ),
		);
	}

	/**
	 * Credit columns for the command's table.
	 *
	 * @param array<string, string> $credit Credit.
	 * @return array<string, string>
	 */
	private static function credit_columns( array $credit ): array {
		return array(
			'openverse_id' => $credit['openverse_id'] ?? '',
			'creator'      => $credit['creator'] ?? '',
			'license'      => Cuisine_Photos::license_label( $credit ),
			'source'       => $credit['source_url'] ?? '',
		);
	}
}
