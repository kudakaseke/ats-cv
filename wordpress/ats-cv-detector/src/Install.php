<?php

namespace ATSCV;

final class Install
{
    public static function activate(): void
    {
        global $wpdb;
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->users));
        if (strtolower((string) $engine) !== 'innodb') {
            throw new \RuntimeException('ATS CV Detector requires InnoDB for the WordPress users table. Ask your database administrator to migrate its engine before activation.');
        }
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $schemas = [
            'limits' => 'bucket char(64) NOT NULL, hits int unsigned NOT NULL DEFAULT 1, expires_at datetime NOT NULL, PRIMARY KEY  (bucket), KEY expiry (expires_at)',
            'resumes' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, title varchar(150) NOT NULL, template varchar(100) NOT NULL, content longtext NOT NULL, revision int unsigned NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), KEY owner (user_id)',
            'wallets' => 'user_id bigint unsigned NOT NULL, allowance int NOT NULL DEFAULT 15, topup int NOT NULL DEFAULT 0, debt int NOT NULL DEFAULT 0, period_id bigint unsigned DEFAULT NULL, PRIMARY KEY  (user_id)',
            'ledger' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, event_key varchar(190) NOT NULL, type varchar(50) NOT NULL, allowance_delta int NOT NULL DEFAULT 0, topup_delta int NOT NULL DEFAULT 0, debt_delta int NOT NULL DEFAULT 0, reference varchar(100) NOT NULL DEFAULT \'\', created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY event_key (event_key), KEY owner (user_id)',
            'operations' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, operation_key varchar(64) NOT NULL, input_hash char(64) NOT NULL, kind varchar(20) NOT NULL, state varchar(20) NOT NULL, period_id bigint unsigned DEFAULT NULL, allowance_spent int NOT NULL DEFAULT 0, topup_spent int NOT NULL DEFAULT 0, input longtext NOT NULL, result longtext DEFAULT NULL, attempts int unsigned NOT NULL DEFAULT 0, lease_token varchar(64) DEFAULT NULL, lease_until datetime DEFAULT NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY owner_operation (user_id,operation_key), KEY due (state,lease_until)',
            'payments' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, reference varchar(64) NOT NULL, user_id bigint unsigned NOT NULL, plan varchar(20) NOT NULL, kind varchar(20) NOT NULL DEFAULT \'subscription\', mode varchar(10) NOT NULL, integration_id varchar(100) NOT NULL, amount_cents int unsigned NOT NULL, credits int unsigned NOT NULL, currency char(3) NOT NULL DEFAULT \'USD\', state varchar(20) NOT NULL DEFAULT \'pending\', provider_status varchar(100) DEFAULT NULL, provider_reference varchar(100) DEFAULT NULL, poll_url longtext DEFAULT NULL, checkout_url longtext DEFAULT NULL, trace char(32) NOT NULL, period_id bigint unsigned DEFAULT NULL, paid_at datetime DEFAULT NULL, verified_at datetime DEFAULT NULL, next_check_at datetime DEFAULT NULL, attempts int unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY reference (reference), UNIQUE KEY trace (trace), UNIQUE KEY provider_reference (integration_id,mode,provider_reference), KEY due (state,next_check_at), KEY owner (user_id)',
            'periods' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, payment_id bigint unsigned NOT NULL, plan varchar(20) NOT NULL, credits int unsigned NOT NULL, state varchar(20) NOT NULL DEFAULT \'active\', starts_at datetime NOT NULL, ends_at datetime NOT NULL, cancelled_at datetime DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY payment (payment_id), KEY owner_period (user_id,starts_at,ends_at)',
            'notifications' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, payment_id bigint unsigned DEFAULT NULL, fingerprint char(64) NOT NULL, payload text NOT NULL, hash_valid tinyint NOT NULL DEFAULT 0, processed_at datetime DEFAULT NULL, error varchar(100) DEFAULT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY fingerprint (fingerprint)',
            'receipts' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, payment_id bigint unsigned NOT NULL, details longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY payment (payment_id)',
            'outbox' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, event_key varchar(190) NOT NULL, subject varchar(190) NOT NULL, message text NOT NULL, attempts int NOT NULL DEFAULT 0, due_at datetime NOT NULL, sent_at datetime DEFAULT NULL, PRIMARY KEY  (id), UNIQUE KEY event_key (event_key)',
            'reviews' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, payment_id bigint unsigned NOT NULL, actor_id bigint unsigned NOT NULL, type varchar(30) NOT NULL, notes text NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY payment (payment_id)',
            'audit' => 'id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL, actor_id bigint unsigned NOT NULL DEFAULT 0, event varchar(100) NOT NULL, reference varchar(100) NOT NULL, details text NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY owner (user_id)',
        ];
        foreach ($schemas as $name => $columns) {
            $columns = str_replace(', ', ",\n", $columns);
            dbDelta('CREATE TABLE '.DB::table($name)." (\n".$columns."\n) ENGINE=InnoDB $collate;");
            $table = DB::table($name);
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                throw new \RuntimeException('ATS CV database installation failed.');
            }
        }
        $admin = get_role('administrator');
        foreach (['atscv_manage_billing', 'atscv_manage_app'] as $capability) {
            $admin?->add_cap($capability);
        }
        if (! get_option('atscv_page_id')) {
            $id = wp_insert_post(['post_title' => 'ATS CV Detector', 'post_name' => 'ats-cv-detector', 'post_content' => '[ats_cv_detector]', 'post_status' => 'publish', 'post_type' => 'page'], true);
            if (! is_wp_error($id)) {
                update_option('atscv_page_id', $id, false);
            }
        }
        update_option('atscv_schema_version', ATSCV_VERSION, false);
        if (! wp_next_scheduled('atscv_maintenance')) {
            wp_schedule_event(time() + 60, 'atscv_five_minutes', 'atscv_maintenance');
        }
    }
}
