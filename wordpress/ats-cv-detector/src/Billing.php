<?php

namespace ATSCV;

final class Billing
{
    public static function find(string $reference, ?int $owner = null): ?object
    {
        global $wpdb;
        $table = DB::table('payments');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE reference=%s", $reference));

        return $row && ($owner === null || (int) $row->user_id === $owner) ? $row : null;
    }

    public static function locked(object $payment): object
    {
        global $wpdb;
        DB::lock((int) $payment->user_id);
        $table = DB::table('payments');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $payment->id));
        if (! $row) {
            throw new \RuntimeException('Payment unavailable.');
        }

        return $row;
    }

    public static function checkout(int $user, string $plan, string $kind = 'subscription'): array
    {
        $offer = Config::plans()[$plan] ?? null;
        if (! $offer || $offer['cents'] < 1 || ! in_array($kind, ['subscription', 'topup'], true) || ($kind === 'topup' && $plan !== 'monthly')) {
            throw new \InvalidArgumentException('Choose a valid plan or credit pack.');
        }
        if (! Config::enabled('PAYNOW_ENABLED') || ! Config::get('PAYNOW_ID') || ! Config::get('PAYNOW_KEY')) {
            throw new \DomainException('Paynow checkout is not configured.');
        }
        $mode = Config::get('PAYNOW_MODE', 'test');
        if (! in_array($mode, ['test', 'live'], true)) {
            throw new \DomainException('Invalid Paynow mode.');
        }
        $payment = DB::transaction(static function () use ($user, $plan, $kind, $offer, $mode) {
            global $wpdb;
            DB::lock($user);
            $table = DB::table('payments');
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d AND mode=%s AND kind=%s AND state='pending' ORDER BY id LIMIT 1", $user, $mode, $kind));
            if ($existing) {
                if ($existing->plan !== $plan) {
                    throw new \DomainException('Another plan checkout is still open. Check its payment status before changing plans.');
                }

                return $existing;
            }
            $periods = DB::table('periods');
            if ($kind === 'subscription' && $wpdb->get_var($wpdb->prepare("SELECT id FROM $periods WHERE user_id=%d AND state='active' AND starts_at>%s LIMIT 1", $user, DB::now()))) {
                throw new \DomainException('Your next paid period is already scheduled.');
            }
            $id = DB::insert('payments', ['reference' => wp_generate_uuid4(), 'user_id' => $user, 'plan' => $plan, 'kind' => $kind,
                'mode' => $mode, 'integration_id' => (string) Config::get('PAYNOW_ID'), 'amount_cents' => $offer['cents'], 'credits' => $offer['credits'],
                'trace' => bin2hex(random_bytes(16)), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
            $row->is_new = true;

            return $row;
        });
        if (empty($payment->is_new)) {
            $url = $payment->checkout_url ? Config::crypt($payment->checkout_url, true) : null;

            return ['reference' => $payment->reference, 'redirect_url' => $url && Paynow::safeUrl($url) ? $url : null];
        }
        try {
            return ['reference' => $payment->reference, 'redirect_url' => Paynow::start($payment)];
        } catch (\Throwable $e) {
            if ($e instanceof \DomainException) {
                DB::update('payments', ['state' => 'failed', 'provider_status' => 'Initialization rejected'], ['id' => $payment->id]);
            }
            DB::audit($user, 'payment.initialization_unavailable', $payment->reference);

            return ['reference' => $payment->reference, 'redirect_url' => null, 'message' => 'Checkout could not be opened. Check this payment before trying again.'];
        }
    }

    public static function endDate(string $start, string $plan): string
    {
        $date = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        if ($plan === 'annual') {
            $target = $date->modify('first day of this month')->modify('+1 year');
        } else {
            $target = $date->modify('first day of this month')->modify('+1 month');
        }

        return $target->setDate((int) $target->format('Y'), (int) $target->format('m'), min((int) $date->format('d'), (int) $target->format('t')))->format('Y-m-d H:i:s');
    }

    public static function refresh(object $payment): void
    {
        DB::transaction(static function () use ($payment) {
            global $wpdb;
            $row = self::locked($payment);
            $result = Paynow::poll($row);
            $raw = (string) ($result['status'] ?? 'Unknown');
            $state = match (strtolower($raw)) {
                'paid', 'awaiting delivery', 'delivered' => $row->mode === 'live' ? 'paid' : 'test_paid',
                'cancelled' => 'cancelled', 'refunded', 'disputed' => 'reversed', default => 'pending',
            };
            $previous = $row->state;
            $changes = ['provider_status' => $raw, 'provider_reference' => $result['paynowreference'] ?? $row->provider_reference,
                'verified_at' => DB::now(), 'next_check_at' => gmdate('Y-m-d H:i:s', time() + ($state === 'pending' ? 300 : 86400)),
                'attempts' => (int) $row->attempts + 1, 'updated_at' => DB::now()];
            if ($state === 'paid' && ! $row->paid_at && $previous !== 'reversed') {
                if ($row->kind === 'topup') {
                    $wallet = Credits::syncLocked((int) $row->user_id);
                    DB::update('wallets', ['topup' => $wallet->topup + $row->credits], ['user_id' => $row->user_id]);
                    Credits::ledger((int) $row->user_id, 'payment:'.$row->id, 'topup_purchase', 0, (int) $row->credits, 0, $row->reference);
                } else {
                    $table = DB::table('periods');
                    $last = $wpdb->get_var($wpdb->prepare("SELECT MAX(ends_at) FROM $table WHERE user_id=%d AND state='active' AND ends_at>%s", $row->user_id, DB::now()));
                    $start = $last ?: DB::now();
                    $period = DB::insert('periods', ['user_id' => $row->user_id, 'payment_id' => $row->id, 'plan' => $row->plan,
                        'credits' => $row->credits, 'starts_at' => $start, 'ends_at' => self::endDate($start, $row->plan)]);
                    $changes['period_id'] = $period;
                }
                $changes['paid_at'] = DB::now();
                $changes['state'] = 'paid';
                DB::update('payments', $changes, ['id' => $row->id]);
                Credits::syncLocked((int) $row->user_id);
                self::receipt(self::find($row->reference));
                self::message((int) $row->user_id, 'payment:'.$row->id.':paid', 'Payment successful', 'Your '.$row->plan.' purchase is verified. View your receipt and paid period in ATS CV Detector. Reference: '.$row->reference);
                if ($row->kind === 'subscription') {
                    self::message((int) $row->user_id, 'period:'.$period.':confirmed', 'Subscription period confirmed', 'Your paid period is confirmed. Existing paid time is preserved and new credits become available when the new period begins. Renew manually; no automatic deductions are enabled.');
                }
            } else {
                if ($state === 'reversed' && $previous !== 'reversed') {
                    if ($row->kind === 'topup' && $row->paid_at) {
                        $wallet = Credits::syncLocked((int) $row->user_id);
                        $remove = min((int) $wallet->topup, (int) $row->credits);
                        $debt = $row->credits - $remove;
                        DB::update('wallets', ['topup' => $wallet->topup - $remove, 'debt' => $wallet->debt + $debt], ['user_id' => $row->user_id]);
                        Credits::ledger((int) $row->user_id, 'payment:'.$row->id.':reversal', 'payment_reversal', 0, -$remove, $debt, $row->reference);
                    }
                    if ($row->period_id) {
                        DB::update('periods', ['state' => 'cancelled'], ['id' => $row->period_id]);
                    }
                    $changes['state'] = 'reversed';
                    Credits::syncLocked((int) $row->user_id);
                } elseif (! in_array($previous, ['paid', 'test_paid', 'reversed'], true)) {
                    $changes['state'] = $state;
                }
                DB::update('payments', $changes, ['id' => $row->id]);
                $current = $changes['state'] ?? $previous;
                if ($current !== $previous && in_array($current, ['cancelled', 'failed', 'reversed'], true)) {
                    self::message((int) $row->user_id, 'payment:'.$row->id.':'.$current, 'Payment update', 'Payment '.$row->reference.' is '.$current.'. Provider status: '.$raw.'.');
                }
            }
            DB::audit((int) $row->user_id, 'payment.verified', $row->reference, ['provider_status' => $raw]);
        });
    }

    public static function message(int $user, string $key, string $subject, string $message): void
    {
        global $wpdb;
        $table = DB::table('outbox');
        if (! $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE event_key=%s", $key))) {
            DB::insert('outbox', ['user_id' => $user, 'event_key' => $key, 'subject' => $subject, 'message' => $message, 'due_at' => DB::now()]);
        }
    }

    private static function receipt(object $payment): void
    {
        global $wpdb;
        $table = DB::table('periods');
        $period = $payment->period_id ? $wpdb->get_row($wpdb->prepare("SELECT starts_at,ends_at FROM $table WHERE id=%d", $payment->period_id)) : null;
        $user = get_userdata($payment->user_id);
        DB::insert('receipts', ['payment_id' => $payment->id, 'details' => Config::crypt(wp_json_encode(['business' => Config::get('BUSINESS_NAME', 'ATS CV Detector'),
            'address' => Config::get('BUSINESS_ADDRESS'), 'customer' => $user->display_name, 'email' => $user->user_email,
            'reference' => $payment->reference, 'paynow_reference' => $payment->provider_reference, 'plan' => $payment->plan, 'kind' => $payment->kind,
            'amount_cents' => (int) $payment->amount_cents, 'currency' => 'USD', 'paid_at' => $payment->paid_at,
            'starts_at' => $period?->starts_at ?? '', 'ends_at' => $period?->ends_at ?? ''])), 'created_at' => DB::now()]);
    }

    public static function notify(string $reference, string $body): \WP_REST_Response
    {
        if (strlen($body) > 65536) {
            return new \WP_REST_Response(['message' => 'Invalid notification.'], 413);
        }
        parse_str($body, $data);
        $valid = Paynow::verify($data);
        $data = array_change_key_case($data, CASE_LOWER);
        $payment = self::find($reference);
        $fingerprint = hash('sha256', $body);
        global $wpdb;
        $table = DB::table('notifications');
        $notification = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE fingerprint=%s", $fingerprint));
        if (! $notification) {
            $safe = array_intersect_key($data, array_flip(['reference', 'amount', 'paynowreference', 'status']));
            $safe = array_map(static fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 200) : '[invalid]', $safe);
            // Race-safe duplicate handling is confined to the unique fingerprint.
            $saved = $wpdb->query($wpdb->prepare("INSERT INTO $table (payment_id,fingerprint,payload,hash_valid,created_at) VALUES (%d,%s,%s,%d,%s) ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint)", $payment?->id ?? 0, $fingerprint, wp_json_encode($safe), $valid ? 1 : 0, DB::now()));
            if ($saved === false) {
                return new \WP_REST_Response(['message' => 'Please retry.'], 503);
            }
        }
        if (! $valid) {
            return new \WP_REST_Response(['message' => 'Invalid notification.'], 400);
        }
        if (! $payment) {
            return new \WP_REST_Response(['message' => 'Payment not found.'], 404);
        }
        if (! Paynow::matches($payment, $data)) {
            return new \WP_REST_Response(['message' => 'Payment details mismatch.'], 400);
        }
        if ($notification?->processed_at) {
            return new \WP_REST_Response(['message' => 'OK']);
        }
        try {
            if (! $payment->poll_url && is_string($data['pollurl'] ?? null) && Paynow::safeUrl($data['pollurl'])) {
                DB::transaction(static function () use ($payment, $data) {
                    $locked = self::locked($payment);
                    if (! $locked->poll_url) {
                        DB::update('payments', ['poll_url' => Config::crypt($data['pollurl'])], ['id' => $payment->id]);
                    }
                });
            }
            self::refresh(self::find($reference));
            DB::update('notifications', ['processed_at' => DB::now(), 'error' => null], ['fingerprint' => $fingerprint]);

            return new \WP_REST_Response(['message' => 'OK']);
        } catch (\Throwable) {
            DB::update('notifications', ['error' => 'Verification unavailable'], ['fingerprint' => $fingerprint]);

            return new \WP_REST_Response(['message' => 'Please retry verification.'], 503);
        }
    }
}
