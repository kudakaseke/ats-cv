<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'local'
            && config('admin.dashboard_preview_without_2fa')
            && $request->routeIs('admin.dashboard')) {
            return $next($request);
        }

        $user = $request->user('admin');
        if (! $user?->two_factor_confirmed_at) {
            return redirect()->route('admin.2fa.setup');
        }
        $verifiedAt = (int) $request->session()->get('admin_2fa_verified_at', 0);
        if (! $verifiedAt || now()->timestamp - $verifiedAt > 1800) {
            $request->session()->forget('admin_2fa_verified_at');

            return redirect()->route('admin.2fa.challenge');
        }
        $request->session()->put('admin_2fa_verified_at', now()->timestamp);

        return $next($request);
    }
}
