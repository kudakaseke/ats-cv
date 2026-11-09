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
            'billing.paynow.live_currency_confirmed' => true,
            'billing.paynow.mode' => 'live', 'billing.paynow.callback_url' => null,
        ]);
    }

    public function test_checkout_return_rechecks_recent_pending_status_and_redirects_after_verified_payment(): void
    {
        config(['performance.queue_payment_checks' => false]);
        foreach (['test', 'live'] as $mode) {
            $payment = $this->payment(mode: $mode);
            $payment->update(['verified_at' => now(), 'provider_status' => 'Created', 'credits' => 150]);
            $user = User::findOrFail($payment->user_id);
            Http::swap(new Factory);
            Http::fake(['*' => Http::response($this->signed(['reference' => $payment->reference,
                'amount' => '5.00', 'paynowreference' => 'PN'.$payment->id, 'status' => 'Paid']))]);
            $url = route('billing.paynow.return', $payment->reference);
            $this->actingAs($user)->get($url)->assertRedirect(route('dashboard'))->assertSessionHas('payment_success');
            $this->get($url)->assertRedirect(route('dashboard'));
            Http::assertSentCount(1);
            $this->assertSame($mode === 'live' ? 'paid' : 'test_paid', $payment->fresh()->status);
            $this->assertSame($mode === 'live' ? 150 : 15, app(\App\Services\AiCreditService::class)->wallet($user)['credits']);
            $this->assertSame($mode === 'live' ? 'monthly' : 'free', app(\App\Services\PlanService::class)->current($user)->slug);
            $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        }
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_checkout_return_does_not_trust_browser_success_parameters(): void
    {
        $payment = $this->payment();
        $payment->update(['verified_at' => now(), 'provider_status' => 'Created']);
        config(['performance.queue_payment_checks' => false]);
        Http::fake(['*' => Http::response($this->signed(['reference' => $payment->reference,
            'amount' => '5.00', 'paynowreference' => 'PN'.$payment->id, 'status' => 'Created']))]);
        $this->actingAs(User::findOrFail($payment->user_id))->get(route('billing.paynow.return', $payment->reference).'?status=Paid')
            ->assertOk()->assertSee('Confirming your payment');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_paynow_local_test_uses_https_callbacks_and_never_grants_live_access(): void
    {
        config(['billing.paynow.mode' => 'test', 'billing.paynow.callback_url' => 'https://example.trycloudflare.com']);
        Http::fake(['*' => Http::response($this->signed(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/test', 'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=test']))]);
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/test');
        Http::assertSent(fn ($request) => str_starts_with($request['resulturl'], 'https://example.trycloudflare.com/billing/paynow/notify/') && str_starts_with($request['returnurl'], 'https://example.trycloudflare.com/billing/paynow/return/') && ! isset($request['authemail']));
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
        $body = $this->signed(['reference' => $payment->reference, 'amount' => '5.00', 'paynowreference' => 'PN123', 'status' => 'Paid', 'pollurl' => 'http://127.0.0.1/private']);
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

    public function test_disabled_provider_and_missing_consent_do_not_create_payments(): void
    {
        config(['billing.paynow.enabled' => false]);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create())->post('/billing/paynow/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $this->post('/billing/paynow/monthly')->assertSessionHasErrors('billing_consent');
        $this->assertDatabaseCount('billing_payments', 0);
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
        foreach (['test', 'live'] as $mode) {
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
