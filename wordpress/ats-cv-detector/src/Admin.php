<?php

namespace ATSCV;

final class Admin
{
    public static function menu(): void
    {
        add_menu_page('ATS CV Detector', 'ATS CV Detector', 'atscv_manage_billing', 'atscv', [self::class, 'render'], 'dashicons-media-document');
    }

    public static function render(): void
    {
        if (! current_user_can('atscv_manage_billing')) {
            return;
        }
        global $wpdb;
        $payments = DB::table('payments');
        $periods = DB::table('periods');
        $outbox = DB::table('outbox');
        $notifications = DB::table('notifications');
        $search = sanitize_text_field(wp_unslash($_GET['search'] ?? ''));
        $where = '1=1';
        if ($search) {
            $where = $wpdb->prepare('(reference LIKE %s OR provider_reference LIKE %s)', '%'.$wpdb->esc_like($search).'%', '%'.$wpdb->esc_like($search).'%');
        }
        echo '<div class="wrap"><h1>ATS CV Detector</h1><p><a href="'.esc_url(Config::page()).'">Open customer workspace</a></p>';
        if (isset($_GET['notice'])) {
            echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['notice']))).'</p></div>';
        }
        echo '<h2>Configuration</h2><table class="widefat"><tbody>';
        foreach (['Paynow mode' => Config::get('PAYNOW_MODE', 'test'), 'Paynow configured' => (Config::get('PAYNOW_ID') && Config::get('PAYNOW_KEY')) ? 'Yes' : 'No',
            'Live approved' => Config::enabled('PAYNOW_LIVE_APPROVED') ? 'Yes' : 'No', 'AI configured' => Config::get('OPENAI_KEY') ? 'Yes' : 'No',
            'Public site' => home_url('/'), 'Last maintenance heartbeat' => get_option('atscv_heartbeat', 'Not yet run'),
            'Unsent billing messages' => (string) $wpdb->get_var("SELECT COUNT(*) FROM $outbox WHERE sent_at IS NULL"), 'Unprocessed notifications' => (string) $wpdb->get_var("SELECT COUNT(*) FROM $notifications WHERE processed_at IS NULL")] as $label => $value) {
            echo '<tr><th>'.esc_html($label).'</th><td>'.esc_html($value).'</td></tr>';
        }
        echo '</tbody></table><p>Configure secrets using ATSCV_* constants in protected wp-config.php or environment variables. Never put credentials in page content. Use test merchant credentials first. Configure a real scheduler and authenticated mail delivery.</p><h2>Payment totals</h2>';
        foreach ($wpdb->get_results("SELECT mode,state,COUNT(*) AS total,COALESCE(SUM(amount_cents),0) AS cents FROM $payments GROUP BY mode,state") as $total) {
            echo '<p>'.esc_html($total->mode.' / '.$total->state.': '.$total->total.' payments · USD '.number_format($total->cents / 100, 2)).'</p>';
        }
        echo '<h2>Subscription periods</h2>';
        foreach ($wpdb->get_results("SELECT state,COUNT(*) AS total FROM $periods GROUP BY state") as $total) {
            echo '<p>'.esc_html($total->state.': '.$total->total).'</p>';
        }
        $month = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM $payments WHERE mode='live' AND state='paid' AND paid_at>=%s", gmdate('Y-m-01 00:00:00')));
        $year = $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount_cents),0) FROM $payments WHERE mode='live' AND state='paid' AND paid_at>=%s", gmdate('Y-01-01 00:00:00')));
        echo '<h2>Verified gross live revenue</h2><p>This month: USD '.esc_html(number_format($month / 100, 2)).' · This year: USD '.esc_html(number_format($year / 100, 2)).'. Currently reversed payments are excluded. This is not a settlement report.</p><h2>Paid plan distribution</h2>';
        foreach ($wpdb->get_results("SELECT plan,COUNT(*) AS total,SUM(credits) AS credits FROM $payments WHERE mode='live' AND state='paid' GROUP BY plan") as $total) {
            echo '<p>'.esc_html($total->plan.': '.$total->total.' payments / '.$total->credits.' purchased credits').'</p>';
        }
        echo '<form method="get"><input type="hidden" name="page" value="atscv"><label>Reference <input name="search" value="'.esc_attr($search).'" maxlength="100"></label><button class="button">Search</button></form><h2>Recent payments</h2><table class="widefat striped"><thead><tr><th>Reference</th><th>Purchase</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach ($wpdb->get_results("SELECT * FROM $payments WHERE $where ORDER BY id DESC LIMIT 50") as $row) {
            echo '<tr><td>'.esc_html($row->reference).'<br>'.esc_html('Paynow: '.($row->provider_reference ?: 'Not received')).'<br>'.esc_html('Account '.$row->user_id).'</td><td>'.esc_html($row->plan.' / '.$row->kind.' / '.$row->mode).'<br>USD '.esc_html(number_format($row->amount_cents / 100, 2)).'</td><td>'.esc_html($row->state.' / '.$row->provider_status).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('atscv_admin');
            echo '<input type="hidden" name="action" value="atscv_admin"><input type="hidden" name="reference" value="'.esc_attr($row->reference).'"><button class="button" name="task" value="reconcile">Reconcile</button><label>Review <select name="review_type"><option value="refund_requested">Refund requested</option><option value="manual_refund_recorded">Manual refund recorded</option><option value="dispute_review">Dispute review</option></select></label><textarea name="notes" maxlength="2000" placeholder="Review notes; actual refunds are processed with Paynow"></textarea><button class="button" name="task" value="review">Save review</button></form></td></tr>';
        }
        echo '</tbody></table><h2>Recent unprocessed provider notifications</h2><ul>';
        foreach ($wpdb->get_results("SELECT id,payment_id,hash_valid,error FROM $notifications WHERE processed_at IS NULL ORDER BY id DESC LIMIT 25") as $notification) {
            echo '<li>'.esc_html('#'.$notification->id.' / Payment '.$notification->payment_id.' / '.($notification->hash_valid ? 'Authenticated' : 'Invalid signature').' / '.$notification->error).'</li>';
        }
        $reviews = DB::table('reviews');
        echo '</ul><h2>Refund and dispute reviews</h2><ul>';
        foreach ($wpdb->get_results("SELECT * FROM $reviews ORDER BY id DESC LIMIT 25") as $review) {
            echo '<li>'.esc_html('Payment '.$review->payment_id.' / '.$review->type.' / '.$review->notes.' / '.$review->created_at.' UTC').'</li>';
        }
        echo '</ul><h2>Billing report</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('atscv_admin');
        echo '<input type="hidden" name="action" value="atscv_admin"><button class="button" name="task" value="export">Download payment CSV</button></form><h2>Deployment</h2><p>Run the WordPress cron runner at least once per minute. Exclude the customer workspace, private REST responses and downloads from page/CDN caching. Backup the database and WordPress salts; encrypted CVs and provider URLs depend on those salts. Plugin deactivation retains data.</p></div>';
    }

    public static function action(): void
    {
        if (! current_user_can('atscv_manage_billing')) {
            wp_die('Forbidden', 403);
        }
        check_admin_referer('atscv_admin');
        try {
            $task = sanitize_key($_POST['task'] ?? '');
            if ($task === 'export') {
                global $wpdb;
                $table = DB::table('payments');
                nocache_headers();
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="ats-payments.csv"');
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Reference', 'Provider reference', 'Plan', 'Kind', 'Mode', 'State', 'Amount cents', 'Currency', 'Paid at']);
                $after = 0;
                do {
                    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE id>%d ORDER BY id LIMIT 100", $after));
                    foreach ($rows as $row) {
                        $after = (int) $row->id;
                        $cells = [$row->reference, $row->provider_reference, $row->plan, $row->kind, $row->mode, $row->state, $row->amount_cents, 'USD', $row->paid_at];
                        fputcsv($output, array_map(static fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $cells));
                    }
                } while (count($rows) === 100);
                fclose($output);
                exit;
            }
            $payment = Billing::find(sanitize_text_field(wp_unslash($_POST['reference'] ?? '')));
            if (! $payment) {
                throw new \DomainException('Payment not found.');
            }
            if ($task === 'reconcile') {
                Billing::refresh($payment);
                $notice = 'Provider status reconciled.';
            } elseif ($task === 'review') {
                $type = sanitize_key($_POST['review_type'] ?? '');
                $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
                if (! in_array($type, ['refund_requested', 'manual_refund_recorded', 'dispute_review'], true) || mb_strlen($notes) < 10 || mb_strlen($notes) > 2000) {
                    throw new \InvalidArgumentException('Choose a valid review type and notes between 10 and 2,000 characters.');
                }
                DB::insert('reviews', ['payment_id' => $payment->id, 'actor_id' => get_current_user_id(), 'type' => $type, 'notes' => $notes, 'created_at' => DB::now()]);
                DB::audit((int) $payment->user_id, 'payment.'.$type, $payment->reference);
                $notice = 'Review saved. Perform money movement through Paynow, then reconcile. A review note does not change financial status.';
            } else {
                throw new \InvalidArgumentException('Invalid billing action.');
            }
        } catch (\Throwable $e) {
            $notice = $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Verification unavailable. The recorded payment has not been assumed paid or cancelled.';
        }
        wp_safe_redirect(add_query_arg(['page' => 'atscv', 'notice' => $notice], admin_url('admin.php')));
        exit;
    }
}
