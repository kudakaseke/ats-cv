<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\PaynowGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaynowOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.paynow.enabled' => true, 'billing.paynow.mode' => 'test',
            'billing.paynow.integration_id' => '123', 'billing.paynow.integration_key' => 'fixture']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_immediate_credit_checkout_allows_another_purchase_after_a_prepaid_period(): void
    {
        config(['billing.subscription_credit_timing' => 'immediate', 'billing.paynow.plan_change_policy' => 'end_of_cycle']);
        $user = auth()->user();
        foreach ([[now()->subDay(), now()->addMonth()], [now()->addMonth(), now()->addMonths(2)]] as [$start, $end]) {
            $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->value('id'),
                'status' => 'active', 'starts_at' => $start, 'ends_at' => $end]);
        }
        $this->mock(PaynowGateway::class)->shouldReceive('start')->once()->andReturn('https://www.paynow.co.zw/Payment/fixture');
        $this->post('/billing/paynow/annual', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/fixture');
        $payment = BillingPayment::sole();
        $this->assertSame(4740, $payment->amount_cents);
        $this->assertSame(2400, $payment->credits);
        $this->post('/billing/paynow/annual', ['billing_consent' => 1])->assertRedirect(route('billing.show', $payment->reference));
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertDatabaseCount('subscriptions', 2);
        Http::assertNothingSent();
    }

    public function test_each_selected_plan_reaches_checkout_with_its_own_server_price_and_credits(): void
    {
        $expected = ['monthly' => [475, 150], 'pro' => [650, 225], 'annual' => [4740, 2400]];
        $this->mock(PaynowGateway::class)->shouldReceive('start')->times(3)
            ->withArgs(function ($payment, $email) use ($expected) {
                $slug = SubscriptionPlan::findOrFail($payment->subscription_plan_id)->slug;
                $this->assertSame($expected[$slug][0], $payment->amount_cents);
                $this->assertSame($expected[$slug][1], $payment->credits);
                return true;
            })->andReturn('https://www.paynow.co.zw/Payment/fixture');
        foreach ($expected as $slug => [$amount, $credits]) {
            $this->actingAs(User::factory()->create())->post('/billing/paynow/'.$slug,
                ['billing_consent' => 1, 'amount' => 1, 'credits' => 99999])
                ->assertRedirect('https://www.paynow.co.zw/Payment/fixture');
        }
        $this->assertDatabaseCount('billing_payments', 3);
    }

    public function test_pending_monthly_checkout_cannot_be_reused_as_pro_or_annual(): void
    {
        $monthly = BillingPayment::create(['reference' => 'pending-monthly', 'user_id' => auth()->id(),
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->value('id'),
            'provider' => 'paynow', 'mode' => 'test', 'amount_cents' => 475, 'credits' => 150,
            'currency' => 'USD', 'billing_interval' => 'monthly']);
        $this->mock(PaynowGateway::class)->shouldNotReceive('start');
        foreach (['pro', 'annual'] as $slug) {
            $this->post('/billing/paynow/'.$slug, ['billing_consent' => 1])
                ->assertRedirect(route('subscription.payment-methods', $slug))->assertSessionHasErrors('billing');
        }
        $this->assertDatabaseCount('billing_payments', 1);
        $this->assertSame(475, $monthly->fresh()->amount_cents);
        $this->assertSame('pending', $monthly->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_jobs_and_manual_checks_do_not_requeue_retired_providers(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        config(['performance.queue_payment_checks' => true]);
        $payment = BillingPayment::create(['reference' => 'retired-checkout', 'user_id' => auth()->id(),
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->value('id'),
            'provider' => 'paystack', 'mode' => 'test', 'status' => 'closed', 'amount_cents' => 475,
            'currency' => 'USD', 'billing_interval' => 'monthly']);
        $service = $this->mock(\App\Services\Billing\PaymentService::class);
        $service->shouldNotReceive('refresh');
        (new \App\Jobs\RefreshPayment($payment->id))->handle($service);
        $this->get('/payments/'.$payment->reference)->assertOk()->assertSee('This local checkout was closed')
            ->assertDontSee('Check payment status');
        $this->postJson('/payments/'.$payment->reference.'/check')->assertOk()->assertJsonPath('status', 'closed');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_removed_providers_cannot_start_subscription_or_topup_checkouts(): void
    {
        foreach (['paypal', 'paystack'] as $provider) {
            $this->post('/billing/'.$provider.'/monthly', ['billing_consent' => 1])->assertNotFound();
            $this->postJson('/credits/topup', ['provider' => $provider, 'pack' => 'monthly', 'billing_consent' => 1])
                ->assertUnprocessable()->assertJsonValidationErrors('provider');
        }
        $this->assertDatabaseCount('billing_payments', 0);
        Http::assertNothingSent();
    }

    public function test_subscription_exposes_the_topup_section_used_by_dashboard_links(): void
    {
        $this->get('/subscription')->assertOk()->assertSee('id="credit-topups"', false)
            ->assertSee('150 credits for US$4.75.')->assertSee('Buy 150 credits with Paynow (test)')
            ->assertSee('name="provider" value="paynow"', false)->assertDontSee('PayPal')->assertDontSee('Paystack');
    }

    public function test_unconfirmed_live_currency_disables_both_purchase_forms_and_topups(): void
    {
        config(['billing.paynow.mode' => 'live', 'billing.paynow.live_currency_confirmed' => false]);
        $this->get('/subscription/monthly/payment-method')->assertOk()->assertDontSee('Pay with Paynow');
        $this->get('/subscription')->assertOk()->assertSee('Paynow unavailable');
        $this->post('/credits/topup', ['provider' => 'paynow', 'pack' => 'monthly', 'billing_consent' => 1])->assertStatus(503);
        $this->assertDatabaseCount('billing_payments', 0);
        Http::assertNothingSent();
    }

    public function test_confirmed_topup_rejection_allows_a_new_attempt_instead_of_leaving_it_pending(): void
    {
        $this->mock(PaynowGateway::class)->shouldReceive('start')->once()->andThrow(new \DomainException('Rejected'));
        $this->post('/credits/topup', ['provider' => 'paynow', 'pack' => 'monthly', 'billing_consent' => 1])
            ->assertRedirect()->assertSessionHasErrors('billing');
        $this->assertSame('failed', BillingPayment::sole()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_terms_describe_the_actual_annual_allowance(): void
    {
        $this->get('/terms')->assertOk()->assertSee('2,400 credits available when the paid year begins')
            ->assertDontSee('Annual plans are paid yearly with credits allocated monthly');
    }

    public function test_historical_test_agreements_do_not_block_paynow_checkout(): void
    {
        BillingPayment::create(['reference' => 'legacy-test', 'user_id' => auth()->id(),
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->value('id'),
            'provider' => 'legacy', 'mode' => 'test', 'recurring' => true, 'amount_cents' => 475,
            'currency' => 'USD', 'billing_interval' => 'monthly']);
        $this->mock(PaynowGateway::class)->shouldReceive('start')->once()->andReturn('https://www.paynow.co.zw/Payment/fixture');
        $this->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/fixture');
    }

    public function test_unresolved_live_agreements_still_prevent_duplicate_billing(): void
    {
        config(['billing.paynow.mode' => 'live', 'billing.paynow.live_currency_confirmed' => true]);
        BillingPayment::create(['reference' => 'legacy-live', 'user_id' => auth()->id(),
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->value('id'),
            'provider' => 'legacy', 'mode' => 'live', 'recurring' => true, 'amount_cents' => 475,
            'currency' => 'USD', 'billing_interval' => 'monthly']);
        $this->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_payments', 1);
        Http::assertNothingSent();
    }
}
