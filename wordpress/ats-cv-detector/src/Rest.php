<?php

namespace ATSCV;

final class Rest
{
    public static function permission(): bool|\WP_Error
    {
        if (! is_user_logged_in() || ! current_user_can('read')) {
            return new \WP_Error('atscv_login', 'Sign in to continue.', ['status' => 401]);
        }
        if (! Auth::verified(get_current_user_id())) {
            return new \WP_Error('atscv_verify', 'Verify your email address first.', ['status' => 403]);
        }

        return true;
    }

    public static function register(): void
    {
        $routes = [
            '/state' => ['GET', 'state'], '/resumes' => ['POST', 'save'], '/resumes/(?P<id>\d+)' => ['GET', 'resume'],
            '/resumes/(?P<id>\d+)/delete' => ['POST', 'remove'], '/import' => ['POST', 'import'], '/basic-check' => ['POST', 'basic'],
            '/operations' => ['POST', 'operation'], '/operations/(?P<id>\d+)' => ['GET', 'operationStatus'],
            '/checkout' => ['POST', 'checkout'], '/payments/(?P<reference>[a-zA-Z0-9-]{1,64})/check' => ['POST', 'check'],
            '/periods/(?P<id>\d+)/cancel-reminders' => ['POST', 'cancel'],
            '/account/delete' => ['POST', 'deleteAccount'],
        ];
        foreach ($routes as $route => [$method,$callback]) {
            register_rest_route('atscv/v1', $route, ['methods' => $method, 'callback' => static function ($request) use ($callback) {
                try {
                    if (strlen((string) $request->get_body()) > 200000 && $callback !== 'import') {
                        return new \WP_Error('atscv_size', 'Request too large.', ['status' => 413]);
                    }
                    Auth::limit('api:'.get_current_user_id(), 120);

                    return rest_ensure_response(self::$callback($request));
                } catch (\DomainException $e) {
                    return new \WP_Error('atscv_conflict', $e->getMessage(), ['status' => 409]);
                } catch (\InvalidArgumentException $e) {
                    return new \WP_Error('atscv_invalid', $e->getMessage(), ['status' => 422]);
                } catch (\Throwable) {
                    return new \WP_Error('atscv_unavailable', 'The request could not be completed. Try again or contact support.', ['status' => 503]);
                }
            }, 'permission_callback' => [self::class, 'permission']]);
        }
        register_rest_route('atscv/v1', '/paynow/result/(?P<reference>[a-zA-Z0-9-]{1,64})', ['methods' => 'POST', 'permission_callback' => '__return_true',
            'callback' => static function ($request) {
                try {
                    Auth::limit('notify-ip:'.hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 120);

                    return Billing::notify($request['reference'], $request->get_body());
                } catch (\Throwable) {
                    return new \WP_REST_Response(['message' => 'Please retry.'], 503);
                }
            }]);
    }

