<?php

/**
 * Plugin Name: ATS CV Detector
 * Description: Private CV builder, evidence-based job analysis, AI revamping, credits and Paynow Zimbabwe billing.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: ATS CV Detector
 * License: GPL-2.0-or-later
 * Text Domain: ats-cv-detector
 */
defined('ABSPATH') || exit;
define('ATSCV_VERSION', '1.0.0');
define('ATSCV_FILE', __FILE__);
define('ATSCV_DIR', __DIR__.'/');
if (is_file(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}
spl_autoload_register(static function ($class) {
    $prefix = 'ATSCV\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
add_filter('cron_schedules', static function ($schedules) {
    $schedules['atscv_five_minutes'] = ['interval' => 300, 'display' => 'ATS CV every five minutes'];

    return $schedules;
});
register_activation_hook(__FILE__, ['ATSCV\\Install', 'activate']);
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('atscv_maintenance');
    wp_unschedule_hook('atscv_ai_job');
});
add_action('plugins_loaded', ['ATSCV\\Plugin', 'boot']);
