<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');
        abort_unless($user && $user->role === 'super_admin' && $user->account_status === 'active', 403);
        $ownerEmail = config('admin.owner_email');
        if (config('app.env') === 'production') {
            abort_unless(is_string($ownerEmail) && $ownerEmail !== '' && hash_equals(mb_strtolower($ownerEmail), mb_strtolower($user->email)), 403);
        }
        return $next($request);
    }
}
