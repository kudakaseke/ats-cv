<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackPlansTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_catalog_has_correct_prices_and_annual_total(): void
    {
        $this->assertSame(['Free', 'Monthly', 'Annual'], SubscriptionPlan::where('is_active', true)->orderBy('price_cents')->pluck('name')->all());
        $annual = SubscriptionPlan::where('slug', 'annual')->firstOrFail();
        $this->assertSame(4740, $annual->price_cents);
        $this->assertSame('3.95', $annual->displayPrice());
        $this->get('/')->assertOk()->assertSee('$3.95')->assertSee('US$47.40')->assertSee('21%')->assertSee('ZAR');
    }

    public function test_missing_amounts_do_not_contact_paystack(): void
    {
        config(['paystack.monthly_amount' => null, 'paystack.annual_amount' => null]);
        Http::preventStrayRequests();
        $this->artisan('paystack:sync-plans')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_hosted_test_checkout_links_are_only_shown_in_local_development(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->app->instance('env', 'local');
        $this->actingAs($user)->get('/subscription/monthly/payment-method')->assertOk()
            ->assertSee('https://paystack.shop/pay/rehfdbdk33', false)
            ->assertSee('ZAR 83.00')->assertSee('Test checkout');
        $this->get('/subscription/annual/payment-method')->assertOk()
            ->assertSee('https://paystack.shop/pay/5l1ff9py-9', false)->assertSee('ZAR 780.00');
        $this->app->instance('env', 'production');
        $this->get('/subscription/monthly/payment-method')->assertOk()
            ->assertDontSee('https://paystack.shop/pay/rehfdbdk33', false)
            ->assertDontSee('https://paystack.shop/pay/5l1ff9py-9', false);
        $this->get('/')->assertOk()->assertDontSee('https://paystack.shop/pay/', false);
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
                return Http::response(['status' => true, 'data' => array_merge($request->data(), ['plan_code' => 'PLN_'.$request['interval']])]);
            }
            if (str_contains($request->url(), '/plan/PLN_')) {
                $annual = str_ends_with($request->url(), 'annually');

                return Http::response(['status' => true, 'data' => ['plan_code' => $annual ? 'PLN_annually' : 'PLN_monthly', 'amount' => $annual ? 85320 : 9000, 'currency' => 'ZAR', 'interval' => $annual ? 'annually' : 'monthly']]);
            }

            return Http::response(['status' => true, 'data' => [], 'meta' => ['pageCount' => 1]]);
        });
        $this->artisan('paystack:sync-plans')->assertSuccessful();
        $this->artisan('paystack:sync-plans')->assertSuccessful();
        $posts = Http::recorded(fn ($request) => $request->method() === 'POST');
        $this->assertCount(2, $posts);
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

    private function configure(): void
    {
        // Test fixtures only: these are not approved production rand prices.
        config(['paystack.secret_key' => 'sk_test_fixture', 'paystack.monthly_amount' => '9000', 'paystack.annual_amount' => '85320']);
    }
}
