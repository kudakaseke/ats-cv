<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaynowLocalTestCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.paynow.mode' => 'test', 'billing.paynow.local_test_credits' => true]);
        $this->mock(PaynowGateway::class)->shouldReceive('status')->andReturn('paid');
    }

    private function payment(string $slug = 'pro'): BillingPayment
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();
        return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id,
            'subscription_plan_id' => $plan->id, 'provider' => 'paynow', 'mode' => 'test', 'status' => 'test_paid',
            'amount_cents' => $plan->price_cents, 'currency' => 'USD', 'billing_interval' => $plan->billing_interval,
            'credits' => config('ai_credits.plans.'.$slug)]);
    }

    public function test_confirmed_local_test_plan_grants_allowance_once_and_preserves_spending(): void
    {
        app()->instance('env', 'local');
        foreach (['pro' => 225, 'annual' => 2400] as $slug => $credits) {
            $payment = $this->payment($slug);
            $user = User::findOrFail($payment->user_id);
            app(PaymentService::class)->refresh($payment);
            $this->assertSame('test_paid', $payment->fresh()->status);
            $this->assertSame('sandbox_active', Subscription::findOrFail($payment->fresh()->subscription_id)->status);
            $this->assertSame($credits, app(AiCreditService::class)->wallet($user)['credits']);
            app(AiCreditService::class)->reserve($user, 'analyse', (string) Str::uuid(), ['fixture' => $slug]);
            app(PaymentService::class)->refresh($payment->fresh());
            $this->assertSame($credits - 5, app(AiCreditService::class)->wallet($user)['credits']);
            $this->assertSame($slug, app(\App\Services\PlanService::class)->current($user)->slug);
        }
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('billing_receipts', 0);
        $this->assertSame(2, DB::table('ai_credit_transactions')->where('type', 'subscription_allocation')->count());
    }

    public function test_next_test_subscription_adds_credits_now_without_another_grant_at_period_start(): void
    {
        app()->instance('env', 'local');
        $first = $this->payment('pro');
        $user = User::findOrFail($first->user_id);
        app(PaymentService::class)->refresh($first);
        $nextPlan = SubscriptionPlan::where('slug', 'monthly')->firstOrFail();
        $next = BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
            'subscription_plan_id' => $nextPlan->id, 'provider' => 'paynow', 'mode' => 'test',
            'amount_cents' => 475, 'currency' => 'USD', 'billing_interval' => 'monthly', 'credits' => 150]);
        app(PaymentService::class)->refresh($next);
        $next->refresh();
        $period = Subscription::findOrFail($next->subscription_id);
        $this->assertTrue($period->starts_at->isFuture());
        $this->assertSame(375, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertTrue($period->credits_granted_upfront);
        $this->actingAs($user)->get(route('billing.show', $next->reference))->assertRedirect(route('dashboard'))
            ->assertSessionHas('payment_success', fn ($message) => str_contains($message, '150 credits have been added now'));
        $this->get('/subscription')->assertOk()->assertSee('Scheduled: Monthly starts')
            ->assertSee($period->starts_at->timezone('Africa/Johannesburg')->format('d M Y'));
        $operation = app(AiCreditService::class)->reserve($user, 'analyse', (string) Str::uuid(), []);
        $this->assertSame(370, app(AiCreditService::class)->wallet($user)['credits']);
        app(AiCreditService::class)->finish($user, $operation->id, null);
        $this->travelTo($period->starts_at->copy()->addSecond());
        app(PaymentService::class)->refresh($next->fresh());
        $this->assertSame(375, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertSame(1, DB::table('ai_credit_transactions')->where('type', 'subscription_upfront')->count());
    }

    public function test_production_never_grants_or_recognises_local_test_entitlements(): void
    {
        app()->instance('env', 'production');
        $payment = $this->payment();
        app(PaymentService::class)->refresh($payment);
        $this->assertNull($payment->fresh()->subscription_id);
        $this->assertSame(['active'], Subscription::availableStatuses());
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_disabling_sandbox_revokes_test_allowance_but_preserves_purchased_credits(): void
    {
        app()->instance('env', 'local');
        $payment = $this->payment();
        $user = User::findOrFail($payment->user_id);
        app(PaymentService::class)->refresh($payment);
        app(AiCreditService::class)->topup($user, 120, 'real-existing-credit-fixture');
        app(AiCreditService::class)->reserve($user, 'analyse', (string) Str::uuid(), []);
        config(['billing.paynow.local_test_credits' => false]);
        $this->assertSame(120, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertSame('free', app(\App\Services\PlanService::class)->current($user)->slug);
        config(['billing.paynow.local_test_credits' => true]);
        $this->assertSame(340, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_local_test_purchase_does_not_replace_a_real_paid_subscription(): void
    {
        app()->instance('env', 'local');
        $payment = $this->payment();
        $user = User::findOrFail($payment->user_id);
        $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
        $this->assertSame(150, app(AiCreditService::class)->wallet($user)['credits']);
        app(PaymentService::class)->refresh($payment);
        $this->assertNull($payment->fresh()->subscription_id);
        $this->assertSame(150, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertSame('monthly', app(\App\Services\PlanService::class)->current($user)->slug);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_legacy_test_subscriptions_are_not_reactivated_by_paynow_sandbox(): void
    {
        app()->instance('env', 'local');
        $user = User::factory()->create();
        $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'annual')->value('id'),
            'status' => 'test_active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        $this->assertSame(15, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertSame('free', app(\App\Services\PlanService::class)->current($user)->slug);
    }
}
