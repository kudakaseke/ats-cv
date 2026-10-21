<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AiCreditService;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SubscriptionCreditDisplayTest extends TestCase
{
    use RefreshDatabase;

    public static function plans(): array
    {
        return ['Monthly' => ['monthly', 'Monthly', 150, 7795], 'Pro' => ['pro', 'Pro', 225, 10256], 'Annual' => ['annual', 'Annual', 150, 77783]];
    }

    #[DataProvider('plans')]
    public function test_successful_subscription_shows_correct_credits_and_deducts_usage(string $slug, string $name, int $credits, int $amount): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $this->app->instance('env', 'local');
        config(['paystack.enabled' => true, 'paystack.secret_key' => 'sk_test_subscription_fixture', 'paystack.local_test_credits' => true]);
        $user = User::factory()->create();
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();
        $plan->update(['paystack_plan_code' => 'PLN_'.$slug, 'paystack_mode' => 'test', 'paystack_amount_cents' => $amount]);
        Http::preventStrayRequests();
        Http::fake([
            'api.paystack.co/plan/*' => Http::response(['status' => true, 'data' => ['plan_code' => 'PLN_'.$slug, 'domain' => 'test', 'currency' => 'ZAR', 'amount' => $amount, 'interval' => $plan->billing_interval]]),
            'api.paystack.co/transaction/initialize' => function ($request) {
                return Http::response(['data' => ['reference' => $request['reference'], 'authorization_url' => 'https://checkout.paystack.com/test-subscription-fixture']]);
            },
        ]);
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf])->actingAs($user)->post(route('paystack.subscribe', $slug), ['billing_consent' => 1, '_token' => $csrf])->assertRedirect('https://checkout.paystack.com/test-subscription-fixture');
        $payment = BillingPayment::where('user_id', $user->id)->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, app(AiCreditService::class)->wallet($user)['purchased_credits']);

        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'id' => 700001, 'reference' => $payment->reference, 'domain' => 'test', 'status' => 'success',
            'currency' => 'ZAR', 'amount' => $amount, 'plan' => 'PLN_'.$slug, 'paid_at' => now()->toIso8601String(),
            'customer' => ['customer_code' => 'CUS_TEST_SUBSCRIPTION', 'email' => $user->email],
        ]])]);
        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'))
            ->assertSessionHas('payment_success', fn ($message) => str_contains($message, $credits.' credits'));
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Current plan:')->assertSee($credits.' credits')
            ->assertSee('credits: '.$credits, false);
        $this->getJson(route('ai.credits'))->assertOk()->assertJson(['credits' => $credits, 'purchased_credits' => $credits]);
        $this->get(route('subscription'))->assertOk()->assertSee('Your current plan is '.$name)->assertSee($credits.' AI credits');
        $this->get(route('account.activity'))->assertOk()->assertSee('Test payment confirmed')->assertSee($payment->reference);
        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('subscriptions', 1);

        $service = app(AiCreditService::class);
        foreach (['analyse', 'revamp'] as $feature) {
            $operation = $service->reserve($user, $feature, (string) Str::uuid(), ['fixture' => true]);
            $service->finish($user, $operation->id, ['complete' => true]);
        }
        $this->getJson(route('ai.credits'))->assertJson(['credits' => $credits - 15, 'purchased_credits' => $credits - 15]);
        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'));
        $this->getJson(route('ai.credits'))->assertJson(['purchased_credits' => $credits - 15]);
    }
}
