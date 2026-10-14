<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function issue(User $user): string
    {
        Notification::fake();
        $user->sendEmailVerificationNotification();
        $code = '';
        Notification::assertSentTo($user, VerifyEmailCode::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/verify-email')
            ->assertOk()->assertSee('Verification code');
    }

    public function test_email_can_be_verified_and_code_is_consumed(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issue($user);
        Event::fake([Verified::class]);
        $this->actingAs($user)->post('/verify-email', ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNull($user->fresh()->email_verification_code_hash);
    }

    public function test_wrong_and_expired_codes_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issue($user);
        $wrong = $code === '000000' ? '000001' : '000000';
        $this->actingAs($user)->post('/verify-email', ['code' => $wrong])->assertSessionHasErrors('code');
        $this->assertEquals(1, $user->fresh()->email_verification_code_attempts);
        $this->travel(11)->minutes();
        $this->post('/verify-email', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_five_wrong_attempts_block_even_the_correct_code(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issue($user);
        $wrong = $code === '000000' ? '000001' : '000000';
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify-email', ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->post('/verify-email', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resending_replaces_code_and_is_throttled(): void
    {
        $user = User::factory()->unverified()->create();
        $this->issue($user);
        $hash = $user->fresh()->email_verification_code_hash;
        $this->travel(61)->seconds();
        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHas('status', 'verification-code-sent');
        $this->assertNotEquals($hash, $user->fresh()->email_verification_code_hash);
        $this->post('/email/verification-notification')->assertStatus(429);
    }

    public function test_codes_cannot_verify_another_user_or_changed_email(): void
    {
        $user = User::factory()->unverified()->create();
        $code = $this->issue($user);
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->post('/verify-email', ['code' => $code])->assertSessionHasErrors('code');
        $user->forceFill(['email' => 'changed@example.com'])->save();
        $this->actingAs($user)->post('/verify-email', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_and_guest_users_cannot_bypass_verification(): void
    {
        $this->post('/verify-email', ['code' => '123456'])->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->get('/dashboard')->assertRedirect('/verify-email');
    }
}
