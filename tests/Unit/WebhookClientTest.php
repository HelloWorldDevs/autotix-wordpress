<?php

namespace Autotix\Tests\Unit;

use Autotix\Webhook_Client;
use PHPUnit\Framework\TestCase;

/**
 * Tests focus on the WordPress-side adapter only — env-var-or-option
 * resolution and that the SDK delegation produces the expected outcome.
 * The HTTP / JSON / auth header wiring is owned by the SDK and tested
 * there.
 *
 * @coversDefaultClass \Autotix\Webhook_Client
 */
class WebhookClientTest extends TestCase {

	private string|false $previousErrorLog;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['autotix_wp_stub'] = [
			'transients' => [],
			'http_response' => [
				'response' => [ 'code' => 202 ],
				'body' => '{"ok":true}',
				'headers' => [],
			],
			'http_error_message' => null,
			'last_request' => null,
			'error_log' => [],
		];
		// Clear env vars between cases.
		putenv( 'AUTOTIX_AUTH_TOKEN' );
		putenv( 'AUTOTIX_HMAC_SECRET' );
		// Silence WpErrorLogLogger output during tests so the success
		// "delivered" lines don't pollute test output.
		$this->previousErrorLog = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		putenv( 'AUTOTIX_AUTH_TOKEN' );
		putenv( 'AUTOTIX_HMAC_SECRET' );
		ini_set( 'error_log', (string) $this->previousErrorLog );
		parent::tearDown();
	}

	public function test_returns_true_on_2xx_response(): void {
		$client = new Webhook_Client( [ 'auth_method' => 'none' ] );
		$this->assertTrue( $client->send( [ 'message' => 'hi' ] ) );
	}

	public function test_throws_on_non_2xx_response(): void {
		$GLOBALS['autotix_wp_stub']['http_response'] = [
			'response' => [ 'code' => 500 ],
			'body' => '',
			'headers' => [],
		];
		$client = new Webhook_Client( [ 'auth_method' => 'none' ] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'HTTP 500' );
		$client->send( [ 'message' => 'x' ] );
	}

	public function test_uses_token_from_options(): void {
		$client = new Webhook_Client( [
			'auth_method' => 'token',
			'auth_token' => 'iat_from_options',
		] );
		$client->send( [ 'message' => 'x' ] );

		$headers = $GLOBALS['autotix_wp_stub']['last_request']['args']['headers'];
		$this->assertSame( 'iat_from_options', $headers['X-Webhook-Token'] );
	}

	public function test_env_var_overrides_option_for_token(): void {
		putenv( 'AUTOTIX_AUTH_TOKEN=iat_from_env' );
		$client = new Webhook_Client( [
			'auth_method' => 'token',
			'auth_token' => 'iat_from_options',
		] );
		$client->send( [ 'message' => 'x' ] );

		$this->assertSame(
			'iat_from_env',
			$GLOBALS['autotix_wp_stub']['last_request']['args']['headers']['X-Webhook-Token']
		);
	}

	public function test_env_var_overrides_option_for_hmac_secret(): void {
		putenv( 'AUTOTIX_HMAC_SECRET=env-secret' );
		$client = new Webhook_Client( [
			'auth_method' => 'hmac',
			'auth_secret' => 'option-secret',
		] );
		$payload = [ 'message' => 'x' ];
		$client->send( $payload );

		$expectedBody = json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$expectedSig  = hash_hmac( 'sha256', $expectedBody, 'env-secret' );

		$this->assertSame(
			$expectedSig,
			$GLOBALS['autotix_wp_stub']['last_request']['args']['headers']['X-Webhook-Signature']
		);
	}

	public function test_omits_auth_headers_when_method_is_none(): void {
		$client = new Webhook_Client( [
			'auth_method' => 'none',
			'auth_token' => 'ignored',
			'auth_secret' => 'ignored',
		] );
		$client->send( [ 'message' => 'x' ] );

		$headers = $GLOBALS['autotix_wp_stub']['last_request']['args']['headers'];
		$this->assertArrayNotHasKey( 'X-Webhook-Token', $headers );
		$this->assertArrayNotHasKey( 'X-Webhook-Signature', $headers );
	}

	public function test_identifies_as_wordpress_via_user_agent(): void {
		$client = new Webhook_Client( [ 'auth_method' => 'none' ] );
		$client->send( [ 'message' => 'x' ] );

		$ua = $GLOBALS['autotix_wp_stub']['last_request']['args']['headers']['User-Agent'];
		$this->assertStringStartsWith( 'Autotix-WordPress/', $ua );
	}

	public function test_records_outcome_on_success(): void {
		$client = new Webhook_Client( [ 'auth_method' => 'none' ] );
		$client->send( [ 'message' => 'x' ] );

		$transients = $GLOBALS['autotix_wp_stub']['transients'];
		$this->assertSame( 'ok', $transients['autotix_last_status']['value'] );
		$this->assertSame( 1, $transients['autotix_total_delivered']['value'] );
	}

	public function test_records_outcome_on_failure_before_throwing(): void {
		$GLOBALS['autotix_wp_stub']['http_response'] = [
			'response' => [ 'code' => 500 ],
			'body' => '',
			'headers' => [],
		];
		$client = new Webhook_Client( [ 'auth_method' => 'none' ] );

		try {
			$client->send( [ 'message' => 'x' ] );
		} catch ( \RuntimeException ) {
			// Expected — outcome must still be recorded.
		}

		$transients = $GLOBALS['autotix_wp_stub']['transients'];
		$this->assertSame( 'failed', $transients['autotix_last_status']['value'] );
		$this->assertSame( 1, $transients['autotix_total_failed']['value'] );
	}

	public function test_passes_configured_timeout_to_http_request(): void {
		$client = new Webhook_Client( [ 'auth_method' => 'none', 'timeout' => 9 ] );
		$client->send( [ 'source' => 'wordpress', 'message' => 'x' ] );
		$this->assertSame( 9, $GLOBALS['autotix_wp_stub']['last_request']['args']['timeout'] );
	}
}
