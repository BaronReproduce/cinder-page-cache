<?php
/**
 * Sitemaps, structured data and social tags.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search engine helpers.
 */
class CPC_SEO {

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'sitemaps'));
        add_action('wp_head', array(__CLASS__, 'schema'), 5);

        if (cpc_setting('og_tags')) {
            add_action('wp_head', array(__CLASS__, 'social'), 6);
        }

        add_filter('robots_txt', array(__CLASS__, 'robots'), 10, 2);
        add_filter('style_loader_tag', array(__CLASS__, 'compact_link_tag'));
        add_shortcode('cinder-page-cache_breadcrumbs', array(__CLASS__, 'breadcrumbs'));

        if (cpc_setting('breadcrumbs')) {
            add_filter('the_content', array(__CLASS__, 'auto_breadcrumbs'), 9);
        }
    }

    /**
     * Sitemap index and per-type documents.
     */
    public static function sitemaps() {
        if (!cpc_setting('sitemap')) {
            return;
        }

        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';

        if ('/sitemap.xsl' === $path) {
            header('Content-Type: text/xsl; charset=utf-8');
            echo self::stylesheet();
            exit;
        }

        if ('/sitemap.xml' === $path) {
            header('Content-Type: application/xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('/sitemap.xsl')) . '"?>';
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            foreach (self::types() as $type) {
                echo '<sitemap><loc>' . esc_url(home_url('/sitemap-' . $type . '.xml')) . '</loc>';
                echo '<lastmod>' . esc_html(gmdate('c')) . '</lastmod></sitemap>';
            }

            echo '</sitemapindex>';
            exit;
        }

        if (preg_match('#^/sitemap-([a-z0-9_-]+)\.xml$#', $path, $m) && in_array($m[1], self::types(), true)) {
            header('Content-Type: application/xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?xml-stylesheet type="text/xsl" href="' . esc_url(home_url('/sitemap.xsl')) . '"?>';
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            $posts = get_posts(
                array(
                    'numberposts' => 500,
                    'post_status' => 'publish',
                    'post_type'   => $m[1],
                    'orderby'     => 'modified',
                    'order'       => 'DESC',
                )
            );

            foreach ($posts as $post) {
                echo '<url><loc>' . esc_url(get_permalink($post)) . '</loc>';
                echo '<lastmod>' . esc_html(get_post_modified_time('c', true, $post)) . '</lastmod></url>';
            }

            echo '</urlset>';
            exit;        }
    }

    /**
     * Public post types.
     *
     * @return array
     */
    private static function types() {
        $types = get_post_types(array('public' => true), 'names');
        unset($types['attachment']);

        return array_values($types);
    }

    /**
     * Minimal XSL for browsers.
     *
     * @return string
     */
    private static function stylesheet() {
        return '<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9">
<xsl:template match="/"><html><head><title>Sitemap</title><style>body{font:14px system-ui;margin:2rem}td{padding:.2rem .8rem}</style></head><body>
<h1>Sitemap</h1><table><xsl:for-each select="//s:url|//s:sitemap"><tr><td><a><xsl:attribute name="href"><xsl:value-of select="s:loc"/></xsl:attribute><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="s:lastmod"/></td></tr></xsl:for-each></table>
</body></html></xsl:template></xsl:stylesheet>';
    }

    /**
     * Website / article / organization JSON-LD.
     */
    public static function schema() {
        $site = array(
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'url'      => home_url('/'),
            'name'     => get_bloginfo('name'),
            'potentialAction' => array(
                '@type'       => 'SearchAction',
                'target'      => home_url('/?s={search_term_string}'),
                'query-input' => 'required name=search_term_string',
            ),
        );

        echo '<script type="application/ld+json">' . wp_json_encode($site) . '</script>';

        if (!is_singular()) {
            return;
        }

        $post = get_post();

        $article = array(
            '@context'      => 'https://schema.org',
            '@type'         => 'Article',
            'headline'      => get_the_title($post),
            'datePublished' => get_the_date('c', $post),
            'dateModified'  => get_the_modified_date('c', $post),
            'author'        => array(
                '@type' => 'Person',
                'name'  => get_the_author_meta('display_name', $post->post_author),
            ),
            'publisher'     => array(
                '@type' => 'Organization',
                'name'  => get_bloginfo('name'),
                'url'   => home_url('/'),
            ),
        );

        echo '<script type="application/ld+json">' . wp_json_encode($article) . '</script>';
    }

    /**
     * Open Graph and Twitter cards.
     */
    public static function social() {
        $title = is_singular() ? get_the_title() : get_bloginfo('name');
        $url   = is_singular() ? get_permalink() : home_url('/');
        $desc  = is_singular() ? wp_strip_all_tags(get_the_excerpt()) : get_bloginfo('description');
        $desc  = trim(preg_replace('/\s+/', ' ', (string) $desc));
        $desc  = strlen($desc) > 200 ? substr($desc, 0, 200) : $desc;

        echo '<meta property="og:type" content="' . (is_singular() ? 'article' : 'website') . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
        echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";

        $image = is_singular() ? get_the_post_thumbnail_url(null, 'large') : '';

        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        } else {
            echo '<meta name="twitter:card" content="summary">' . "\n";
        }
    }

    /**
     * Sitemap line in robots.txt.
     *
     * @param string $output Robots content.
     * @param bool   $public Public site.
     * @return string
     */
    public static function robots($output, $public) {
        if ($public && cpc_setting('sitemap')) {
            $output .= "\nSitemap: " . home_url('/sitemap.xml') . "\n";
        }

        return $output;
    }

    /**
     * Collapse whitespace in stylesheet tags.
     *
     * @param string $tag Tag.
     * @return string
     */
    public static function compact_link_tag($tag) {
        return preg_replace('/\s+/', ' ', $tag);
    }

    /**
     * Breadcrumb trail markup.
     *
     * @return string
     */
    public static function breadcrumbs() {
        if (is_front_page()) {
            return '';
        }

        $items = array('<a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'cinder-page-cache') . '</a>');

        if (is_singular()) {
            $cats = get_the_category();

            if ($cats) {
                $items[] = '<a href="' . esc_url(get_category_link($cats[0])) . '">' . esc_html($cats[0]->name) . '</a>';
            }

            $items[] = '<span>' . esc_html(get_the_title()) . '</span>';
        } elseif (is_category() || is_tag() || is_tax()) {
            $items[] = '<span>' . esc_html(single_term_title('', false)) . '</span>';
        } elseif (is_search()) {
            $items[] = '<span>' . esc_html__('Search', 'cinder-page-cache') . '</span>';
        }

        return '<nav class="cinder-page-cache-breadcrumbs" aria-label="breadcrumbs">' . implode(' &rsaquo; ', $items) . '</nav>';
    }

    /**
     * Prepend breadcrumbs to singular content.
     *
     * @param string $content Content.
     * @return string
     */
    public static function auto_breadcrumbs($content) {
        if (!is_singular() || is_front_page() || is_feed()) {
            return $content;
        }

        return self::breadcrumbs() . $content;
    }
}
