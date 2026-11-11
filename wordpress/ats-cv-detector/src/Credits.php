<?php

namespace ATSCV;

final class Credits
{
    public static function current(int $user): ?object
    {
        global $wpdb;
        $table = DB::table('periods');
        $now = DB::now();

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d AND state='active' AND starts_at<=%s AND ends_at>%s ORDER BY starts_at DESC,id DESC LIMIT 1", $user, $now, $now));
    }

    public static function ledger(int $user, string $key, string $type, int $allowance, int $topup = 0, int $debt = 0, string $reference = ''): void
    {
        DB::insert('ledger', ['user_id' => $user, 'event_key' => $key, 'type' => $type, 'allowance_delta' => $allowance,
            'topup_delta' => $topup, 'debt_delta' => $debt, 'reference' => $reference, 'created_at' => DB::now()]);
    }

    public static function syncLocked(int $user): object
    {
        global $wpdb;
        $table = DB::table('wallets');
        $wallet = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d", $user));
        if (! $wallet) {
            DB::insert('wallets', ['user_id' => $user, 'allowance' => 15]);
            self::ledger($user, 'welcome:'.$user, 'welcome', 15);
            $wallet = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d", $user));
        }
        $period = self::current($user);
        if ($period && (int) $wallet->period_id !== (int) $period->id) {
            $operations = DB::table('operations');
            $spent = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(allowance_spent),0) FROM $operations WHERE user_id=%d AND period_id=%d AND state IN ('reserved','running','completed')", $user, $period->id));
            $amount = max(0, (int) $period->credits - $spent);
            self::ledger($user, 'period-sync:'.wp_generate_uuid4(), 'period_allowance', $amount - (int) $wallet->allowance, 0, 0, 'period:'.$period->id);
            DB::update('wallets', ['allowance' => $amount, 'period_id' => $period->id], ['user_id' => $user]);
        } elseif (! $period && $wallet->period_id !== null && (int) $wallet->period_id !== 0) {
            self::ledger($user, 'period-expire:'.wp_generate_uuid4(), 'period_expired', -(int) $wallet->allowance, 0, 0, 'period:'.$wallet->period_id);
            DB::update('wallets', ['allowance' => 0, 'period_id' => 0], ['user_id' => $user]);
        }

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d", $user));
    }

    public static function wallet(int $user): array
    {
        return DB::transaction(static function () use ($user) {
            DB::lock($user);
            $wallet = self::syncLocked($user);
            $period = self::current($user);

            return ['available' => max(0, (int) $wallet->allowance + (int) $wallet->topup - (int) $wallet->debt),
                'allowance' => (int) $wallet->allowance, 'topup' => (int) $wallet->topup, 'debt' => (int) $wallet->debt,
                'plan' => $period?->plan ?? 'free', 'period' => $period];
        });
    }

    public static function reserve(int $user, string $key, string $kind, array $input): object
    {
        if (! in_array($kind, ['analyse', 'revamp'], true) || ! preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid AI operation.');
        }
        $hash = hash('sha256', wp_json_encode($input));

        return DB::transaction(static function () use ($user, $key, $kind, $input, $hash) {
            global $wpdb;
            DB::lock($user);
            $table = DB::table('operations');
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d AND operation_key=%s", $user, $key));
            if ($existing) {
                if (! hash_equals($existing->input_hash, $hash) || $existing->kind !== $kind) {
                    throw new \InvalidArgumentException('This operation belongs to different input.');
                }

                return $existing;
            }
            $wallet = self::syncLocked($user);
            $cost = $kind === 'analyse' ? 5 : 10;
            if ($wallet->allowance + $wallet->topup - $wallet->debt < $cost) {
                throw new \DomainException('Not enough AI credits. Choose a plan or top up.');
            }
            $allowance = min($cost, (int) $wallet->allowance);
            $topup = $cost - $allowance;
            DB::update('wallets', ['allowance' => $wallet->allowance - $allowance, 'topup' => $wallet->topup - $topup], ['user_id' => $user]);
            $id = DB::insert('operations', ['user_id' => $user, 'operation_key' => $key, 'input_hash' => $hash, 'kind' => $kind,
                'state' => 'reserved', 'period_id' => $wallet->period_id, 'allowance_spent' => $allowance, 'topup_spent' => $topup,
                'input' => Config::crypt(wp_json_encode($input)), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
            self::ledger($user, 'operation:'.$id, 'ai_reserved', -$allowance, -$topup, 0, 'operation:'.$id);

            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        });
    }

    public static function finish(int $id, string $token, ?array $result): void
    {
        global $wpdb;
        $table = DB::table('operations');
        $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (! $record) {
            return;
        }
        DB::transaction(static function () use ($record, $table, $id, $token, $result) {
            global $wpdb;
            DB::lock((int) $record->user_id);
            $op = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $id));
            if ($op->state !== 'running' || ! hash_equals((string) $op->lease_token, $token)) {
                return;
            }
            if ($result === null) {
                $wallet = self::syncLocked((int) $op->user_id);
                $allowance = (string) $wallet->period_id === (string) $op->period_id ? (int) $op->allowance_spent : 0;
                DB::update('wallets', ['allowance' => $wallet->allowance + $allowance, 'topup' => $wallet->topup + $op->topup_spent], ['user_id' => $op->user_id]);
                self::ledger((int) $op->user_id, 'operation-refund:'.$id, 'ai_failure_refund', $allowance, (int) $op->topup_spent);
            }
            DB::update('operations', ['state' => $result === null ? 'failed' : 'completed', 'result' => $result === null ? null : Config::crypt(wp_json_encode($result)),
                'lease_until' => null, 'updated_at' => DB::now()], ['id' => $id]);
        });
    }
}
