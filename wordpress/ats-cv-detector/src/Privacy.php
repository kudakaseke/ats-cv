<?php

namespace ATSCV;

final class Privacy
{
    public static function exporters(array $items): array
    {
        $items['atscv'] = ['exporter_friendly_name' => 'ATS CV Detector', 'callback' => [self::class, 'export']];

        return $items;
    }

    public static function erasers(array $items): array
    {
        $items['atscv'] = ['eraser_friendly_name' => 'ATS CV Detector', 'callback' => [self::class, 'erase']];

        return $items;
    }

    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['data' => [], 'done' => true];
        }
        global $wpdb;
        $table = DB::table('resumes');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d ORDER BY id LIMIT 25 OFFSET %d", $user->ID, max(0, $page - 1) * 25));
        $data = [];
        foreach ($rows as $row) {
            $data[] = ['group_id' => 'atscv-resumes', 'group_label' => 'ATS CV documents', 'item_id' => 'atscv-resume-'.$row->id,
                'data' => [['name' => 'Title', 'value' => $row->title], ['name' => 'CV data', 'value' => Config::crypt($row->content, true)]]];
        }
        $operations = DB::table('operations');
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $operations WHERE user_id=%d ORDER BY id LIMIT 25 OFFSET %d", $user->ID, max(0, $page - 1) * 25));
        foreach ($results as $row) {
            $data[] = ['group_id' => 'atscv-operations', 'group_label' => 'ATS CV analysis history', 'item_id' => 'atscv-operation-'.$row->id,
                'data' => [['name' => 'Type', 'value' => $row->kind], ['name' => 'State', 'value' => $row->state], ['name' => 'Input', 'value' => $row->input ? Config::crypt($row->input, true) : 'Removed under retention policy'], ['name' => 'Result', 'value' => $row->result ? Config::crypt($row->result, true) : 'No result']]];
        }

        return ['data' => $data, 'done' => count($rows) < 25 && count($results) < 25];
    }

    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (! $user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        self::deleted((int) $user->ID);

        return ['items_removed' => true, 'items_retained' => true, 'messages' => ['CV documents and AI contents were removed. Financial ledger, transaction references and billing records are retained for reconciliation and the site retention policy.'], 'done' => true];
    }

    public static function deleted(int $user): void
    {
        DB::transaction(static function () use ($user) {
            global $wpdb;
            DB::lock($user);
            $wallet = Credits::syncLocked($user);
            $operations = DB::table('operations');
            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $operations WHERE user_id=%d AND state IN ('reserved','running') FOR UPDATE", $user)) as $op) {
                $allowance = (string) $op->period_id === (string) $wallet->period_id ? (int) $op->allowance_spent : 0;
                $wallet->allowance += $allowance;
                $wallet->topup += (int) $op->topup_spent;
                Credits::ledger($user, 'privacy-refund:'.$op->id, 'privacy_operation_cancelled', $allowance, (int) $op->topup_spent);
            }
            DB::update('wallets', ['allowance' => $wallet->allowance, 'topup' => $wallet->topup], ['user_id' => $user]);
            foreach (['resumes', 'operations'] as $name) {
                DB::run($wpdb->prepare('DELETE FROM '.DB::table($name).' WHERE user_id=%d', $user));
            }
            // The wallet remains: a privacy erasure must not regrant welcome credits.
            $outbox = DB::table('outbox');
            DB::run($wpdb->prepare("DELETE FROM $outbox WHERE user_id=%d AND sent_at IS NULL", $user));
            DB::audit($user, 'privacy.cv_data_erased', 'account:'.$user);
        });
    }
}
