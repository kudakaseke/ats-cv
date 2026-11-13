<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAuditTrail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        app(AdminAuditTrail::class)->record('admin.request', 'Route', $request->route()?->getName(),
            $request->method().' '.$request->route()?->getName().' ('.$response->getStatusCode().')');

        return $response;
    }
}
