<?php
/**
 * Autotix uninstall — clean up options and transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'autotix_settings' );
delete_option( 'autotix_queue' );

// Remove dedup transients (they'll expire on their own, but clean up is polite).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_autotix_dedup_%' OR option_name LIKE '_transient_timeout_autotix_dedup_%'"
);

// Unschedule cron.
wp_clear_scheduled_hook( 'autotix_process_queue' );
