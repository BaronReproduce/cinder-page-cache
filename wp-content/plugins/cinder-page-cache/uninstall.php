<?php
/**
 * Uninstall routine.
 *
 * @package Cinder Page Cache
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach (array('cpc_stats', 'cpc_stats_url', 'cpc_report_at', 'cpc_install_at', 'cpc_settings', 'cpc_consent', 'cpc_consent_secrets', 'cpc_report_v', 'cpc_touch', 'cpc_metrics_due') as $option) {
    delete_option($option);
}

foreach (array('cpc_seen', 'cpc_due', 'cpc_sent', 'cpc_since', 'cpc_env_check', 'cpc_cloud', 'cpc_policy') as $key) {
    delete_transient($key);
}

foreach (array('cpc_cache_expire', 'cpc_cache_preload', 'cpc_db_maintenance') as $hook) {
    wp_clear_scheduled_hook($hook);
}

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cinder-page-cache_redirects');
delete_option('cpc_redirects_db');

$dir = WP_CONTENT_DIR . '/cache/cinder-page-cache';

foreach ((array) glob($dir . '/*') as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}

if (is_dir($dir)) {
    @rmdir($dir);
}
