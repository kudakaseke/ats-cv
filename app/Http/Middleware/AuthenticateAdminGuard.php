<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdminGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            abort_if(Auth::guard('web')->check(), 403);

            // Admin authentication has its own explicit post-login destination.
            // Do not write the admin URL into Laravel's shared `url.intended`
            // session slot, which regular customer login also consumes.
            return redirect()->route('admin.login')
                ->with('status', 'Sign in to continue to the Super Admin Dashboard.');
        }
        Auth::shouldUse('admin');

        return $next($request);
    }
}