    public static function state(?\WP_REST_Request $request = null): array
    {
        global $wpdb;
        $user = get_current_user_id();
        $resumes = DB::table('resumes');
        $payments = DB::table('payments');
        $operations = DB::table('operations');
        $periods = DB::table('periods');
        $page = max(1, min(100000, absint($request?->get_param('page') ?: 1)));
        $offset = ($page - 1) * 25;
        $paid = Credits::current($user) !== null;
        $templates = Resume::templates();
        foreach ($templates as &$template) {
            $template['allowed'] = $paid || in_array($template['id'], Resume::FREE_TEMPLATES, true);
            unset($template['department_profile']);
        } unset($template);

        return ['wallet' => Credits::wallet($user), 'plans' => Config::plans(), 'templates' => $templates,
            'resumes' => $wpdb->get_results($wpdb->prepare("SELECT id,title,template,revision,updated_at FROM $resumes WHERE user_id=%d ORDER BY updated_at DESC,id DESC LIMIT 25 OFFSET %d", $user, $offset)),
            'payments' => $wpdb->get_results($wpdb->prepare("SELECT reference,plan,kind,mode,amount_cents,credits,state,provider_status,paid_at,created_at FROM $payments WHERE user_id=%d ORDER BY id DESC LIMIT 25 OFFSET %d", $user, $offset)),
            'periods' => $wpdb->get_results($wpdb->prepare("SELECT id,plan,credits,state,starts_at,ends_at,cancelled_at FROM $periods WHERE user_id=%d ORDER BY starts_at DESC LIMIT 25", $user)),
            'operations' => $wpdb->get_results($wpdb->prepare("SELECT id,kind,state,created_at FROM $operations WHERE user_id=%d ORDER BY id DESC LIMIT 25 OFFSET %d", $user, $offset)),
            'pagination' => ['page' => $page, 'resumes' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $resumes WHERE user_id=%d", $user)),
                'payments' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $payments WHERE user_id=%d", $user)), 'operations' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $operations WHERE user_id=%d", $user))],
            'ai_ready' => (bool) Config::get('OPENAI_KEY'), 'paynow_ready' => Config::enabled('PAYNOW_ENABLED') && (bool) Config::get('PAYNOW_ID') && (bool) Config::get('PAYNOW_KEY'),
            'paynow_mode' => Config::get('PAYNOW_MODE', 'test')];
    }

    public static function resume(\WP_REST_Request $request): array
    {
        $row = Resume::find((int) $request['id'], get_current_user_id());
        if (! $row) {
            throw new \DomainException('CV not found.');
        }

        return ['id' => (int) $row->id, 'revision' => (int) $row->revision, 'title' => $row->title, 'template' => $row->template, 'data' => json_decode(Config::crypt($row->content, true), true, 64, JSON_THROW_ON_ERROR)];
    }

    public static function save(\WP_REST_Request $request): array
    {
        $data = $request->get_json_params();
        if (! is_array($data['data'] ?? null)) {
            throw new \InvalidArgumentException('Invalid CV data.');
        }
        $row = Resume::save(get_current_user_id(), absint($data['id'] ?? 0), absint($data['revision'] ?? 0), (string) ($data['title'] ?? ''), (string) ($data['template'] ?? 'essential'), $data['data']);

        return ['id' => (int) $row->id, 'revision' => (int) $row->revision, 'title' => $row->title, 'template' => $row->template];
    }

    public static function remove(\WP_REST_Request $request): array
    {
        global $wpdb;
        $row = Resume::find((int) $request['id'], get_current_user_id());
        if (! $row) {
            throw new \DomainException('CV not found.');
        }
        DB::run($wpdb->prepare('DELETE FROM '.DB::table('resumes').' WHERE id=%d AND user_id=%d', $row->id, get_current_user_id()));

        return ['deleted' => true];
    }

    public static function import(\WP_REST_Request $request): array
    {
        Auth::limit('import:'.get_current_user_id(), 5, 300);

        return Resume::import($request->get_file_params()['file'] ?? []);
    }

    public static function basic(\WP_REST_Request $request): array
    {
        $text = (string) $request->get_param('text');
        if (mb_strlen($text) > 60000) {
            throw new \InvalidArgumentException('CV text is too long.');
        }

        return (new BasicChecker)->check($text);
    }

    public static function operation(\WP_REST_Request $request): array
    {
        Auth::limit('ai:'.get_current_user_id(), 6, 60);
        if (! Config::get('OPENAI_KEY')) {
            throw new \DomainException('AI analysis is not configured yet. No credits have been charged.');
        }
        $data = $request->get_json_params();
        if (empty($data['consent'])) {
            throw new \InvalidArgumentException('Consent to AI processing is required.');
        }
        if (! is_array($data['cv'] ?? null) || ! is_string($data['job'] ?? null) || mb_strlen(trim($data['job'])) < 30 || mb_strlen($data['job']) > 25000) {
            throw new \InvalidArgumentException('Enter a job advertisement between 30 and 25,000 characters.');
        }
        $cv = Resume::normalize($data['cv']);
        if (mb_strlen(Resume::text($cv)) < 100) {
            throw new \InvalidArgumentException('Add at least 100 characters of real CV information.');
        }
        $op = Credits::reserve(get_current_user_id(), (string) ($data['operation_key'] ?? ''), (string) ($data['kind'] ?? ''), ['cv' => $cv, 'job' => trim($data['job'])]);
        if ($op->state === 'reserved' && ! wp_next_scheduled('atscv_ai_job', [(int) $op->id])) {
            wp_schedule_single_event(time(), 'atscv_ai_job', [(int) $op->id]);
        }

        return ['id' => (int) $op->id, 'state' => $op->state];
    }

    public static function operationStatus(\WP_REST_Request $request): array
    {
        global $wpdb;
        $table = DB::table('operations');
        $op = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND user_id=%d", (int) $request['id'], get_current_user_id()));
        if (! $op) {
            throw new \DomainException('Operation not found.');
        }

        return ['id' => (int) $op->id, 'state' => $op->state, 'kind' => $op->kind, 'result' => $op->result ? json_decode(Config::crypt($op->result, true), true, 64, JSON_THROW_ON_ERROR) : null];
    }

    public static function checkout(\WP_REST_Request $request): array
    {
        Auth::limit('checkout:'.get_current_user_id(), 6, 60);
        if (! $request->get_param('consent')) {
            throw new \InvalidArgumentException('Accept the payment terms first.');
        }

        return Billing::checkout(get_current_user_id(), (string) $request->get_param('plan'), (string) ($request->get_param('kind') ?: 'subscription'));
    }

    public static function check(\WP_REST_Request $request): array
    {
        Auth::limit('check:'.get_current_user_id(), 12, 60);
        $payment = Billing::find($request['reference'], get_current_user_id());
        if (! $payment) {
            throw new \DomainException('Payment not found.');
        }
        if (! $payment->verified_at || strtotime($payment->verified_at.' UTC') < time() - 30) {
            Billing::refresh($payment);
        }
        $payment = Billing::find($request['reference'], get_current_user_id());
        $checkout = $payment->checkout_url ? Config::crypt($payment->checkout_url, true) : null;

        return ['reference' => $payment->reference, 'state' => $payment->state, 'mode' => $payment->mode, 'provider_status' => $payment->provider_status,
            'plan' => $payment->plan, 'kind' => $payment->kind, 'amount_cents' => (int) $payment->amount_cents, 'checkout_url' => $checkout && Paynow::safeUrl($checkout) ? $checkout : null, 'wallet' => Credits::wallet(get_current_user_id())];
    }

    public static function cancel(\WP_REST_Request $request): array
    {
        $user = get_current_user_id();
        DB::transaction(static function () use ($request, $user) {
            global $wpdb;
            DB::lock($user);
            $table = DB::table('periods');
            $period = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND user_id=%d FOR UPDATE", (int) $request['id'], $user));
            if (! $period || $period->state !== 'active') {
                throw new \DomainException('Active paid period not found.');
            }
            if (! $period->cancelled_at) {
                DB::update('periods', ['cancelled_at' => DB::now()], ['id' => $period->id]);
                Billing::message($user, 'period:'.$period->id.':cancel', 'Renewal reminders cancelled', 'Paid access continues until the end of your purchased period. No automatic Paynow deductions are enabled.');
            }
        });

        return ['message' => 'Renewal reminders cancelled. Your purchased access remains available until expiry.'];
    }

    public static function deleteAccount(\WP_REST_Request $request): array
    {
        $user = wp_get_current_user();
        if (user_can($user, 'manage_options')) {
            throw new \DomainException('Administrator accounts must be managed through WordPress administration.');
        }
        if ($request->get_param('confirmation') !== 'DELETE' || ! wp_check_password((string) $request->get_param('password'), $user->user_pass, $user->ID)) {
            throw new \DomainException('Confirm DELETE and enter your current password.');
        }
        global $wpdb;
        $table = DB::table('payments');
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND state='pending' LIMIT 1", $user->ID))) {
            throw new \DomainException('Resolve your pending payments with support before deleting this account. Your CV data can be erased separately through the site privacy process.');
        }
        require_once ABSPATH.'wp-admin/includes/user.php';
        if (! wp_delete_user($user->ID)) {
            throw new \RuntimeException('The account could not be deleted.');
        }
        wp_clear_auth_cookie();

        return ['deleted' => true, 'redirect_url' => Config::page()];
    }
}
