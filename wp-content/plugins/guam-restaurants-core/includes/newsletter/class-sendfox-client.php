<?php
/**
 * Creates newsletter drafts in SendFox.
 *
 * @package GuamRestaurants\Core
 */

namespace GuamRestaurants\Core\Newsletter;

defined( 'ABSPATH' ) || exit;

/**
 * Calls POST https://api.sendfox.com/campaigns, which creates a campaign as
 * a draft (https://sendfox.com/developer/docs). This client never sends
 * `scheduled_at` and never calls the send endpoint, so a person always
 * reviews and sends the issue in SendFox.
 *
 * SendFox's API needs a paid plan: on the free plan every call returns HTTP
 * 402. One attempt only, so a timeout cannot create two drafts.
 */
class Sendfox_Client {

	public const ENDPOINT = 'https://api.sendfox.com/campaigns';

	/**
	 * Personal access token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Sends the request; same signature as wp_remote_request().
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param string        $token     Personal access token.
	 * @param callable|null $transport Defaults to wp_remote_request().
	 */
	public function __construct( string $token, ?callable $transport = null ) {
		$this->token     = $token;
		$this->transport = $transport ?? 'wp_remote_request';
	}

	/**
	 * Creates a draft campaign.
	 *
	 * @param array{title: string, subject: string, html: string, preview_text: string, from_name: string, from_email: string, lists: int[]} $campaign Campaign.
	 * @return array{ok: bool, id: int, code: int, error: string}
	 */
	public function create_draft( array $campaign ): array {
		$body = array(
			'title'        => mb_substr( $campaign['title'], 0, 191 ),
			'subject'      => mb_substr( $campaign['subject'], 0, 191 ),
			'html'         => $campaign['html'],
			'preview_text' => mb_substr( $campaign['preview_text'], 0, 191 ),
			'from_name'    => mb_substr( $campaign['from_name'], 0, 191 ),
			'from_email'   => mb_substr( $campaign['from_email'], 0, 191 ),
		);
		if ( $campaign['lists'] ) {
			$body['lists'] = array_values( array_map( 'intval', $campaign['lists'] ) );
		}

		$response = call_user_func(
			$this->transport,
			self::ENDPOINT,
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( false, 0, 0, $response->get_error_message() );
		}
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code >= 200 && $code < 300 && ! empty( $decoded['id'] ) ) {
			return self::result( true, (int) $decoded['id'], $code, '' );
		}
		return self::result( false, 0, $code, self::error_text( $code, $decoded ) );
	}

	/**
	 * A readable reason for a failed call.
	 *
	 * @param int                  $code    HTTP status.
	 * @param array<string, mixed> $decoded Response body.
	 * @return string
	 */
	public static function error_text( int $code, array $decoded ): string {
		switch ( $code ) {
			case 401:
				return __( 'SendFox rejected the token. Create a new personal access token in SendFox and update GUAM_RESTAURANTS_SENDFOX_TOKEN.', 'guam-restaurants-core' );
			case 402:
				return __( 'SendFox\'s API needs a paid SendFox plan. Copy the HTML below into a new SendFox campaign instead.', 'guam-restaurants-core' );
			case 429:
				return __( 'SendFox is rate limiting requests. Try again in a minute.', 'guam-restaurants-core' );
		}
		$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : '';
		/* translators: 1: HTTP status code, 2: message from SendFox. */
		return trim( sprintf( __( 'SendFox returned HTTP %1$d. %2$s', 'guam-restaurants-core' ), $code, sanitize_text_field( $message ) ) );
	}

	/**
	 * Result shape.
	 *
	 * @param bool   $ok    Success.
	 * @param int    $id    Campaign ID.
	 * @param int    $code  HTTP status.
	 * @param string $error Error.
	 * @return array{ok: bool, id: int, code: int, error: string}
	 */
	private static function result( bool $ok, int $id, int $code, string $error ): array {
		return array(
			'ok'    => $ok,
			'id'    => $id,
			'code'  => $code,
			'error' => $error,
		);
	}
}
