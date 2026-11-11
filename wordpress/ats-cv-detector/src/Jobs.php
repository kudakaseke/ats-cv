<?php

namespace ATSCV;

final class Jobs
{
    public static function maintenance(): void
    {
        global $wpdb;
        $lock = 'atscv-maintenance-'.substr(hash('sha256', $wpdb->prefix), 0, 20);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
            return;
        }
        try {
            update_option('atscv_heartbeat', DB::now(), false);
            $table = DB::table('payments');
            $payments = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE state IN ('pending','paid','test_paid') AND (next_check_at IS NULL OR next_check_at<=%s) AND created_at>%s ORDER BY COALESCE(next_check_at,created_at) LIMIT 20", DB::now(), gmdate('Y-m-d H:i:s', time() - 400 * 86400)));
            foreach ($payments as $payment) {
                try {
                    Billing::refresh($payment);
                } catch (\Throwable) {
                    DB::update('payments', ['next_check_at' => gmdate('Y-m-d H:i:s', time() + min(86400, 300 * (2 ** min(8, (int) $payment->attempts)))), 'attempts' => (int) $payment->attempts + 1], ['id' => $payment->id]);
                    DB::audit((int) $payment->user_id, 'payment.verification_unavailable', $payment->reference);
                }
            }
            $periodTable = DB::table('periods');
            $periods = $wpdb->get_results($wpdb->prepare("SELECT * FROM $periodTable WHERE state='active' AND ends_at<=%s ORDER BY ends_at LIMIT 100", gmdate('Y-m-d H:i:s', time() + 7 * 86400)));
            foreach ($periods as $period) {
                DB::transaction(static function () use ($period, $periodTable) {
                    global $wpdb;
                    DB::lock((int) $period->user_id);
                    $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $periodTable WHERE id=%d FOR UPDATE", $period->id));
                    if ($current->state !== 'active') {
                        return;
                    }
                    if ($current->ends_at <= DB::now()) {
                        DB::update('periods', ['state' => 'expired'], ['id' => $current->id]);
                        Credits::syncLocked((int) $current->user_id);
                        Billing::message((int) $current->user_id, 'period:'.$current->id.':expired', 'Paid period ended', 'Your paid period has ended. Your documents and payment history are preserved. Visit ATS CV Detector to check your current plan or renew.');
                    } elseif (! $current->cancelled_at && ! $wpdb->get_var($wpdb->prepare("SELECT id FROM $periodTable WHERE user_id=%d AND state='active' AND starts_at>=%s LIMIT 1", $current->user_id, $current->ends_at))) {
                        Billing::message((int) $current->user_id, 'period:'.$current->id.':reminder', 'Renewal reminder', 'Your paid access ends on '.$current->ends_at.' UTC. Renew manually in ATS CV Detector. We do not deduct money automatically.');
                    }
                });
            }
            $operations = DB::table('operations');
            foreach ($wpdb->get_results($wpdb->prepare("SELECT id,lease_token FROM $operations WHERE state='running' AND lease_until<%s LIMIT 20", DB::now())) as $stale) {
                Credits::finish((int) $stale->id, $stale->lease_token, null);
            }
            foreach ($wpdb->get_col("SELECT id FROM $operations WHERE state='reserved' ORDER BY id LIMIT 3") as $id) {
                AI::process((int) $id);
            }
            self::mail();
            // Only completed inputs expire; financial and ledger records are retained.
            $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
            DB::run($wpdb->prepare("UPDATE $operations SET input='' WHERE state IN ('completed','failed') AND updated_at<%s AND input<>''", $cutoff));
            DB::run($wpdb->prepare('DELETE FROM '.DB::table('limits').' WHERE expires_at<%s', DB::now()));
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public static function mail(): void
    {
        global $wpdb;
        $table = DB::table('outbox');
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE sent_at IS NULL AND attempts<5 AND due_at<=%s ORDER BY id LIMIT 30", DB::now())) as $message) {
            $user = get_userdata($message->user_id);
            if (! $user) {
                continue;
            }
            DB::update('outbox', ['attempts' => $message->attempts + 1, 'due_at' => gmdate('Y-m-d H:i:s', time() + 300 * (2 ** $message->attempts))], ['id' => $message->id]);
            try {
                if (wp_mail($user->user_email, $message->subject, $message->message)) {
                    DB::update('outbox', ['sent_at' => DB::now()], ['id' => $message->id]);
                }
            } catch (\Throwable) {
                DB::audit((int) $user->ID, 'email.delivery_unavailable', 'message:'.$message->id);
            }
        }
    }
}
