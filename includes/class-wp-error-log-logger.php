<?php

namespace Autotix;

/**
 * PSR-3 logger implementation backed by WordPress's `error_log()`
 * (typically debug.log on most hosts). Each level prefixes the entry so
 * admins can grep by severity.
 *
 * Implementing PSR-3 inline avoids vendoring a fuller logger package.
 */
class WpErrorLogLogger extends \Psr\Log\AbstractLogger {

	public function log( $level, string|\Stringable $message, array $context = [] ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[Autotix:%s] %s%s',
			strtoupper( (string) $level ),
			(string) $message,
			$context ? ' ' . wp_json_encode( $context ) : ''
		) );
	}
}
