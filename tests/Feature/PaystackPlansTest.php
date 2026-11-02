<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaystackPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_local_test_purchase_without_renewal_does_not_block_checkout(): void
    {
        $this->app->instance('env', 'local');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['paystack.enabled' => true, 'paystack.secret_key' => 'sk_test_fixture', 'paystack.local_test_credits' => true, 'paystack.currency' => 'ZAR']);
        $user = User::factory()->create();
        $plan = SubscriptionPlan::where('slug', 'monthly')->firstOrFail();
        $plan->update(['paystack_plan_code' => 'PLN_fixture', 'paystack_amount_cents' => 9000, 'paystack_mode' => 'test']);
        $payment = BillingPayment::create(['reference' => (string) Str::uuid(), 'user_id' => $user->id,
            'subscription_plan_id' => $plan->id, 'provider' => 'paystack', 'mode' => 'test', 'status' => 'test_paid',
            'amount_cents' => 9000, 'currency' => 'ZAR', 'billing_interval' => 'monthly', 'recurring' => true]);
        Http::fake(function ($request) {
            return Http::response(['status' => true, 'data' => $request->method() === 'GET'
                ? ['plan_code' => 'PLN_fixture', 'domain' => 'test', 'amount' => 9000, 'currency' => 'ZAR', 'interval' => 'monthly']
                : ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/fixture']]);
        });
        $this->actingAs($user)->get(route('subscription'))->assertOk()->assertDontSee('Pay securely with Paystack.')->assertDontSee('Cancel automatic renewal');
        $this->post(route('paystack.subscribe', 'monthly'), ['billing_consent' => 1])->assertRedirect('https://checkout.paystack.com/fixture');
        $this->assertDatabaseCount('billing_payments', 2);

        // Retry resumes the existing checkout without creating another payment.
        $this->post(route('paystack.subscribe', 'monthly'), ['billing_consent' => 1])->assertRedirect('https://checkout.paystack.com/fixture');
        $this->assertDatabaseCount('billing_payments', 2);
        Http::assertSentCount(2);
        $this->assertNull($payment->fresh()->recurring_status);
        BillingPayment::where('id', '!=', $payment->id)->delete();
        $payment->update(['provider_reference' => 'SUB_fixture']);
        $this->post(route('paystack.subscribe', 'monthly'), ['billing_consent' => 1])->assertRedirect(route('subscription.payment-methods', 'monthly'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('billing_payments', 1);
        $payment->update(['provider_reference' => null]);
        $this->app->instance('env', 'production');
        $this->post(route('paystack.subscribe', 'monthly'), ['billing_consent' => 1])->assertRedirect(route('subscription.payment-methods', 'monthly'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('billing_payments', 1);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_catalog_has_correct_prices_and_annual_total(): void
    {
        $this->assertSame(['Free', 'Monthly', 'Pro', 'Annual'], SubscriptionPlan::where('is_active', true)->orderBy('price_cents')->pluck('name')->all());
        $annual = SubscriptionPlan::where('slug', 'annual')->firstOrFail();
        $this->assertSame(4740, $annual->price_cents);
        $this->assertSame('3.95', $annual->displayPrice());
        $this->get('/pricing')->assertOk()->assertSee('$3.95')->assertSee('US$47.40')->assertSee('2,400 credits')->assertSee('exact charge and currency');
    }

    public function test_missing_amounts_do_not_contact_paystack(): void
    {
        config(['paystack.monthly_amount' => null, 'paystack.annual_amount' => null]);
        Http::preventStrayRequests();
        $this->artisan('paystack:sync-plans')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_untracked_hosted_links_are_never_used_for_credit_purchases(): void
    {
        $user = User::factory()->create();
        config(['paystack.enabled' => true, 'paystack.secret_key' => null]);
        foreach (['local', 'production'] as $environment) {
            $this->app->instance('env', $environment);
            $this->actingAs($user)->get('/subscription/monthly/payment-method')->assertOk()
                ->assertDontSee('https://paystack.shop/pay/', false)->assertDontSee('Paystack - unavailable')->assertSee('Paynow');
        }
        $this->app->instance('env', 'testing');
        Http::preventStrayRequests();
        $this->post(route('paystack.subscribe', 'monthly'), ['billing_consent' => 1])
            ->assertRedirect(route('subscription'))->assertSessionHasErrors('billing');
        Http::assertNothingSent();
        $this->assertDatabaseCount('billing_payments', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_dry_run_does_not_create_plans(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        $this->artisan('paystack:sync-plans --dry-run')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_sync_creates_only_paid_plans_in_zar_and_reuses_stored_codes(): void
    {
        $this->configure();
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => true, 'data' => array_merge($request->data(), ['plan_code' => 'PLN_'.$request['amount']])]);
            }
            if (str_contains($request->url(), '/plan/PLN_')) {
                $amount = (int) substr($request->url(), strrpos($request->url(), '_') + 1);
                $annual = $amount === 85320;

                return Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_'.$amount, 'amount' => $amount, 'currency' => 'ZAR', 'interval' => $annual ? 'annually' : 'monthly']]);
            }

            return Http::response(['status' => true, 'data' => [], 'meta' => ['pageCount' => 1]]);
        });
        $this->artisan('paystack:sync-plans')->assertSuccessful();
        $this->artisan('paystack:sync-plans')->assertSuccessful();
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST');
        $this->assertCount(3, $posts);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['interval'] === 'annually' && $request['amount'] === 85320 && $request['currency'] === 'ZAR');
        $this->assertNull(SubscriptionPlan::where('slug', 'free')->first()->paystack_plan_code);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_sync_does_not_change_an_existing_remote_price(): void
    {
        $this->configure();
        SubscriptionPlan::where('slug', 'monthly')->update(['paystack_plan_code' => 'PLN_old', 'paystack_mode' => 'test']);
        Http::fake(['*' => Http::response(['status' => true, 'data' => ['amount' => 1, 'currency' => 'ZAR', 'interval' => 'monthly']])]);
        $this->artisan('paystack:sync-plans')->assertFailed();
        Http::assertSentCount(1);
    }

    public function test_replacement_selects_new_plans_without_modifying_old_remote_plans(): void
    {
        $this->configure();
        SubscriptionPlan::where('slug', 'monthly')->update(['paystack_plan_code' => 'PLN_old', 'paystack_mode' => 'test']);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/plan/PLN_old')) {
                return Http::response(['status' => true, 'data' => ['amount' => 1, 'currency' => 'ZAR', 'interval' => 'monthly']]);
            }
            if ($request->method() === 'POST') {
                return Http::response(['status' => true, 'data' => [...$request->data(), 'plan_code' => 'PLN_new_'.$request['amount']]]);
            }

            return Http::response(['status' => true, 'data' => [], 'meta' => ['pageCount' => 1]]);
        });
        $this->artisan('paystack:sync-plans --replace-mismatched')->assertSuccessful();
        $this->assertSame('PLN_new_9000', SubscriptionPlan::where('slug', 'monthly')->first()->paystack_plan_code);
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true));
    }

    private function configure(): void
    {
        // Test fixtures only: these are not approved production rand prices.
        config(['paystack.secret_key' => 'sk_test_fixture', 'paystack.pro_amount' => '12000', 'paystack.monthly_amount' => '9000', 'paystack.annual_amount' => '85320']);
    }
}
