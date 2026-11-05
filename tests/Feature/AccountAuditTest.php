<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\Resume;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\PaynowGateway;
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
            'provider' => 'paynow', 'mode' => $mode, 'status' => 'pending', 'amount_cents' => 7795,
            'currency' => 'USD', 'billing_interval' => 'monthly', 'credits' => 150]);
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
        $this->mock(PaynowGateway::class)->shouldReceive('status')->once()->andThrow(new \RuntimeException('sk_live_do_not_log'));
        $this->actingAs($user)->postJson(route('billing.check', $payment->reference))->assertStatus(503);
        $event = DB::table('audit_events')->where('event', 'payment.verification_failed')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('sk_live_do_not_log', $event->details);
        $this->assertDatabaseHas('audit_events', ['event' => 'account.request', 'source' => 'billing.check']);
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
