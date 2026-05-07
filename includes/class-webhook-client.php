<?php

namespace Autotix;

use Autotix\PhpSdk\WebhookClient as CoreClient;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * WordPress-side adapter around \Autotix\PhpSdk\WebhookClient.
 *
 * The HTTP transport, JSON encoding, auth, exception shape, and outcome
 * recording all live in the shared SDK. This adapter only handles things
 * WordPress owns: env-var-or-option lookup for secrets, error_log() as
 * the fallback logger, and writing delivery outcomes to a transient so
 * the admin status widget reflects reality.
 *
 * If you find yourself adding non-WP logic here, push it down into
 * autotix/php-sdk so Drupal and Laravel inherit it for free.
 */
class Webhook_Client {

	private array $options;

	public function __construct( array $options ) {
		$this->options = $options;
	}

	/**
	 * Send a payload to the Autotix webhook.
	 *
	 * @throws \RuntimeException Non-2xx response or JSON encoding failure.
	 * @throws \Psr\Http\Client\ClientExceptionInterface Network failure.
	 */
	public function send( array $payload ): bool {
		$factory = new Psr17Factory();

		$core = new CoreClient(
			new WpHttpClient(),
			$factory,
			$factory,
			[
				'auth_method' => $this->options['auth_method'] ?? 'token',
				'auth_token'  => $this->get_env_or_option( 'AUTOTIX_AUTH_TOKEN', 'auth_token' ) ?: null,
				'auth_secret' => $this->get_env_or_option( 'AUTOTIX_HMAC_SECRET', 'auth_secret' ) ?: null,
				'debug'       => ! empty( $this->options['debug'] ),
				'user_agent'  => 'Autotix-WordPress/' . AUTOTIX_VERSION,
			],
			new WpStateRecorder(),
			new WpErrorLogLogger(),
		);

		return $core->send( $payload );
	}

	/**
	 * Get a value from an env var, falling back to the saved option.
	 * Env wins so secrets can stay out of the wp_options table.
	 */
	private function get_env_or_option( string $env_name, string $option_key ): string {
		$env = getenv( $env_name );
		if ( ! empty( $env ) ) {
			return $env;
		}
		return $this->options[ $option_key ] ?? '';
	}
}
