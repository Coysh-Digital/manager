<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\BackupSizeLimit;
use App\Contracts\BillingAdministration;
use App\Contracts\DirectUploadGrants;
use App\Contracts\KeyService;
use App\Contracts\MailAdministration;
use App\Contracts\ObjectStore;
use App\Contracts\PairingAddress;
use App\Contracts\ProductLabel;
use App\Contracts\Provisioner;
use App\Contracts\ServerAccess;
use App\Contracts\StorageQuota;
use App\Domain\Findings\RuleCategory;
use App\Domain\Notifications\EmailCatalogue;
use App\Models\Finding;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use App\Support\CorrelationId;
use App\Support\SelfHosted\ConfiguredQuota;
use App\Support\SelfHosted\DerivedKeyService;
use App\Support\SelfHosted\DiskObjectStore;
use App\Support\SelfHosted\NoDirectUploads;
use App\Support\SelfHosted\NoPublishedAddress;
use App\Support\SelfHosted\NullProvisioner;
use App\Support\SelfHosted\OwnServerAccess;
use App\Support\SelfHosted\SelfHostedBilling;
use App\Support\SelfHosted\SelfHostedLabel;
use App\Support\SelfHosted\SelfHostedMail;
use App\Support\SelfHosted\SiteDecidesBackupSize;
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

        // Whether the person reading Settings holds the mail configuration. Self-hosted they do, and
        // the test-send button is worth having; on a hosted edition the relay belongs to somebody
        // else and the button tests infrastructure the reader cannot change.
        $this->app->singletonIf(MailAdministration::class, SelfHostedMail::class);

        // Whether the reader can run a command on the machine serving the page. Self-hosted they
        // can, and a command is often the honest answer - it streams an artifact without a request
        // timing out underneath it. Hosted, "run this on the server" is an instruction for a
        // machine they have no account on.
        $this->app->singletonIf(ServerAccess::class, OwnServerAccess::class);

        // The address a managed site should pair against. Self-hosted this answers null: the
        // operator chose it, and APP_URL is what this application generates links with rather than a
        // promise about what a Craft site elsewhere can reach. A hosted edition knows, because it
        // published the name — and has to say so when it serves connector traffic somewhere other
        // than the address the reader is looking at.
        $this->app->singletonIf(PairingAddress::class, NoPublishedAddress::class);

        // Where to send somebody to manage what they pay. Nobody bills a self-hosted installation,
        // so this answers null and every part of the interface that would link to billing stays
        // absent - not greyed out, not explaining why it does not apply.
        $this->app->singletonIf(BillingAdministration::class, SelfHostedBilling::class);

        // Whether a site's own maxBackupMegabytes stands. It does here: whoever runs this
        // installation runs the machines being backed up, and the limit protects their disk. A
        // hosted edition owns and bills for the storage, so it lifts it.
        $this->app->singletonIf(BackupSizeLimit::class, SiteDecidesBackupSize::class);

        // The word under the wordmark. Bound the same way and for the same reason, even though it
        // decides nothing: an installation should say which one it is because of what is wired into
        // it, not because of a string somebody set.
        $this->app->singletonIf(ProductLabel::class, SelfHostedLabel::class);

        /*
         | Every email this installation can send, and what triggers each one.
         |
         | A plain singleton, and deliberately not a contract in app/Contracts. The seams there each
         | answer one question with one answer per installation; this is the union of what every
         | installed layer can send, so a hosting layer appends to it rather than replacing it. Using
         | singletonIf here would be wrong for the same reason: a second implementation is not what is
         | wanted, an extra call to add() is.
         |
         | See App\Domain\Notifications\EmailCatalogue.
         */
        $this->app->singleton(EmailCatalogue::class);

        /*
         | None of the passkey package's own routes are registered, and this is the single most
         | important line in this file.
         |
         | Among them is POST /passkeys/login, which signs somebody in on a passkey alone. That is
         | the package's headline feature and a perfectly reasonable default - but it is the exact
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

        /*
         | Every generated URL is built from the canonical address, never from the request.
         |
         | Forcing the scheme was already here, and it closed half the problem: a reverse proxy
         | terminating TLS could no longer send password-reset links out over plain HTTP. It left
         | the host alone, and the host is the half that is attacker-controlled. Laravel derives it
         | from the Host header, so a request carrying `Host: attacker.example` produced a reset
         | link on attacker.example - emailed, by us, to the address whose account was being taken.
         |
         | forceRootUrl fixes it at the point of generation, which is the right place: it holds for
         | every route(), url() and signed URL, in a request, a queued job or a console command,
         | without each caller having to remember.
         |
         | TrustHosts was considered and deliberately not added. It refuses the request outright,
         | which is a stronger control and the wrong trade here: /up and /ready exist to be probed
         | by an orchestrator, which may reasonably reach them on a container address rather than on
         | the canonical host. Refusing those would swap a fixed vulnerability for an outage, and the
         | vulnerability is already fixed by the line below.
         |
         | APP_URL is safe to lean on this hard: the entrypoint refuses to boot without it, and it
         | already decides cookie security.
        */
        $canonical = (string) config('app.url');

        if ($canonical !== '') {
            URL::forceRootUrl($canonical);

            if (str_starts_with($canonical, 'https://')) {
                URL::forceScheme('https');
            }
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

            // One badge per screen, and the two are disjoint: every rule declares exactly one
            // category, so a finding counted here is not counted there. A shared total would go up by
            // one and leave the reader unable to tell which screen to open.
            $securityKeys = RuleCategory::keysFor(RuleCategory::SECURITY);

            $security = $outstanding->clone()->whereIn('rule', $securityKeys);
            $other = $outstanding->clone()->whereNotIn('rule', $securityKeys);

            $view->with([
                'siteCount' => $view->getData()['siteCount'] ?? $sites->clone()->count(),
                'updateCount' => $sites->clone()->sum('available_updates'),
                'securityUpdates' => $sites->clone()->where('has_security_release', true)->exists(),

                'securityFindingCount' => $security->clone()->count(),
                'findingCount' => $other->clone()->count(),

                // Red only for critical or high. Amber for the rest, so the badge distinguishes
                // "look now" from "there is a list".
                'severeSecurityFindings' => $security->clone()->whereIn('severity', ['critical', 'high'])->exists(),
                'severeFindings' => $other->clone()->whereIn('severity', ['critical', 'high'])->exists(),
            ]);
        });
    }
}
