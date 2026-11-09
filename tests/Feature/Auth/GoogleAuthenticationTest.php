<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_buttons_and_redirect(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continue with Google');
        $this->get('/register')->assertOk()->assertSee('Continue with Google');
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        $response = $this->get('/auth/google')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(session('google_oauth_state'), $query['state']);
        $this->assertSame(route('auth.google.callback'), $query['redirect_uri']);
    }

    public function test_invalid_state_is_rejected_without_contacting_google(): void
    {
        Http::preventStrayRequests();
        $this->get('/auth/google/callback?state=bad&code=code')->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_google_creates_verified_user_then_signs_in_existing_user(): void
    {
        $this->fakeGoogle();
        $this->googleCallback()->assertRedirect('/get-started?questions=1');
        $user = User::sole();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('subject', $user->google_id);
        $this->assertTrue($user->canSetInitialPassword());
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $this->googleCallback()->assertRedirect('/dashboard');
        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_gmail_user_is_linked_without_password_change(): void
    {
        $user = User::factory()->create(['email' => 'person@gmail.com']);
        $password = $user->password;
        $this->fakeGoogle();
        $this->googleCallback()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertFalse($user->fresh()->canSetInitialPassword());
    }

    public function test_unverified_email_is_rejected(): void
    {
        $this->fakeGoogle(['email_verified' => false]);
        $this->googleCallback()->assertSessionHasErrors('google');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_existing_third_party_email_requires_password(): void
    {
        User::factory()->create(['email' => 'person@example.com']);
        $this->fakeGoogle(['email' => 'person@example.com']);
        $this->googleCallback()->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_provider_error_is_handled(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response([], 400)]);
        $this->googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    public function test_malformed_profile_name_uses_email_fallback(): void
    {
        $this->fakeGoogle(['name' => ['unexpected' => 'array']]);
        $this->googleCallback()->assertRedirect('/get-started?questions=1');
        $this->assertSame('person', User::sole()->name);
    }

    public function test_oversized_google_subject_is_rejected_without_database_error(): void
    {
        $this->fakeGoogle(['sub' => str_repeat('x', 256)]);
        $this->googleCallback()->assertSessionHasErrors('google');
        $this->assertGuest();
    }

    private function fakeGoogle(array $overrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'token']),
            'openidconnect.googleapis.com/*' => Http::response(array_merge([
                'sub' => 'subject', 'name' => 'Person', 'email' => 'person@gmail.com', 'email_verified' => true,
            ], $overrides)),
        ]);
    }

    private function googleCallback(): TestResponse
    {
        return $this->withSession(['google_oauth_state' => 'valid'])
            ->get('/auth/google/callback?state=valid&code=code');
    }
}
