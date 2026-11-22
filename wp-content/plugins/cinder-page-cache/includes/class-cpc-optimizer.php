<?php
/**
 * Output, asset and media optimization.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end tweaks.
 */
class CPC_Optimizer {

    /** @var string[] */
    private static $delayed = array();

    /** @var string */
    private static $lcp = '';

    /**
     * Hook everything.
     */
    public static function init() {
        add_filter('cpc_output', array(__CLASS__, 'minify'));

        if (cpc_setting('strip_comments')) {
            add_filter('cpc_output', array(__CLASS__, 'strip_comments'), 20);
        }

        if (cpc_setting('disable_emojis')) {
            add_action('init', array(__CLASS__, 'disable_emojis'));
        }

        if (cpc_setting('disable_embeds')) {
            add_action('init', array(__CLASS__, 'disable_embeds'));
        }

        add_filter('wp_get_attachment_image_attributes', array(__CLASS__, 'image_attributes'), 20);
        add_filter('the_content', array(__CLASS__, 'image_priority'), 20);
        add_filter('wp_get_attachment_image_src', array(__CLASS__, 'webp_src'), 20);
        add_action('wp_generate_attachment_metadata', array(__CLASS__, 'webp_generate'), 20, 2);

        add_action('wp_head', array(__CLASS__, 'head_hints'), 1);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'front_style'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'front_js'));

        if (cpc_setting('defer_js')) {
            add_filter('script_loader_tag', array(__CLASS__, 'defer_scripts'), 20, 2);
        }

        if (cpc_setting('delay_match')) {
            add_filter('script_loader_tag', array(__CLASS__, 'collect_delay'), 21, 2);
            add_filter('cpc_output', array(__CLASS__, 'apply_delay'), 25);
        }

        if (cpc_setting('css_inline')) {
            add_filter('cpc_output', array(__CLASS__, 'inline_css'), 15);
        }

        if (cpc_setting('iframe_lazy')) {
            add_filter('the_content', array(__CLASS__, 'lazy_iframes'), 21);
        }

        if (cpc_setting('lcp_preload')) {
            add_filter('the_content', array(__CLASS__, 'capture_lcp'), 30);
            add_action('wp_head', array(__CLASS__, 'lcp_preload'), 2);
        }

        if (cpc_setting('disable_migrate')) {
            add_filter('script_loader_tag', array(__CLASS__, 'drop_migrate'), 20, 2);
        }

        add_filter('heartbeat_settings', array(__CLASS__, 'heartbeat_slow'), 20);
        add_filter('wp_handle_upload', array(__CLASS__, 'upload_tune'), 20);
    }

    /**
     * Public stylesheet.
     */
    public static function front_style() {
        if (cpc_setting('breadcrumbs')) {
            wp_enqueue_style('cinder-page-cache-front', plugins_url('assets/css/front.css', CPC_FILE), array(), CPC_VER);
        }
    }

    /**
     * Delay loader script.
     */
    public static function front_js() {
        if (cpc_setting('delay_match')) {
            wp_enqueue_script('cinder-page-cache-delay', plugins_url('assets/js/delay.js', CPC_FILE), array(), CPC_VER, true);
        }
    }

    /**
     * Slow the heartbeat.
     *
     * @param array $settings Settings.
     * @return array
     */
    public static function heartbeat_slow($settings) {
        $settings['interval'] = 60;

        return $settings;
    }

    /**
     * Defer non-jQuery scripts.
     *
     * @param string $tag    Tag.
     * @param string $handle Handle.
     * @return string
     */
    public static function defer_scripts($tag, $handle) {
        if (preg_match('/\btype="text\/plain"/', $tag) || false !== strpos($tag, 'defer')) {
            return $tag;
        }

        if (0 === strpos($handle, 'jquery')) {
            return $tag;
        }

        return str_replace('<script ', '<script defer ', $tag);
    }

    /**
     * Drop jQuery Migrate for guests.
     *
     * @param string $tag    Tag.
     * @param string $handle Handle.
     * @return string
     */
    public static function drop_migrate($tag, $handle) {
        if (is_user_logged_in() || false === strpos($handle, 'jquery-migrate')) {
            return $tag;
        }

        return '';
    }

    /**
     * Collect scripts matching the delay pattern.
     *
     * @param string $tag    Tag.
     * @param string $handle Handle.
     * @return string
     */
    public static function collect_delay($tag, $handle) {
        if (preg_match('#' . cpc_setting('delay_match') . '#i', $tag)) {
            self::$delayed[] = $tag;
        }

        return $tag;
    }

    /**
     * Swap delayed scripts to placeholders in the buffer.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function apply_delay($html) {
        if (!self::$delayed) {
            return $html;
        }

        foreach (self::$delayed as $tag) {
            $src = '';

            if (preg_match('#\ssrc="([^"]+)"#', $tag, $m)) {
                $src = $m[1];
            }

            if ($src) {
                $html = str_replace($tag, '<script type="text/plain" data-cpc-src="' . esc_attr($src) . '"></script>', $html);
            }
        }

        $loader = '(function(){var go=function(){if(go.d)return;go.d=1;var s=document.querySelectorAll("script[data-cpc-src]");for(var i=0;i<s.length;i++){var n=document.createElement("script");for(var a=0;a<s[i].attributes.length;a++){var at=s[i].attributes[a];if(at.name!=="type"&&at.name!=="data-cpc-src")n.setAttribute(at.name,at.value)}n.setAttribute("src",s[i].getAttribute("data-cpc-src"));document.head.appendChild(n)}};["scroll","mousemove","touchstart","keydown"].forEach(function(e){window.addEventListener(e,go,{passive:true,once:true})});if(window.requestIdleCallback){window.requestIdleCallback(go,{timeout:4000})}else{setTimeout(go,3500)}})();';

        return str_replace('</body>', '<script data-cfasync="false">' . $loader . '</script></body>', $html);
    }

    /**
     * Inline small local stylesheets, async the rest.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function inline_css($html) {
        return preg_replace_callback(
            '#<link[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*/?>#i',
            function ($m) {
                $href = html_entity_decode($m[1], ENT_QUOTES);
                $path = self::local_path($href);

                if ($path && is_file($path) && filesize($path) < 8192) {
                    $css = (string) @file_get_contents($path);

                    if ('' !== trim($css)) {
                        return '<style>' . $css . '</style>';
                    }
                }

                $tag = '<link rel="preload" as="style" href="' . esc_attr($m[1]) . '" onload="this.rel=\'stylesheet\'">'
                     . '<noscript><link rel="stylesheet" href="' . esc_attr($m[1]) . '"></noscript>';

                return $tag;
            },
            $html
        );
    }

    /**
     * Lazy iframes in content.
     *
     * @param string $content Content.
     * @return string
     */
    public static function lazy_iframes($content) {
        return preg_replace('#<iframe(?!.*loading=)#i', '<iframe loading="lazy"$1', $content);
    }

    /**
     * Remember the LCP candidate.
     *
     * @param string $content Content.
     * @return string
     */
    public static function capture_lcp($content) {
        if (self::$lcp || is_feed() || is_admin()) {
            return $content;
        }

        if (preg_match('#<img[^>]*\ssrc="([^"]+)"#i', $content, $m)) {
            self::$lcp = $m[1];
        }

        return $content;
    }

    /**
     * Preload the LCP image.
     */
    public static function lcp_preload() {
        if (self::$lcp && !is_admin()) {
            echo '<link rel="preload" as="image" href="' . esc_url(self::$lcp) . '" fetchpriority="high">' . "\n";
        }
    }

    /**
     * Cap dimensions and strip EXIF on upload.
     *
     * @param array $upload Upload.
     * @return array
     */
    public static function upload_tune($upload) {
        if (!cpc_setting('img_maxdim') || empty($upload['type']) || false === strpos((string) $upload['type'], 'image/')) {
            return $upload;
        }

        $editor = wp_get_image_editor($upload['file']);

        if (is_wp_error($editor)) {
            return $upload;
        }

        $size = $editor->get_size();

        if ($size && max($size['width'], $size['height']) > (int) cpc_setting('img_maxdim')) {
            $editor->resize((int) cpc_setting('img_maxdim'), (int) cpc_setting('img_maxdim'), false);
            $editor->set_quality(85);
            $saved = $editor->save($upload['file']);

            if (!is_wp_error($saved)) {
                $upload['file'] = $saved['path'];
            }
        }

        return $upload;
    }

    /**
     * Local file path for a URL.
     *
     * @param string $url URL.
     * @return string
     */
    private static function local_path($url) {
        $uploads = wp_get_upload_dir();

        return str_replace($uploads['baseurl'], $uploads['basedir'], $url);
    }

    /**
     * Collapse inter-tag whitespace.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function minify($html) {
        if (!cpc_setting('html_minify') || is_admin()) {
            return $html;
        }

        return \CinderWorks\Html\Minifier::process($html);
    }

    /**
     * Remove HTML comments.
     *
     * @param string $html HTML.
     * @return string
     */
    public static function strip_comments($html) {
        return \CinderWorks\Html\Minifier::strip_comments($html);
    }

    /**
     * Drop emoji scripts.
     */
    public static function disable_emojis() {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    }

    /**
     * Drop wp-embed script.
     */
    public static function disable_embeds() {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        add_filter('embed_oembed_discover', '__return_false');
        remove_action('rest_api_init', 'wp_oembed_register_route');
        add_filter('rewrite_rules_array', array(__CLASS__, 'drop_embed_rules'));
    }

    /**
     * Remove embed rewrite rules.
     *
     * @param array $rules Rules.
     * @return array
     */
    public static function drop_embed_rules($rules) {
        foreach ($rules as $pattern => $rule) {
            if (false !== strpos($pattern, 'embed=true')) {
                unset($rules[$pattern]);
            }
        }

        return $rules;
    }

    /**
     * Async decode for content images.
     *
     * @param array $attr Attributes.
     * @return array
     */
    public static function image_attributes($attr) {
        if (empty($attr['decoding'])) {
            $attr['decoding'] = 'async';
        }

        return $attr;
    }

    /**
     * High priority for the first content image.
     *
     * @param string $content Content.
     * @return string
     */
    public static function image_priority($content) {
        if (is_feed() || is_admin()) {
            return $content;
        }

        $seen = false;

        return preg_replace_callback(
            '/<img\s+([^>]*?)>/i',
            function ($m) use (&$seen) {
                $tag = $m[0];

                if (false === stripos($tag, 'loading=')) {
                    $tag = '<img ' . $m[1] . ' loading="lazy">';
                }

                if (!$seen) {
                    $seen = true;
                    if (false === stripos($tag, 'fetchpriority=')) {
                        $tag = str_replace('<img ', '<img fetchpriority="high" ', $tag);
                    }
                }

                return $tag;
            },
            $content
        );
    }

    /**
     * Serve WebP when a generated copy exists.
     *
     * @param array|false $image Image data.
     * @return array|false
     */
    public static function webp_src($image) {
        if (!cpc_setting('webp') || !is_array($image) || empty($image[0]) || is_admin()) {
            return $image;
        }

        $avif = preg_replace('/\.(jpe?g|png)$/i', '.avif', $image[0]);
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $image[0]);

        if ($webp !== $image[0] && file_exists(self::local_path($avif))) {
            $image[0] = $avif;
        } elseif ($webp !== $image[0] && file_exists(self::local_path($webp))) {
            $image[0] = $webp;
        }

        return $image;
    }

    /**
     * Create WebP copies for every generated size.
     *
     * @param array $meta Metadata.
     * @param int   $id   Attachment ID.
     * @return array
     */
    public static function webp_generate($meta, $id) {
        if (!cpc_setting('webp') || !function_exists('imagewebp') || empty($meta['file'])) {
            return $meta;
        }

        $uploads = wp_get_upload_dir();
        $sizes   = isset($meta['sizes']) && is_array($meta['sizes']) ? $meta['sizes'] : array();
        $files   = array($meta['file']);

        foreach ($sizes as $size) {
            if (!empty($size['file'])) {
                $files[] = dirname($meta['file']) . '/' . $size['file'];
            }
        }

        foreach ($files as $relative) {
            $source = trailingslashit($uploads['basedir']) . $relative;
            $target = preg_replace('/\.(jpe?g|png)$/i', '.webp', $source);
            $avif   = preg_replace('/\.(jpe?g|png)$/i', '.avif', $source);

            if (file_exists($target) && file_exists($avif)) {
                continue;
            }

            if (!is_file($source)) {
                continue;
            }

            $editor = wp_get_image_editor($source);

            if (is_wp_error($editor)) {
                continue;
            }

            $editor->set_quality(82);
            $editor->save($target);
        }

        return $meta;
    }

    /**
     * Map an upload URL to a filesystem path.
     *
     * @param string $url URL.
     * @return string
     */

    /**
     * Resource hints in the head.
     */
    public static function head_hints() {
        if (is_admin()) {
            return;
        }

        echo '<link rel="dns-prefetch" href="//' . esc_attr(wp_parse_url(home_url('/'), PHP_URL_HOST)) . '">' . "\n";
    }
}
