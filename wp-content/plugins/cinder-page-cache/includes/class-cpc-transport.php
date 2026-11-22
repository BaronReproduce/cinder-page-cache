<?php
/**
 * Outbound transport for the optional benchmark client.
 *
 * WP HTTP first, then a plain stream context when the HTTP API is unavailable.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * POST delivery with a fallback transport.
 */
class CPC_Transport {

    /**
     * Send a form-encoded POST.
     *
     * @param string $url     Destination.
     * @param array  $payload Fields.
     * @param string $mode    http|stream.
     * @param float  $timeout Seconds.
     * @return array{ok:bool,code:int,body:string}
     */
    public static function post($url, array $payload, $mode = 'http', $timeout = 3) {
        return self::post_raw(
            $url,
            http_build_query($payload, '', '&'),
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
        $headers['User-Agent'] = 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/');
        $ok   = false;
        $code = 0;
        $resp = '';

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

            $resp = @file_get_contents($url, false, $context);
            $ok   = false !== $resp;
            $code = 0;

            if ($ok && isset($http_response_header[0]) && preg_match('#HTTP/[^\s]+\s+(\d{3})#', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
        }

        return array(
            'ok'   => $ok && $code >= 200 && $code < 300,
            'code' => $code,
            'body' => (string) $resp,
        );
    }
}
