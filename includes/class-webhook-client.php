<?php

namespace Autotix;

/**
 * HTTP client that sends error payloads to the Autotix webhook endpoint.
 */
class Webhook_Client {

	/** Production webhook endpoint — not configurable. */
	private const WEBHOOK_URL = 'https://app.autotix.io/api/webhook/error';

	private array $options;

	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Send a payload to the Autotix webhook.
	 *
	 * @param array $payload The error payload.
	 * @return bool TRUE on success.
	 * @throws \RuntimeException On failure.
	 */
	public function send( array $payload ): bool {
		$url = self::WEBHOOK_URL;

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			throw new \RuntimeException( 'Failed to encode Autotix webhook payload as JSON.' );
		}

		$headers = [
			'Content-Type' => 'application/json',
			'User-Agent'   => 'Autotix-WordPress/' . AUTOTIX_VERSION,
		];

		// Authentication — prefer environment variables over options.
		$auth_method = $this->options['auth_method'] ?? 'token';
		$token       = $this->get_env_or_option( 'AUTOTIX_AUTH_TOKEN', 'auth_token' );
		$secret      = $this->get_env_or_option( 'AUTOTIX_HMAC_SECRET', 'auth_secret' );

		if ( 'token' === $auth_method && $token ) {
			$headers['X-Webhook-Token'] = $token;
		} elseif ( 'hmac' === $auth_method && $secret ) {
			$headers['X-Webhook-Signature'] = hash_hmac( 'sha256', $body, $secret );
		}

		$debug = ! empty( $this->options['debug'] );

		if ( $debug ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[Autotix] Sending to %s | source: %s | level: %s | message: %s',
				$url,
				$payload['source'] ?? '(none)',
				$payload['level'] ?? '(none)',
				mb_strimwidth( $payload['message'] ?? '(empty)', 0, 200, '...' )
			) );
		}

		$response = wp_remote_post( $url, [
			'body'    => $body,
			'headers' => $headers,
			'timeout' => (int) ( $this->options['timeout'] ?? 20 ),
		] );

		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[Autotix] Delivery FAILED (network): {$error_msg}" );
			throw new \RuntimeException( "Autotix webhook request failed: {$error_msg}" );
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "[Autotix] Delivery FAILED - HTTP {$status} from {$url}" );
			throw new \RuntimeException( "Autotix webhook returned HTTP {$status}." );
		}

		// Always log successful deliveries.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[Autotix] Delivered error to %s - source: %s | level: %s | message: %s',
			$url,
			$payload['source'] ?? '(none)',
			$payload['level'] ?? '(none)',
			mb_strimwidth( $payload['message'] ?? '(empty)', 0, 120, '...' )
		) );

		return true;
	}

	/**
	 * Get a value from an environment variable, falling back to the saved option.
	 */
	private function get_env_or_option( string $env_name, string $option_key ): string {
		$env = getenv( $env_name );
		if ( ! empty( $env ) ) {
			return $env;
		}
		return $this->options[ $option_key ] ?? '';
	}
}
