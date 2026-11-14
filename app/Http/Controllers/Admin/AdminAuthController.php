<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAuditTrail;
use App\Services\Admin\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        if ($user = Auth::guard('admin')->user()) {
            if ($this->dashboardPreviewEnabled()) {
                return to_route('admin.dashboard');
            }

            return to_route($user->two_factor_confirmed_at ? 'admin.2fa.challenge' : 'admin.2fa.setup');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request, AdminAuditTrail $audit): RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            $user = Auth::guard('admin')->user();

            if ($this->dashboardPreviewEnabled()) {
                return to_route('admin.dashboard');
            }

            return to_route($user->two_factor_confirmed_at ? 'admin.2fa.challenge' : 'admin.2fa.setup');
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'admin-login:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $audit->record('auth.login_throttled', email: $email);
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Try again in one minute.']);
        }
        $ownerEmail = config('admin.owner_email');
        $ownerAllowed = in_array(config('app.env'), ['local', 'testing'], true)
            || (is_string($ownerEmail) && $ownerEmail !== '' && hash_equals(mb_strtolower($ownerEmail), $email));
        $authenticated = $ownerAllowed && Auth::guard('admin')->attempt(['email' => $email, 'password' => $data['password'],
            'role' => 'super_admin', 'account_status' => 'active'], false);
        if (! $authenticated) {
            RateLimiter::hit($key, 60);
            $audit->record('auth.login_failed', email: $email);
            throw ValidationException::withMessages(['email' => 'These credentials are not authorised for the admin portal.']);
        }
        if (! Auth::guard('admin')->user()->email_verified_at) {
            Auth::guard('admin')->logout();
            RateLimiter::hit($key, 60);
            $audit->record('auth.login_unverified', email: $email);
            throw ValidationException::withMessages(['email' => 'Verify the administrator email address before signing in.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget(['admin_2fa_verified_at', 'admin_totp_setup_secret']);
        $audit->record('auth.login', 'User', Auth::guard('admin')->id());

        if ($this->dashboardPreviewEnabled()) {
            $audit->record('auth.local_dashboard_preview', 'User', Auth::guard('admin')->id());

            return to_route('admin.dashboard');
        }

        return to_route(Auth::guard('admin')->user()->two_factor_confirmed_at ? 'admin.2fa.challenge' : 'admin.2fa.setup');
    }

    public function setup(Request $request, TotpService $totp): View|RedirectResponse
    {
        $user = $request->user('admin');
        if ($user->two_factor_confirmed_at) {
            return to_route('admin.2fa.challenge');
        }
        $secret = $request->session()->get('admin_totp_setup_secret');
        if (! $secret) {
            $secret = $totp->secret();
            $request->session()->put('admin_totp_setup_secret', $secret);
        }

        return view('admin.auth.setup', ['secret' => $secret, 'uri' => $totp->uri($secret, $user->email)]);
    }

    public function confirmSetup(Request $request, TotpService $totp, AdminAuditTrail $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user('admin');
        $secret = $request->session()->get('admin_totp_setup_secret');
        abort_unless($secret && ! $user->two_factor_confirmed_at, 403);
        $counter = $totp->matchingCounter($secret, $data['code']);
        if ($counter === null) {
            throw ValidationException::withMessages(['code' => 'That code could not be verified. Check your device time and try the current code.']);
        }
        $codes = $totp->recoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $codes),
            'two_factor_confirmed_at' => now(), 'two_factor_last_counter' => $counter])->save();
        $request->session()->forget('admin_totp_setup_secret');
        $request->session()->put('admin_2fa_verified_at', now()->timestamp);
        $audit->record('auth.2fa_enabled', 'User', $user->id);

        return to_route('admin.dashboard')->with('recovery_codes', $codes);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->user('admin')->two_factor_confirmed_at) {
            return to_route('admin.2fa.setup');
        }

        return view('admin.auth.challenge');
    }

    public function verify(Request $request, TotpService $totp, AdminAuditTrail $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $userId = $request->user('admin')->id;
        $verified = DB::transaction(function () use ($userId, $data, $totp): bool {
            $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $matchedCounter = $totp->matchingCounter((string) $user->two_factor_secret,
                preg_replace('/\s+/', '', $data['code']), $user->two_factor_last_counter);
            if ($matchedCounter !== null) {
                $user->forceFill(['two_factor_last_counter' => $matchedCounter])->save();

                return true;
            }

            $codes = $user->two_factor_recovery_codes ?? [];
            $normalized = strtoupper(str_replace([' ', '-'], '', $data['code']));
            foreach ($codes as $i => $hash) {
                if (Hash::check($normalized, $hash)) {
                    unset($codes[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                    return true;
                }
            }

            return false;
        }, 3);
        if (! $verified) {
            $audit->record('auth.2fa_failed', 'User', $userId);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid or has already been used.']);
        }
        $request->session()->regenerate();
        $request->session()->put('admin_2fa_verified_at', now()->timestamp);
        $audit->record('auth.2fa_verified', 'User', $userId);

        return to_route('admin.dashboard');
    }

    public function logout(Request $request, AdminAuditTrail $audit): RedirectResponse
    {
        $userId = $request->user('admin')->id;
        $audit->record('auth.logout', 'User', $userId);
        Auth::guard('admin')->logout();
        $request->session()->forget(['admin_2fa_verified_at', 'admin_totp_setup_secret']);
        $request->session()->migrate(true);
        $request->session()->regenerateToken();

        return to_route('admin.login');
    }

    private function dashboardPreviewEnabled(): bool
    {
        return config('app.env') === 'local' && config('admin.dashboard_preview_without_2fa');
    }
}
