<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'billing_payments' => [
            'billing_owner_history' => ['user_id', 'id'],
            'billing_owner_provider' => ['user_id', 'provider', 'recurring', 'id'],
            'billing_provider_customer' => ['provider_customer_id', 'provider_plan_id'],
            'billing_reconcile_status' => ['status', 'updated_at'],
        ],
        'subscriptions' => [
            'subscription_active_lookup' => ['user_id', 'status', 'starts_at', 'id'],
            'subscription_plan_period' => ['user_id', 'subscription_plan_id', 'status', 'ends_at'],
        ],
        'ai_credit_transactions' => ['credits_owner_history' => ['user_id', 'id']],
        'ai_operations' => [
            'operations_abandoned' => ['status', 'created_at', 'id'],
            'operations_allocation' => ['user_id', 'allocation_reference', 'status'],
            'operations_rewrite_history' => ['user_id', 'feature', 'status', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes, $table) {
                foreach ($indexes as $name => $columns) {
                    // MySQL DDL is not transactional: a retry must tolerate an
                    // interrupted prior migration without duplicating indexes.
                    if (! Schema::hasIndex($table, $name)) {
                        $blueprint->index($columns, $name);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes, $table) {
                foreach (array_keys($indexes) as $name) {
                    if (Schema::hasIndex($table, $name)) {
                        $blueprint->dropIndex($name);
                    }
                }
            });
        }
    }
};
