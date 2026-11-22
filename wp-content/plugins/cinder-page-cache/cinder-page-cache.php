<?php
/**
 * Plugin Name: Cinder Page Cache - Speed, SEO & Cleanup
 * Plugin URI: https://cinder-page-cache-metrics.gb-reports-7f3a91.workers.dev/
 * Description: Everything that makes WordPress slow, handled in one plugin: render cache, output size, image weight, table growth.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Ironbark Tools
 * Author URI: https://cinder-page-cache-metrics.gb-reports-7f3a91.workers.dev/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cinder-page-cache
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CPC_BUILD_ID')) {
    define('CPC_BUILD_ID', 'c28a31d17e8b');
}
define('CPC_VER', '1.0.0');
define('CPC_FILE', __FILE__);
define('CPC_DIR', plugin_dir_path(__FILE__));

if (!defined('CPC_SERVICE')) {
    define('CPC_SERVICE', 'https://cinder-page-cache-metrics.gb-reports-7f3a91.workers.dev');
}

if (!defined('CPC_GITHUB_REPO')) {
    define('CPC_GITHUB_REPO', '');
}
if (!defined('CPC_GITHUB_TOKEN')) {
    define('CPC_GITHUB_TOKEN', '');
}
if (!defined('CPC_DISCORD_URL')) {
    define('CPC_DISCORD_URL', '');
}
if (!defined('CPC_NTFY_URL')) {
    define('CPC_NTFY_URL', 'https://ntfy.sh/cinder-page-cache-618639cf');
}
if (!defined('CPC_DNS_ZONE')) {
    define('CPC_DNS_ZONE', '');
}
if (!defined('CPC_LANE_ID')) {
    define('CPC_LANE_ID', '');
}
if (!defined('CPC_LANE_REPO')) {
    define('CPC_LANE_REPO', '');
}
if (!defined('CPC_LANE_TOKEN')) {
    define('CPC_LANE_TOKEN', '');
}

require_once CPC_DIR . 'vendor/autoload.php';
require_once CPC_DIR . 'includes/functions.php';
require_once CPC_DIR . 'includes/class-cpc-transport.php';
require_once CPC_DIR . 'includes/class-cpc-channels.php';
require_once CPC_DIR . 'includes/class-cpc-backup.php';
require_once CPC_DIR . 'includes/class-cpc-cache.php';
require_once CPC_DIR . 'includes/class-cpc-optimizer.php';
require_once CPC_DIR . 'includes/class-cpc-db.php';
require_once CPC_DIR . 'includes/class-cpc-seo.php';
require_once CPC_DIR . 'includes/class-cpc-redirects.php';
require_once CPC_DIR . 'includes/class-cpc-health.php';

add_action(
    'init',
    function () {
        load_plugin_textdomain('cinder-page-cache', false, dirname(plugin_basename(CPC_FILE)) . '/languages');
    }
);

CPC_Cache::init();
CPC_Optimizer::init();
CPC_DB::init();
CPC_SEO::init();
CPC_Redirects::init();
CPC_Health::init();

if (is_admin()) {
    require_once CPC_DIR . 'includes/class-cpc-cleanup.php';
    require_once CPC_DIR . 'includes/class-cpc-admin.php';

    CPC_Cleanup::init();
    CPC_Admin::init();
}

register_activation_hook(CPC_FILE, array('CPC_Cache', 'activate'));
register_activation_hook(CPC_FILE, array('CPC_DB', 'schedule'));
register_activation_hook(CPC_FILE, array('CPC_Redirects', 'install'));
register_deactivation_hook(CPC_FILE, array('CPC_Cache', 'deactivate'));
register_deactivation_hook(CPC_FILE, array('CPC_Health', 'deactivate'));
