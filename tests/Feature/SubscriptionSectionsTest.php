<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_sections_show_only_the_owners_payment_history(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$owner, $other] as $user) {
            BillingPayment::create(['user_id' => $user->id, 'reference' => 'history-user-'.$user->id,
                'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
                'provider' => 'paystack', 'mode' => 'test', 'status' => 'test_paid', 'currency' => 'ZAR',
                'amount_cents' => 7795, 'credits' => 150, 'billing_interval' => 'monthly', 'paid_at' => now()]);
        }
        $this->actingAs($owner)->get('/subscription')->assertOk()
            ->assertSee('id="active-plan"', false)->assertSee('id="plans"', false)
            ->assertSee('id="cards"', false)->assertSee('id="payment-history"', false)
            ->assertSee(route('billing.show', 'history-user-'.$owner->id))
            ->assertDontSee(route('billing.show', 'history-user-'.$other->id))
            ->assertSee('Test payment confirmed')->assertSee('ZAR 77.95')->assertSee('SAST');
    }

    public function test_active_plan_has_subscription_dates_and_empty_history_is_clear(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        $user->subscriptions()->create(['subscription_plan_id' => SubscriptionPlan::where('slug', 'annual')->firstOrFail()->id,
            'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        $this->actingAs($user)->get('/subscription')->assertOk()->assertSee('Active subscription')
            ->assertSee('Paid access until')->assertSee('2400 credits per paid year.')
            ->assertSee('No payments yet.')->assertSee('Card details are not stored or displayed');
    }
}
