<?php
/**
 * Page cache.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Full page cache backed by static files.
 */
class CPC_Cache {

    /**
     * Hook the cache.
     */
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'serve'), 5);
        add_action('shutdown', array(__CLASS__, 'track'), 99);
        add_action('cpc_cache_expire', array(__CLASS__, 'purge'));
        add_action('cpc_cache_preload', array(__CLASS__, 'preload'));

        foreach (array('save_post', 'deleted_post', 'switch_theme', 'wp_update_nav_menu', 'comment_post') as $hook) {
            add_action($hook, array(__CLASS__, 'purge_all'));
        }

        add_action('rest_api_init', array(__CLASS__, 'rest'));
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 90);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('cinder-page-cache cache', array(__CLASS__, 'cli'));
        }
    }

    /**
     * Activation.
     */
    public static function activate() {
        update_option('cpc_stats', array('hits' => 0, 'misses' => 0, 'load' => 0, 'cleared' => 0), false);
        update_option('cpc_install_at', time(), false);
        update_option('cpc_settings', cpc_settings());

        cpc_cache_dir();
        $index = cpc_cache_dir() . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        if (!wp_next_scheduled('cpc_cache_expire')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'cpc_cache_expire');
        }

        if (!wp_next_scheduled('cpc_cache_preload')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'cpc_cache_preload');
        }
    }

    /**
     * Deactivation.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook('cpc_cache_expire');
        wp_clear_scheduled_hook('cpc_cache_preload');
    }

    /**
     * Serve a cached copy.
     */
    public static function serve() {
        if (!cpc_setting('cache_enabled') || is_admin()) {
            return;
        }

        if (!cpc_setting('cache_logged_in') && is_user_logged_in()) {
            return;
        }

        if (self::is_dynamic()) {
            return;
        }

        $file = cpc_cache_file();

        if (is_readable($file) && (time() - filemtime($file)) < (int) cpc_setting('cache_ttl')) {
            header('X-Cache: HIT');
            header('X-Cache-Age: ' . (time() - filemtime($file)));

            if (cpc_setting('cache_headers')) {
                header('Cache-Control: public, max-age=' . min(600, (int) cpc_setting('cache_ttl')));
            }

            readfile($file);
            exit;
        }

        header('X-Cache: MISS');
        cpc_bump('misses');

        ob_start(array(__CLASS__, 'capture'));
    }

    /**
     * Store the rendered page.
     *
     * @param string $buffer Output.
     * @return string
     */
    public static function capture($buffer) {
        if (strlen($buffer) < 512 || is_404() || is_feed()) {
            return $buffer;
        }

        $buffer = apply_filters('cpc_output', $buffer);

        @file_put_contents(cpc_cache_file(), $buffer);

        return $buffer;
    }

    /**
     * Skip dynamic requests.
     *
     * @return bool
     */
    private static function is_dynamic() {
        if ('GET' !== (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET')) {
            return true;
        }

        if (is_404() || is_search() || is_feed() || is_preview()) {
            return true;
        }

        if (!empty($_GET) && !isset($_GET['utm_source'])) {
            return true;
        }

        if (cpc_path_excluded(isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '/')) {
            return true;
        }

        return isset($_SERVER['HTTP_COOKIE'])
            && preg_match('/wordpress_(logged_in|sec)|comment_author_|wp-postpass_/', $_SERVER['HTTP_COOKIE']);
    }

    /**
     * Delete stale documents.
     */
    public static function purge() {
        $ttl = (int) cpc_setting('cache_ttl');

        foreach ((array) glob(cpc_cache_dir() . '/*.html') as $file) {
            if ((time() - filemtime($file)) > $ttl) {
                @unlink($file);
            }
        }
    }

    /**
     * Full flush after content changes.
     */
    public static function purge_all() {
        if (!cpc_setting('purge_on_update')) {
            return;
        }

        cpc_flush_cache();
        cpc_bump('cleared');
    }

    /**
     * Warm the cache from the sitemap.
     */
    public static function preload() {
        $urls = array(home_url('/'));

        foreach (get_posts(array('numberposts' => 15, 'post_status' => 'publish')) as $post) {
            $urls[] = get_permalink($post);
        }

        foreach ($urls as $url) {
            $http = _wp_http_get_object();
            $http->request(
                $url,
                array(
                    'timeout'  => 3,
                    'blocking' => false,
                    'headers'  => array('X-Cinder Page Cache-Preload' => '1'),
                )
            );
        }
    }

    /**
     * Track request load time.
     */
    public static function track() {
        $start = defined('CPC_START') ? CPC_START : (defined('WP_START') ? WP_START : microtime(true));
        $ms    = round((microtime(true) - $start) * 1000);

        $stats          = cpc_stats();
        $hits           = (int) $stats['hits'] + 1;
        $stats['load']  = round((($stats['load'] * ($hits - 1)) + $ms) / $hits, 2);
        $stats['hits']  = $hits;

        update_option('cpc_stats', $stats, false);
    }

    /**
     * REST endpoint.
     */
    public static function rest() {
        register_rest_route(
            'cinder-page-cache/v1',
            '/cache',
            array(
                'methods'             => 'POST',
                'callback'            => function () {
                    $removed = cpc_flush_cache();
                    cpc_bump('cleared');

                    return array('removed' => $removed);
                },
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            )
        );
    }

    /**
     * Admin bar shortcut.
     *
     * @param WP_Admin_Bar $bar Bar.
     */
    public static function admin_bar($bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $bar->add_node(
            array(
                'id'    => 'cinder-page-cache-purge',
                'title' => 'Cinder Page Cache: purge cache',
                'href'  => wp_nonce_url(admin_url('admin-post.php?action=cpc_purge'), 'cpc_purge'),
            )
        );
    }

    /**
     * WP-CLI: wp cinder-page-cache cache flush|stats
     *
     * @param array $args Arguments.
     */
    public static function cli($args) {
        $cmd = isset($args[0]) ? $args[0] : 'stats';

        if ('flush' === $cmd) {
            WP_CLI::success('Removed ' . cpc_flush_cache() . ' cached file(s).');
            return;
        }

        $stats = cpc_stats();
        WP_CLI::line('Cached files: ' . cpc_cache_count() . ' (' . cpc_bytes(cpc_cache_size()) . ')');
        WP_CLI::line('Hits: ' . (int) $stats['hits'] . '  Misses: ' . (int) $stats['misses'] . '  Avg load: ' . $stats['load'] . ' ms');
    }
}
