<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HostedPaystackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
        config(['paystack.secret_key' => 'sk_test_fixture', 'paystack.local_test_credits' => true]);
        $this->app->instance('env', 'local');
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function receipt(User $user, string $slug = 'monthly', string $status = 'success'): array
    {
        return ['id' => 90001, 'reference' => 'hosted-fixture', 'status' => $status, 'domain' => 'test',
            'amount' => config('paystack.test_payment_pages.'.$slug.'.amount_cents'), 'currency' => 'ZAR',
            'paid_at' => now()->toIso8601String(), 'created_at' => now()->subMinute()->toIso8601String(),
            'customer' => ['email' => $user->email, 'customer_code' => 'CUS_fixture'],
            'metadata' => ['referrer' => config('paystack.test_payment_pages.'.$slug.'.url')]];
    }

    private function fake(array $data, string $slug = 'monthly'): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => $data]),
            'api.paystack.co/page/*' => Http::response(['status' => true, 'data' => [
                'slug' => basename(config('paystack.test_payment_pages.'.$slug.'.url')),
                'plan' => ['plan_code' => 'PLN_hosted', 'domain' => 'test', 'currency' => 'ZAR',
                    'amount' => config('paystack.test_payment_pages.'.$slug.'.amount_cents'),
                    'interval' => $slug === 'annual' ? 'annually' : 'monthly'],
            ]]),
        ]);
    }

    public function test_success_redirects_to_dashboard_and_callback_webhook_retries_grant_once(): void
    {
        $user = User::factory()->create();
        $data = $this->receipt($user);
        $this->fake($data);
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'))->assertSessionHas('payment_success');
        $this->assertSame(150, app(AiCreditService::class)->wallet($user)['credits']);
        $this->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'));
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $data['reference']]]);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, config('paystack.secret_key'))], $body)->assertOk();
        $this->assertSame(150, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('paystack_receipts', 1);
    }

    public function test_annual_success_adds_2400_credits_upfront(): void
    {
        $user = User::factory()->create();
        $this->fake($this->receipt($user, 'annual'), 'annual');
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'));
        $this->assertSame(2400, app(AiCreditService::class)->wallet($user)['credits']);
        $this->travel(32)->days();
        $this->assertSame(2400, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_monthly_purchase_adds_to_existing_annual_balance_once(): void
    {
        $user = User::factory()->create();
        $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'annual')->value('id'),
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        $this->assertSame(2400, app(AiCreditService::class)->wallet($user)['credits']);
        $this->fake($this->receipt($user));
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'));
        $this->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'));
        $wallet = app(AiCreditService::class)->wallet($user);
        $this->assertSame(2550, $wallet['credits']);
        $this->assertSame(150, $wallet['topup_credits']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('billing_payments', ['hosted_reference' => 'hosted-fixture', 'kind' => 'topup', 'credits' => 150]);
        $this->travel(32)->days();
        $this->assertSame(2550, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_declined_card_adds_no_credits_and_shows_failure(): void
    {
        $user = User::factory()->create();
        $this->fake($this->receipt($user, 'monthly', 'failed'));
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'))->assertSessionHasErrors('billing');
        $this->get(route('dashboard'))->assertSee('Payment was declined or has not completed. No credits were added.');
        $this->assertSame(15, app(AiCreditService::class)->wallet($user)['credits']);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('paystack_receipts', 0);
    }

    public function test_wrong_account_cannot_claim_payment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->fake($this->receipt($owner));
        $this->actingAs($other)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'))->assertSessionHasErrors('billing');
        $this->get(route('dashboard'))->assertSee('Do not pay again.');
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_hosted_plan_links_remain_hidden_while_paynow_is_the_visible_option(): void
    {
        config(['paystack.enabled' => true]);
        $user = User::factory()->create(['email' => 'checkout+member@example.com']);
        foreach (['monthly', 'pro', 'annual'] as $slug) {
            $expected = config('paystack.test_payment_pages.'.$slug.'.url').'?'.http_build_query([
                'email' => $user->email, 'read-only' => 'email',
            ], '', '&', PHP_QUERY_RFC3986);
            $this->actingAs($user)->get(route('subscription.payment-methods', $slug))->assertOk()
                ->assertDontSee(e($expected), false)->assertDontSee('Subscribe with Paystack')->assertSee('Paynow');
        }
    }

    public function test_wrong_amount_cannot_claim_payment(): void
    {
        $user = User::factory()->create();
        $data = $this->receipt($user);
        $data['amount'] = 1;
        $this->fake($data);
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'))->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('paystack_receipts', 0);
        $this->assertSame(15, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_abandoned_payment_adds_no_credits(): void
    {
        $user = User::factory()->create();
        $this->fake($this->receipt($user, 'monthly', 'abandoned'));
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'))->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertSame(15, app(AiCreditService::class)->wallet($user)['credits']);
    }

    public function test_signed_webhook_can_credit_pro_before_browser_returns(): void
    {
        $user = User::factory()->create();
        $this->fake($this->receipt($user, 'pro'), 'pro');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => 'hosted-fixture']]);
        $this->call('POST', '/webhooks/paystack', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, config('paystack.secret_key'))], $body)->assertOk();
        $this->assertSame(225, app(AiCreditService::class)->wallet($user)['credits']);
        $this->actingAs($user)->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('paystack_receipts', 1);
    }

    public function test_guest_callback_requires_login_and_preserves_reference(): void
    {
        $this->get('/paystack/callback?reference=hosted-fixture')->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', url('/paystack/callback?reference=hosted-fixture'));
    }
}
