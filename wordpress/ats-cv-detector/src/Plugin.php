<?php

namespace ATSCV;

final class Plugin
{
    public static function boot(): void
    {
        if (get_option('atscv_schema_version') !== ATSCV_VERSION) {
            Install::activate();
        }
        add_action('rest_api_init', [Rest::class, 'register']);
        add_action('atscv_maintenance', [Jobs::class, 'maintenance']);
        add_action('atscv_ai_job', [AI::class, 'process']);
        add_shortcode('ats_cv_detector', [UI::class, 'render']);
        foreach (['ats_dashboard', 'ats_checker', 'ats_builder', 'ats_subscription', 'ats_payment_result'] as $shortcode) {
            add_shortcode($shortcode, [UI::class, 'render']);
        }
        add_action('wp_enqueue_scripts', [UI::class, 'assets']);
        add_action('admin_menu', [Admin::class, 'menu']);
        add_action('admin_post_atscv_admin', [Admin::class, 'action']);
        add_action('admin_post_atscv_download', [UI::class, 'download']);
        foreach (['atscv_register', 'atscv_verify', 'atscv_resend'] as $action) {
            add_action('admin_post_'.$action, [Auth::class, 'action']);
            add_action('admin_post_nopriv_'.$action, [Auth::class, 'action']);
        }
        add_filter('wp_privacy_personal_data_exporters', [Privacy::class, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [Privacy::class, 'erasers']);
        add_action('delete_user', [Privacy::class, 'deleted']);
        add_action('profile_update', static function ($id, $old) {
            $user = get_userdata($id);
            if ($user && strcasecmp($old->user_email, $user->user_email) !== 0) {
                delete_user_meta($id, 'atscv_email_verified');
                delete_user_meta($id, 'atscv_verification_hash');
                delete_user_meta($id, 'atscv_verification_expiry');
            }
        }, 10, 2);
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (str_starts_with($request->get_route(), '/atscv/')) {
                $response->header('Cache-Control', 'private, no-store');
            }

            return $response;
        }, 10, 3);
    }
}
