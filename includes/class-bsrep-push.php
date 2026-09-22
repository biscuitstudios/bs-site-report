<?php
/**
 * Sends the payload to Biscuit.
 *
 * Push only. There is deliberately no public endpoint that serves the payload on
 * request: a pull route would be forty-eight new pieces of attack surface on
 * forty-eight client sites, all answering to whoever works out the secret, and
 * the payload describes the site's login posture. A site that only ever speaks
 * outward cannot be asked anything by a stranger.
 *
 * @package BiscuitSiteReport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signs and posts the payload.
 */
class Bsrep_Push {

	/**
	 * Option holding the receiver URL.
	 */
	const URL_OPTION = 'bsrep_endpoint_url';

	/**
	 * Option holding the shared secret, used only when BSREP_SECRET is undefined.
	 */
	const SECRET_OPTION = 'bsrep_shared_secret';

	/**
	 * Option holding the outcome of the last push.
	 */
	const LAST_OPTION = 'bsrep_last_push';

	/**
	 * Register the cron handler.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'bsrep_push', array( $this, 'run' ) );
	}

	/**
	 * The shared secret.
	 *
	 * A constant in wp-config.php wins, because a secret in the options table is
	 * in every backup and every database export. The option is the fallback so a
	 * site can be brought up without a wp-config edit.
	 *
	 * @return string
	 */
	public static function secret() {
		if ( defined( 'BSREP_SECRET' ) && is_string( BSREP_SECRET ) && '' !== BSREP_SECRET ) {
			return BSREP_SECRET;
		}

		$stored = get_option( self::SECRET_OPTION );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$generated = wp_generate_password( 64, false, false );
		update_option( self::SECRET_OPTION, $generated, false );

		return $generated;
	}

	/**
	 * Where the payload goes.
	 *
	 * @return string
	 */
	public static function endpoint() {
		if ( defined( 'BSREP_ENDPOINT' ) && is_string( BSREP_ENDPOINT ) ) {
			return BSREP_ENDPOINT;
		}

		return (string) get_option( self::URL_OPTION, '' );
	}

	/**
	 * Build, sign and send.
	 *
	 * @return array{sent:bool,status:int,message:string}
	 */
	public function run() {
		$endpoint = self::endpoint();

		if ( '' === $endpoint || ! wp_http_validate_url( $endpoint ) ) {
			return $this->remember(
				array(
					'sent'    => false,
					'status'  => 0,
					'message' => 'No valid receiver URL is configured, so nothing was sent.',
				)
			);
		}

		$payload = ( new Bsrep_Collector() )->build();
		$body    = wp_json_encode( $payload );

		if ( false === $body ) {
			return $this->remember(
				array(
					'sent'    => false,
					'status'  => 0,
					'message' => 'The payload could not be encoded as JSON, so nothing was sent.',
				)
			);
		}

		$timestamp = (string) time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, self::secret() );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'  => 30,
				'headers'  => array(
					'Content-Type'        => 'application/json; charset=utf-8',
					'X-Bsrep-Site'        => home_url( '/' ),
					'X-Bsrep-Timestamp'   => $timestamp,
					'X-Bsrep-Signature'   => $signature,
					'X-Bsrep-Collector'   => BSREP_VERSION,
				),
				'body'     => $body,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->remember(
				array(
					'sent'    => false,
					'status'  => 0,
					'message' => $response->get_error_message(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return $this->remember(
			array(
				'sent'    => $status >= 200 && $status < 300,
				'status'  => $status,
				'message' => $status >= 200 && $status < 300
					? 'Sent.'
					: 'The receiver answered ' . $status . '.',
			)
		);
	}

	/**
	 * Store the outcome so the admin screen can show it.
	 *
	 * @param array $result Result.
	 * @return array The same result.
	 */
	protected function remember( array $result ) {
		$result['at'] = gmdate( 'c' );

		update_option( self::LAST_OPTION, $result, false );

		return $result;
	}
}
