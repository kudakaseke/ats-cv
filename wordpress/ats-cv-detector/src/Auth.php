<?php

namespace ATSCV;

final class Auth
{
    public static function verified(int $user): bool
    {
        return user_can($user, 'atscv_manage_app') || (bool) get_user_meta($user, 'atscv_email_verified', true);
    }

    public static function limit(string $name, int $max, int $seconds = 60): void
    {
        global $wpdb;
        $table = DB::table('limits');
        $bucket = hash('sha256', $name.':'.(int) floor(time() / $seconds));
        DB::run($wpdb->prepare("INSERT INTO $table (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1", $bucket, gmdate('Y-m-d H:i:s', time() + $seconds * 2)));
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT hits FROM $table WHERE bucket=%s", $bucket)) > $max) {
            throw new \DomainException('Too many requests. Please wait and try again.');
        }
    }

    public static function sendVerification(int $user): void
    {
        self::limit('verify:'.$user, 1, 60);
        $account = get_userdata($user);
        if (! $account) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        update_user_meta($user, 'atscv_verification_hash', hash('sha256', $token));
        update_user_meta($user, 'atscv_verification_expiry', time() + 86400);
        $url = add_query_arg(['action' => 'atscv_verify', 'user' => $user, 'token' => $token], admin_url('admin-post.php'));
        if (! wp_mail($account->user_email, 'Verify your ATS CV Detector email', 'Verify your account within 24 hours: '.$url)) {
            throw new \RuntimeException('Verification email could not be sent. Please retry after checking your mail setup.');
        }
    }

    public static function action(): void
    {
        try {
            $action = sanitize_key($_REQUEST['action'] ?? '');
            if ($action === 'atscv_verify') {
                self::limit('verify-ip:'.hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 30);
                $user = absint($_GET['user'] ?? 0);
                $token = (string) ($_GET['token'] ?? '');
                if (! preg_match('/^[a-f0-9]{64}$/D', $token) || (int) get_user_meta($user, 'atscv_verification_expiry', true) < time()
                    || ! hash_equals((string) get_user_meta($user, 'atscv_verification_hash', true), hash('sha256', $token))) {
                    throw new \DomainException('This verification link is invalid or expired.');
                }
                update_user_meta($user, 'atscv_email_verified', DB::now());
                delete_user_meta($user, 'atscv_verification_hash');
                delete_user_meta($user, 'atscv_verification_expiry');
                $message = 'Email verified. Sign in to continue.';
            } else {
                check_admin_referer('atscv_account');
                self::limit('account-ip:'.hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 5, 300);
                if ($action === 'atscv_register') {
                    if (! get_option('users_can_register')) {
                        throw new \DomainException('Account registration is disabled. Contact the site administrator.');
                    }
                    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
                    $password = (string) wp_unslash($_POST['password'] ?? '');
                    if (! is_email($email) || strlen($password) < 12 || strlen($password) > 200) {
                        throw new \InvalidArgumentException('Enter a valid email and a password of at least 12 characters.');
                    }
                    $result = wp_insert_user(['user_login' => 'atscv_'.bin2hex(random_bytes(8)), 'user_email' => $email, 'user_pass' => $password,
                        'display_name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')) ?: 'CV user', 'role' => 'subscriber']);
                    if (is_wp_error($result)) {
                        throw new \DomainException('Registration could not be completed. If you already have an account, sign in or reset your password.');
                    }
                    self::sendVerification((int) $result);
                    $message = 'Account created. Check your email to verify, then sign in using your email address.';
                } elseif ($action === 'atscv_resend') {
                    if (! is_user_logged_in()) {
                        throw new \DomainException('Sign in first.');
                    }
                    self::sendVerification(get_current_user_id());
                    $message = 'Verification email sent.';
                } else {
                    throw new \InvalidArgumentException('Invalid account action.');
                }
            }
        } catch (\Throwable $e) {
            $message = $e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The account action could not be completed. Please check your mail settings or contact support.';
        }
        wp_safe_redirect(add_query_arg('atscv_notice', $message, Config::page()));
        exit;
    }
}
