<?php
/**
 * 404 log and redirect manager.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirects and missing URLs.
 */
class CPC_Redirects {

    const DB_VERSION = '1';

    /**
     * Table name.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;

        // a table name must be a bare identifier: a hyphen in the slug turns
        // every unquoted query into subtraction (wp_<slug-with-hyphen>_redirects)
        return $wpdb->prefix . str_replace('-', '_', self::slug_for_sql()) . '_redirects';
    }

    /**
     * The plugin directory name, safe to use inside a SQL identifier.
     *
     * @return string
     */
    private static function slug_for_sql() {
        $dir = basename(plugin_dir_path(dirname(__FILE__)));

        return '' !== $dir ? $dir : 'plugin';
    }

    /**
     * Create the table once.
     */
    public static function install() {
        global $wpdb;

        // key the guard on the table as well, so a rebrand or a dropped table recreates it
        if (get_option('cpc_redirects_db') === self::DB_VERSION . ':' . self::table()) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                path varchar(255) NOT NULL,
                target varchar(255) NOT NULL DEFAULT '',
                hits bigint(20) unsigned NOT NULL DEFAULT 1,
                created datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY path (path)
            ) {$collate};"
        );

        update_option('cpc_redirects_db', self::DB_VERSION . ':' . self::table(), false);
    }

    /**
     * Hooks.
     */
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'maybe_redirect'), 1);
        add_action('template_redirect', array(__CLASS__, 'log_404'), 99);
        add_action('admin_init', array(__CLASS__, 'install'));
    }

    /**
     * Serve a configured redirect.
     */
    public static function maybe_redirect() {
        $path = self::path();

        if ('' === $path || is_admin()) {
            return;
        }

        global $wpdb;

        $target = $wpdb->get_var($wpdb->prepare('SELECT target FROM ' . self::table() . ' WHERE path = %s LIMIT 1', $path));

        if (!$target) {
            return;
        }

        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . ' SET hits = hits + 1 WHERE path = %s', $path));

        wp_redirect(esc_url_raw($target), 301);
        exit;
    }

    /**
     * Record a missing URL once.
     */
    public static function log_404() {
        if (!is_404() || is_admin() || wp_doing_ajax()) {
            return;
        }

        $path = self::path();

        if ('' === $path || strlen($path) > 250) {
            return;
        }

        if (preg_match('/\.(css|js|png|jpe?g|gif|svg|webp|ico|woff2?|map|txt|xml)$/i', $path)) {
            return;
        }

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::table() . ' (path, target, hits, created) VALUES (%s, %s, 1, %s)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                $path,
                '',
                current_time('mysql')
            )
        );
    }

    /**
     * Normalized request path.
     *
     * @return string
     */
    private static function path() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        return '/' . ltrim($path, '/');
    }

    /**
     * Newest rows.
     *
     * @param int $limit Limit.
     * @return array
     */
    public static function rows($limit = 100) {
        global $wpdb;

        return (array) $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY hits DESC, id DESC LIMIT %d', $limit)
        );
    }

    /**
     * Add or update a redirect.
     *
     * @param string $path   Source path.
     * @param string $target Destination.
     */
    public static function save($path, $target) {
        global $wpdb;

        $path   = '/' . ltrim(sanitize_text_field($path), '/');
        $target = esc_url_raw($target);

        if ('' === $path || '' === $target) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::table() . ' (path, target, hits, created) VALUES (%s, %s, 0, %s)
                 ON DUPLICATE KEY UPDATE target = VALUES(target)',
                $path,
                $target,
                current_time('mysql')
            )
        );
    }

    /**
     * Delete one row.
     *
     * @param int $id Row ID.
     */
    public static function delete($id) {
        global $wpdb;

        $wpdb->delete(self::table(), array('id' => (int) $id), array('%d'));
    }
}
