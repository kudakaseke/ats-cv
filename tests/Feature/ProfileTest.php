<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_account_email_cannot_be_changed_with_a_direct_request(): void
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;
        $verifiedAt = $user->email_verified_at;
        $this->actingAs($user)->patchJson('/profile', ['name' => 'Test User', 'email' => 'changed@example.invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($originalEmail, $user->fresh()->email);
        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_verified_at));
        $this->patch('/profile', ['name' => 'New Name'])->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertSame($originalEmail, $user->fresh()->email);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_disabled_paystack_renewal_does_not_block_account_deletion(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        BillingPayment::create(['user_id' => $user->id, 'reference' => 'disabled-renewal-test',
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
            'provider' => 'paystack', 'mode' => 'test', 'status' => 'test_paid', 'currency' => 'ZAR',
            'amount_cents' => 7795, 'credits' => 150, 'billing_interval' => 'monthly', 'recurring' => true, 'recurring_status' => 'DISABLED']);
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/');
        $this->assertNull($user->fresh());
    }

    public function test_active_paystack_renewal_still_blocks_account_deletion(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
        $user = User::factory()->create();
        BillingPayment::create(['user_id' => $user->id, 'reference' => 'active-renewal-test',
            'subscription_plan_id' => SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->id,
            'provider' => 'paystack', 'mode' => 'test', 'status' => 'test_paid', 'currency' => 'ZAR',
            'amount_cents' => 7795, 'credits' => 150, 'billing_interval' => 'monthly', 'recurring' => true, 'recurring_status' => 'ACTIVE']);
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($user->fresh());
    }
}
