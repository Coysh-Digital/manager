<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureSecondFactorWhenRequired;
use App\Http\Middleware\EnsureSetupIsAvailable;
use App\Http\Middleware\EnsureSiteBelongsToOrganisation;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\RequiresCapability;
use App\Http\Middleware\ResolveOrganisation;
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
            /*
             | Connector traffic is machine-to-machine and stateless: no session, no cookies, no
             | CSRF token. Authentication is the Ed25519 signature on each request, so none of the
             | browser-oriented middleware applies and including it would only add attack surface.
             |
             | The group is named rather than anonymous so that the pipeline has somewhere to add to.
             | It starts empty and stays empty here. An operator wanting to allow-list source
             | networks, or an edition wanting to refuse traffic from a suspended tenant, appends to
             | the group from a service provider; group membership resolves at runtime, so route:cache
             | does not bake the decision in the way an inline middleware list would.
             */
            Route::prefix('api/connector/v1')
                ->name('connector.')
                ->middleware('connector')
                ->group(base_path('routes/connector.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'connector.signed' => VerifyConnectorSignature::class,
            'capability' => RequiresCapability::class,
            'organisation' => ResolveOrganisation::class,
            'setup.available' => EnsureSetupIsAvailable::class,
            'second-factor' => EnsureSecondFactorWhenRequired::class,

            // Overrides the framework's own 'password.confirm' alias — custom aliases are merged
            // over the defaults, so this one wins. Same gate, same redirect; it additionally keeps
            // the typed form, which Laravel's cannot because a POST body does not survive
            // Redirector::guest(). See RequirePasswordConfirmation.
            'password.confirm' => RequirePasswordConfirmation::class,

            // Applied to every route with a {site} parameter. Tenant scoping is not something an
            // action should have to remember.
            'site.scoped' => EnsureSiteBelongsToOrganisation::class,
        ]);

        // Declared empty, and deliberately so. See the connector route group above: this exists to
        // be appended to, not to carry anything by default.
        $middleware->group('connector', []);

        // Sensitive actions require the password to have been proved recently.
        $middleware->redirectGuestsTo(fn () => route('login'));

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
