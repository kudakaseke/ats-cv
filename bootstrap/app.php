<?php

use App\Http\Middleware\RecordAccountActivity;
use App\Http\Middleware\WorkspaceResponseHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->web(append: [WorkspaceResponseHeaders::class, RecordAccountActivity::class]);
        $middleware->validateCsrfTokens(except: ['billing/paynow/notify/*', 'billing/paypal/notify', 'paypal/webhook', 'webhooks/paystack']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
