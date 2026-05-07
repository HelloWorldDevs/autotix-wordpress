<?php

namespace Autotix;

use Autotix\PhpSdk\StateRecorderInterface;

/**
 * Adapter that satisfies the SDK's StateRecorderInterface using WordPress
 * transients. Long-lived (30 days) so the admin status widget can show
 * "last delivery N seconds ago / X delivered / Y failed" across requests.
 */
class WpStateRecorder implements StateRecorderInterface {

	private const TTL = 30 * DAY_IN_SECONDS;

	public function recordOutcome( string $status, array $context = [] ): void {
		try {
			set_transient( 'autotix_last_status', $status, self::TTL );
			set_transient( 'autotix_last_delivery_at', time(), self::TTL );
			$key     = 'ok' === $status ? 'autotix_total_delivered' : 'autotix_total_failed';
			$current = (int) get_transient( $key );
			set_transient( $key, $current + 1, self::TTL );
		} catch ( \Throwable ) {
			// State writes never break delivery.
		}
	}
}
