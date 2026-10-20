<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaynowGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReturnTest extends TestCase
{
    use RefreshDatabase;

    private function payment(string $mode = 'live'): BillingPayment
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user);

        return BillingPayment::create([
            'reference' => (string) Str::uuid(), 'user_id' => $user->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
            'provider' => 'paynow', 'mode' => $mode, 'status' => 'pending',
            'amount_cents' => 475, 'currency' => 'USD', 'billing_interval' => 'monthly', 'credits' => 150,
        ]);
    }

    public function test_verified_return_redirects_to_dashboard_with_credits_exactly_once(): void
    {
        $payment = $this->payment();
        $this->mock(PaynowGateway::class)->shouldReceive('status')->once()->andReturn('paid');
        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'))
            ->assertSessionHas('payment_success', fn ($message) => str_contains($message, '150 credits'));
        $this->get(route('dashboard'))->assertOk()->assertSee('150 credits')->assertSee('Payment successful');
        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame(150, app(AiCreditService::class)->wallet(User::find($payment->user_id))['credits']);
    }

    public function test_pending_return_never_trusts_success_query_parameters(): void
    {
        $payment = $this->payment();
        $this->mock(PaynowGateway::class)->shouldReceive('status')->twice()->andReturn('pending');
        $this->get(route('billing.show', $payment->reference).'?status=success')->assertOk()->assertSee('Confirming your payment automatically');
        $this->postJson(route('billing.check', $payment->reference))->assertOk()->assertJson(['status' => 'pending', 'redirect_url' => null]);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_polling_a_confirmed_payment_returns_dashboard_and_actual_balance(): void
    {
        $payment = $this->payment();
        $this->mock(PaynowGateway::class)->shouldReceive('status')->once()->andReturn('paid');
        $this->postJson(route('billing.check', $payment->reference))->assertOk()
            ->assertJson(['status' => 'paid', 'credits' => 150, 'redirect_url' => route('dashboard')]);
    }

    public function test_test_payments_do_not_activate_live_credits(): void
    {
        $payment = $this->payment('test');
        $this->mock(PaynowGateway::class)->shouldReceive('status')->once()->andReturn('paid');
        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'))->assertSessionHas('payment_success', fn ($message) => str_contains($message, 'Test payment successful'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Test payment confirmed')->assertSee('Monthly')->assertSee('Current plan: Free');
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertSame(15, app(AiCreditService::class)->wallet(User::find($payment->user_id))['credits']);
    }

    public function test_another_user_cannot_confirm_or_view_a_payment(): void
    {
        $payment = $this->payment();
        $this->actingAs(User::factory()->create());
        $this->get(route('billing.show', $payment->reference))->assertNotFound();
        $this->postJson(route('billing.check', $payment->reference))->assertNotFound();
    }
}
