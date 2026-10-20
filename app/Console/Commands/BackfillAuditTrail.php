<?php

namespace App\Console\Commands;

use App\Services\AuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAuditTrail extends Command
{
    protected $signature = 'audit:backfill';

    protected $description = 'Record labelled historical snapshots without inventing past activity';

    public function handle(AuditTrail $audit): int
    {
        $tables = [
            'billing_payments' => ['reference', 'provider', 'mode', 'status', 'kind', 'amount_cents', 'currency', 'credits', 'subscription_id', 'created_at', 'paid_at'],
            'subscriptions' => ['subscription_plan_id', 'status', 'starts_at', 'ends_at', 'funding_period'],
            'ai_credit_transactions' => ['reference', 'type', 'subscription_delta', 'topup_delta', 'debt_delta', 'created_at'],
            'ai_usage_logs' => ['ai_operation_id', 'feature', 'status', 'credits_charged', 'created_at'],
        ];
        foreach ($tables as $table => $fields) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($records) use ($audit, $table, $fields) {
                foreach ($records as $record) {
                    $audit->record($record->user_id, 'historical.snapshot', $table, $record->id,
                        array_intersect_key((array) $record, array_flip($fields)), 'snapshot:'.$table.':'.$record->id);
                }
            });
        }
        $this->info('Historical payment, subscription, credit and AI-usage snapshots recorded. Existing snapshots were preserved.');

        return self::SUCCESS;
    }
}
