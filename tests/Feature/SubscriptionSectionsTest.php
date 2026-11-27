<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_day_test_button_is_available_only_in_nonproduction(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->actingAs(User::factory()->create())->get('/subscription')->assertOk()
            ->assertSee('One-day test plan')->assertSee('US$0.10')->assertSee('15 AI credits')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
        $this->get('/subscription/day-test/payment-method')->assertOk()
            ->assertSee('BillPaymentLink')->assertSee('This payment may be real')
            ->assertSee('contact support with your Paynow payment reference');
        config(['billing.paynow.enabled' => true, 'billing.paynow.mode' => 'test', 'billing.paynow.local_test_credits' => true,
            'billing.paynow.day_site.integration_id' => '123', 'billing.paynow.day_site.integration_key' => 'fake-key', 'billing.paynow.day_site.test_email' => 'merchant@example.test']);
        $this->get('/subscription/day-test/payment-method')->assertOk()->assertSee('Continue to Paynow')
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self' https://www.paynow.co.zw https://paynow.co.zw")
            ->assertSee('VISA')->assertSee('Mastercard')->assertSee('EcoCash')
            ->assertSee('If no payment is made, no credits are added.')->assertSee(route('billing.start', ['provider' => 'paynow', 'plan' => 'day-test']), false)->assertDontSee('BillPaymentLink');
        app()->instance('env', 'production');
        $this->get('/subscription/day-test/payment-method')->assertNotFound();
    }

    public function test_subscription_sections_show_only_the_owners_payment_history(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$owner, $other] as $user) {
            BillingPayment::create(['user_id' => $user->id, 'reference' => 'history-user-'.$user->id,
                'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
                'provider' => 'paynow', 'mode' => 'test', 'status' => 'test_paid', 'currency' => 'USD',
                'amount_cents' => 475, 'credits' => 150, 'billing_interval' => 'monthly', 'paid_at' => now()]);
        }
        BillingPayment::create(['user_id' => $owner->id, 'reference' => 'unfinished-checkout',
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
            'provider' => 'paynow', 'mode' => 'test', 'status' => 'pending', 'currency' => 'USD',
            'amount_cents' => 475, 'credits' => 150, 'billing_interval' => 'monthly']);
        foreach (['failed', 'cancelled'] as $status) {
            BillingPayment::create(['user_id' => $owner->id, 'reference' => $status.'-checkout',
                'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
                'provider' => 'paynow', 'mode' => 'test', 'status' => $status, 'currency' => 'USD',
                'amount_cents' => 475, 'credits' => 150, 'billing_interval' => 'monthly']);
        }
        $this->actingAs($owner)->get('/subscription')->assertOk()
            ->assertSee('id="active-plan"', false)->assertSee('id="plans"', false)
            ->assertDontSee('id="cards"', false)->assertDontSee('href="#cards"', false)->assertSee('id="payment-history"', false)
            ->assertSee(route('billing.show', 'history-user-'.$owner->id))
            ->assertDontSee(route('billing.show', 'history-user-'.$other->id))
            ->assertDontSee(route('billing.show', 'unfinished-checkout'))
            ->assertSee(route('billing.show', 'failed-checkout'))->assertSee(route('billing.show', 'cancelled-checkout'))
            ->assertSee('Test payment confirmed')->assertSee('USD 4.75')->assertSee('SAST');
        $this->get('/account/activity')->assertOk()->assertDontSee('unfinished-checkout')
            ->assertSee('failed-checkout')->assertSee('cancelled-checkout');
    }

    public function test_active_plan_has_subscription_dates_and_empty_history_is_clear(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'annual')->firstOrFail()->id,
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        $this->actingAs($user)->get('/subscription')->assertOk()->assertSee('Active subscription')
            ->assertSee('Paid access until')->assertSee('2400 credits per paid year.')
            ->assertSee('No payments yet.')->assertDontSee('Card details are not stored or displayed');
    }
}
