<?php
/**
 * @file
 * Bootstrap for Autotix WordPress unit tests.
 *
 * Loads Composer's autoloader (for autotix/php-sdk + nyholm/psr7) and the
 * WordPress function stubs, then loads the plugin's own classes — without
 * any of WP core.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Stubs/WordPressStubs.php';

// Plugin classes under test.
if ( ! defined( 'AUTOTIX_VERSION' ) ) {
	define( 'AUTOTIX_VERSION', '1.0.0-test' );
}
require_once __DIR__ . '/../includes/class-wp-http-client.php';
require_once __DIR__ . '/../includes/class-wp-state-recorder.php';
require_once __DIR__ . '/../includes/class-wp-error-log-logger.php';
require_once __DIR__ . '/../includes/class-webhook-client.php';
require_once __DIR__ . '/../includes/class-env-reporter.php';
