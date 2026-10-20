<?php

namespace App\Http\Middleware;

use App\Services\AuditTrail;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RecordAccountActivity
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (\Throwable $exception) {
            $this->record($request, $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : ($exception instanceof ValidationException ? 422 : 500));
            throw $exception;
        }
        $this->record($request, $response->getStatusCode());

        return $response;
    }

    private function record(Request $request, int $status): void
    {
        if (! $request->user() || $request->isMethodSafe() || $request->routeIs('builder.save')) {
            return;
        }
        app(AuditTrail::class)->record($request->user()->id, 'account.request', 'Route', $request->route()?->getName() ?? 'unknown',
            ['method' => $request->method(), 'http_status' => $status]);
    }
}
