<?php
/**
 * Plugin Name: Autotix
 * Plugin URI:  https://autotix.io
 * Description: Captures PHP errors, warnings, and exceptions and sends them to your Autotix webhook for automated ticket creation and auto-fix PRs.
 * Version:     1.0.0
 * Author:      Hello World Devs
 * Author URI:  https://helloworlddevs.com
 * License:     GPL-2.0-or-later
 * Text Domain: autotix
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AUTOTIX_VERSION', '1.0.0' );
define( 'AUTOTIX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTOTIX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AUTOTIX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Vendor autoloader: required for autotix/php-sdk + nyholm/psr7. The .zip
// release ships with vendor/ pre-scoped via php-scoper (see scoper.inc.php)
// so multiple plugins shipping the same packages don't collide.
if ( file_exists( AUTOTIX_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once AUTOTIX_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes.
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-wp-http-client.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-wp-state-recorder.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-wp-error-log-logger.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-webhook-client.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-dedup.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-error-handler.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-settings.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-test-error-page.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-env-reporter.php';

/**
 * Bootstrap the plugin.
 */
function autotix_init(): void {
	$options = get_option( 'autotix_settings', [] );

	// Always load the admin settings page and test error page.
	if ( is_admin() ) {
		new Autotix\Settings();
		new Autotix\Test_Error_Page();
	}

	// Only register error handlers when the plugin is enabled.
	if ( empty( $options['enabled'] ) ) {
		return;
	}

	$client  = new Autotix\Webhook_Client( $options );
	$dedup   = new Autotix\Dedup( $options );
	$handler = new Autotix\Error_Handler( $client, $dedup, $options );
	$handler->register();

	// Safety net: if the env-report cron wasn't scheduled (e.g. plugin
	// updated in place without re-activation), schedule it now.
	if ( ! wp_next_scheduled( 'autotix_report_env' ) ) {
		wp_schedule_event( time(), 'daily', 'autotix_report_env' );
	}
}
add_action( 'plugins_loaded', 'autotix_init', 5 );

/**
 * Set sane defaults on activation.
 */
function autotix_activate(): void {
	$defaults = [
		'enabled'            => false,
		'auth_method'        => 'token',
		'auth_token'         => '',
		'auth_secret'        => '',
		'send_immediately'   => true,
		'severity_threshold' => E_ERROR,
		'capture_warnings'   => true,
		'capture_notices'    => false,
		'capture_deprecated' => false,
		'environment'        => wp_get_environment_type(),
		'dedup_window'       => 86400,
		'include_backtrace'  => false,
		'debug'              => false,
		'timeout'            => 20,
	];

	// Only set defaults — don't overwrite existing config.
	if ( ! get_option( 'autotix_settings' ) ) {
		add_option( 'autotix_settings', $defaults );
	}
}
register_activation_hook( __FILE__, 'autotix_activate' );

/**
 * Register WP-Cron schedule for queued delivery.
 */
function autotix_cron_schedules( array $schedules ): array {
	$schedules['autotix_every_minute'] = [
		'interval' => 60,
		'display'  => __( 'Every Minute (Autotix)', 'autotix' ),
	];
	return $schedules;
}
add_filter( 'cron_schedules', 'autotix_cron_schedules' );

/**
 * Process the error queue via WP-Cron.
 */
function autotix_process_queue(): void {
	$queue = get_option( 'autotix_queue', [] );
	if ( empty( $queue ) ) {
		return;
	}

	$options = get_option( 'autotix_settings', [] );
	$client  = new Autotix\Webhook_Client( $options );

	$remaining = [];
	foreach ( $queue as $payload ) {
		try {
			$client->send( $payload );
		} catch ( \Exception $e ) {
			// Keep failed items for retry on next cron run (max 3 retries).
			$payload['_retries'] = ( $payload['_retries'] ?? 0 ) + 1;
			if ( $payload['_retries'] <= 3 ) {
				$remaining[] = $payload;
			}
		}
	}

	update_option( 'autotix_queue', $remaining, false );
}
add_action( 'autotix_process_queue', 'autotix_process_queue' );

/**
 * Schedule queue processing on activation, unschedule on deactivation.
 */
function autotix_schedule_cron(): void {
	if ( ! wp_next_scheduled( 'autotix_process_queue' ) ) {
		wp_schedule_event( time(), 'autotix_every_minute', 'autotix_process_queue' );
	}
}
register_activation_hook( __FILE__, 'autotix_schedule_cron' );

function autotix_unschedule_cron(): void {
	wp_clear_scheduled_hook( 'autotix_process_queue' );
	wp_clear_scheduled_hook( 'autotix_report_env' );
}
register_deactivation_hook( __FILE__, 'autotix_unschedule_cron' );

/**
 * Environment reporting for the Vulnerability Scanning feature.
 *
 * Runs daily via WP-Cron; the Env_Reporter itself no-ops (no network
 * call) unless the plugin fingerprint changed or the ~monthly recheck
 * window elapsed, so this is cheap to schedule daily.  The server drops
 * the report unless the customer enabled vuln scanning, so reporting is
 * safe to do unconditionally — the opt-in gate is server-side.
 */
function autotix_report_env(): void {
	$options = get_option( 'autotix_settings', [] );
	if ( empty( $options['enabled'] ) ) {
		return;
	}
	( new Autotix\Env_Reporter( $options ) )->maybe_report();
}
add_action( 'autotix_report_env', 'autotix_report_env' );

/**
 * Schedule env reporting on activation, and fire once immediately so a
 * freshly-installed plugin shows up in Known Issues without waiting a
 * day for the first cron tick.
 */
function autotix_schedule_env_report(): void {
	if ( ! wp_next_scheduled( 'autotix_report_env' ) ) {
		wp_schedule_event( time(), 'daily', 'autotix_report_env' );
	}
}
register_activation_hook( __FILE__, 'autotix_schedule_env_report' );

/**
 * Force an environment report the moment the admin saves an enabled
 * config — this IS the "I just set it up" moment, so there's no reason to
 * wait for cron or make them click "Re-report environment now" separately.
 * Mirrors the Drupal SettingsForm auto-report on save.
 *
 * Fail-soft: a telemetry error must never break the settings save. The
 * verdict is persisted to the separate autotix_env_report_state option
 * (not autotix_settings), so this cannot recurse through these hooks.
 */
function autotix_maybe_report_env_now( array $settings ): void {
	if ( empty( $settings['enabled'] ) ) {
		return;
	}
	try {
		( new Autotix\Env_Reporter( $settings ) )->maybe_report( true );
	} catch ( \Throwable $e ) {
		// Swallow — the save must succeed regardless.
	}
}
// update_option_{$option} fires with ($old_value, $value); add_option_{$option}
// with ($option, $value). Adapt each to the new settings array.
add_action( 'update_option_autotix_settings', function ( $old_value, $value ) {
	autotix_maybe_report_env_now( (array) $value );
}, 10, 2 );
add_action( 'add_option_autotix_settings', function ( $option, $value ) {
	autotix_maybe_report_env_now( (array) $value );
}, 10, 2 );

/**
 * Add a "Settings" link on the Plugins page.
 */
function autotix_plugin_action_links( array $links ): array {
	$url  = admin_url( 'options-general.php?page=autotix' );
	$link = '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'autotix' ) . '</a>';
	array_unshift( $links, $link );
	return $links;
}
add_filter( 'plugin_action_links_' . AUTOTIX_PLUGIN_BASENAME, 'autotix_plugin_action_links' );
