<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityRequestGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        // Validate before routing, redirects, password-reset URLs or provider callbacks.
        if (! in_array(strtolower($request->getHost()), array_map('strtolower', config('security.hosts', [])), true)) {
            return response('Unrecognised host.', 400);
        }

        if (in_array($request->method(), ['TRACE', 'CONNECT'], true)) {
            return response('Method not allowed.', 405, ['Allow' => 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS']);
        }

        return $next($request);
    }
}
