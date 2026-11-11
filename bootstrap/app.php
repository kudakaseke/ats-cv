<?php

use App\Http\Middleware\RecordAccountActivity;
use App\Http\Middleware\SecurityRequestGuard;
use App\Http\Middleware\WorkspaceResponseHeaders;
use App\Services\IncidentReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Configure only real reverse-proxy addresses; direct clients cannot spoof their IP.
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $middleware->prepend(SecurityRequestGuard::class);
        $middleware->append(\App\Http\Middleware\ThrottleIngress::class);
        $middleware->web(append: [WorkspaceResponseHeaders::class, \App\Http\Middleware\AuthenticateAccountSession::class, 'throttle:site', RecordAccountActivity::class]);
        $middleware->validateCsrfTokens(except: ['billing/paynow/notify/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();
        $exceptions->report(function (Throwable $exception): void {
            app(IncidentReporter::class)->report($exception);
        })->stop();
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() >= 500) {
                $reference = app(IncidentReporter::class)->reference();
                if (request()->expectsJson()) {
                    $response = response()->json([
                        'message' => 'Something went wrong. Please try again or contact support with this reference.',
                        'reference' => $reference,
                    ], $response->getStatusCode());
                }
                $response->headers->set('X-Error-Reference', $reference);
                $response->headers->set('Cache-Control', 'private, no-store');
            }

            return $response;
        });
    })->create();
