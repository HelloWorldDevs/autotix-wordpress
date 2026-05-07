<?php

namespace Autotix\Tests\Unit;

use Autotix\WpStateRecorder;
use PHPUnit\Framework\TestCase;

/**
 * Tests the WordPress-side adapter for the SDK's StateRecorderInterface.
 * Just verifies the right transients get written; the SDK owns the
 * "when to call this" contract.
 *
 * @coversDefaultClass \Autotix\WpStateRecorder
 */
class WpStateRecorderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['autotix_wp_stub']['transients'] = [];
	}

	private function transients(): array {
		return $GLOBALS['autotix_wp_stub']['transients'];
	}

	public function test_records_ok_outcome(): void {
		( new WpStateRecorder() )->recordOutcome( 'ok' );

		$t = $this->transients();
		$this->assertSame( 'ok', $t['autotix_last_status']['value'] );
		$this->assertIsInt( $t['autotix_last_delivery_at']['value'] );
		$this->assertSame( 1, $t['autotix_total_delivered']['value'] );
		$this->assertArrayNotHasKey( 'autotix_total_failed', $t );
	}

	public function test_records_failed_outcome(): void {
		( new WpStateRecorder() )->recordOutcome( 'failed' );

		$t = $this->transients();
		$this->assertSame( 'failed', $t['autotix_last_status']['value'] );
		$this->assertSame( 1, $t['autotix_total_failed']['value'] );
		$this->assertArrayNotHasKey( 'autotix_total_delivered', $t );
	}

	public function test_increments_counters_across_calls(): void {
		$recorder = new WpStateRecorder();
		$recorder->recordOutcome( 'ok' );
		$recorder->recordOutcome( 'ok' );
		$recorder->recordOutcome( 'failed' );
		$recorder->recordOutcome( 'ok' );

		$t = $this->transients();
		$this->assertSame( 3, $t['autotix_total_delivered']['value'] );
		$this->assertSame( 1, $t['autotix_total_failed']['value'] );
	}

	public function test_uses_long_ttl_for_cross_request_survival(): void {
		( new WpStateRecorder() )->recordOutcome( 'ok' );

		// 30 days in seconds. Long enough that low-traffic dev environments
		// don't show "never delivered" between hourly health checks.
		$expected = 30 * DAY_IN_SECONDS;
		foreach ( $this->transients() as $entry ) {
			$this->assertSame( $expected, $entry['ttl'] );
		}
	}
}
