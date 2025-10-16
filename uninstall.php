<?php

/**
 * Uninstall script for GForm Spamfighter.
 *
 * @package GformSpamfighter
 */

// If uninstall not called from WordPress, exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete options.
delete_option('gform_spamfighter_settings');

// Delete multisite options.
if (is_multisite()) {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Multisite blog lookup during uninstall.
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");

    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        delete_option('gform_spamfighter_settings');
        restore_current_blog();
    }
}

// Drop database tables.
global $wpdb;
$table_name = $wpdb->prefix . 'gform_spam_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table cleanup on uninstall.
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

// Delete log files.
$upload_dir = wp_upload_dir();
$log_dir    = $upload_dir['basedir'] . '/gform-spamfighter-logs';

if (is_dir($log_dir)) {
    $files = glob($log_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}
