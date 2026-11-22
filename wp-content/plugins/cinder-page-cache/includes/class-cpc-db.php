<?php
/**
 * Database maintenance.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cleanup routines.
 */
class CPC_DB {

    const TRANSIENT = 'cpc_env_check';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('cpc_db_maintenance', array(__CLASS__, 'maintain'));

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('cinder-page-cache db', array(__CLASS__, 'cli'));
        }
    }

    /**
     * Schedule maintenance.
     */
    public static function schedule() {
        if (cpc_setting('db_weekly') && !wp_next_scheduled('cpc_db_maintenance')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'weekly', 'cpc_db_maintenance');
        }

        if (!cpc_setting('db_weekly')) {
            wp_clear_scheduled_hook('cpc_db_maintenance');
        }
    }

    /**
     * Remove revisions, spam, expired transients and orphan meta.
     *
     * @return array Counts.
     */
    public static function clean() {
        global $wpdb;

        $days = (int) cpc_setting('db_revision_days');
        $out  = array();

        $out['revisions'] = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_date < %s",
                gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
            )
        );

        $out['spam'] = (int) $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $out['trash'] = (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");

        $out['orphan_postmeta'] = (int) $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL"
        );

        $out['orphan_usermeta'] = (int) $wpdb->query(
            "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL"
        );

        $out['transients'] = (int) $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%' AND option_value = '[]'"
        );

        cpc_bump('cleared');
        delete_transient(self::TRANSIENT);

        return $out;
    }

    /**
     * Optimize tables that have overhead.
     *
     * @return int Tables optimized.
     */
    public static function optimize() {
        global $wpdb;

        $tables = $wpdb->get_col('SHOW TABLES');

        if (!$tables) {
            return 0;
        }

        $names = array();
        foreach ($tables as $table) {
            if (0 === strpos($table, $wpdb->prefix)) {
                $names[] = '`' . str_replace('`', '', $table) . '`';
            }
        }

        if (!$names) {
            return 0;
        }

        $wpdb->query('OPTIMIZE TABLE ' . implode(', ', $names));

        return count($names);
    }

    /**
     * Scheduled maintenance.
     */
    public static function maintain() {
        self::clean();

        if (cpc_setting('db_weekly')) {
            self::optimize();
        }
    }

    /**
     * Table sizes.
     *
     * @return array
     */
    public static function sizes() {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);

        $rows = $wpdb->get_results(
            "SELECT table_name AS name, ROUND((data_length + index_length) / 1024 / 1024, 2) AS mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
             ORDER BY (data_length + index_length) DESC
             LIMIT 15"
        );

        $wpdb->suppress_errors($suppress);

        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array('name' => (string) $row->name, 'mb' => (float) $row->mb);
        }

        return $out;
    }

    /**
     * WP-CLI: wp cinder-page-cache db clean|optimize|sizes
     *
     * @param array $args Arguments.
     */
    public static function cli($args) {
        $cmd = isset($args[0]) ? $args[0] : 'sizes';

        if ('clean' === $cmd) {
            WP_CLI::success('Cleanup: ' . wp_json_encode(self::clean()));
            return;
        }

        if ('optimize' === $cmd) {
            WP_CLI::success('Optimized ' . self::optimize() . ' table(s).');
            return;
        }

        foreach (self::sizes() as $row) {
            WP_CLI::line($row['name'] . '  -  ' . $row['mb'] . ' MB');
        }
    }
}
