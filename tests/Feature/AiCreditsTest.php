<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCreditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_restored_allowance_can_expire_again_without_breaking_ats_or_duplicating_expiry(): void
    {
        $user = User::factory()->create();
        $subscription = $this->paid($user, 'annual');
        $this->credits()->wallet($user);
        $original = DB::table('ai_wallets')->where('user_id', $user->id)->first();
        $subscription->update(['status' => 'cancelled']);
        $this->credits()->wallet($user);
        // Reproduce an allowance restored by the former local-test configuration.
        // Its original immutable expiry must remain in the ledger.
        DB::table('ai_credit_transactions')->where('user_id', $user->id)->where('type', 'subscription_expired')
            ->update(['reference' => 'expire:'.$original->allocation_reference]);
        DB::table('ai_credit_transactions')->insert(['user_id' => $user->id, 'reference' => 'restore-fixture',
            'type' => 'subscription_restored', 'subscription_delta' => $original->subscription_credits,
            'topup_delta' => 0, 'debt_delta' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ai_wallets')->where('user_id', $user->id)->update([
            'allocation_reference' => $original->allocation_reference, 'subscription_credits' => $original->subscription_credits,
            'topup_credits' => 50, 'expires_at' => $original->expires_at]);
        $this->actingAs($user)->get('/workspace/checker')->assertOk()->assertSee('Job-to-CV ATS Detector');
        $this->getJson('/ai/credits')->assertOk()->assertJsonPath('credits', 50);
        $this->get('/dashboard')->assertOk();
        $this->get('/subscription')->assertOk();
        $this->assertSame(2, DB::table('ai_credit_transactions')->where('user_id', $user->id)->where('type', 'subscription_expired')->count());
        $this->assertDatabaseHas('ai_credit_transactions', ['reference' => 'expire:'.$original->allocation_reference,
            'subscription_delta' => -$original->subscription_credits]);
        $this->assertSame(0, $this->credits()->wallet($user)['subscription_credits']);
    }

    public function test_existing_annual_allowance_preserves_spending_and_pending_refunds_on_upgrade(): void
    {
        $user = User::factory()->create();
        $subscription = $this->paid($user, 'annual');
        config(['ai_credits.plans.annual' => 200]);
        $this->credits()->wallet($user);
        $this->useCredits($user);
        $operation = $this->credits()->reserve($user, 'revamp', (string) Str::uuid(), []);
        $oldReference = 'subscription:'.$subscription->id.':'.$subscription->starts_at->format('YmdHis');
        DB::table('ai_wallets')->where('user_id', $user->id)->update(['allocation_reference' => $oldReference]);
        DB::table('ai_operations')->where('user_id', $user->id)->update(['allocation_reference' => $oldReference]);
        DB::table('ai_credit_transactions')->where('reference', 'like', 'subscription:'.$subscription->id.':%')->delete();
        config(['ai_credits.plans.annual' => 2400]);
        $this->assertSame(2380, $this->credits()->wallet($user)['credits']);
        $this->credits()->finish($user, $operation->id, null);
        $this->assertSame(2390, $this->credits()->wallet($user)['credits']);
        $this->assertSame(2390, $this->credits()->wallet($user)['credits']);
    }

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
        $this->assertSame(650, SubscriptionPlan::where('slug', 'pro')->value('price_cents'));
        $this->assertSame(4740, SubscriptionPlan::where('slug', 'annual')->value('price_cents'));
        $this->useCredits($user);
        $this->assertSame(5, $this->credits()->wallet($user)['credits']);
        $this->travel(2)->months();
        $this->assertSame(5, $this->credits()->wallet($user)['credits']);
    }

    public function test_free_account_header_shows_spendable_welcome_credits_and_remaining_balance(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertSee('<strong x-text="available">15</strong>', false)
            ->assertSee('this.available = wallet.credits;', false);
        $this->getJson('/ai/credits')->assertOk()
            ->assertJsonPath('credits', 15)->assertJsonPath('purchased_credits', 0);

        $this->useCredits($user);
        $this->get('/dashboard')->assertOk()
            ->assertSee('<strong x-text="available">5</strong>', false);
        $this->getJson('/ai/credits')->assertOk()->assertJsonPath('credits', 5);
        $this->assertSame(1, DB::table('ai_credit_transactions')
            ->where('user_id', $user->id)->where('type', 'welcome')->count());
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

    public function test_annual_credits_are_2400_upfront_without_monthly_refills_and_expire_at_term_end(): void
    {
        $this->travelTo(now()->setDate(2026, 1, 31)->startOfDay());
        $user = User::factory()->create();
        $this->paid($user, 'annual');
        $this->assertSame(2400, $this->credits()->wallet($user)['credits']);
        $this->useCredits($user);
        $this->travelTo(now()->setDate(2026, 2, 28));
        $this->assertSame(2390, $this->credits()->wallet($user)['credits']);
        $this->useCredits($user);
        $this->travelTo(now()->setDate(2026, 3, 30));
        $this->assertSame(2380, $this->credits()->wallet($user)['credits']);
        $this->travelTo(now()->setDate(2026, 3, 31));
        $this->assertSame(2380, $this->credits()->wallet($user)['credits']);
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
        $this->assertSame(2390, $this->credits()->wallet($user)['credits']);
        $this->credits()->finish($user, $operation->id, null);
        $this->assertSame(2400, $this->credits()->wallet($user)['credits']);
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

    public function test_confirmed_scan_failure_identifies_the_failed_request_and_replay_refunds_only_once(): void
    {
        $user = User::factory()->create();
        config(['services.openai.key' => 'fake-key']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $id = (string) Str::uuid();
        $input = ['job_description' => 'PHP HTML', 'resume_text' => 'PHP', 'operation_id' => $id];
        $this->actingAs($user)->postJson('/checker/scan', $input)->assertStatus(503)
            ->assertJsonPath('operation_failed', true)->assertJsonPath('operation_id', $id)->assertJsonPath('credits_remaining', 15);
        $providerCalls = Http::recorded()->count();
        $this->postJson('/checker/scan', $input)->assertStatus(503)
            ->assertJsonPath('operation_failed', true)->assertJsonPath('operation_id', $id);
        Http::assertSentCount($providerCalls);
        $this->assertSame(15, $this->credits()->wallet($user)['credits']);
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'failed_usage_refund')->count());
        $this->assertDatabaseCount('ai_operations', 1);
    }

    public function test_failed_rewrite_replay_identifies_the_refunded_operation_without_contacting_ai(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        $input = ['job_description' => 'PHP', 'resume_text' => 'PHP', 'confirmed_keywords' => []];
        $operation = $this->credits()->reserve($user, 'revamp', $id, $input);
        $this->credits()->finish($user, $operation->id, null);
        Http::preventStrayRequests();
        $this->actingAs($user)->postJson('/checker/rewrite', [...$input, 'operation_id' => $id])->assertStatus(503)
            ->assertJsonPath('operation_failed', true)->assertJsonPath('operation_id', $id);
        Http::assertNothingSent();
        $this->assertSame(15, $this->credits()->wallet($user)['credits']);
        $this->assertDatabaseCount('ai_operations', 1);
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

    public function test_topup_checkout_uses_only_server_prices_and_verified_fulfilment(): void
    {
        $user = User::factory()->create();
        config(['billing.paynow.enabled' => true, 'billing.paynow.mode' => 'live', 'billing.paynow.integration_id' => '123', 'billing.paynow.integration_key' => 'fixture', 'billing.paynow.live_currency_confirmed' => true]);
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
