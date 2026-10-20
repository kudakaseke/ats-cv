<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Billing\PaynowGateway;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.paynow.enabled' => true, 'billing.paynow.integration_id' => '123', 'billing.paynow.integration_key' => 'test-secret',
            'billing.paynow.mode' => 'live', 'billing.paynow.callback_url' => null,
            'billing.paypal.enabled' => true, 'billing.paypal.mode' => 'sandbox', 'billing.paypal.client_id' => 'test-client', 'billing.paypal.client_secret' => 'test-secret']);
    }

    public function test_paynow_local_test_uses_https_callbacks_and_never_grants_live_access(): void
    {
        config(['billing.paynow.mode' => 'test', 'billing.paynow.callback_url' => 'https://example.trycloudflare.com']);
        Http::fake(['*' => Http::response($this->signed(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/test', 'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']))]);
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/test');
        Http::assertSent(fn ($request) => str_starts_with($request['resulturl'], 'https://example.trycloudflare.com/billing/paynow/notify/') && ! isset($request['authemail']));
        $payment = BillingPayment::sole();
        $this->assertSame('test', $payment->mode);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response($this->signed(['reference' => $payment->reference, 'amount' => '4.75', 'paynowreference' => 'PN123', 'status' => 'Paid']))]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame('test_paid', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_supplied_paynow_credentials_are_distinguished_from_disabled_checkout(): void
    {
        config(['billing.paynow.enabled' => false]);
        $this->actingAs(User::factory()->create())->get('/subscription/monthly/payment-method')->assertOk()->assertSee('Paynow — currently disabled');
        config(['billing.paynow.enabled' => true]);
        $this->get('/subscription/monthly/payment-method')->assertOk()->assertSee('Pay with Paynow');
    }

    public function test_paynow_rejects_insecure_callback_override_without_contacting_provider(): void
    {
        config(['billing.paynow.callback_url' => 'http://localhost:8000']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        Http::assertNothingSent();
    }

    public function test_start_uses_server_price_and_does_not_activate_access(): void
    {
        $user = User::factory()->create();
        Http::fake(['*' => Http::response($this->signed(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/test', 'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']))]);
        $this->actingAs($user)->post('/billing/paynow/annual', ['billing_consent' => 1, 'amount' => '0.01'])->assertRedirect('https://www.paynow.co.zw/Payment/test');
        Http::assertSent(fn ($request) => $request['amount'] === '47.40');
        $this->assertDatabaseHas('billing_payments', ['user_id' => $user->id, 'amount_cents' => 4740, 'status' => 'pending']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_paynow_verified_payment_is_applied_once(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed(['reference' => $payment->reference, 'amount' => '5.00', 'paynowreference' => 'PN123', 'status' => 'Paid']))]);
        $service = app(PaymentService::class);
        $service->refresh($payment);
        $service->refresh($payment->fresh());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertSame('paid', $payment->fresh()->status);
        $subscription = $payment->user_id ? User::find($payment->user_id)->subscriptions()->sole() : null;
        $this->assertTrue($subscription->ends_at->equalTo($subscription->starts_at->copy()->addMonthNoOverflow()));
    }

    public function test_forged_callback_cannot_activate_or_poll(): void
    {
        $payment = $this->payment();
        Http::preventStrayRequests();
        $this->post('/billing/paynow/notify/'.$payment->reference, ['status' => 'Paid', 'hash' => 'forged'])->assertBadRequest();
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_wrong_amount_or_invalid_signature_cannot_activate(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed(['reference' => $payment->reference, 'amount' => '0.01', 'paynowreference' => 'PN123', 'status' => 'Paid']))]);
        $this->actingAs(User::find($payment->user_id))->post('/payments/'.$payment->reference.'/check')->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_another_user_cannot_view_or_check_a_payment(): void
    {
        $payment = $this->payment();
        $this->actingAs(User::factory()->create())->get('/payments/'.$payment->reference)->assertNotFound();
        $this->post('/payments/'.$payment->reference.'/check')->assertNotFound();
    }

    public function test_callback_does_not_follow_a_supplied_poll_url(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::response($this->signed(['reference' => $payment->reference, 'amount' => '5.00', 'paynowreference' => 'PN123', 'status' => 'Paid']))]);
        $body = $this->signed(['reference' => $payment->reference, 'status' => 'Paid', 'pollurl' => 'http://127.0.0.1/private']);
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
        Http::assertSent(fn ($request) => $request->url() === 'https://www.paynow.co.zw/interface/checkpayment?guid=test');
    }

    public function test_refund_revokes_the_associated_access(): void
    {
        $payment = $this->payment();
        Http::fake(['*' => Http::sequence()
            ->push($this->signed(['reference' => $payment->reference, 'amount' => '5.00', 'paynowreference' => 'PN123', 'status' => 'Paid']))
            ->push($this->signed(['reference' => $payment->reference, 'amount' => '5.00', 'paynowreference' => 'PN123', 'status' => 'Refunded']))]);
        app(PaymentService::class)->refresh($payment);
        app(PaymentService::class)->refresh($payment->fresh());
        $this->assertDatabaseHas('subscriptions', ['id' => $payment->fresh()->subscription_id, 'status' => 'cancelled']);
    }

    public function test_paypal_capture_requires_matching_completed_payment_and_sandbox_never_grants_access(): void
    {
        $payment = $this->payment('paypal', 'sandbox');
        $payment->update(['provider_reference' => 'ORDER123']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v2/checkout/orders/ORDER123' => Http::response(['id' => 'ORDER123', 'status' => 'APPROVED', 'purchase_units' => [[
                'custom_id' => $payment->reference, 'amount' => ['currency_code' => 'USD', 'value' => '5.00'],
            ]]]),
            '*/v2/checkout/orders/ORDER123/capture' => Http::response(['status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [[
                'id' => 'CAPTURE123', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '5.00'],
            ]]]]]]),
        ]);
        app(PaymentService::class)->refresh($payment);
        $this->assertSame('test_paid', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/capture') && $request->hasHeader('PayPal-Request-Id', $payment->reference.'-capture'));
    }

    public function test_disabled_provider_and_missing_consent_do_not_create_payments(): void
    {
        config(['billing.paynow.enabled' => false]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $this->post('/billing/paypal/monthly')->assertSessionHasErrors('billing_consent');
        $this->assertDatabaseCount('billing_payments', 0);
    }

    public function test_unverified_paypal_webhooks_are_rejected(): void
    {
        config(['billing.paypal.webhook_id' => 'WH_TEST']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);
        $this->postJson('/billing/paypal/notify', ['resource' => ['id' => 'forged']])->assertBadRequest();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_paypal_wrong_currency_does_not_capture_or_activate(): void
    {
        $payment = $this->payment('paypal', 'sandbox');
        $payment->update(['provider_reference' => 'ORDER123']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v2/checkout/orders/ORDER123' => Http::response(['id' => 'ORDER123', 'status' => 'APPROVED', 'purchase_units' => [[
                'custom_id' => $payment->reference, 'amount' => ['currency_code' => 'ZAR', 'value' => '5.00'],
            ]]]),
        ]);
        $this->actingAs(User::find($payment->user_id))->post('/payments/'.$payment->reference.'/check')->assertSessionHasErrors('billing');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/capture'));
        $this->assertDatabaseCount('subscriptions', 0);
    }

    private function payment(string $provider = 'paynow', string $mode = 'live'): BillingPayment
    {
        return BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->first()->id,
            'provider' => $provider, 'mode' => $mode, 'amount_cents' => 500, 'currency' => 'USD', 'billing_interval' => 'monthly',
            'poll_url' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']);
    }

    public function test_payment_status_page_renders_for_every_mode_and_status(): void
    {
        $payment = $this->payment();
        $this->actingAs(User::findOrFail($payment->user_id));
        Http::preventStrayRequests();
        foreach (['test', 'live', 'sandbox'] as $mode) {
            foreach (['pending', 'test_paid', 'paid', 'cancelled'] as $status) {
                $payment->update(['mode' => $mode, 'status' => $status]);
                $response = $this->get('/payments/'.$payment->reference);
                if (($mode === 'live' && $status === 'paid') || $status === 'test_paid') {
                    $response->assertRedirect(route('dashboard'));
                } else {
                    $response->assertOk()->assertSee('Check payment status');
                }
            }
        }
    }

    private function signed(array $data): string
    {
        $data['hash'] = app(PaynowGateway::class)->signature($data);

        return http_build_query($data);
    }
}
