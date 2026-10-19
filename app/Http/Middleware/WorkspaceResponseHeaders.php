<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($request->user() || $request->is('login', 'register', 'forgot-password', 'reset-password/*', 'verify-email')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
