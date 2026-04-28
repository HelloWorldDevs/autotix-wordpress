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

// Load classes.
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-webhook-client.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-dedup.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-error-handler.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-settings.php';
require_once AUTOTIX_PLUGIN_DIR . 'includes/class-test-error-page.php';

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
}
register_deactivation_hook( __FILE__, 'autotix_unschedule_cron' );

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
