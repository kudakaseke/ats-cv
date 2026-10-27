<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleIngress
{
    public function handle(Request $request, Closure $next): Response
    {
        // Runs after trusted-proxy resolution, before route matching and database sessions.
        return app(ThrottleRequests::class)->handle($request, $next, 'ingress');
    }
}
