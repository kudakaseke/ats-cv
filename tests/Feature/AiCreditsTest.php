<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use App\Services\Billing\PaystackGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function credits(): AiCreditService
    {
        return app(AiCreditService::class);
    }

    private function paid(User $user, string $plan = 'monthly', $start = null, $end = null)
    {
        return $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', $plan)->firstOrFail()->id,
            'status' => 'active', 'starts_at' => $start ?? now(), 'ends_at' => $end ?? ($plan === 'annual' ? now()->addYearNoOverflow() : now()->addMonthNoOverflow())]);
    }

    private function useCredits(User $user, string $feature = 'revamp'): void
    {
        $operation = $this->credits()->reserve($user, $feature, (string) Str::uuid(), ['test' => true]);
        $this->credits()->finish($user, $operation->id, ['done' => true]);
    }

    public function test_pricing_and_one_time_welcome_credit_grant(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 3) as $_) {
            $this->assertSame(15, $this->credits()->wallet($user)['credits']);
            $this->assertSame(0, $this->credits()->wallet($user)['purchased_credits']);
        }
        $this->assertDatabaseCount('ai_credit_transactions', 1);
        $this->assertSame(475, SubscriptionPlan::where('slug', 'monthly')->value('price_cents'));
        $this->assertSame(625, SubscriptionPlan::where('slug', 'pro')->value('price_cents'));
        $this->assertSame(4740, SubscriptionPlan::where('slug', 'annual')->value('price_cents'));
        $this->useCredits($user);
        $this->assertSame(5, $this->credits()->wallet($user)['credits']);
        $this->travel(2)->months();
        $this->assertSame(5, $this->credits()->wallet($user)['credits']);
    }

    public function test_monthly_workflow_costs_fifteen_and_only_paid_renewal_restores_allowance(): void
    {
        $user = User::factory()->create();
        $this->paid($user);
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->assertSame(150, $this->credits()->wallet($user)['purchased_credits']);
        $this->useCredits($user, 'analyse');
        $this->useCredits($user);
        $this->assertSame(135, $this->credits()->wallet($user)['credits']);
        $this->credits()->topup($user, 50, 'paid-topup-one');
        $this->travel(32)->days();
        $this->assertSame(50, $this->credits()->wallet($user)['credits']);
        $this->paid($user);
        $this->assertSame(200, $this->credits()->wallet($user)['credits']);
        $this->assertSame(50, $this->credits()->wallet($user)['topup_credits']);
    }

    public function test_annual_credits_are_monthly_not_1800_upfront_and_stop_at_paid_term_end(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31)->startOfDay());
        $user = User::factory()->create();
        $this->paid($user, 'annual');
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->useCredits($user);
        $this->travelTo(now()->setDate(2026, 2, 28));
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->useCredits($user);
        $this->travelTo(now()->setDate(2026, 3, 30));
        $this->assertSame(140, $this->credits()->wallet($user)['credits']);
        $this->travelTo(now()->setDate(2026, 3, 31));
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->credits()->topup($user, 100, 'annual-topup');
        $this->travelTo(now()->setDate(2027, 1, 31));
        $this->assertSame(100, $this->credits()->wallet($user)['credits']);
    }

    public function test_failed_request_restores_exact_buckets_once_and_completed_retries_are_cached(): void
    {
        $user = User::factory()->create();
        $this->useCredits($user); // Five welcome credits left.
        $this->credits()->topup($user, 50, 'topup');
        $id = (string) Str::uuid();
        $operation = $this->credits()->reserve($user, 'revamp', $id, ['input' => 'same']);
        $this->assertSame(0, $this->credits()->wallet($user)['subscription_credits']);
        $this->assertSame(45, $this->credits()->wallet($user)['topup_credits']);
        $this->credits()->finish($user, $operation->id, null);
        $this->credits()->finish($user, $operation->id, null);
        $this->assertSame(5, $this->credits()->wallet($user)['subscription_credits']);
        $this->assertSame(50, $this->credits()->wallet($user)['topup_credits']);
        $id = (string) Str::uuid();
        $operation = $this->credits()->reserve($user, 'revamp', $id, ['input' => 'same']);
        $this->credits()->finish($user, $operation->id, ['cv' => 'private result']);
        $cached = $this->credits()->reserve($user, 'revamp', $id, ['input' => 'same']);
        $this->assertTrue($cached->cached);
        $this->assertSame('private result', $cached->result['cv']);
        $this->assertStringNotContainsString('private result', DB::table('ai_operations')->where('id', $operation->id)->value('result'));
    }

    public function test_refund_after_renewal_does_not_create_extra_or_permanent_credits(): void
    {
        $user = User::factory()->create();
        $this->paid($user, 'annual');
        $operation = $this->credits()->reserve($user, 'revamp', (string) Str::uuid(), []);
        $this->travel(32)->days();
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->credits()->finish($user, $operation->id, null);
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->assertSame(0, $this->credits()->wallet($user)['topup_credits']);
    }

    public function test_topup_grants_and_reversals_are_idempotent_and_spent_refunds_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $this->credits()->topup($user, 50, 'topup-payment');
        $this->credits()->topup($user, 50, 'topup-payment');
        $this->assertSame(65, $this->credits()->wallet($user)['credits']);
        for ($i = 0; $i < 6; $i++) {
            $this->useCredits($user);
        }
        $this->credits()->reverseTopup($user, 50, 'refund-payment');
        $this->credits()->reverseTopup($user, 50, 'refund-payment');
        $this->assertSame(0, $this->credits()->wallet($user)['credits']);
        $this->assertSame(45, $this->credits()->wallet($user)['credit_debt']);
    }

    public function test_insufficient_credits_block_openai_and_do_not_change_the_cv(): void
    {
        $user = User::factory()->create();
        $this->useCredits($user);
        Http::preventStrayRequests();
        $this->actingAs($user)->postJson('/checker/rewrite', ['job_description' => 'PHP', 'resume_text' => 'Original CV', 'confirmed_keywords' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('credits');
        Http::assertNothingSent();
    }

    public function test_analysis_reserves_five_credits_records_tokens_and_replays_without_another_api_call(): void
    {
        $user = User::factory()->create();
        config(['services.openai.key' => 'fake-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'usage' => ['input_tokens' => 123, 'output_tokens' => 45],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['summary' => 'Your CV mentions PHP.', 'recommendations' => ['Describe your PHP experience.']])]]]]])]);
        $input = ['job_description' => 'PHP HTML', 'resume_text' => 'PHP', 'operation_id' => (string) Str::uuid()];
        $this->actingAs($user)->postJson('/checker/scan', $input)->assertOk()->assertJsonPath('credits_remaining', 10);
        $this->postJson('/checker/scan', $input)->assertOk()->assertJsonPath('credits_remaining', 10);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('cv_analyses', 1);
        $this->assertDatabaseHas('ai_usage_logs', ['input_tokens' => 123, 'output_tokens' => 45, 'credits_charged' => 5]);
        $this->postJson('/checker/scan', [...$input, 'resume_text' => 'Different CV'])->assertConflict();
    }

    public function test_analysis_failure_refunds_credits_and_does_not_save_a_result(): void
    {
        $user = User::factory()->create();
        config(['services.openai.key' => 'fake-key']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $this->actingAs($user)->postJson('/checker/scan', ['job_description' => 'PHP HTML', 'resume_text' => 'PHP'])->assertStatus(503)->assertJsonPath('credits_remaining', 15);
        $this->assertDatabaseCount('cv_analyses', 0);
        $this->assertDatabaseHas('ai_usage_logs', ['credits_charged' => 0, 'status' => 'failed']);
    }

    public function test_abandoned_reservations_are_refunded_by_maintenance(): void
    {
        $user = User::factory()->create();
        $this->credits()->reserve($user, 'revamp', (string) Str::uuid(), []);
        $this->travel(11)->minutes();
        $this->artisan('credits:maintain')->assertSuccessful();
        $this->assertSame(15, $this->credits()->wallet($user)['credits']);
    }

    private function payment(User $user, string $kind = 'subscription', string $mode = 'live'): BillingPayment
    {
        config(['paystack.secret_key' => 'sk_'.$mode.'_fake']);

        return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id, 'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->first()->id,
            'provider' => 'paystack', 'mode' => $mode, 'amount_cents' => 8500, 'currency' => 'ZAR', 'billing_interval' => 'monthly',
            'provider_plan_id' => $kind === 'topup' ? null : 'PLN_test', 'recurring' => $kind !== 'topup', 'kind' => $kind, 'credits' => 50]);
    }

    private function receipt(BillingPayment $payment, int $id = 1, ?string $reference = null): array
    {
        return ['id' => $id, 'status' => 'success', 'domain' => $payment->mode, 'reference' => $reference ?? $payment->reference,
            'amount' => 8500, 'currency' => 'ZAR', 'paid_at' => now()->toIso8601String(), 'plan_object' => ['plan_code' => 'PLN_test'],
            'customer' => ['customer_code' => 'CUS_test', 'email' => $payment->user_id ? User::find($payment->user_id)->email : '']];
    }

    public function test_verified_paystack_payment_and_renewal_grant_once_and_reversal_preserves_topups(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user);
        $gateway = app(PaystackGateway::class);
        $gateway->apply($payment, $this->receipt($payment));
        $gateway->apply($payment, $this->receipt($payment));
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->useCredits($user);
        $this->assertSame(140, $this->credits()->wallet($user)['credits']);
        $this->travel(32)->days();
        $renewal = $this->receipt($payment->fresh(), 2, 'renewal-ref');
        $gateway->apply($payment->fresh(), $renewal);
        $this->assertSame(150, $this->credits()->wallet($user)['credits']);
        $this->credits()->topup($user, 50, 'purchased');
        $gateway->apply($payment->fresh(), [...$renewal, 'status' => 'reversed']);
        $this->assertSame(50, $this->credits()->wallet($user)['credits']);
        $this->assertDatabaseCount('paystack_receipts', 2);
    }

    public function test_signed_paystack_webhook_verifies_remotely_and_duplicate_delivery_does_not_regrant(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user);
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => $this->receipt($payment)])]);
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $payment->reference]]);
        $signature = hash_hmac('sha512', $body, 'sk_live_fake');
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();
        $this->useCredits($user);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature], $body)->assertOk();
        $this->assertSame(140, $this->credits()->wallet($user)['credits']);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $body)->assertUnauthorized();
    }

    public function test_test_payments_and_pending_payments_never_add_paid_credits(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user, 'subscription', 'test');
        app(PaystackGateway::class)->apply($payment, $this->receipt($payment));
        $this->assertSame(15, $this->credits()->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseHas('billing_payments', ['id' => $payment->id, 'status' => 'test_paid']);
    }

    public function test_two_provider_receipts_for_the_same_billing_period_cannot_reset_spent_credits(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user);
        $gateway = app(PaystackGateway::class);
        $gateway->apply($payment, $this->receipt($payment));
        $this->useCredits($user);
        $gateway->apply($payment->fresh(), $this->receipt($payment->fresh(), 2, 'second-transaction-same-period'));
        $this->assertSame(140, $this->credits()->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('paystack_receipts', 2);
        // Refunding a duplicate receipt must not revoke the original paid term.
        $gateway->apply($payment->fresh(), [...$this->receipt($payment->fresh(), 2, 'second-transaction-same-period'), 'status' => 'reversed']);
        $this->assertSame(140, $this->credits()->wallet($user)['credits']);
    }

    public function test_wrong_amounts_or_currency_do_not_grant_credits(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user);
        foreach (['currency' => 'USD', 'amount' => 1, 'domain' => 'test'] as $field => $value) {
            try {
                app(PaystackGateway::class)->apply($payment, [...$this->receipt($payment), $field => $value]);
                $this->fail('Mismatched payment was accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame(15, $this->credits()->wallet($user)['credits']);
                $this->assertDatabaseCount('subscriptions', 0);
            }
        }
    }

    public function test_topup_checkout_uses_only_server_prices_and_verified_fulfilment(): void
    {
        $user = User::factory()->create();
        config(['billing.paynow.enabled' => true, 'billing.paynow.mode' => 'live']);
        $this->mock(PaynowGateway::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturn('https://www.paynow.co.zw/Payment/test');
            $mock->shouldReceive('status')->andReturn('paid');
        });
        $this->actingAs($user)->post('/credits/topup', ['pack' => 'monthly', 'provider' => 'paynow', 'billing_consent' => 1, 'amount' => 1, 'credits' => 9999])->assertRedirect('https://www.paynow.co.zw/Payment/test');
        $payment = BillingPayment::sole();
        $this->assertSame(475, $payment->amount_cents);
        $this->assertSame(150, $payment->credits);
        $this->assertSame(15, $this->credits()->wallet($user)['credits']);
        app(PaymentService::class)->refresh($payment);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame(165, $this->credits()->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
