<?php

namespace ATSCV;

final class UI
{
    public static function assets(): void
    {
        global $post;
        if (! $post || (! str_contains($post->post_content, '[ats_') && (int) $post->ID !== (int) get_option('atscv_page_id'))) {
            return;
        }
        wp_enqueue_style('atscv-app', plugins_url('assets/app.css', ATSCV_FILE), [], ATSCV_VERSION);
        wp_enqueue_script('atscv-app', plugins_url('assets/app.js', ATSCV_FILE), [], ATSCV_VERSION, true);
        wp_add_inline_script('atscv-app', 'window.ATSCV='.wp_json_encode(['api' => rest_url('atscv/v1/'), 'nonce' => wp_create_nonce('wp_rest'),
            'download' => admin_url('admin-post.php'), 'downloadNonce' => wp_create_nonce('atscv_download'),
            'payment' => sanitize_text_field(wp_unslash($_GET['atscv_payment'] ?? ''))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';', 'before');
    }

    public static function render(): string
    {
        if (! headers_sent()) {
            nocache_headers();
        }
        ob_start();
        echo '<div class="atscv-app">';
        if (isset($_GET['atscv_notice'])) {
            echo '<p class="atscv-notice" role="status">'.esc_html(sanitize_text_field(wp_unslash($_GET['atscv_notice']))).'</p>';
        }
        if (! is_user_logged_in()) {
            echo '<header><p class="atscv-kicker">Your next opportunity starts here</p><h1>ATS CV Detector</h1><p>Create a clear CV, compare it with a job and improve it using your real experience.</p></header><div class="atscv-grid"><section class="atscv-card"><h2>Sign in</h2>';
            wp_login_form(['redirect' => Config::page(), 'label_username' => 'Email or username']);
            echo '<a href="'.esc_url(wp_lostpassword_url(Config::page())).'">Reset password</a></section><section class="atscv-card"><h2>Create your account</h2>';
            if (get_option('users_can_register')) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="atscv_register">';
                wp_nonce_field('atscv_account');
                echo '<label>Name<input name="name" required maxlength="100" autocomplete="name"></label><label>Email<input name="email" type="email" required autocomplete="email"></label><label>Password<input name="password" type="password" required minlength="12" maxlength="200" autocomplete="new-password"></label><button>Create account</button><p>Check your email to verify. You receive 15 welcome credits once. AI processing uses an external provider; review the site privacy and payment terms.</p></form>';
            } else {
                echo '<p>Registration is currently closed. Contact the site administrator to create an account.</p>';
            }
            echo '</section></div>';
        } elseif (! Auth::verified(get_current_user_id())) {
            echo '<section class="atscv-card"><h1>Verify your email</h1><p>Open the verification link sent to your account email, then return here. This protects your CVs and credits.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="atscv_resend">';
            wp_nonce_field('atscv_account');
            echo '<button>Send verification email</button></form></section>';
        } else {
            echo '<header class="atscv-header"><div><p class="atscv-kicker">Build. Match. Improve.</p><h1>ATS CV Detector</h1></div><div><a href="'.esc_url(get_edit_profile_url()).'">Account settings</a> · <a href="'.esc_url(wp_logout_url(Config::page())).'">Sign out</a></div></header><nav class="atscv-nav" aria-label="CV workspace"><button data-tab="dashboard">Dashboard</button><button data-tab="builder">CV builder</button><button data-tab="checker">Check and improve</button><button data-tab="billing">Plans and billing</button><button data-tab="history">History</button></nav><p id="atscv-status" role="status" aria-live="polite"></p><main id="atscv-workspace"><p>Loading your workspace…</p></main>';
        }
        echo '</div>';

        return ob_get_clean();
    }

    public static function download(): void
    {
        if (! is_user_logged_in() || ! Auth::verified(get_current_user_id())) {
            wp_die('Sign in with a verified account.', 403);
        }
        check_admin_referer('atscv_download');
        try {
            Auth::limit('export:'.get_current_user_id(), 10, 60);
            $format = sanitize_key($_POST['format'] ?? '');
            if ($format === 'receipt') {
                global $wpdb;
                $payment = Billing::find(sanitize_text_field(wp_unslash($_POST['reference'] ?? '')), get_current_user_id());
                if (! $payment) {
                    throw new \DomainException('Receipt unavailable.');
                }
                $table = DB::table('receipts');
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE payment_id=%d", $payment->id));
                if (! $row) {
                    throw new \DomainException('No live receipt exists for this payment.');
                }
                $details = json_decode(Config::crypt($row->details, true), true, 64, JSON_THROW_ON_ERROR);
                $html = '<!doctype html><html><meta charset="utf-8"><title>ATS receipt</title><body><h1>'.esc_html($details['business']).'</h1><h2>Receipt ATS-'.$payment->id.'</h2><p>Payment receipt; not a tax invoice.</p><dl>';
                foreach ($details as $name => $value) {
                    $html .= '<dt>'.esc_html(ucfirst(str_replace('_', ' ', $name))).'</dt><dd>'.esc_html((string) $value).'</dd>';
                }
                $html .= '<dt>Current status</dt><dd>'.esc_html($payment->state.' / '.$payment->provider_status).'</dd></dl></body></html>';
                $content = $html;
                $mime = 'text/html; charset=utf-8';
                $filename = 'ats-receipt-'.$payment->id.'.html';
            } else {
                if (! in_array($format, ['pdf', 'docx'], true)) {
                    throw new \InvalidArgumentException('Invalid export format.');
                }
                $row = Resume::find(absint($_POST['id'] ?? 0), get_current_user_id());
                if (! $row || ! Resume::allowed(get_current_user_id(), $row->template, $row)) {
                    throw new \DomainException('CV unavailable.');
                }
                $cv = json_decode(Config::crypt($row->content, true), true, 64, JSON_THROW_ON_ERROR);
                $content = $format === 'pdf' ? Documents::pdf($cv, $row->template) : Documents::docx($cv, $row->template);
                $mime = $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                $filename = 'my-cv.'.$format;
            }
            nocache_headers();
            header('Content-Type: '.$mime);
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('Content-Length: '.strlen($content));
            echo $content;
            exit;
        } catch (\Throwable $e) {
            wp_die(esc_html($e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The document could not be generated. Please try again or contact support.'), 'ATS CV Detector', ['response' => 400]);
        }
    }
}
