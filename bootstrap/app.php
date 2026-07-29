<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyConnectorSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Connector traffic is machine-to-machine and stateless: no session, no cookies, no
            // CSRF token. Authentication is the Ed25519 signature on each request, so none of the
            // browser-oriented middleware applies and including it would only add attack surface.
            Route::prefix('api/connector/v1')
                ->name('connector.')
                ->group(base_path('routes/connector.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'connector.signed' => VerifyConnectorSignature::class,
        ]);

        // Trusted proxies are configured explicitly, never with a wildcard. Trusting every proxy
        // would let any caller set X-Forwarded-For and defeat the per-network rate limits along
        // with the source addresses recorded in the audit log.
        $middleware->trustProxies(at: array_values(array_filter(
            array_map('trim', explode(',', (string) env('MANAGER_TRUSTED_PROXIES', '')))
        )));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
