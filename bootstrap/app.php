<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            require __DIR__.'/../routes/ai.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\MaskDemoData::class,
        ]);

        // Exclude video API routes from CSRF (uses Sanctum token auth instead)
        // Also exclude public video view/ping routes (anonymous viewers)
        // Exclude webhooks (they have their own signature verification)
        // Exclude Ollie API routes (authenticated via session, AJAX calls)
        $middleware->validateCsrfTokens(except: [
            'api/videos/*',
            'api/ollie/*',
            'api/site-builder/*',
            'v/*/view',
            'v/*/ping',
            'v/*/comments',
            'webhooks/*',
            'mcp/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
