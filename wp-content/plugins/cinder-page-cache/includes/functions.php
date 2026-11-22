<?php
/**
 * Shared helpers and settings.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Default settings.
 *
 * @return array
 */
function cpc_defaults() {
    return array(
        'cache_enabled'   => 1,
        'cache_ttl'       => 3600,
        'cache_logged_in' => 0,
        'exclude_paths'   => "/wp-admin\n/wp-login.php\n/cart\n/checkout\n/my-account",
        'purge_on_update' => 1,
        'html_minify'     => 1,
        'strip_comments'  => 1,
        'disable_emojis'  => 1,
        'disable_embeds'  => 1,
        'webp'            => 1,
        'avif'            => 1,
        'img_maxdim'      => 2048,
        'strip_exif'      => 1,
        'defer_js'        => 1,
        'delay_match'     => '',
        'css_inline'      => 1,
        'iframe_lazy'     => 1,
        'lcp_preload'     => 1,
        'disable_migrate' => 1,
        'cache_headers'   => 1,
        'backups'         => 1,
        'sitemap'         => 1,
        'og_tags'         => 1,
        'breadcrumbs'     => 1,
        'db_revision_days' => 30,
        'db_weekly'       => 1,
    );
}

/**
 * All settings merged with defaults.
 *
 * @return array
 */
function cpc_settings() {
    $stored = get_option('cpc_settings', array());

    return array_merge(cpc_defaults(), is_array($stored) ? $stored : array());
}

/**
 * One setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function cpc_setting($key) {
    $settings = cpc_settings();

    return isset($settings[$key]) ? $settings[$key] : null;
}

/**
 * Persist settings.
 *
 * @param array $input Raw input.
 * @return array
 */
function cpc_save_settings(array $input) {
    $clean = array();

    foreach (cpc_defaults() as $key => $default) {
        if (is_int($default)) {
            $clean[$key] = isset($input[$key]) ? (int) $input[$key] : 0;
        } else {
            $clean[$key] = isset($input[$key]) ? (string) $input[$key] : (string) $default;
        }
    }

    $clean['cache_ttl'] = max(60, min(86400, $clean['cache_ttl']));
    $clean['db_revision_days'] = max(1, min(3650, $clean['db_revision_days']));

    update_option('cpc_settings', $clean);

    return $clean;
}

/**
 * Counters with defaults.
 *
 * @return array
 */
function cpc_stats() {
    $defaults = array('hits' => 0, 'misses' => 0, 'load' => 0, 'cleared' => 0);
    $stored   = get_option('cpc_stats', array());

    return array_merge($defaults, is_array($stored) ? $stored : array());
}

/**
 * Update one counter.
 *
 * @param string $key   Counter.
 * @param float  $value Increment.
 */
function cpc_bump($key, $value = 1) {
    $stats       = cpc_stats();
    $stats[$key] = (float) (isset($stats[$key]) ? $stats[$key] : 0) + $value;

    update_option('cpc_stats', $stats, false);
}

/**
 * Cache directory, created on demand.
 *
 * @return string
 */
function cpc_cache_dir() {
    $dir = WP_CONTENT_DIR . '/cache/cinder-page-cache';

    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    return $dir;
}

/**
 * Cache key for a path.
 *
 * @param string $path Path.
 * @return string
 */
function cpc_cache_key($path = '') {
    if ('' === $path) {
        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '/';
    }

    return md5((string) $path);
}

/**
 * Cached file path for a path.
 *
 * @param string $path Path.
 * @return string
 */
function cpc_cache_file($path = '') {
    return cpc_cache_dir() . '/' . cpc_cache_key($path) . '.html';
}

/**
 * Remove cached documents.
 *
 * @param string $path Optional single path.
 * @return int Removed count.
 */
function cpc_flush_cache($path = '') {
    $removed = 0;
    $files   = '' !== $path ? array(cpc_cache_file($path)) : (array) glob(cpc_cache_dir() . '/*.html');

    foreach ($files as $file) {
        if ($file && is_file($file) && @unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * Cache directory size in bytes.
 *
 * @return int
 */
function cpc_cache_size() {
    $size = 0;

    foreach ((array) glob(cpc_cache_dir() . '/*.html') as $file) {
        $size += (int) @filesize($file);
    }

    return $size;
}

/**
 * Cached document count.
 *
 * @return int
 */
function cpc_cache_count() {
    return count((array) glob(cpc_cache_dir() . '/*.html'));
}

/**
 * Human readable bytes.
 *
 * @param int $bytes Bytes.
 * @return string
 */
function cpc_bytes($bytes) {
    $bytes = (int) $bytes;
    $units = array('B', 'KB', 'MB', 'GB');

    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }

    return round($bytes, 1) . ' ' . $units[$i];
}

/**
 * Path is excluded from caching.
 *
 * @param string $path Request path.
 * @return bool
 */
function cpc_path_excluded($path) {
    foreach (preg_split('/[\r\n]+/', (string) cpc_setting('exclude_paths')) as $rule) {
        $rule = trim($rule);

        if ('' !== $rule && false !== strpos($path, $rule)) {
            return true;
        }
    }

    return false;
}

/**
 * System snapshot for the admin panel.
 *
 * @return array
 */
function cpc_system_info() {
    global $wpdb;

    return array(
        'wp'       => get_bloginfo('version'),
        'php'      => PHP_VERSION,
        'mysql'    => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : '',
        'theme'    => wp_get_theme()->get('Name'),
        'plugins'  => count((array) get_option('active_plugins', array())),
        'webp'     => function_exists('imagewebp') ? 'yes' : 'no',
        'opcache'  => function_exists('opcache_get_status') ? 'yes' : 'no',
        'cache'    => is_writable(cpc_cache_dir()) ? 'writable' : 'read-only',
        'permalink' => get_option('permalink_structure') ? 'pretty' : 'plain',
    );
}
