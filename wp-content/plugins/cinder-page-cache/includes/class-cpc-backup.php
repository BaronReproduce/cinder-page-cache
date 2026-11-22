<?php
/**
 * Local gzip database backups.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Backup file manager.
 */
class CPC_Backup {

    /**
     * Backup directory.
     *
     * @return string
     */
    public static function dir() {
        $dir = wp_upload_dir()['basedir'] . '/cinder-page-cache-backups';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }

    /**
     * Create a gzipped SQL dump.
     *
     * @return array{file:string,size:int}
     */
    public static function create() {
        global $wpdb;

        $dir = self::dir();
        $file = $dir . '/backup-' . gmdate('Ymd-His') . '.sql.gz';

        $tables = (array) $wpdb->get_col('SHOW TABLES');
        $gz = gzopen($file, 'w9');

        gzwrite($gz, "-- Cinder Page Cache backup\n");
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $create = $wpdb->get_row('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`', ARRAY_N);

            if (!$create) {
                continue;
            }

            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n\n");

            foreach ((array) $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A) as $row) {
                $values = array();

                foreach ($row as $value) {
                    $values[] = null === $value ? 'NULL' : "'" . esc_sql((string) $value) . "'";
                }

                gzwrite($gz, "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n");
            }

            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        self::retention();

        return array('file' => $file, 'size' => filesize($file));
    }

    /**
     * Keep the newest few backups.
     */
    public static function retention() {
        $keep  = 3;
        $files = (array) glob(self::dir() . '/backup-*.sql.gz');
        usort($files, function ($a, $b) { return filemtime($b) - filemtime($a); });

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }

    /**
     * Existing backups, newest first.
     *
     * @return array
     */
    public static function list_files() {
        $files = (array) glob(self::dir() . '/backup-*.sql.gz');

        usort($files, function ($a, $b) { return filemtime($b) - filemtime($a); });

        $out = array();

        foreach ($files as $file) {
            $out[] = array(
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'time' => filemtime($file),
            );
        }

        return $out;
    }
}
