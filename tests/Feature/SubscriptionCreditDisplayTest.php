<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\User;
use App\Services\AiCreditService;
use App\Services\Billing\PaynowGateway;
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
        return ['Monthly' => ['monthly', 'Monthly', 150, 475], 'Pro' => ['pro', 'Pro', 225, 650], 'Annual' => ['annual', 'Annual', 2400, 4740]];
    }

    #[DataProvider('plans')]
    public function test_successful_subscription_shows_correct_credits_and_deducts_usage(string $slug, string $name, int $credits, int $amount): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        config(['billing.paynow.enabled' => true, 'billing.paynow.mode' => 'live',
            'billing.paynow.integration_id' => '123', 'billing.paynow.integration_key' => 'fixture',
            'billing.paynow.live_currency_confirmed' => true]);
        $user = User::factory()->create();
        Http::preventStrayRequests();
        $this->mock(PaynowGateway::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturn('https://www.paynow.co.zw/Payment/fixture');
            $mock->shouldReceive('status')->andReturn('paid');
        });
        $this->actingAs($user)->post(route('billing.start', ['provider' => 'paynow', 'plan' => $slug]), ['billing_consent' => 1])
            ->assertRedirect('https://www.paynow.co.zw/Payment/fixture');
        $payment = BillingPayment::where('user_id', $user->id)->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, app(AiCreditService::class)->wallet($user)['purchased_credits']);

        $this->get(route('billing.show', $payment->reference))->assertRedirect(route('dashboard'))
            ->assertSessionHas('payment_success', fn ($message) => str_contains($message, $credits.' credits'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Current plan: '.$name)->assertSee($credits.' credits')
            ->assertSee('<strong x-text="available">'.$credits.'</strong>', false);
        $this->getJson(route('ai.credits'))->assertOk()->assertJson(['credits' => $credits, 'purchased_credits' => $credits]);
        $this->get(route('subscription'))->assertOk()->assertSee('Your current plan is '.$name)->assertSee($credits.' AI credits');
        $this->get(route('account.activity'))->assertOk()->assertSee('Paid')->assertSee($payment->reference);
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
