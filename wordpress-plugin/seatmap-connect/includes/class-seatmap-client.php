<?php
/**
 * Signed HTTP client for the Seatmap API.
 *
 * @package SeatmapConnect
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every server-to-server call goes through here so signing, idempotency and retries are applied
 * consistently. Nothing else in the plugin builds a request by hand.
 */
class Seatmap_Client {

	private const TIMEOUT = 15;

	/** Idempotent verbs are safe to retry; the API is idempotent for the rest too, but only when the caller reuses its key. */
	private const RETRY_ATTEMPTS = 3;

	private string $base_url;
	private string $key_id;
	private string $secret;

	public function __construct( ?string $base_url = null, ?string $key_id = null, ?string $secret = null ) {
		$this->base_url = untrailingslashit( $base_url ?? (string) get_option( 'seatmap_api_url', '' ) );
		$this->key_id   = $key_id ?? (string) get_option( 'seatmap_api_key_id', '' );
		$this->secret   = $secret ?? (string) get_option( 'seatmap_api_secret', '' );
	}

	public function is_configured(): bool {
		return '' !== $this->base_url && '' !== $this->key_id && '' !== $this->secret;
	}

	public function base_url(): string {
		return $this->base_url;
	}

	/**
	 * Public widget endpoints. Unsigned by design: the browser holds no secret, and these expose
	 * only what the venue already shows publicly.
	 *
	 * @return array|WP_Error
	 */
	public function get_public( string $path, array $query = array() ) {
		$url = $this->base_url . $path;

		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		return $this->parse( wp_remote_get( $url, array( 'timeout' => self::TIMEOUT ) ) );
	}

	/**
	 * Unsigned POST to a public widget endpoint.
	 *
	 * Holds are created through the store rather than from the browser so the *server's* prices are
	 * what reach the cart; the endpoint itself needs no credential.
	 *
	 * @return array|WP_Error
	 */
	public function get_public_post( string $path, array $body ) {
		return $this->parse(
			wp_remote_post(
				$this->base_url . $path,
				array(
					'timeout' => self::TIMEOUT,
					'headers' => array(
						'Content-Type'    => 'application/json',
						'Accept'          => 'application/json',
						'Idempotency-Key' => wp_generate_uuid4(),
					),
					'body'    => wp_json_encode( $body ),
				)
			)
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function get_public_delete( string $path ) {
		return $this->parse(
			wp_remote_request(
				$this->base_url . $path,
				array(
					'method'  => 'DELETE',
					'timeout' => self::TIMEOUT,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			)
		);
	}

	/**
	 * Signed server-to-server request.
	 *
	 * @param string      $method          HTTP verb.
	 * @param string      $path            Path beginning with /v1.
	 * @param array|null  $body            Payload, encoded as JSON.
	 * @param string|null $idempotency_key Reused verbatim across retries — this is what makes a
	 *                                     lost response safe to re-send.
	 * @return array|WP_Error
	 */
	public function request( string $method, string $path, ?array $body = null, ?string $idempotency_key = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'seatmap_not_configured', __( 'Seatmap Connect is not configured yet.', 'seatmap-connect' ) );
		}

		$method  = strtoupper( $method );
		$payload = null === $body ? '' : (string) wp_json_encode( $body );

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::RETRY_ATTEMPTS; $attempt++ ) {
			// A fresh timestamp and nonce per attempt: the API rejects a reused nonce, so retrying
			// with the original headers would fail even when the original request never landed.
			// The Idempotency-Key deliberately stays the same, and that is what prevents a
			// duplicate sale.
			$response = $this->send( $method, $path, $payload, $idempotency_key );

			if ( ! is_wp_error( $response ) ) {
				return $response;
			}

			$last_error = $response;

			if ( ! $this->is_retryable( $response ) ) {
				return $response;
			}

			Seatmap_Logger::log(
				'retry',
				sprintf( 'Attempt %d of %s %s failed: %s', $attempt, $method, $path, $response->get_error_message() )
			);

			if ( $attempt < self::RETRY_ATTEMPTS ) {
				// 1s, then 3s. Long enough to ride out a blip, short enough not to stall checkout.
				sleep( 1 + ( ( $attempt - 1 ) * 2 ) );
			}
		}

		return $last_error;
	}

	/**
	 * @return array|WP_Error
	 */
	private function send( string $method, string $path, string $payload, ?string $idempotency_key ) {
		$timestamp = (string) time();
		$nonce     = wp_generate_uuid4();

		$canonical = implode(
			"\n",
			array(
				$method,
				$path,
				$timestamp,
				$nonce,
				hash( 'sha256', $payload ),
			)
		);

		$headers = array(
			'Content-Type'        => 'application/json',
			'Accept'              => 'application/json',
			'X-Seatmap-Key'       => $this->key_id,
			'X-Seatmap-Timestamp' => $timestamp,
			'X-Seatmap-Nonce'     => $nonce,
			'X-Seatmap-Signature' => hash_hmac( 'sha256', $canonical, $this->secret ),
			'X-Request-Id'        => wp_generate_uuid4(),
		);

		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers,
		);

		if ( '' !== $payload ) {
			$args['body'] = $payload;
		}

		return $this->parse( wp_remote_request( $this->base_url . $path, $args ) );
	}

	/**
	 * @param array|WP_Error $response Raw wp_remote_* result.
	 * @return array|WP_Error
	 */
	private function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'seatmap_transport', $response->get_error_message(), array( 'transport' => true ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $body ) ? $body : array();
		}

		$code    = $body['error']['code'] ?? 'http_' . $status;
		$message = $body['error']['message'] ?? sprintf(
			/* translators: %d: HTTP status code. */
			__( 'The seating service returned status %d.', 'seatmap-connect' ),
			$status
		);

		return new WP_Error(
			'seatmap_' . $code,
			$message,
			array(
				'status'  => $status,
				'code'    => $code,
				'details' => $body['error']['details'] ?? array(),
			)
		);
	}

	/**
	 * Retry only what might succeed next time.
	 *
	 * A 409 "seat unavailable" or a 422 will not change on retry and must surface immediately; a
	 * timeout or a 502 very well might.
	 */
	private function is_retryable( WP_Error $error ): bool {
		$data = $error->get_error_data();

		if ( ! empty( $data['transport'] ) ) {
			return true;
		}

		$status = (int) ( $data['status'] ?? 0 );

		return 429 === $status || $status >= 500;
	}
}
