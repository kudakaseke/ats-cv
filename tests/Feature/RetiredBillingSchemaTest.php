<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RetiredBillingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_retired_schema_is_removed_and_records_are_archived_before_removal(): void
    {
        foreach (['paystack_receipts', 'paypal_subscription_receipts'] as $source) {
            Schema::create($source, function (Blueprint $table) {
                $table->id();
                $table->string('reference');
            });
            DB::table($source)->insert(['id' => 42, 'reference' => 'historic-receipt']);
        }
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->string('paystack_plan_code')->nullable()->unique();
            $table->string('paystack_mode')->nullable();
            $table->unsignedInteger('paystack_amount_cents')->nullable();
        });
        $before = DB::table('billing_payments')->count();
        $migration = require database_path('migrations/2026_11_08_000001_retire_unused_payment_provider_schema.php');
        $migration->up();
        $migration->up(); // Recovery/retry must not duplicate archival rows.
        foreach (['paystack_receipts', 'paypal_subscription_receipts'] as $source) {
            $this->assertFalse(Schema::hasTable($source));
            $payload = DB::table('retired_billing_records')->where('source', $source)->where('source_id', 42)->value('payload');
            $this->assertStringNotContainsString('historic-receipt', $payload);
            $this->assertSame('historic-receipt', json_decode(Crypt::decryptString($payload), true)['reference']);
            $this->assertSame(1, DB::table('retired_billing_records')->where('source', $source)->where('source_id', 42)->count());
        }
        $this->assertFalse(Schema::hasColumn('subscription_plans', 'paystack_plan_code'));
        foreach (['billing_payments', 'subscriptions', 'ai_wallets', 'ai_credit_transactions', 'billing_receipts'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertSame($before, DB::table('billing_payments')->count());
    }

    public function test_unused_payment_columns_are_archived_without_losing_the_payment(): void
    {
        $this->seed(\Database\Seeders\SubscriptionPlanSeeder::class);
        $user = \App\Models\User::factory()->create();
        Schema::table('billing_payments', function (Blueprint $table) {
            $table->string('hosted_reference')->nullable()->unique();
            $table->string('hosted_page_slug')->nullable();
            $table->string('capture_reference')->nullable()->index();
            $table->string('provider_plan_id')->nullable();
            $table->string('provider_customer_id')->nullable();
            $table->index(['provider', 'mode', 'provider_plan_id', 'provider_customer_id'], 'billing_provider_customer');
        });
        $payment = \App\Models\BillingPayment::create(['reference' => 'historic-payment', 'user_id' => $user->id,
            'subscription_plan_id' => \App\Models\SubscriptionPlan::where('slug', 'monthly')->value('id'),
            'provider' => 'legacy', 'mode' => 'test', 'amount_cents' => 475, 'currency' => 'USD',
            'billing_interval' => 'monthly']);
        // The fixture recreates retired columns after Eloquent may have cached
        // the current schema. Write historical-only fields through the query builder.
        DB::table('billing_payments')->where('id', $payment->id)->update([
            'hosted_reference' => 'original-hosted-ref', 'capture_reference' => 'original-capture']);
        $migration = require database_path('migrations/2026_11_08_000002_retire_unused_gateway_payment_columns.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('billing_payments', ['id' => $payment->id, 'reference' => 'historic-payment', 'amount_cents' => 475]);
        foreach (['hosted_reference', 'hosted_page_slug', 'capture_reference', 'provider_plan_id', 'provider_customer_id'] as $column) {
            $this->assertFalse(Schema::hasColumn('billing_payments', $column));
        }
        $this->assertFalse(Schema::hasIndex('billing_payments', 'billing_provider_customer'));
        $payload = DB::table('retired_billing_records')->where('source', 'payment_gateway_settings')->where('source_id', $payment->id)->value('payload');
        $archive = json_decode(Crypt::decryptString($payload), true);
        $this->assertSame('original-hosted-ref', $archive['hosted_reference']);
        $this->assertSame('original-capture', $archive['capture_reference']);
        $this->assertSame(1, DB::table('retired_billing_records')->where('source', 'payment_gateway_settings')->where('source_id', $payment->id)->count());
    }

    public function test_invalid_archive_stops_removal_of_historical_data(): void
    {
        Schema::create('paystack_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('reference');
        });
        DB::table('paystack_receipts')->insert(['id' => 42, 'reference' => 'retain-me']);
        DB::table('retired_billing_records')->insert(['source' => 'paystack_receipts', 'source_id' => 42,
            'payload' => Crypt::encryptString('{"id":42,"reference":"wrong"}'), 'archived_at' => now()]);
        try {
            (require database_path('migrations/2026_11_08_000001_retire_unused_payment_provider_schema.php'))->up();
            $this->fail('An inconsistent archive must block schema removal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Retired billing archive verification failed.', $exception->getMessage());
        }
        $this->assertDatabaseHas('paystack_receipts', ['id' => 42, 'reference' => 'retain-me']);
    }
}
