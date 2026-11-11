<?php

/**
 * Plugin Name: ATS CV Detector — Original Application
 * Description: Connects WordPress to the original ATS CV Detector application, preserving its interface and Laravel behaviour.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 */
defined('ABSPATH') || exit;

final class ATS_CV_Original
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'settings']);
        add_action('template_redirect', [self::class, 'launch']);
        add_shortcode('ats_cv_original', [self::class, 'shortcode']);
    }

    public static function url(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = esc_url_raw(trim($value), ['https', 'http']);
        $parts = wp_parse_url($value);
        if (! $parts || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        return untrailingslashit($value);
    }

    public static function configured_url(): string
    {
        return self::url(defined('ATSCV_ORIGINAL_URL') ? ATSCV_ORIGINAL_URL : get_option('atscv_original_url', ''));
    }

    public static function activate(): void
    {
        $id = (int) get_option('atscv_original_page');
        if ($id && get_post($id)) {
            return;
        }
        $id = wp_insert_post([
            'post_title' => 'ATS CV Detector', 'post_name' => 'ats-cv-original',
            'post_content' => '[ats_cv_original]', 'post_status' => 'publish', 'post_type' => 'page',
        ], true);
        if (! is_wp_error($id)) {
            update_option('atscv_original_page', $id);
        }
    }

    public static function menu(): void
    {
        add_options_page('ATS CV Original', 'ATS CV Original', 'manage_options', 'ats-cv-original', [self::class, 'screen']);
    }

    public static function settings(): void
    {
        register_setting('atscv_original', 'atscv_original_url', [
            'type' => 'string', 'sanitize_callback' => static function ($value) {
                $url = self::url($value);
                if (! $url && trim((string) $value) !== '') {
                    add_settings_error('atscv_original_url', 'invalid_url', 'Enter an absolute application URL without credentials, query parameters or fragments.');

                    return get_option('atscv_original_url', '');
                }

                return $url;
            }, 'default' => '',
        ]);
    }

    public static function screen(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>ATS CV Detector — Original Application</h1>';
        echo '<p>This connector opens the existing Laravel application. It preserves its screens, accounts, CVs, AI credits, subscriptions and Paynow integration. The application must remain hosted and running separately.</p>';
        echo '<form action="options.php" method="post">';
        settings_fields('atscv_original');
        echo '<p><label for="atscv-original-url">Original application URL</label></p><input id="atscv-original-url" type="url" class="regular-text" name="atscv_original_url" value="'.esc_attr(self::configured_url()).'" placeholder="https://app.example.com" '.(defined('ATSCV_ORIGINAL_URL') ? 'readonly' : '').'>';
        echo '<p>Use the public HTTPS URL. Keep Paynow and AI credentials in the Laravel server environment. WordPress login remains separate from the application login.</p>';
        submit_button();
        echo '</form>';
        $page = get_permalink((int) get_option('atscv_original_page'));
        if ($page) {
            echo '<p><a class="button" href="'.esc_url($page).'">Open ATS CV Detector page</a></p>';
        }
        echo '<p>The plugin page opens the application in the full browser window so checkout, downloads and authentication retain the original behaviour. Add this page to your WordPress navigation.</p></div>';
    }

    public static function destination(): string
    {
        $url = self::configured_url();
        // Avoid sending visitors back to a WordPress page containing this shortcode.
        $home = untrailingslashit(home_url('/'));
        $page = get_permalink((int) get_option('atscv_original_page'));
        if ($url === $home || ($page && $url === untrailingslashit($page))) {
            return '';
        }

        return $url;
    }

    public static function launch(): void
    {
        if (! is_page() || is_preview()) {
            return;
        }
        $post = get_queried_object();
        if (! $post instanceof WP_Post || ! has_shortcode($post->post_content, 'ats_cv_original')) {
            return;
        }
        $url = self::destination();
        if (! $url) {
            return;
        }
        nocache_headers();
        // The destination comes only from administrator-controlled configuration, never request parameters.
        wp_redirect($url, 302, 'ATS CV Original');
        exit;
    }

    public static function shortcode(): string
    {
        $url = self::destination();
        if (! $url) {
            return '<p>ATS CV Detector is awaiting application configuration.</p>';
        }

        return '<p><a href="'.esc_url($url).'">Open ATS CV Detector</a></p>';
    }
}

ATS_CV_Original::boot();
register_activation_hook(__FILE__, [ATS_CV_Original::class, 'activate']);
