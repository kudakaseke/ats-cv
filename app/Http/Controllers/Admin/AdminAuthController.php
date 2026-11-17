<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAuditTrail;
use App\Services\Admin\AdminLoginCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminAuthController extends Controller
{
    public function loginForm(): View|RedirectResponse
    {
        if (Auth::guard('admin')->user()) {
            if ($this->dashboardPreviewEnabled()) {
                return to_route('admin.dashboard');
            }

            return to_route('admin.2fa.challenge');
        }

        return view('admin.auth.login');
    }

    public function login(Request $request, AdminAuditTrail $audit, AdminLoginCodeService $codes): RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            $user = Auth::guard('admin')->user();

            if ($this->dashboardPreviewEnabled()) {
                return to_route('admin.dashboard');
            }

            return to_route('admin.2fa.challenge');
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
        $request->session()->forget('admin_2fa_verified_at');
        $audit->record('auth.login', 'User', Auth::guard('admin')->id());

        if ($this->dashboardPreviewEnabled()) {
            $audit->record('auth.local_dashboard_preview', 'User', Auth::guard('admin')->id());

            return to_route('admin.dashboard');
        }

        try {
            $codes->issue(Auth::guard('admin')->user());
        } catch (ValidationException $exception) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw $exception;
        }
        $audit->record('auth.email_code_sent', 'User', Auth::guard('admin')->id());

        return to_route('admin.2fa.challenge')->with('status', 'A six-digit sign-in code was sent to your administrator email address.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $email = (string) $request->user('admin')->email;
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $maskedEmail = $local !== '' && $domain !== ''
            ? mb_substr($local, 0, 1).str_repeat('*', min(max(mb_strlen($local) - 1, 2), 8)).'@'.$domain
            : 'your administrator email address';

        return view('admin.auth.challenge', ['maskedEmail' => $maskedEmail]);
    }

    public function resendCode(Request $request, AdminLoginCodeService $codes, AdminAuditTrail $audit): RedirectResponse
    {
        $codes->issue($request->user('admin'));
        $audit->record('auth.email_code_sent', 'User', $request->user('admin')->id);

        return to_route('admin.2fa.challenge')->with('status', 'A new sign-in code was sent to your administrator email address.');
    }

    public function verify(Request $request, AdminLoginCodeService $codes, AdminAuditTrail $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $userId = $request->user('admin')->id;
        try {
            $codes->verify($request->user('admin'), $data['code']);
        } catch (ValidationException $exception) {
            $audit->record('auth.email_code_failed', 'User', $userId);
            throw $exception;
        }
        $request->session()->regenerate();
        $request->session()->put('admin_2fa_verified_at', now()->timestamp);
        $audit->record('auth.email_code_verified', 'User', $userId);

        return to_route('admin.dashboard');
    }

    public function logout(Request $request, AdminAuditTrail $audit): RedirectResponse
    {
        $userId = $request->user('admin')->id;
        $audit->record('auth.logout', 'User', $userId);
        app(AdminLoginCodeService::class)->invalidate($request->user('admin'));
        Auth::guard('admin')->logout();
        $request->session()->forget('admin_2fa_verified_at');
        $request->session()->migrate(true);
        $request->session()->regenerateToken();

        return to_route('admin.login');
    }

    private function dashboardPreviewEnabled(): bool
    {
        return config('app.env') === 'local' && config('admin.dashboard_preview_without_2fa');
    }
}
