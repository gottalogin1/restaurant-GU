<?php
/**
 * Request that failed after every retry.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by Retrying_Client, carrying the failed attempts so the ingestion
 * run can record them.
 */
class Request_Failed extends \RuntimeException {

	/**
	 * Failed attempts.
	 *
	 * @var string[]
	 */
	private array $attempt_log;

	/**
	 * Constructor.
	 *
	 * @param string   $message     Message.
	 * @param string[] $attempt_log Failed attempts.
	 */
	public function __construct( string $message, array $attempt_log = array() ) {
		parent::__construct( $message );
		$this->attempt_log = $attempt_log;
	}

	/**
	 * Failed attempts.
	 *
	 * @return string[]
	 */
	public function attempt_log(): array {
		return $this->attempt_log;
	}
}
