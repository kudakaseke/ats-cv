<?php

namespace App\Http\Middleware;

use App\Services\AccountSessions;
use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;

class AuthenticateAccountSession extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        // Route middleware may have selected the admin guard on a prior request in long-running/test app instances.
        // Keep the normal account-session fingerprint check explicitly bound to the customer guard.
        Auth::shouldUse('web');
        $guard = Auth::guard('web');
        if ($request->hasSession()) {
            $customer = $guard->user();
            if ($customer && ($customer->role ?? 'user') === 'user' && ($customer->account_status ?? 'active') !== 'active') {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->route('login')->withErrors(['email' => 'This account is currently unavailable. Please contact support.']);
            }
            if (! $request->user() && $request->session()->has($guard->getName())) {
                // A deleted account must not become a guest with its old CV draft.
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } elseif ($request->user() && ! $guard->viaRemember() && ! $request->session()->has('password_hash_web')) {
                // Legacy sessions have no trustworthy pre-change fingerprint.
                $this->logout($request);
            }
        }

        $response = parent::handle($request, $next);
        if ($request->hasSession()) app(AccountSessions::class)->track($request);

        return $response;
    }
}
