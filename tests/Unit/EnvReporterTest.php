<?php

namespace Autotix\Tests\Unit;

use Autotix\Env_Reporter;
use PHPUnit\Framework\TestCase;

/**
 * Tests the WordPress-side env reporter: that it captures and persists the
 * server's Vulnerability-Scanning verdict, honors the $force cadence bypass,
 * and no-ops without a network call when the fingerprint is unchanged.
 *
 * The SDK owns the actual HTTP/JSON/fingerprint work; here we drive the
 * response via the shared $GLOBALS['autotix_wp_stub'] seam (same pattern as
 * WebhookClientTest) and assert on the persisted verdict.
 *
 * @coversDefaultClass \Autotix\Env_Reporter
 */
class EnvReporterTest extends TestCase {

	private string|false $previousErrorLog;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['autotix_wp_stub'] = [
			'transients' => [],
			'options' => [],
			'plugins' => [ 'akismet/akismet.php' => [ 'Version' => '5.3' ] ],
			'http_response' => [
				'response' => [ 'code' => 200 ],
				'body' => '{"stored":true}',
				'headers' => [],
			],
			'http_error_message' => null,
			'last_request' => null,
			'error_log' => [],
		];
		putenv( 'AUTOTIX_AUTH_TOKEN' );
		$this->previousErrorLog = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		putenv( 'AUTOTIX_AUTH_TOKEN' );
		ini_set( 'error_log', (string) $this->previousErrorLog );
		parent::tearDown();
	}

	public function test_captures_accepted_verdict(): void {
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );

		$this->assertTrue( $reporter->maybe_report() );

		$last = $reporter->last_report();
		$this->assertNotNull( $last );
		$this->assertTrue( $last['sent'] );
		$this->assertTrue( $last['stored'] );
		$this->assertNull( $last['reason'] );
	}

	public function test_captures_declined_verdict(): void {
		$GLOBALS['autotix_wp_stub']['http_response']['body'] =
			'{"stored":false,"reason":"Vulnerability Scanning is not enabled"}';
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );

		// A decline is still a 200 → "sent" is true, but "stored" is false.
		$this->assertTrue( $reporter->maybe_report() );

		$last = $reporter->last_report();
		$this->assertTrue( $last['sent'] );
		$this->assertFalse( $last['stored'] );
		$this->assertSame( 'Vulnerability Scanning is not enabled', $last['reason'] );
	}

	public function test_captures_failure_verdict_on_network_error(): void {
		$GLOBALS['autotix_wp_stub']['http_error_message'] = 'Connection refused';
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );

		$this->assertFalse( $reporter->maybe_report() );

		// report() threw before responding, so getLastResult() is null and the
		// verdict records sent:false with null stored/reason.
		$last = $reporter->last_report();
		$this->assertNotNull( $last );
		$this->assertFalse( $last['sent'] );
		$this->assertNull( $last['stored'] );
	}

	public function test_force_bypasses_unchanged_short_circuit(): void {
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );
		$reporter->maybe_report(); // first send persists fingerprint + future next_check

		$GLOBALS['autotix_wp_stub']['last_request'] = null;
		$reporter->maybe_report( true ); // forced → must send again despite no change

		$this->assertNotNull( $GLOBALS['autotix_wp_stub']['last_request'] );
	}

	public function test_unchanged_fingerprint_no_ops_without_force(): void {
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );
		$reporter->maybe_report(); // persists fingerprint + next_check in the future

		$GLOBALS['autotix_wp_stub']['last_request'] = null;
		$sent = $reporter->maybe_report(); // unchanged + in window → skip network

		$this->assertFalse( $sent );
		$this->assertNull( $GLOBALS['autotix_wp_stub']['last_request'] );
	}

	public function test_next_check_at_null_until_reported_then_future(): void {
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );
		$this->assertNull( $reporter->next_check_at() );

		$reporter->maybe_report();

		$this->assertGreaterThan( time(), $reporter->next_check_at() );
	}

	public function test_last_report_null_before_any_report(): void {
		$reporter = new Env_Reporter( [ 'auth_token' => 't' ] );
		$this->assertNull( $reporter->last_report() );
	}
}
