<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\Resume;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaystackGateway;
use App\Services\PlanService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function payment(User $user, string $mode): BillingPayment
    {
        return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
            'provider' => 'paystack', 'mode' => $mode, 'status' => 'pending', 'amount_cents' => 7795,
            'currency' => 'ZAR', 'billing_interval' => 'monthly', 'credits' => 150, 'provider_plan_id' => 'PLN_MONTHLY']);
    }

    public function test_local_test_subscription_grants_150_and_counts_down_to_zero_without_replay_refills(): void
    {
        $this->app->instance('env', 'local');
        config(['paystack.secret_key' => 'sk_test_fake', 'paystack.local_test_credits' => true]);
        $user = User::factory()->create();
        $payment = $this->payment($user, 'test');
        $data = ['id' => 201, 'reference' => $payment->reference, 'domain' => 'test', 'currency' => 'ZAR', 'amount' => 7795,
            'plan' => 'PLN_MONTHLY', 'status' => 'success', 'paid_at' => now()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_TEST', 'email' => $user->email]];
        $gateway = app(PaystackGateway::class);
        $credits = app(AiCreditService::class);
        $gateway->apply($payment, $data);
        $this->assertDatabaseHas('subscriptions', ['status' => 'test_active', 'user_id' => $user->id]);
        $this->assertSame(150, $credits->wallet($user)['credits']);
        $this->assertSame(150, $credits->wallet($user)['purchased_credits']);
        for ($used = 1; $used <= 15; $used++) {
            $operation = $credits->reserve($user, 'revamp', (string) Str::uuid(), ['fixture' => true]);
            $credits->finish($user, $operation->id, ['done' => true]);
            $gateway->apply($payment->fresh(), $data);
            $this->assertSame(150 - $used * 10, $credits->wallet($user)['purchased_credits']);
        }
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(0, $credits->wallet($user)['credits']);
        $this->app->instance('env', 'production');
        $this->assertSame(0, $credits->wallet($user)['credits']);
        $this->app->instance('env', 'local');
        $this->assertSame(0, $credits->wallet($user)['credits']);
    }

    public function test_existing_verified_test_receipt_can_be_activated_once_and_never_in_production(): void
    {
        config(['paystack.secret_key' => 'sk_test_fake', 'paystack.local_test_credits' => true]);
        $user = User::factory()->create();
        $payment = $this->payment($user, 'test');
        $data = ['id' => 202, 'reference' => $payment->reference, 'domain' => 'test', 'currency' => 'ZAR', 'amount' => 7795,
            'plan' => 'PLN_MONTHLY', 'status' => 'success', 'paid_at' => now()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_TEST', 'email' => $user->email]];
        $gateway = app(PaystackGateway::class);
        $this->app->instance('env', 'production');
        $gateway->apply($payment, $data);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->app->instance('env', 'local');
        $gateway->apply($payment->fresh(), $data);
        $gateway->apply($payment->fresh(), $data);
        $this->assertDatabaseCount('subscriptions', 1);
        $credits = app(AiCreditService::class);
        $this->assertSame(150, $credits->wallet($user)['purchased_credits']);
        $operation = $credits->reserve($user, 'revamp', (string) Str::uuid(), ['fixture' => true]);
        $this->app->instance('env', 'production');
        $this->assertSame('free', app(PlanService::class)->current($user)->slug);
        $credits->finish($user, $operation->id, null);
        $this->assertSame(0, $credits->wallet($user)['credits']);
        $this->assertSame(0, $credits->wallet($user)['purchased_credits']);
        $this->app->instance('env', 'local');
        $this->assertSame(150, $credits->wallet($user)['purchased_credits']);
        $this->assertDatabaseHas('audit_events', ['event' => 'payment.test_credits_activated', 'user_id' => $user->id]);
    }

    public function test_audit_page_is_private_and_does_not_expose_other_accounts(): void
    {
        $this->get(route('account.activity'))->assertRedirect(route('login'));
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->payment($owner, 'test');
        $theirs = $this->payment($other, 'test');
        $this->actingAs($owner)->get(route('account.activity'))->assertOk()
            ->assertSee('Payment history')->assertSee('Credit ledger')->assertSee('Activity history')
            ->assertSee($mine->reference)->assertDontSee($theirs->reference);
    }

    public function test_live_payment_activates_plan_credits_and_deduplicated_provider_audit(): void
    {
        config(['paystack.secret_key' => 'sk_live_fake']);
        $user = User::factory()->create();
        $payment = $this->payment($user, 'live');
        $data = ['id' => 101, 'reference' => $payment->reference, 'domain' => 'live', 'currency' => 'ZAR', 'amount' => 7795,
            'plan' => 'PLN_MONTHLY', 'status' => 'success', 'paid_at' => now()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_OWNER', 'email' => $user->email]];
        $gateway = app(PaystackGateway::class);
        $gateway->apply($payment, $data);
        $gateway->apply($payment->fresh(), $data);
        $this->actingAs($user)->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Current plan:')->assertSee('150 credits');
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(1, DB::table('audit_events')->where('event', 'payment.provider_confirmed')->count());
        $this->assertDatabaseHas('audit_events', ['event' => 'credits.subscription_allocation', 'user_id' => $user->id]);
        $this->assertSame(150, app(AiCreditService::class)->wallet($user)['purchased_credits']);
    }

    public function test_test_payment_is_visible_without_live_entitlements(): void
    {
        config(['paystack.secret_key' => 'sk_test_fake']);
        $user = User::factory()->create();
        $payment = $this->payment($user, 'test');
        app(PaystackGateway::class)->apply($payment, ['id' => 102, 'reference' => $payment->reference, 'domain' => 'test',
            'currency' => 'ZAR', 'amount' => 7795, 'plan' => 'PLN_MONTHLY', 'status' => 'success',
            'paid_at' => now()->toIso8601String(), 'customer' => ['customer_code' => 'CUS_TEST', 'email' => $user->email]]);
        $this->actingAs($user)->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'))
            ->assertSessionHas('payment_success', fn ($message) => str_contains($message, 'Test payment successful'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Current plan:');
        $this->get(route('subscription'))->assertOk()->assertSee('Test payment confirmed')->assertSee('ZAR 77.95')->assertSee('Current plan: Free');
        $this->get(route('account.activity'))->assertOk()->assertSee('Test payment confirmed')->assertSee($payment->reference);
        $this->assertDatabaseCount('subscriptions', 0);
        $event = DB::table('audit_events')->where('event', 'payment.provider_confirmed')->first();
        $this->assertSame(0, json_decode($event->details, true)['credits_granted']);
    }

    public function test_audit_captures_account_changes_without_passwords_email_or_cv_text(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->update(['password' => 'secret-password-do-not-log', 'email' => 'private-address@example.com']);
        $resume = new Resume;
        $resume->forceFill(['user_id' => $user->id, 'name' => 'PRIVATE CV NAME', 'source' => 'builder', 'status' => 'draft', 'content' => ['text' => 'PRIVATE CV CONTENT']])->save();
        $serialized = DB::table('audit_events')->where('user_id', $user->id)->pluck('details')->implode(' ');
        foreach (['secret-password-do-not-log', 'private-address@example.com', $user->password, 'PRIVATE CV NAME', 'PRIVATE CV CONTENT'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $this->assertStringContainsString('password_changed', $serialized);
        $this->assertDatabaseHas('audit_events', ['event' => 'resume.created', 'subject_id' => (string) $resume->id]);
    }

    public function test_rolled_back_payment_changes_leave_no_committed_audit_event(): void
    {
        $user = User::factory()->create();
        $before = DB::table('audit_events')->count();
        try {
            DB::transaction(function () use ($user) {
                $this->payment($user, 'live');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback', $exception->getMessage());
        }
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_historical_snapshot_backfill_is_idempotent_and_preserves_original_snapshot(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user, 'test');
        $this->artisan('audit:backfill')->assertSuccessful();
        $count = DB::table('audit_events')->where('event', 'historical.snapshot')->count();
        $payment->update(['status' => 'cancelled']);
        $this->artisan('audit:backfill')->assertSuccessful();
        $this->assertSame($count, DB::table('audit_events')->where('event', 'historical.snapshot')->count());
        $snapshot = DB::table('audit_events')->where('event_key', 'snapshot:billing_payments:'.$payment->id)->first();
        $this->assertSame('pending', json_decode($snapshot->details, true)['status']);
    }

    public function test_account_deletion_does_not_destroy_the_audit_trail_or_fail_on_actor_foreign_key(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->delete();
        $this->assertDatabaseHas('audit_events', ['event' => 'user.deleted', 'subject_id' => (string) $user->id, 'user_id' => null, 'actor_id' => null]);
    }

    public function test_failed_verification_is_recorded_without_provider_secrets(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment($user, 'live');
        $this->mock(PaystackGateway::class)->shouldReceive('refresh')->once()->andThrow(new \RuntimeException('sk_live_do_not_log'));
        $this->actingAs($user)->postJson(route('billing.check', $payment->reference))->assertStatus(503);
        $event = DB::table('audit_events')->where('event', 'payment.verification_failed')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('sk_live_do_not_log', $event->details);
        $this->assertDatabaseHas('audit_events', ['event' => 'account.request', 'source' => 'billing.check']);
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
