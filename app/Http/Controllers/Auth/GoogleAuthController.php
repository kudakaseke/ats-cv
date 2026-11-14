<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    private function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('auth.google.callback');
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return to_route('login')->withErrors(['google' => 'Google sign-in is not configured yet. Please use email and password.']);
        }

        $request->session()->put('google_oauth_state', $state = Str::random(64));

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('google_oauth_state');
        if (! is_string($state) || ! is_string($request->query('state')) || ! hash_equals($state, $request->query('state'))) {
            return to_route('login')->withErrors(['google' => 'Your Google sign-in session expired. Please try again.']);
        }

        if ($request->has('error') || ! is_string($request->query('code'))) {
            return to_route('login')->withErrors(['google' => 'Google sign-in was cancelled. Please try again.']);
        }

        try {
            $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
            ])->throw()->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('Missing Google access token.');
            }

            $profile = Http::withToken($token)->timeout(15)
                ->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
        } catch (\Exception $exception) {
            // Do not log OAuth responses, which may contain tokens or credentials.
            return to_route('login')->withErrors(['google' => 'We could not connect to Google. Please try again.']);
        }

        $email = $profile['email'] ?? null;
        $subject = $profile['sub'] ?? null;
        if (! is_string($subject) || $subject === '' || strlen($subject) > 255 || ! is_string($email) || strlen($email) > 255 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ($profile['email_verified'] ?? false) !== true) {
            return to_route('login')->withErrors(['google' => 'Please use a Google account with a verified email address.']);
        }

        $email = strtolower($email);
        $user = User::where('google_id', $subject)->first();
        if (! $user) {
            $user = User::where('email', $email)->first();
            if ($user && ($user->google_id || (! str_ends_with($email, '@gmail.com') && empty($profile['hd'])))) {
                return to_route('login')->withErrors(['google' => 'An account with this email already exists. Please sign in using your password.']);
            }

            $user ??= new User([
                'name' => Str::limit(is_string($profile['name'] ?? null) && trim($profile['name']) !== '' ? $profile['name'] : Str::before($email, '@'), 255, ''),
                'email' => $email,
                'password' => Str::random(64),
            ]);
            if (! $user->exists) {
                $user->password_setup_required = true;
            }
            $user->google_id = $subject;
            $user->email_verified_at = now();
            $user->save();
        }

        $user->refresh();
        if ($user->account_status !== 'active') {
            return to_route('login')->withErrors(['google' => 'This account is currently unavailable. Please contact support.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended($user->wasRecentlyCreated
            ? route('builder.start', ['questions' => 1], absolute: false)
            : route('dashboard', absolute: false));
    }
}
