<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered separately so /broadcasting/auth uses token auth instead of the session-based `web` group.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Webhooks are authenticated by their HMAC signature.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // There is no login route; without this, requests lacking `Accept: application/json` get a 500.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Webhook senders are programs too, though their route is outside /api.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'webhooks/*'),
        );
    })->create();
