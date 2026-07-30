<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\DirectUploadGrants;
use App\Contracts\KeyService;
use App\Contracts\ObjectStore;
use App\Contracts\Provisioner;
use App\Contracts\StorageQuota;
use App\Models\Finding;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use App\Support\SelfHosted\ConfiguredQuota;
use App\Support\SelfHosted\DerivedKeyService;
use App\Support\SelfHosted\DiskObjectStore;
use App\Support\SelfHosted\NoDirectUploads;
use App\Support\SelfHosted\NullProvisioner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One identifier per request, shared by logs, audit events and connector responses.
        $this->app->singleton(CorrelationId::class);

        /*
         | Cloud-specific concerns sit behind interfaces, with the self-hosted implementation bound
         | here. manager-cloud rebinds them; nothing in the core knows which edition it is running.
         |
         | singletonIf, not singleton, and the distinction is load-bearing. Laravel registers
         | package-discovered providers *before* application providers, so a Cloud overlay shipped as
         | a Composer package binds first and a plain singleton() here would overwrite it. These are
         | defaults: whatever is already bound wins, which is what "the self-hosted implementation"
         | has always meant.
         */
        $this->app->singletonIf(KeyService::class, DerivedKeyService::class);
        $this->app->singletonIf(ObjectStore::class, DiskObjectStore::class);
        $this->app->singletonIf(Provisioner::class, NullProvisioner::class);
        $this->app->singletonIf(StorageQuota::class, ConfiguredQuota::class);
        $this->app->singletonIf(DirectUploadGrants::class, NoDirectUploads::class);

        /*
         | None of the passkey package's own routes are registered, and this is the single most
         | important line in this file.
         |
         | Among them is POST /passkeys/login, which signs somebody in on a passkey alone. That is
         | the package's headline feature and a perfectly reasonable default — but it is the exact
         | opposite of what this platform is for. A passkey here is a *second* factor: one on an
         | already-unlocked laptop is a single factor, and this system can read every installation it
         | manages.
         |
         | Registered routes are the whole attack surface. Switching them off here, rather than
         | relying on nobody linking to them, is what makes "a passkey alone cannot produce a
         | session" true by construction. There is an invariant test that fails if any of these
         | route names comes back.
         |
         | The endpoints this application does serve are in routes/web.php, and they drive the
         | package's action classes directly.
         */
        Passkeys::ignoreRoutes();

        // The package resolves the user model by class-string default ('App\Models\User'), which is
        // right by accident here. Stated so a rename cannot quietly break the passkey relation.
        Passkeys::useUserModel(User::class);
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
