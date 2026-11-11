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
        $guard = Auth::guard('web');
        if ($request->hasSession()) {
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
