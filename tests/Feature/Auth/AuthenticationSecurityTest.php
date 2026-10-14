<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use App\Services\EmailVerificationCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_ip_limit_blocks_rotating_email_addresses_and_then_expires(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->post('/login', ['email' => "unknown{$i}@example.com", 'password' => 'wrong'])->assertStatus(302);
        }
        $this->post('/login', ['email' => 'another@example.com', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertSee('Please wait before trying again');
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'another@example.com', 'password' => 'wrong'])->assertStatus(302);
    }

    public function test_login_email_limit_blocks_rotating_ips_and_email_case(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "192.0.2.{$i}"])
                ->post('/login', ['email' => $i % 2 ? 'target@example.com' : 'TARGET@example.com', 'password' => 'wrong'])
                ->assertStatus(302);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])
            ->post('/login', ['email' => 'target@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_failed_login_pair_limit_remains_in_place(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_initial_email_counts_toward_resend_cooldown(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $this->actingAs($user)->post('/email/verification-notification')->assertSessionHasErrors('delivery');
        Notification::assertSentToTimes($user, VerifyEmailCode::class, 1);
    }

    public function test_email_hourly_and_daily_quotas_apply_outside_http_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $service = app(EmailVerificationCodeService::class);
        for ($i = 0; $i < 5; $i++) {
            $service->send($user);
            $this->travel(61)->seconds();
        }
        $this->assertSendLimited($service, $user);
        $this->travel(1)->hours();
        for ($i = 0; $i < 5; $i++) {
            $service->send($user->fresh());
            $this->travel(61)->seconds();
        }
        $this->travel(1)->hours();
        $this->assertSendLimited($service, $user);
        Notification::assertSentToTimes($user, VerifyEmailCode::class, 10);
        $this->travel(1)->days();
        $service->send($user);
        Notification::assertSentToTimes($user, VerifyEmailCode::class, 11);
    }

    private function assertSendLimited(EmailVerificationCodeService $service, User $user): void
    {
        try {
            $service->send($user);
            $this->fail('Expected the email quota to block sending.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery', $exception->errors());
        }
    }

    public function test_password_reset_response_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $known->assertSessionHasNoErrors();
        $message = session('status');
        $this->post('/forgot-password', ['email' => 'unknown@example.com'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', $message);
    }

    public function test_resend_quota_is_not_reset_by_a_new_session_or_ip(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])
            ->post('/email/verification-notification')->assertSessionHasErrors('delivery');
        $this->app['session']->flush();
        $this->actingAs($user->fresh())->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])
            ->post('/email/verification-notification')->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmailCode::class, 1);
    }

    public function test_signup_and_password_reset_are_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/register', [])->assertStatus(302);
        }
        $this->post('/register', [])->assertStatus(429);
        for ($i = 0; $i < 3; $i++) {
            $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertStatus(302);
        }
        $this->post('/forgot-password', ['email' => 'missing@example.com'])->assertStatus(429);
    }
}
