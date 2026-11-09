<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Archive before any DDL: MySQL schema changes cannot be rolled back atomically.
        if (! Schema::hasTable('retired_billing_records')) {
            Schema::create('retired_billing_records', function (Blueprint $table) {
                $table->id();
                $table->string('source');
                $table->unsignedBigInteger('source_id');
                $table->longText('payload');
                $table->timestamp('archived_at');
                $table->unique(['source', 'source_id']);
            });
        }
        $archive = function (string $source, object $record): void {
            DB::table('retired_billing_records')->insertOrIgnore([
                'source' => $source, 'source_id' => $record->id,
                'payload' => Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)),
                'archived_at' => now(),
            ]);
            // Verify the durable archive before allowing the source to be removed.
            $stored = DB::table('retired_billing_records')->where('source', $source)->where('source_id', $record->id)->value('payload');
            if (array_intersect_key(json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR), (array) $record) != (array) $record) {
                throw new RuntimeException('Retired billing archive verification failed.');
            }
        };
        foreach (['paystack_receipts', 'paypal_subscription_receipts'] as $source) {
            if (Schema::hasTable($source)) {
                DB::table($source)->orderBy('id')->chunkById(100, function ($records) use ($archive, $source) {
                    foreach ($records as $record) {
                        $archive($source, $record);
                    }
                });
            }
        }
        $columns = array_values(array_filter(['paystack_plan_code', 'paystack_mode', 'paystack_amount_cents'],
            fn ($column) => Schema::hasColumn('subscription_plans', $column)));
        if ($columns) {
            DB::table('subscription_plans')->select(array_merge(['id'], $columns))->orderBy('id')->chunkById(100,
                function ($records) use ($archive) {
                    foreach ($records as $record) {
                        $archive('subscription_plan_gateway_settings', $record);
                    }
                });
        }
        foreach (['paystack_receipts', 'paypal_subscription_receipts'] as $source) {
            Schema::dropIfExists($source);
        }
        if (Schema::hasIndex('subscription_plans', 'subscription_plans_paystack_plan_code_unique')) {
            Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropUnique(['paystack_plan_code']));
        }
        if ($columns) {
            Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn($columns));
        }
        // Shared payments, subscriptions, credit ledger and their links are retained.
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the pre-deployment database backup to reinstate retired provider schema. Preserve APP_KEY to decrypt the billing archive.');
    }
};
