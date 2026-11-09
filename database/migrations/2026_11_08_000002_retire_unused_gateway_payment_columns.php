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
        $columns = array_values(array_filter(['hosted_reference', 'hosted_page_slug', 'capture_reference', 'provider_plan_id', 'provider_customer_id'],
            fn ($column) => Schema::hasColumn('billing_payments', $column)));
        if (! $columns) {
            return;
        }
        DB::table('billing_payments')->select(array_merge(['id'], $columns))->orderBy('id')->chunkById(100,
            function ($records) {
                foreach ($records as $record) {
                    DB::table('retired_billing_records')->insertOrIgnore([
                        'source' => 'payment_gateway_settings', 'source_id' => $record->id,
                        'payload' => Crypt::encryptString(json_encode($record, JSON_THROW_ON_ERROR)), 'archived_at' => now(),
                    ]);
                    $payload = DB::table('retired_billing_records')->where('source', 'payment_gateway_settings')->where('source_id', $record->id)->value('payload');
                    if (array_intersect_key(json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR), (array) $record) != (array) $record) {
                        throw new RuntimeException('Retired payment settings archive verification failed.');
                    }
                }
            });
        if (Schema::hasIndex('billing_payments', 'billing_provider_customer')) {
            Schema::table('billing_payments', fn (Blueprint $table) => $table->dropIndex('billing_provider_customer'));
        }
        if (Schema::hasIndex('billing_payments', 'billing_payments_hosted_reference_unique')) {
            Schema::table('billing_payments', fn (Blueprint $table) => $table->dropUnique(['hosted_reference']));
        }
        if (Schema::hasIndex('billing_payments', 'billing_payments_capture_reference_index')) {
            Schema::table('billing_payments', fn (Blueprint $table) => $table->dropIndex(['capture_reference']));
        }
        Schema::table('billing_payments', fn (Blueprint $table) => $table->dropColumn($columns));
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the pre-deployment database backup and APP_KEY to reinstate retired gateway columns.');
    }
};
