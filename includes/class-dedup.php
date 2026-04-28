<?php

namespace Autotix;

/**
 * Deduplication service using WordPress transients.
 *
 * Prevents the same error message from being sent multiple times within
 * a configurable time window.
 */
class Dedup {

	private int $window;

	public function __construct( array $options ) {
		$this->window = (int) ( $options['dedup_window'] ?? 86400 );
	}

	/**
	 * Check if this error has already been reported within the dedup window.
	 *
	 * @param string $channel  Error channel / type.
	 * @param string $message  The raw error message (before variable substitution).
	 * @return bool TRUE if this is a duplicate and should be skipped.
	 */
	public function is_duplicate( string $channel, string $message ): bool {
		if ( $this->window <= 0 ) {
			return false;
		}

		$key = 'autotix_dedup_' . md5( $channel . '|' . $message );

		// Transients are the WP equivalent of Drupal's cache API.
		if ( false !== get_transient( $key ) ) {
			return true;
		}

		// Mark as seen for the duration of the dedup window.
		set_transient( $key, 1, $this->window );
		return false;
	}
}
