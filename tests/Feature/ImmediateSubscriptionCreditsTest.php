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
use Illuminate\Support\Str;
use Tests\TestCase;

class ImmediateSubscriptionCreditsTest extends TestCase
{
    use RefreshDatabase;

    private array $statuses = [];

    public function test_late_paid_cannot_allocate_a_refunded_deferred_renewal_after_timing_changes(): void
    {
        $user = User::factory()->create();
        $this->pay($user, 'monthly');
        config(['billing.subscription_credit_timing' => 'next_period']);
        $future = $this->pay($user, 'pro');
        $this->statuses[$future->id] = 'reversed';
        app(PaymentService::class)->refresh($future);
        config(['billing.subscription_credit_timing' => 'immediate']);
        $this->statuses[$future->id] = 'paid';
        app(PaymentService::class)->refresh($future);
        $this->assertSame('reversed', $future->fresh()->status);
        $this->assertFalse(Subscription::findOrFail($future->subscription_id)->credits_granted_upfront);
        $this->assertSame(150, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_early_purchase_with_only_a_future_legacy_period_has_a_fully_backed_wallet(): void
    {
        $user = User::factory()->create();
        $plan = SubscriptionPlan::where('slug', 'monthly')->firstOrFail();
        $user->subscriptions()->create(['subscription_plan_id' => $plan->id, 'status' => 'active',
            'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonths(2)]);
        $credits = app(AiCreditService::class);
        $this->assertSame(15, $credits->wallet($user)['credits']);
        $this->pay($user, 'monthly');
        $this->assertSame(150, $credits->wallet($user)['credits']);
        for ($i = 0; $i < 15; $i++) {
            $credits->reserve($user, 'revamp', (string) Str::uuid(), []);
        }
        $this->assertSame(0, $credits->wallet($user)['credits']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.subscription_credit_timing' => 'immediate']);
        $this->mock(PaynowGateway::class)->shouldReceive('status')->andReturnUsing(fn ($payment) => $this->statuses[$payment->id] ?? 'paid');
    }

    private function pay(User $user, string $slug): BillingPayment
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();
        $payment = BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
            'subscription_plan_id' => $plan->id, 'provider' => 'paynow', 'mode' => 'live', 'amount_cents' => $plan->price_cents,
            'currency' => 'USD', 'billing_interval' => $plan->billing_interval, 'credits' => config('ai_credits.plans.'.$slug)]);
        app(PaymentService::class)->refresh($payment);

        return $payment->fresh();
    }

    public function test_third_purchase_adds_credits_now_and_extends_access_without_overlap_or_refill(): void
    {
        $user = User::factory()->create();
        $first = $this->pay($user, 'pro');
        $second = $this->pay($user, 'monthly');
        $third = $this->pay($user, 'annual');
        $secondPeriod = Subscription::findOrFail($second->subscription_id);
        $thirdPeriod = Subscription::findOrFail($third->subscription_id);
        $this->assertTrue($thirdPeriod->starts_at->equalTo($secondPeriod->ends_at));
        $this->assertSame(2775, app(AiCreditService::class)->wallet($user)['credits']);
        app(PaymentService::class)->refresh($third);
        $this->travelTo($thirdPeriod->starts_at->copy()->addSecond());
        $this->assertSame(2775, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 3);
    }

    public function test_refunding_current_period_preserves_unspent_credits_from_the_next_purchase(): void
    {
        $user = User::factory()->create();
        $first = $this->pay($user, 'pro');
        $next = $this->pay($user, 'monthly');
        $credits = app(AiCreditService::class);
        for ($i = 0; $i < 25; $i++) {
            $op = $credits->reserve($user, 'analyse', (string) Str::uuid(), []);
            $credits->finish($user, $op->id, ['complete' => true]);
        }
        $this->assertSame(250, $credits->wallet($user)['credits']);
        $this->statuses[$first->id] = 'reversed';
        app(PaymentService::class)->refresh($first);
        $this->assertSame(150, $credits->wallet($user)['credits']);
        app(PaymentService::class)->refresh($first);
        $this->assertSame(150, $credits->wallet($user)['credits']);
        $this->statuses[$next->id] = 'reversed';
        app(PaymentService::class)->refresh($next);
        $this->assertSame(0, $credits->wallet($user)['credits']);
    }

    public function test_failed_operations_restore_their_funding_sources_even_after_early_renewal(): void
    {
        $user = User::factory()->create();
        $this->pay($user, 'pro');
        $credits = app(AiCreditService::class);
        $oldOperation = $credits->reserve($user, 'revamp', (string) Str::uuid(), []);
        $next = $this->pay($user, 'monthly');
        $this->assertSame(365, $credits->wallet($user)['credits']);
        $credits->finish($user, $oldOperation->id, null);
        $this->assertSame(375, $credits->wallet($user)['credits']);
        $operation = $credits->reserve($user, 'analyse', (string) Str::uuid(), []);
        $period = Subscription::findOrFail($next->subscription_id);
        $this->travelTo($period->starts_at->copy()->addSecond());
        $credits->finish($user, $operation->id, null);
        $this->assertSame(375, $credits->wallet($user)['credits']);
        $credits->topup($user, 50, 'independent-purchased-credits');
        $this->travelTo($period->ends_at->copy()->addSecond());
        $this->assertSame(50, $credits->wallet($user)['credits']);
    }

    public function test_failed_operation_cannot_restore_credits_from_a_refunded_period(): void
    {
        $user = User::factory()->create();
        $first = $this->pay($user, 'pro');
        $this->pay($user, 'monthly');
        $credits = app(AiCreditService::class);
        $operation = $credits->reserve($user, 'revamp', (string) Str::uuid(), []);
        $this->statuses[$first->id] = 'reversed';
        app(PaymentService::class)->refresh($first);
        $credits->finish($user, $operation->id, null);
        $this->assertSame(150, $credits->wallet($user)['credits']);
    }
}
