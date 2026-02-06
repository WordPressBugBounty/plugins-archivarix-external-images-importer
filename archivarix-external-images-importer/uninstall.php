<?php
/**
 * Uninstall file for Archivarix External Images Importer
 *
 * This file is executed when the plugin is deleted from WordPress.
 * It removes all plugin data from the database.
 *
 * @package Archivarix External Images Importer
 * @since 2.0.0
 */

// Exit if accessed directly or not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'aeii_options' );
delete_option( 'aeii_scan_results' );
delete_option( 'aeii_queue_position' );
delete_option( 'aeii_background_running' );
delete_option( 'aeii_url_cache' );
delete_option( 'aeii_success_count' );
delete_option( 'aeii_cached_count' );
delete_option( 'aeii_failed_count' );
delete_option( 'aeii_removed_count' );
delete_option( 'aeii_placeholder_count' );
delete_option( 'aeii_archive_error' );
delete_option( 'aeii_archive_error_time' );
delete_option( 'aeii_archive_429_retries' );

// Clear scheduled events.
wp_clear_scheduled_hook( 'aeii_background_process' );
wp_clear_scheduled_hook( 'aeii_images_process_cron' );

// Delete transients.
delete_transient( 'aeii_images_process_process_lock' );
delete_transient( 'aeii_images_process_completed' );
delete_site_transient( 'aeii_images_process_process_lock' );
delete_site_transient( 'aeii_images_process_completed' );

// Delete all batch data and filename index transients.
global $wpdb;

// Delete batch data.
$table  = $wpdb->options;
$column = 'option_name';

if ( is_multisite() ) {
	$table  = $wpdb->sitemeta;
	$column = 'meta_key';
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$table} WHERE {$column} LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->esc_like( 'aeii_images_process_batch_' ) . '%'
	)
);

// Delete filename index transients and URL locks.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE %s 
            OR option_name LIKE %s
            OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_aeii_idx_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_aeii_idx_' ) . '%',
		$wpdb->esc_like( 'aeii_lock_' ) . '%'
	)
);

// Delete log files directory.
$upload_dir = wp_upload_dir();
$logs_dir   = $upload_dir['basedir'] . '/archivarix-logs';

if ( is_dir( $logs_dir ) ) {
	// Delete all files in the directory.
	$aeii_files = glob( $logs_dir . '/*' );
	foreach ( $aeii_files as $aeii_file ) {
		if ( is_file( $aeii_file ) ) {
			wp_delete_file( $aeii_file );
		}
	}
	// Remove the directory using WP_Filesystem.
	global $wp_filesystem;
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}
	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $logs_dir );
	}
}
