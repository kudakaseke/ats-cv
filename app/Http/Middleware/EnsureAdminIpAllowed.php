<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminIpAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('admin.ip_allowlist', []);
        abort_if($allowed && ! in_array($request->ip(), $allowed, true), 403);

        return $next($request);
    }
}
