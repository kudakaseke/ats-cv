<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaymentService;
use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaynowDayCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function signed(array $fields, string $key): string
    {
        $fields['hash'] = strtoupper(hash('sha512', implode('', $fields).$key));

        return http_build_query($fields);
    }

    public function test_unpaid_cancelled_and_awaiting_delivery_do_not_grant_day_credits(): void
    {
        app()->instance('env', 'local');
        config(['app.env' => 'local', 'billing.paynow.mode' => 'test', 'billing.paynow.local_test_credits' => true,
            'billing.paynow.day_site.integration_id' => 'day-id', 'billing.paynow.day_site.integration_key' => 'day-key']);
        $this->seed(SubscriptionPlanSeeder::class);
        $plan = SubscriptionPlan::create(['slug' => 'day-test', 'name' => 'One-day test', 'price_cents' => 10,
            'currency' => 'USD', 'billing_interval' => 'daily', 'is_active' => false, 'features' => []]);
        foreach (['Created' => 'pending', 'Cancelled' => 'cancelled', 'Awaiting Delivery' => 'pending'] as $providerStatus => $expected) {
            $payment = BillingPayment::create(['reference' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => User::factory()->create()->id,
                'subscription_plan_id' => $plan->id, 'provider' => 'paynow', 'mode' => 'test', 'currency' => 'USD',
                'amount_cents' => 10, 'billing_interval' => 'daily', 'credits' => 15,
                'poll_url' => 'https://www.paynow.co.zw/interface/checkpayment?guid=day']);
            $responses[] = $this->signed(['reference' => $payment->reference, 'amount' => '0.10',
                'paynowreference' => 'DAY'.$payment->id, 'status' => $providerStatus], 'day-key');
            $payments[] = [$payment, $expected];
        }
        $sequence = Http::sequence();
        foreach ($responses as $response) {
            $sequence->push($response);
        }
        Http::fake(['*' => $sequence]);
        foreach ($payments as [$payment, $expected]) {
            app(PaymentService::class)->refresh($payment);
            $this->assertSame($expected, $payment->fresh()->status);
        }
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseMissing('ai_credit_transactions', ['type' => 'subscription_allocation']);
    }

    public function test_day_checkout_uses_its_own_key_and_grants_once_only_after_verification(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        app()->instance('env', 'local');
        config(['app.env' => 'local', 'billing.paynow.enabled' => true, 'billing.paynow.mode' => 'test',
            'billing.paynow.local_test_credits' => true, 'billing.paynow.integration_id' => 'other-id',
            'billing.paynow.integration_key' => 'other-key', 'billing.paynow.day_site.integration_id' => 'day-id',
            'billing.paynow.day_site.integration_key' => 'day-key', 'billing.paynow.day_site.test_email' => 'merchant@example.com',
            'billing.paynow.day_site.callback_url' => 'https://callbacks.example.com', 'performance.queue_payment_checks' => false]);
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'initiatetransaction')) {
                return Http::response($this->signed(['status' => 'Ok', 'browserurl' => 'https://www.paynow.co.zw/Payment/test',
                    'pollurl' => 'https://www.paynow.co.zw/interface/checkpayment?guid=day'], 'day-key'));
            }

            return Http::response($this->signed(['reference' => BillingPayment::sole()->reference, 'amount' => '0.10',
                'paynowreference' => 'DAY123', 'status' => 'Paid'], 'day-key'));
        });
        $this->actingAs($user)->post('/billing/paynow/day-test', ['billing_consent' => 1])->assertRedirect('https://www.paynow.co.zw/Payment/test');
        $payment = BillingPayment::sole();
        $this->assertSame(10, $payment->amount_cents);
        $this->assertDatabaseCount('subscriptions', 0);
        Http::assertSent(fn ($request) => $request['id'] === 'day-id' && $request['amount'] === '0.10'
            && $request['resulturl'] === 'https://callbacks.example.com/billing/paynow/notify/'.$payment->reference
            && parse_url($request['returnurl'], PHP_URL_PATH) === '/billing/paynow/return/'.$payment->reference);
        $payload = ['reference' => $payment->reference, 'amount' => '0.10', 'paynowreference' => 'DAY123', 'status' => 'Paid'];
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], [], $this->signed($payload, 'other-key'))->assertBadRequest();
        $this->assertDatabaseCount('subscriptions', 0);
        $body = $this->signed($payload, 'day-key');
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], [], $body)->assertOk();
        $this->call('POST', '/billing/paynow/notify/'.$payment->reference, [], [], [], [], $body)->assertOk();
        $this->assertDatabaseCount('subscriptions', 1);
        $period = $user->subscriptions()->sole();
        $this->assertTrue($period->ends_at->equalTo($period->starts_at->copy()->addDay()));
        $this->assertSame(15, app(AiCreditService::class)->wallet($user)['subscription_credits']);
        $this->assertSame('test_paid', $payment->fresh()->status);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true, 'role' => 'super_admin', 'account_status' => 'active'])->save();
        $this->actingAs($admin, 'admin')->withSession(['admin_2fa_verified_at' => now()->timestamp])
            ->get(route('admin.users.payments', $user->id))->assertOk()->assertSee($payment->reference)->assertSee('USD 0.10');
    }
}
