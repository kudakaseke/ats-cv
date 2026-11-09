<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function googleUser(): User
    {
        $user = User::factory()->create(['email' => 'person@gmail.com']);
        $user->forceFill(['google_id' => 'google-subject', 'password_set_at' => null])->save();

        return $user;
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user);
    }

    public function test_form_has_fixed_email_and_password_confirmation_without_email_recovery(): void
    {
        $user = $this->googleUser();
        $this->confirmed($user)->get('/set-password')->assertOk()->assertSee($user->email)
            ->assertSee('readonly', false)->assertSee('name="password_confirmation"', false);
        $this->get('/profile')->assertOk()->assertSee(route('password.setup'))
            ->assertDontSee('using email recovery')->assertDontSee('Current Password');
    }

    public function test_initial_password_is_hashed_and_setup_cannot_be_repeated(): void
    {
        $user = $this->googleUser();
        $oldToken = $user->remember_token;
        $this->confirmed($user)->post('/set-password', ['email' => $user->email,
            'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])
            ->assertRedirect(route('profile.edit'))->assertSessionHas('status', 'password-updated')
            ->assertSessionMissing('google_password_setup');
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
        $this->assertNotNull($user->fresh()->password_set_at);
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->post('/set-password', ['email' => $user->email, 'password' => 'another-password',
            'password_confirmation' => 'another-password'])->assertForbidden();
        $this->get('/set-password')->assertRedirect(route('profile.edit'));
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_tampered_email_and_mismatched_password_confirmation_are_rejected(): void
    {
        $user = $this->googleUser();
        $other = User::factory()->create();
        $oldHash = $user->password;
        $otherHash = $other->password;
        $this->confirmed($user)->post('/set-password', ['email' => $other->email,
            'password' => 'new-password', 'password_confirmation' => 'different-password'])
            ->assertSessionHasErrors(['email', 'password']);
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame($otherHash, $other->fresh()->password);
        $this->assertNull($user->fresh()->password_set_at);
    }

    public function test_existing_signed_in_account_can_open_and_save_without_google_redirects(): void
    {
        $user = $this->googleUser();
        $this->actingAs($user)->get('/set-password')->assertOk();
        $this->post('/set-password', ['email' => $user->email, 'password' => 'new-password',
            'password_confirmation' => 'new-password'])->assertRedirect(route('profile.edit'));
        Http::assertNothingSent();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_existing_password_and_email_only_accounts_cannot_bypass_current_password(): void
    {
        $user = $this->googleUser();
        $user->forceFill(['password_set_at' => now()])->save();
        $this->confirmed($user)->post('/set-password', ['email' => $user->email,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
        $emailUser = User::factory()->create();
        $this->actingAs($emailUser)->post('/set-password', ['email' => $emailUser->email,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
    }

    public function test_guest_and_unverified_users_cannot_set_a_password(): void
    {
        $this->get('/set-password')->assertRedirect(route('login'));
        $this->post('/set-password', [])->assertRedirect(route('login'));
        $user = $this->googleUser();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->confirmed($user)->get('/set-password')->assertRedirect(route('verification.notice'));
    }

    public function test_setup_attempts_are_rate_limited(): void
    {
        $user = $this->googleUser();
        $this->confirmed($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/set-password', ['email' => $user->email, 'password' => 'new-password',
                'password_confirmation' => 'mismatch'])->assertSessionHasErrors('password');
        }
        $this->post('/set-password', ['email' => $user->email, 'password' => 'new-password',
            'password_confirmation' => 'new-password'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNull($user->fresh()->password_set_at);
    }

    public function test_setting_password_invalidates_other_sessions_for_only_this_account(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->googleUser();
        $other = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'old-account-session', 'user_id' => $user->id, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp],
            ['id' => 'other-account-session', 'user_id' => $other->id, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp],
        ]);
        $this->confirmed($user)->post('/set-password', ['email' => $user->email,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertRedirect(route('profile.edit'));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-account-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-account-session', 'user_id' => $other->id]);
        $this->assertAuthenticatedAs($user);
    }
}
