<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\KeyService;
use App\Models\Finding;
use App\Models\Organisation;
use App\Models\Site;
use App\Support\CorrelationId;
use App\Support\SelfHosted\DerivedKeyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One identifier per request, shared by logs, audit events and connector responses.
        $this->app->singleton(CorrelationId::class);

        // Cloud-specific concerns sit behind interfaces, with the self-hosted implementation bound
        // here. manager-cloud rebinds them; nothing in the core knows which edition it is running.
        $this->app->singleton(KeyService::class, DerivedKeyService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail loudly on a mass-assignment mistake rather than silently discarding the attribute.
        // In a control plane, a security field that quietly does not save is worse than an
        // exception: see the TOTP confirmation flow, where the wrong behaviour would leave an
        // account looking protected when it is not.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Model::preventLazyLoading(! $this->app->isProduction());

        // Emit HTTPS URLs whenever the canonical URL is HTTPS, so a reverse proxy terminating TLS
        // cannot cause password-reset and verification links to go out over plain HTTP.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Counts for the sidebar. A composer rather than something threaded through every
        // controller, and scoped to the partial so the queries only run when the sidebar renders.
        View::composer('layouts.partials.sidebar', function ($view): void {
            if (! app()->bound(Organisation::class)) {
                return;
            }

            $organisation = app(Organisation::class);

            $sites = Site::query()
                ->where('organisation_id', $organisation->id)
                ->active();

            $outstanding = Finding::query()
                ->whereIn('site_id', $sites->clone()->select('id'))
                ->whereIn('state', [Finding::STATE_OPEN, Finding::STATE_ACKNOWLEDGED]);

            $view->with([
                'siteCount' => $view->getData()['siteCount'] ?? $sites->clone()->count(),
                'updateCount' => $sites->clone()->sum('available_updates'),
                'securityUpdates' => $sites->clone()->where('has_security_release', true)->exists(),
                'findingCount' => $outstanding->clone()->count(),

                // Red only for critical or high. Amber for the rest, so the badge distinguishes
                // "look now" from "there is a list".
                'severeFindings' => $outstanding->clone()->whereIn('severity', ['critical', 'high'])->exists(),
            ]);
        });
    }
}
