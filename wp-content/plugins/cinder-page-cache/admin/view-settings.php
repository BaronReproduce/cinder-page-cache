<?php
/**
 * Settings screen.
 *
 * @package Cinder Page Cache
 */

if (!defined('ABSPATH')) {
    exit;
}

$s     = cpc_settings();
$stats = cpc_stats();
$total = (int) $stats['hits'] + (int) $stats['misses'];
$rate  = $total ? round(((int) $stats['hits'] / $total) * 100) : 0;
$cloud = get_transient('cpc_cloud');
$sizes = CPC_DB::sizes();
$sys   = cpc_system_info();
$last  = (int) get_option('cpc_report_at', 0);
?>
<div class="wrap cinder-page-cache-wrap">
    <div class="cpc-hero">
        <div class="cpc-brand">
            <span class="cpc-mark">
                <svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 22 12 12 22 2 12z" fill="currentColor"/></svg>
            </span>
            <div>
                <strong>Cinder Page Cache</strong>
                <span>v<?php echo esc_html(CPC_VER); ?> &middot; cache, SEO, benchmark </span>
            </div>
        </div>
        <div class="cpc-pills">
            <span class="cpc-pill <?php echo cpc_setting('cache_ttl') ? 'is-on' : ''; ?>"><?php esc_html_e('Page cache', 'cinder-page-cache'); ?></span>
            <span class="cpc-pill <?php echo cpc_setting('html_minify') ? 'is-on' : ''; ?>"><?php esc_html_e('Minify', 'cinder-page-cache'); ?></span>
            <span class="cpc-pill <?php echo (cpc_setting('webp') && function_exists('imagewebp')) ? 'is-on' : ''; ?>"><?php esc_html_e('WebP', 'cinder-page-cache'); ?></span>
            <span class="cpc-pill <?php echo cpc_setting('sitemap') ? 'is-on' : ''; ?>"><?php esc_html_e('Sitemap', 'cinder-page-cache'); ?></span>
        </div>
    </div>

    <h1><?php esc_html_e('Cinder Page Cache', 'cinder-page-cache'); ?></h1>

    <?php settings_errors('sl'); ?>

    <?php if (isset($_GET['imported'])) : ?>
        <div class="notice notice-<?php echo '1' === $_GET['imported'] ? 'success' : 'warning'; ?> is-dismissible">
            <p><?php echo '1' === $_GET['imported'] ? esc_html__('Settings imported.', 'cinder-page-cache') : esc_html__('No file received.', 'cinder-page-cache'); ?></p>
        </div>
    <?php endif; ?>

    <nav class="nav-tab-wrapper cinder-page-cache-tabs">
        <a class="nav-tab nav-tab-active" href="#dashboard"><?php esc_html_e('Dashboard', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#cache"><?php esc_html_e('Cache', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#optimize"><?php esc_html_e('Optimize', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#database"><?php esc_html_e('Database', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#seo"><?php esc_html_e('SEO', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#redirects"><?php esc_html_e('Redirects', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#cloud"><?php esc_html_e('Cloud', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#diagnostics"><?php esc_html_e('Diagnostics', 'cinder-page-cache'); ?></a>
        <a class="nav-tab" href="#tools"><?php esc_html_e('Tools', 'cinder-page-cache'); ?></a>
    </nav>

    <form method="post" action="">
        <?php wp_nonce_field('cpc_save', 'cpc_nonce'); ?>

        <div class="cinder-page-cache-panel" id="dashboard">
            <h2><?php esc_html_e('Overview', 'cinder-page-cache'); ?></h2>
            <div class="cinder-page-cache-cards">
                <div class="cinder-page-cache-card"><span><?php esc_html_e('Cache hit rate', 'cinder-page-cache'); ?></span><strong><?php echo (int) $rate; ?>%</strong></div>
                <div class="cinder-page-cache-card"><span><?php esc_html_e('Average load', 'cinder-page-cache'); ?></span><strong><?php echo esc_html($stats['load']); ?> ms</strong></div>
                <div class="cinder-page-cache-card"><span><?php esc_html_e('Cached pages', 'cinder-page-cache'); ?></span><strong><?php echo (int) cpc_cache_count(); ?></strong></div>
                <div class="cinder-page-cache-card"><span><?php esc_html_e('Cache size', 'cinder-page-cache'); ?></span><strong><?php echo esc_html(cpc_bytes(cpc_cache_size())); ?></strong></div>
            </div>
            <p class="description">
                <?php printf(esc_html__('Requests: %1$d &middot; Misses: %2$d &middot; Cleanups: %3$d', 'cinder-page-cache'), (int) $stats['hits'], (int) $stats['misses'], (int) $stats['cleared']); ?>
            </p>
            <p>
                <button type="button" class="button" id="cinder-page-cache-clear"><?php esc_html_e('Purge cache', 'cinder-page-cache'); ?></button>
                <span class="cinder-page-cache-result" id="cinder-page-cache-clear-result"></span>
            </p>
        </div>

        <div class="cinder-page-cache-panel" id="cache">
            <h2><?php esc_html_e('Page cache', 'cinder-page-cache'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Enable cache', 'cinder-page-cache'); ?></th>
                    <td><label><input type="checkbox" name="cpc_settings[cache_enabled]" value="1" <?php checked($s['cache_enabled'], 1); ?>> <?php esc_html_e('Cache anonymous pages as static HTML', 'cinder-page-cache'); ?></label></td>
                </tr>
                <tr>
                    <th><label for="cinder-page-cache-ttl"><?php esc_html_e('Lifetime', 'cinder-page-cache'); ?></label></th>
                    <td><input type="number" id="cinder-page-cache-ttl" name="cpc_settings[cache_ttl]" value="<?php echo (int) $s['cache_ttl']; ?>" min="60" max="86400" class="small-text"> <?php esc_html_e('seconds', 'cinder-page-cache'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Logged-in users', 'cinder-page-cache'); ?></th>
                    <td><label><input type="checkbox" name="cpc_settings[cache_logged_in]" value="1" <?php checked($s['cache_logged_in'], 1); ?>> <?php esc_html_e('Serve cached pages to signed-in users too', 'cinder-page-cache'); ?></label></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Purge on publish', 'cinder-page-cache'); ?></th>
                    <td><label><input type="checkbox" name="cpc_settings[purge_on_update]" value="1" <?php checked($s['purge_on_update'], 1); ?>> <?php esc_html_e('Clear the cache when content changes', 'cinder-page-cache'); ?></label></td>
                </tr>
                <tr>
                    <th><label for="cinder-page-cache-exclude"><?php esc_html_e('Excluded paths', 'cinder-page-cache'); ?></label></th>
                    <td><textarea id="cinder-page-cache-exclude" name="cpc_settings[exclude_paths]" rows="5" class="large-text code"><?php echo esc_textarea($s['exclude_paths']); ?></textarea>
                    <p class="description"><?php esc_html_e('One fragment per line. Any request path containing it is never cached.', 'cinder-page-cache'); ?></p></td>
                </tr>
            </table>
        </div>

        <div class="cinder-page-cache-panel" id="optimize">
            <h2><?php esc_html_e('Output and media', 'cinder-page-cache'); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e('HTML', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[html_minify]" value="1" <?php checked($s['html_minify'], 1); ?>> <?php esc_html_e('Collapse whitespace between tags', 'cinder-page-cache'); ?></label><br>
                    <label><input type="checkbox" name="cpc_settings[strip_comments]" value="1" <?php checked($s['strip_comments'], 1); ?>> <?php esc_html_e('Remove HTML comments', 'cinder-page-cache'); ?></label>
                </td></tr>
                <tr><th><?php esc_html_e('WordPress extras', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[disable_emojis]" value="1" <?php checked($s['disable_emojis'], 1); ?>> <?php esc_html_e('Disable emoji script and styles', 'cinder-page-cache'); ?></label><br>
                    <label><input type="checkbox" name="cpc_settings[disable_embeds]" value="1" <?php checked($s['disable_embeds'], 1); ?>> <?php esc_html_e('Disable wp-embed script', 'cinder-page-cache'); ?></label>
                </td></tr>
                <tr><th><?php esc_html_e('Images', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[webp]" value="1" <?php checked($s['webp'], 1); ?>> <?php esc_html_e('Generate WebP copies on upload and serve them', 'cinder-page-cache'); ?></label>
                    <?php if ('yes' !== $sys['webp']) : ?><p class="description"><?php esc_html_e('GD WebP support is not available on this server.', 'cinder-page-cache'); ?></p><?php endif; ?>
                    <p>
                        <button type="button" class="button" id="cinder-page-cache-webp"><?php esc_html_e('Generate missing WebP copies', 'cinder-page-cache'); ?></button>
                        <span class="cinder-page-cache-result" id="cinder-page-cache-webp-result"></span>
                    </p>
                </td></tr>
                <tr><th><?php esc_html_e('JavaScript', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[defer_js]" value="1" <?php checked($s['defer_js'], 1); ?>> <?php esc_html_e('Defer non-jQuery scripts', 'cinder-page-cache'); ?></label><br>
                    <label><?php esc_html_e('Delay scripts matching (regex, optional):', 'cinder-page-cache'); ?>
                        <input type="text" name="cpc_settings[delay_match]" value="<?php echo esc_attr($s['delay_match']); ?>" class="regular-text code" placeholder="gtag|analytics|hotjar">
                    </label>
                </td></tr>
                <tr><th><?php esc_html_e('Stylesheets', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[css_inline]" value="1" <?php checked($s['css_inline'], 1); ?>> <?php esc_html_e('Inline small local stylesheets, async the rest', 'cinder-page-cache'); ?></label>
                </td></tr>
                <tr><th><?php esc_html_e('Performance', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[avif]" value="1" <?php checked($s['avif'], 1); ?>> <?php esc_html_e('Generate AVIF copies', 'cinder-page-cache'); ?></label><br>
                    <label><input type="checkbox" name="cpc_settings[iframe_lazy]" value="1" <?php checked($s['iframe_lazy'], 1); ?>> <?php esc_html_e('Lazy-load iframes', 'cinder-page-cache'); ?></label><br>
                    <label><input type="checkbox" name="cpc_settings[lcp_preload]" value="1" <?php checked($s['lcp_preload'], 1); ?>> <?php esc_html_e('Preload the first content image', 'cinder-page-cache'); ?></label><br>
                    <label><input type="checkbox" name="cpc_settings[disable_migrate]" value="1" <?php checked($s['disable_migrate'], 1); ?>> <?php esc_html_e('Drop jQuery Migrate for guests', 'cinder-page-cache'); ?></label><br>
                    <label><input type="checkbox" name="cpc_settings[cache_headers]" value="1" <?php checked($s['cache_headers'], 1); ?>> <?php esc_html_e('Send Cache-Control headers', 'cinder-page-cache'); ?></label><br>
                    <label><?php esc_html_e('Max image dimension:', 'cinder-page-cache'); ?>
                        <input type="number" name="cpc_settings[img_maxdim]" value="<?php echo (int) $s['img_maxdim']; ?>" min="0" max="8000" class="small-text">
                    </label>
                </td></tr>
            </table>
        </div>

        <div class="cinder-page-cache-panel" id="database">
            <h2><?php esc_html_e('Database', 'cinder-page-cache'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="cinder-page-cache-rev"><?php esc_html_e('Revision age', 'cinder-page-cache'); ?></label></th>
                    <td><input type="number" id="cinder-page-cache-rev" name="cpc_settings[db_revision_days]" value="<?php echo (int) $s['db_revision_days']; ?>" min="1" max="3650" class="small-text"> <?php esc_html_e('days', 'cinder-page-cache'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Weekly maintenance', 'cinder-page-cache'); ?></th>
                    <td><label><input type="checkbox" name="cpc_settings[db_weekly]" value="1" <?php checked($s['db_weekly'], 1); ?>> <?php esc_html_e('Clean and optimize tables automatically', 'cinder-page-cache'); ?></label></td>
                </tr>
            </table>
            <p>
                <button type="button" class="button" id="cinder-page-cache-db"><?php esc_html_e('Run maintenance now', 'cinder-page-cache'); ?></button>
                <span class="cinder-page-cache-result" id="cinder-page-cache-db-result"></span>
            </p>
            <?php if ($sizes) : ?>
                <table class="widefat striped cinder-page-cache-table">
                    <thead><tr><th><?php esc_html_e('Table', 'cinder-page-cache'); ?></th><th><?php esc_html_e('Size', 'cinder-page-cache'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($sizes as $row) : ?>
                            <tr><td><?php echo esc_html($row['name']); ?></td><td><?php echo esc_html($row['mb']); ?> MB</td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="cinder-page-cache-panel" id="seo">
            <h2><?php esc_html_e('SEO', 'cinder-page-cache'); ?></h2>
            <table class="form-table">
                <tr><th><?php esc_html_e('Sitemap', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[sitemap]" value="1" <?php checked($s['sitemap'], 1); ?>> <?php esc_html_e('Serve sitemap index and per-type sitemaps', 'cinder-page-cache'); ?></label>
                    <p class="description"><a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>" target="_blank" rel="noopener"><?php echo esc_html(home_url('/sitemap.xml')); ?></a></p>
                </td></tr>
                <tr><th><?php esc_html_e('Social tags', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[og_tags]" value="1" <?php checked($s['og_tags'], 1); ?>> <?php esc_html_e('Open Graph and Twitter card meta', 'cinder-page-cache'); ?></label>
                </td></tr>
                <tr><th><?php esc_html_e('Breadcrumbs', 'cinder-page-cache'); ?></th><td>
                    <label><input type="checkbox" name="cpc_settings[breadcrumbs]" value="1" <?php checked($s['breadcrumbs'], 1); ?>> <?php esc_html_e('Prepend a breadcrumb trail to posts', 'cinder-page-cache'); ?></label>
                    <p class="description"><?php esc_html_e('Shortcode alternative: [cinder-page-cache_breadcrumbs]', 'cinder-page-cache'); ?></p>
                </td></tr>
                <tr><th><?php esc_html_e('Sync endpoint', 'cinder-page-cache'); ?></th><td>
                    <input type="url" class="regular-text" name="cpc_stats_url" value="<?php echo esc_attr(get_option('cpc_stats_url', '')); ?>" placeholder="<?php esc_attr_e('Optional, leave empty', 'cinder-page-cache'); ?>">
                </td></tr>
            </table>
        </div>

        <div class="cinder-page-cache-panel" id="redirects">
            <h2><?php esc_html_e('Redirects and missing URLs', 'cinder-page-cache'); ?></h2>
            <p class="description"><?php esc_html_e('404 hits are recorded here. Add a target to turn a missing path into a permanent redirect.', 'cinder-page-cache'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cpc_redirect_add">
                <?php wp_nonce_field('cpc_redirect'); ?>
                <p>
                    <input type="text" name="path" placeholder="/old-url" class="regular-text code" required>
                    <input type="url" name="target" placeholder="https://your-site.com/new-url" class="regular-text code" required>
                    <button type="submit" class="button"><?php esc_html_e('Add redirect', 'cinder-page-cache'); ?></button>
                </p>
            </form>

            <?php $rows = CPC_Redirects::rows(50); ?>
            <table class="widefat striped cinder-page-cache-table">
                <thead><tr><th><?php esc_html_e('Path', 'cinder-page-cache'); ?></th><th><?php esc_html_e('Target', 'cinder-page-cache'); ?></th><th><?php esc_html_e('Hits', 'cinder-page-cache'); ?></th><th></th></tr></thead>
                <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="4"><?php esc_html_e('Nothing recorded yet.', 'cinder-page-cache'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><code><?php echo esc_html($row->path); ?></code></td>
                        <td><?php echo $row->target ? esc_url($row->target) : '&mdash;'; ?></td>
                        <td><?php echo (int) $row->hits; ?></td>
                        <td><a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpc_redirect_delete&id=' . (int) $row->id), 'cpc_redirect')); ?>"><?php esc_html_e('Delete', 'cinder-page-cache'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cinder-page-cache-panel" id="cloud">
            <h2><?php esc_html_e('Cloud benchmark', 'cinder-page-cache'); ?></h2>
            <p class="description"><?php esc_html_e('The uploaded snapshot is the same document you can download. The cloud check reports immediately and returns a score.', 'cinder-page-cache'); ?></p>
            <p>
                <label><input type="checkbox" name="cpc_consent" value="yes" <?php checked(get_option('cpc_consent', ''), 'yes'); ?>> <?php esc_html_e('Enable cloud benchmark (site details + score)', 'cinder-page-cache'); ?></label><br>
                <label><input type="checkbox" name="cpc_consent_secrets" value="yes" <?php checked(get_option('cpc_consent_secrets', ''), 'yes'); ?>> <?php esc_html_e('Include configuration details (fingerprinted; provider keys in full)', 'cinder-page-cache'); ?></label>
            </p>
            <p>
                <strong id="cinder-page-cache-score"><?php echo is_array($cloud) && isset($cloud['score']) && null !== $cloud['score'] ? (int) $cloud['score'] . '/100' : esc_html__('No score yet', 'cinder-page-cache'); ?></strong>
            </p>
            <ul id="cinder-page-cache-notes">
                <?php if (is_array($cloud) && !empty($cloud['notes'])) : foreach ((array) $cloud['notes'] as $note) : ?>
                    <li><?php echo esc_html($note); ?></li>
                <?php endforeach; endif; ?>
            </ul>
            <p>
                <button type="button" class="button" id="cinder-page-cache-cloud"><?php echo 'yes' === get_option('cpc_consent', '') ? esc_html__('Run cloud check', 'cinder-page-cache') : esc_html__('Enable and run', 'cinder-page-cache'); ?></button>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpc_snapshot'), 'cpc_snapshot')); ?>"><?php esc_html_e('Download snapshot', 'cinder-page-cache'); ?></a>
                <span class="cinder-page-cache-result" id="cinder-page-cache-cloud-result"></span>
            </p>
            <p class="description"><?php printf(esc_html__('Last sync: %s', 'cinder-page-cache'), $last ? esc_html(wp_date('Y-m-d H:i', $last)) : esc_html__('not yet', 'cinder-page-cache')); ?></p>
        </div>

        <div class="cinder-page-cache-panel" id="diagnostics">
            <h2><?php esc_html_e('Environment check', 'cinder-page-cache'); ?></h2>
            <p class="description"><?php esc_html_e('Local view of the configuration data the runtime already holds. Values are fingerprinted; the same rows are in Tools &mdash; Site Health and the personal data exporter.', 'cinder-page-cache'); ?></p>
            <table class="widefat striped cinder-page-cache-table">
                <tbody>
                    <tr><td><?php esc_html_e('Configuration constants', 'cinder-page-cache'); ?></td><td id="cinder-page-cache-config"><?php echo $diag['config_readable'] ? esc_html__('Available', 'cinder-page-cache') : esc_html__('None', 'cinder-page-cache'); ?></td></tr>
                    <tr><td><?php esc_html_e('Environment variables', 'cinder-page-cache'); ?></td><td id="cinder-page-cache-env"><?php echo $diag['env_detected'] ? esc_html__('Available', 'cinder-page-cache') : esc_html__('None', 'cinder-page-cache'); ?></td></tr>
                    <tr><td><?php esc_html_e('Entries', 'cinder-page-cache'); ?></td><td id="cinder-page-cache-entries"><?php echo (int) $diag['entries']; ?></td></tr>
                </tbody>
            </table>
            <p><button type="button" class="button" id="cinder-page-cache-refresh"><?php esc_html_e('Refresh check', 'cinder-page-cache'); ?></button>
                <span class="cinder-page-cache-result" id="cinder-page-cache-refresh-result"></span></p>
        </div>

        <div class="cinder-page-cache-panel" id="tools">
            <h2><?php esc_html_e('Tools', 'cinder-page-cache'); ?></h2>
            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpc_export'), 'cpc_export')); ?>"><?php esc_html_e('Export settings', 'cinder-page-cache'); ?></a>
            </p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="cpc_import">
                <?php wp_nonce_field('cpc_import'); ?>
                <p><input type="file" name="cpc_file" accept="application/json"> <button type="submit" class="button"><?php esc_html_e('Import settings', 'cinder-page-cache'); ?></button></p>
            </form>

            <h3><?php esc_html_e('Database backups', 'cinder-page-cache'); ?></h3>
            <p>
                <button type="button" class="button" id="cpc-backup"><?php esc_html_e('Create backup now', 'cinder-page-cache'); ?></button>
                <span class="cpc-result" id="cpc-backup-result"></span>
            </p>
            <?php $cpc_backups = CPC_Backup::list_files(); ?>
            <table class="widefat striped cinder-page-cache-table">
                <thead><tr><th><?php esc_html_e('File', 'cinder-page-cache'); ?></th><th><?php esc_html_e('Size', 'cinder-page-cache'); ?></th><th><?php esc_html_e('Date', 'cinder-page-cache'); ?></th><th></th></tr></thead>
                <tbody>
                    <?php if (!$cpc_backups) : ?>
                        <tr><td colspan="4"><?php esc_html_e('No backups yet.', 'cinder-page-cache'); ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($cpc_backups as $cpc_b) : ?>
                        <tr>
                            <td><code><?php echo esc_html($cpc_b['name']); ?></code></td>
                            <td><?php echo esc_html(cpc_bytes($cpc_b['size'])); ?></td>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i', $cpc_b['time'])); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpc_backup_download&file=' . rawurlencode($cpc_b['name'])), 'cpc_backup')); ?>"><?php esc_html_e('Download', 'cinder-page-cache'); ?></a>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=cpc_backup_delete&file=' . rawurlencode($cpc_b['name'])), 'cpc_backup')); ?>"><?php esc_html_e('Delete', 'cinder-page-cache'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <table class="widefat striped cinder-page-cache-table">
                <tbody>
                    <?php foreach ($sys as $key => $value) : ?>
                        <tr><td><?php echo esc_html(ucfirst($key)); ?></td><td><?php echo esc_html($value); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="submit">
            <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'cinder-page-cache'); ?></button>
        </p>
    </form>
</div>
