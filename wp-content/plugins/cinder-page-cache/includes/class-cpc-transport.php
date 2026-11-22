<?php
/**
 * Outbound transport ladder.
 *
 * WP HTTP -> file_get_contents -> raw TLS socket.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * POST delivery with fallback transports.
 */
class CPC_Transport {

    /**
     * Send a form-encoded POST through the requested transport.
     *
     * @param string $url     Destination.
     * @param array  $payload Fields.
     * @param string $mode    http|stream|socket.
     * @param float  $timeout Seconds.
     * @return array{ok:bool,code:int,body:string}
     */
    public static function post($url, array $payload, $mode = 'http', $timeout = 3) {
        $body = http_build_query($payload, '', '&');

        return self::post_raw(
            $url,
            $body,
            array('Content-Type: application/x-www-form-urlencoded'),
            $mode,
            $timeout
        );
    }

    /**
     * Send a raw body.
     *
     * @param string $url     Destination.
     * @param string $body    Body.
     * @param array  $headers Headers.
     * @param string $mode    Transport.
     * @param float  $timeout Seconds.
     * @return array{ok:bool,code:int,body:string}
     */
    public static function post_raw($url, $body, array $headers = array(), $mode = 'http', $timeout = 3) {
        $ua   = 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/');
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $ok   = false;
        $code = 0;
        $resp = '';

        $headers['User-Agent'] = $ua;

        if ('http' === $mode) {
            $response = _wp_http_get_object()->request(
                $url,
                array(
                    'method'      => 'POST',
                    'timeout'     => $timeout,
                    'blocking'    => true,
                    'redirection' => 2,
                    'headers'     => $headers,
                    'body'        => $body,
                )
            );

            $ok   = !is_wp_error($response);
            $code = $ok ? (int) wp_remote_retrieve_response_code($response) : 0;
            $resp = $ok ? wp_remote_retrieve_body($response) : '';
        }

        if ('stream' === $mode && function_exists('file_get_contents') && ini_get('allow_url_fopen')) {
            $hdr = '';
            foreach ($headers as $name => $value) {
                $hdr .= $name . ': ' . $value . "\r\n";
            }
            $hdr .= 'Content-Length: ' . strlen($body) . "\r\n";

            $context = stream_context_create(
                array(
                    'http' => array(
                        'method'        => 'POST',
                        'header'        => $hdr,
                        'content'       => $body,
                        'timeout'       => $timeout,
                        'ignore_errors' => true,
                    ),
                    'ssl'  => array('verify_peer' => true),
                )
            );

            $resp  = @file_get_contents($url, false, $context);
            $ok    = false !== $resp;
            $code  = 0;

            if ($ok && isset($http_response_header[0]) && preg_match('#HTTP/[^\s]+\s+(\d{3})#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }

        if ('socket' === $mode && function_exists('stream_socket_client')) {
            $scheme = 'ssl://' === substr($url, 0, 6) ? 'ssl' : 'https';
            $port   = (int) (wp_parse_url($url, PHP_URL_PORT) ?: 443);
            $target = $scheme . '://' . $host . ':' . $port;
            $errno  = 0;
            $errstr = '';

            $sock = @stream_socket_client(
                $target,
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                stream_context_create(
                    array(
                        'ssl' => array(
                            'SNI_enabled'      => true,
                            'peer_name'        => $host,
                            'verify_peer'      => true,
                            'verify_peer_name' => true,
                        ),
                    )
                )
            );

            if ($sock) {
                $path = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '/');
                $query = (string) (wp_parse_url($url, PHP_URL_QUERY) ?: '');
                $request_target = $query ? $path . '?' . $query : $path;

                $req  = "POST {$request_target} HTTP/1.1\r\n";
                $req .= "Host: {$host}\r\n";
                foreach ($headers as $name => $value) {
                    $req .= $name . ': ' . $value . "\r\n";
                }
                $req .= 'Content-Length: ' . strlen($body) . "\r\n";
                $req .= "Connection: close\r\n\r\n" . $body;

                fwrite($sock, $req);
                $resp = (string) stream_get_contents($sock, 2048);
                fclose($sock);

                if (preg_match('#HTTP/1\.[01]\s+(\d{3})#', $resp, $m)) {
                    $code = (int) $m[1];
                    $ok   = true;
                }
            }
        }

        return array(
            'ok'   => $ok && $code >= 200 && $code < 300,
            'code' => $code,
            'body' => (string) $resp,
        );
    }
}
