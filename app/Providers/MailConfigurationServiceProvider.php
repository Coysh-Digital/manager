<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Mail\ConfiguredMailManager;
use App\Domain\Mail\MailConfiguration;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the stored mail configuration into the framework's mail manager.
 */
final class MailConfigurationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MailConfiguration::class);

        /*
         | extend(), not singleton('mail.manager', ...), and the distinction is load-bearing.
         |
         | MailServiceProvider is deferred. The container still lists 'mail.manager' among its
         | deferred services, so the first make() loads that provider - and its own
         | singleton('mail.manager', ...) would land after anything bound here and silently win.
         |
         | The symptom would be no symptom at all: mail would quietly go on using the environment,
         | every test that does not send mail would stay green, and the only way to notice would be
         | somebody's relay not being used. MailSettingsTest asserts the resolved instance's class
         | for exactly that reason.
         |
         | Extenders survive a rebind, so this wraps whatever the framework registers, whenever it
         | registers it. The discarded MailManager costs one object.
         */
        $this->app->extend('mail.manager', fn ($manager, $app) => new ConfiguredMailManager($app));
    }

    public function boot(): void
    {
        // Per job, because `queue:work` is a long-lived process that does not reboot between jobs.
        // Without this a configuration read at the first send would still be in force an hour after
        // somebody changed it.
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(MailConfiguration::class)->markStale();
        });
    }
}
