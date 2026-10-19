<?php

namespace Autotix\Tests\Unit;

use Autotix\WpHttpClient;
use Autotix\WpHttpClientException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Tests the PSR-18 bridge over wp_remote_post(). We don't test the SDK's
 * behavior here — only that the bridge correctly translates between PSR-7
 * and WP's response array shape.
 *
 * @coversDefaultClass \Autotix\WpHttpClient
 */
class WpHttpClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset the WP stub state so tests don't leak into each other.
		$GLOBALS['autotix_wp_stub'] = [
			'transients' => [],
			'http_response' => null,
			'http_error_message' => null,
			'last_request' => null,
			'error_log' => [],
		];
	}

	private function makeRequest( string $body = '{"hi":1}' ) {
		$factory = new Psr17Factory();
		return $factory
			->createRequest( 'POST', 'https://example.test/ingest' )
			->withHeader( 'Content-Type', 'application/json' )
			->withHeader( 'X-Webhook-Token', 'iat_test' )
			->withBody( $factory->createStream( $body ) );
	}

	public function test_sends_method_url_headers_and_body_to_wp_remote_post(): void {
		$GLOBALS['autotix_wp_stub']['http_response'] = [
			'response' => [ 'code' => 202 ],
			'body' => '{"ok":true}',
			'headers' => [],
		];

		( new WpHttpClient() )->sendRequest( $this->makeRequest() );

		$captured = $GLOBALS['autotix_wp_stub']['last_request'];
		$this->assertSame( 'https://example.test/ingest', $captured['url'] );
		$this->assertSame( 'POST', $captured['args']['method'] );
		$this->assertSame( '{"hi":1}', $captured['args']['body'] );
		$this->assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );
		$this->assertSame( 'iat_test', $captured['args']['headers']['X-Webhook-Token'] );
	}

	public function test_returns_psr7_response_with_status_and_body(): void {
		$GLOBALS['autotix_wp_stub']['http_response'] = [
			'response' => [ 'code' => 202 ],
			'body' => '{"ok":true,"id":42}',
			'headers' => [ 'Content-Type' => 'application/json' ],
		];

		$response = ( new WpHttpClient() )->sendRequest( $this->makeRequest() );

		$this->assertSame( 202, $response->getStatusCode() );
		$this->assertSame( '{"ok":true,"id":42}', (string) $response->getBody() );
	}

	public function test_passes_non_2xx_responses_through_without_throwing(): void {
		// PSR-18 contract: don't throw on 4xx/5xx — let the caller decide.
		// SDK's WebhookClient does the throwing, this bridge just transports.
		$GLOBALS['autotix_wp_stub']['http_response'] = [
			'response' => [ 'code' => 500 ],
			'body' => 'Internal Server Error',
			'headers' => [],
		];

		$response = ( new WpHttpClient() )->sendRequest( $this->makeRequest() );

		$this->assertSame( 500, $response->getStatusCode() );
		$this->assertSame( 'Internal Server Error', (string) $response->getBody() );
	}

	public function test_throws_psr18_compliant_exception_on_wp_error(): void {
		$GLOBALS['autotix_wp_stub']['http_error_message'] = 'cURL error 7: Connection refused';

		$this->expectException( ClientExceptionInterface::class );
		$this->expectExceptionMessage( 'Connection refused' );

		( new WpHttpClient() )->sendRequest( $this->makeRequest() );
	}

	public function test_exception_carries_the_originating_request(): void {
		$GLOBALS['autotix_wp_stub']['http_error_message'] = 'timeout';
		$request = $this->makeRequest();

		try {
			( new WpHttpClient() )->sendRequest( $request );
			$this->fail( 'Expected WpHttpClientException' );
		} catch ( WpHttpClientException $e ) {
			$this->assertSame( $request, $e->getRequest() );
		}
	}

	public function test_redirects_are_disabled_so_the_webhook_endpoint_cant_redirect_us_elsewhere(): void {
		$GLOBALS['autotix_wp_stub']['http_response'] = [
			'response' => [ 'code' => 200 ],
			'body' => '',
			'headers' => [],
		];

		( new WpHttpClient() )->sendRequest( $this->makeRequest() );

		$this->assertSame( 0, $GLOBALS['autotix_wp_stub']['last_request']['args']['redirection'] );
	}

	public function test_uses_twenty_second_timeout_by_default(): void {
		( new WpHttpClient() )->sendRequest( $this->makeRequest() );
		$this->assertSame( 20, $GLOBALS['autotix_wp_stub']['last_request']['args']['timeout'] );
	}

	public function test_uses_configured_timeout(): void {
		( new WpHttpClient( 7 ) )->sendRequest( $this->makeRequest() );
		$this->assertSame( 7, $GLOBALS['autotix_wp_stub']['last_request']['args']['timeout'] );
	}

	public function test_clamps_timeout_to_settings_range(): void {
		( new WpHttpClient( 0 ) )->sendRequest( $this->makeRequest() );
		$this->assertSame( 1, $GLOBALS['autotix_wp_stub']['last_request']['args']['timeout'] );
		( new WpHttpClient( 600 ) )->sendRequest( $this->makeRequest() );
		$this->assertSame( 60, $GLOBALS['autotix_wp_stub']['last_request']['args']['timeout'] );
	}
}
