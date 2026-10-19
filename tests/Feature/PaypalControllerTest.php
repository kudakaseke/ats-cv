<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaypalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_requires_login_and_valid_plan(): void
    {
        $this->post('/paypal/checkout')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/paypal/checkout', ['plan' => 'fake'])->assertSessionHasErrors('plan');
    }

    public function test_success_requeries_subscription_and_never_grants_on_redirect_alone(): void
    {
        $payment = $this->payment();
        config(['billing.paypal.mode' => 'sandbox', 'billing.paypal.client_id' => 'client', 'billing.paypal.client_secret' => 'secret']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v1/billing/subscriptions/I-TEST123' => Http::response(['id' => 'I-TEST123', 'plan_id' => 'P-MONTHLY', 'custom_id' => $payment->reference, 'status' => 'ACTIVE']),
            '*/transactions*' => Http::response(['transactions' => []]),
        ]);
        $this->actingAs(User::find($payment->user_id))->get(route('paypal.success', ['reference' => $payment->reference, 'subscription_id' => 'I-TEST123']))
            ->assertRedirect(route('billing.show', $payment->reference));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions'));
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_mismatched_or_unowned_callback_is_rejected(): void
    {
        $payment = $this->payment();
        Http::preventStrayRequests();
        $this->actingAs(User::find($payment->user_id))->get(route('paypal.success', ['reference' => $payment->reference, 'subscription_id' => 'I-FORGED']))->assertSessionHasErrors('billing');
        $this->actingAs(User::factory()->create())->get(route('paypal.cancel', ['reference' => $payment->reference]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_cancel_return_does_not_cancel_an_approved_subscription(): void
    {
        $payment = $this->payment();
        Http::preventStrayRequests();
        $this->actingAs(User::find($payment->user_id))->get(route('paypal.cancel', ['reference' => $payment->reference]))
            ->assertRedirect(route('billing.show', $payment->reference));
        $this->assertSame('ACTIVE', $payment->fresh()->recurring_status);
        Http::assertNothingSent();
    }

    public function test_new_webhook_route_validates_signature(): void
    {
        config(['billing.paypal.webhook_id' => null]);
        Http::preventStrayRequests();
        $this->postJson('/paypal/webhook', ['event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED'])->assertBadRequest();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    private function payment(): BillingPayment
    {
        $this->seed(SubscriptionPlanSeeder::class);

        return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->first()->id, 'provider' => 'paypal', 'mode' => 'sandbox',
            'amount_cents' => 500, 'currency' => 'USD', 'billing_interval' => 'monthly', 'recurring' => true,
            'provider_reference' => 'I-TEST123', 'provider_plan_id' => 'P-MONTHLY', 'recurring_status' => 'ACTIVE']);
    }
}
