<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\PaypalSubscriptions;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Srmklive\PayPal\Services\PayPal;
use Tests\TestCase;

class PaypalSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.paypal.mode' => 'live', 'billing.paypal.enabled' => true, 'billing.paypal.client_id' => 'client',
            'billing.paypal.client_secret' => 'secret', 'billing.paypal.plans.monthly' => 'P-MONTHLY']);
    }

    public function test_activation_alone_never_grants_access(): void
    {
        $payment = $this->payment();
        $this->fake($payment, []);
        app(PaypalSubscriptions::class)->refresh($payment);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_checkout_creates_an_automatic_subscription_not_a_one_time_order(): void
    {
        $sdk = \Mockery::mock(PayPal::class);
        $sdk->shouldReceive('setApiCredentials')->once()->withArgs(fn ($config) => $config['mode'] === 'live' && $config['live']['client_id'] === 'client' && $config['validate_ssl'] === true);
        $sdk->shouldReceive('getAccessToken')->once()->andReturn(['access_token' => 'token']);
        $sdk->shouldReceive('withIdempotencyKey')->once()->withArgs(fn ($key) => Str::isUuid($key))->andReturnSelf();
        $sdk->shouldReceive('createSubscription')->once()->withArgs(fn ($data) => $data['plan_id'] === 'P-MONTHLY' && str_contains($data['application_context']['return_url'], '/paypal/success?reference='))
            ->andReturn(['id' => 'I-NEW123', 'links' => [['rel' => 'approve', 'href' => 'https://www.paypal.com/approve']]]);
        $this->app->instance(PayPal::class, $sdk);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v1/billing/plans/P-MONTHLY' => Http::response(['id' => 'P-MONTHLY', 'status' => 'ACTIVE', 'billing_cycles' => [[
                'tenure_type' => 'REGULAR', 'total_cycles' => 0, 'frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '4.75', 'currency_code' => 'USD']],
            ]]]),
            '*/v1/billing/subscriptions' => Http::response(['id' => 'I-NEW123', 'links' => [['rel' => 'approve', 'href' => 'https://www.paypal.com/approve']]]),
        ]);
        $this->actingAs(User::factory()->create())->post('/paypal/checkout', ['plan' => 'monthly', 'billing_consent' => 1, 'amount' => '100.00'])->assertRedirect('https://www.paypal.com/approve');
        $this->assertTrue(BillingPayment::sole()->recurring);
        $this->assertSame(475, BillingPayment::sole()->amount_cents);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/checkout/orders'));
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_wrong_annual_plan_price_is_rejected_before_subscription_creation(): void
    {
        config(['billing.paypal.plans.annual' => 'P-ANNUAL']);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v1/billing/plans/P-ANNUAL' => Http::response(['id' => 'P-ANNUAL', 'status' => 'ACTIVE', 'billing_cycles' => [[
                'tenure_type' => 'REGULAR', 'total_cycles' => 0, 'frequency' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
                'pricing_scheme' => ['fixed_price' => ['value' => '3.95', 'currency_code' => 'USD']],
            ]]]),
        ]);
        $this->actingAs(User::factory()->create())->post('/billing/paypal/annual', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/billing/subscriptions'));
        $this->assertSame('SETUP_FAILED', BillingPayment::sole()->recurring_status);
    }

    public function test_successful_renewals_are_idempotent_and_have_separate_terms(): void
    {
        $payment = $this->payment();
        $transaction = $this->transaction('TX1');
        $this->fake($payment, [$transaction]);
        app(PaypalSubscriptions::class)->refresh($payment);
        app(PaypalSubscriptions::class)->refresh($payment->fresh());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->travel(1)->months();
        $this->fake($payment, [$this->transaction('TX2')]);
        app(PaypalSubscriptions::class)->refresh($payment->fresh());
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('paypal_subscription_receipts', 2);
        $this->assertTrue(Subscription::latest('id')->first()->ends_at->equalTo(now()->startOfSecond()->addMonthNoOverflow()));
    }

    public function test_sandbox_does_not_grant_live_access(): void
    {
        config(['billing.paypal.mode' => 'sandbox']);
        $payment = $this->payment('sandbox');
        $this->fake($payment, [$this->transaction('TX1')]);
        app(PaypalSubscriptions::class)->refresh($payment);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertSame('test_paid', $payment->fresh()->status);
    }

    public function test_wrong_amount_and_wrong_custom_reference_are_rejected(): void
    {
        $payment = $this->payment();
        $transaction = $this->transaction('TX1');
        $transaction['amount_with_breakdown']['gross_amount']['value'] = '0.01';
        $this->fake($payment, [$transaction]);
        $this->actingAs(User::find($payment->user_id))->post('/payments/'.$payment->reference.'/check')->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_refund_revokes_only_its_receipt_and_cannot_be_replayed_as_paid(): void
    {
        $payment = $this->payment();
        $this->fake($payment, [$this->transaction('TX1')]);
        app(PaypalSubscriptions::class)->refresh($payment);
        $tx = $this->transaction('TX1');
        $tx['status'] = 'REFUNDED';
        $this->fake($payment, [$tx]);
        app(PaypalSubscriptions::class)->refresh($payment->fresh());
        $this->fake($payment, [$this->transaction('TX1')]);
        app(PaypalSubscriptions::class)->refresh($payment->fresh());
        $this->assertSame('cancelled', Subscription::sole()->status);
        $this->assertSame('REFUNDED', DB::table('paypal_subscription_receipts')->sole()->status);
    }

    public function test_cancellation_preserves_paid_access_and_other_users_cannot_cancel(): void
    {
        $payment = $this->payment();
        $this->fake($payment, [$this->transaction('TX1')]);
        app(PaypalSubscriptions::class)->refresh($payment);
        $end = Subscription::sole()->ends_at;
        $this->actingAs(User::factory()->create())->post('/payments/'.$payment->reference.'/cancel-paypal')->assertNotFound();
        $this->actingAs(User::find($payment->user_id))->post('/payments/'.$payment->reference.'/cancel-paypal')->assertRedirect('/subscription');
        $this->assertSame('CANCELLED', $payment->fresh()->recurring_status);
        $this->assertSame('active', Subscription::sole()->status);
        $this->assertTrue($end->equalTo(Subscription::sole()->ends_at));
    }

    public function test_open_agreement_blocks_duplicate_subscriptions_and_account_deletion(): void
    {
        $payment = $this->payment();
        Http::preventStrayRequests();
        $this->actingAs(User::find($payment->user_id))->post('/billing/paypal/monthly', ['billing_consent' => 1])->assertSessionHasErrors('billing');
        $this->delete('/profile', ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertDatabaseCount('billing_payments', 1);
        Http::assertNothingSent();
    }

    public function test_setup_preview_does_not_contact_paypal(): void
    {
        Http::preventStrayRequests();
        $this->artisan('paypal:setup-plans --dry-run')->assertSuccessful();
        Http::assertNothingSent();
    }

    private function payment(string $mode = 'live'): BillingPayment
    {
        $payment = BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => User::factory()->create()->id,
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->first()->id,
            'provider' => 'paypal', 'mode' => $mode, 'amount_cents' => 500, 'currency' => 'USD', 'billing_interval' => 'monthly',
            'recurring' => true, 'provider_reference' => 'I-TEST123', 'provider_plan_id' => 'P-MONTHLY', 'recurring_status' => 'ACTIVE']);
        $payment->forceFill(['created_at' => now()->subDay()])->save();

        return $payment;
    }

    private function transaction(string $id): array
    {
        return ['id' => $id, 'status' => 'COMPLETED', 'time' => now()->toIso8601ZuluString(),
            'amount_with_breakdown' => ['gross_amount' => ['currency_code' => 'USD', 'value' => '5.00']]];
    }

    private function fake(BillingPayment $payment, array $transactions): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            '*/v1/billing/subscriptions/I-TEST123' => Http::response(['id' => 'I-TEST123', 'plan_id' => 'P-MONTHLY', 'custom_id' => $payment->reference, 'status' => 'ACTIVE']),
            '*/v1/billing/subscriptions/I-TEST123/transactions*' => Http::response(['transactions' => $transactions]),
            '*/v1/billing/subscriptions/I-TEST123/cancel' => Http::response('', 204),
        ]);
    }
}
