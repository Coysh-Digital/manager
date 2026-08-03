<?php

declare(strict_types=1);

use App\Contracts\BackupSizeLimit;
use App\Contracts\BillingAdministration;
use App\Contracts\DirectUploadGrants;
use App\Contracts\KeyService;
use App\Contracts\MailAdministration;
use App\Contracts\ObjectStore;
use App\Contracts\ProductLabel;
use App\Contracts\Provisioner;
use App\Contracts\ServerAccess;
use App\Contracts\StorageQuota;
use App\Domain\Health\Diagnostics;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\SelfHosted\ConfiguredQuota;
use App\Support\SelfHosted\DerivedKeyService;
use App\Support\SelfHosted\DiskObjectStore;
use App\Support\SelfHosted\NoDirectUploads;
use App\Support\SelfHosted\NullProvisioner;
use App\Support\SelfHosted\OwnServerAccess;
use App\Support\SelfHosted\SelfHostedBilling;
use App\Support\SelfHosted\SelfHostedLabel;
use App\Support\SelfHosted\SelfHostedMail;
use App\Support\SelfHosted\SiteDecidesBackupSize;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/*
 * The seam between the editions.
 *
 * The contracts in app/Contracts are bound with singletonIf so that an edition shipped as a Composer
 * package can replace them. That is only safe if two things stay true: with nothing installed, this
 * repository resolves entirely to its own implementations; and an installation whose wiring
 * disagrees with the edition it claims to be says so out loud rather than quietly wrapping keys the
 * wrong way.
 */

it('resolves every seam to the self-hosted implementation with no overlay installed', function (
    string $contract,
    string $implementation
): void {
    expect(app($contract))->toBeInstanceOf($implementation);
})->with([
    [KeyService::class, DerivedKeyService::class],
    [ObjectStore::class, DiskObjectStore::class],
    [Provisioner::class, NullProvisioner::class],
    [StorageQuota::class, ConfiguredQuota::class],
    [DirectUploadGrants::class, NoDirectUploads::class],
    [ProductLabel::class, SelfHostedLabel::class],
    [MailAdministration::class, SelfHostedMail::class],
    [BillingAdministration::class, SelfHostedBilling::class],
    [BackupSizeLimit::class, SiteDecidesBackupSize::class],
    [ServerAccess::class, OwnServerAccess::class],
]);

it('says what it is under the wordmark without being told', function (): void {
    // The label is the one seam a person can see, which makes it the one most likely to be "fixed"
    // back into a config key the next time somebody wants the console to say something else. It is a
    // binding for the same reason as the others: a claim about what an installation is should
    // follow from what is wired into it.
    expect(app(ProductLabel::class)->label())->toBe('Self-hosted');
});

it('offers no billing of its own', function (): void {
    /*
     | Nobody bills a self-hosted installation.
     |
     | You have the source and you are running it on your own infrastructure. There is no
     | subscription, no card, no invoice and no allowance to buy more of — so there is nothing for
     | this repository to show, and the correct amount of billing interface here is none. Not a
     | greyed-out link, not a page explaining that it does not apply.
     |
     | Three separate claims, because the interesting failure is not the same as the obvious one.
     | The obvious one is somebody building a billing screen into the core, which a reviewer would
     | catch. The quiet one is somebody dropping the `@if` around a link that is already there: the
     | seam keeps answering null, every test about the hosted edition keeps passing, and a
     | self-hosted install grows a "Billing" item in its sidebar pointing at nothing. That is what
     | the URL assertion is for, and why the view sweep looks for the link rather than the word.
     */
    expect(app(BillingAdministration::class)->url())->toBeNull();

    $named = collect(Route::getRoutes())
        ->map(fn ($route): ?string => $route->getName())
        ->filter()
        ->filter(fn (string $name): bool => str_contains($name, 'billing'))
        ->values()
        ->all();

    expect($named)->toBe([], implode("\n", [
        'These routes exist in the core and name billing:',
        '  '.implode("\n  ", $named),
        'Billing belongs to whoever runs a hosted edition. The core links out to a URL the',
        'BillingAdministration seam supplies and owns no billing endpoint of its own.',
    ]));

    // Every blade that renders a billing link has to get it from the seam, because the seam is the
    // only thing that returns null self-hosted. A hardcoded route() or href to a billing path would
    // render on an installation that has no such page.
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (preg_match('~(route\(\s*[\'"][^\'"]*billing|href="[^"]*/billing)~i', $contents) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These views link to billing without going through the seam:',
        '  '.implode("\n  ", $offenders),
        'Use the URL from App\Contracts\BillingAdministration, which is null when nobody bills this',
        'installation, so the link disappears rather than pointing at a page that does not exist.',
    ]));
});

it('never tells a reader to run a command without asking whether they can', function (): void {
    /*
     | The same shape as the billing sweep above, for the same reason.
     |
     | This one is written from a bug rather than from a worry. The backups screen told every reader
     | to run `php artisan manager:backups:fetch` on the server, and on a hosted edition that is a
     | machine they have no account on — so it read as the answer to "how do I get my backup" while
     | being impossible to follow. Two more screens did it with `manager:audit:verify`, and Settings
     | with `manager:doctor`. MailAdministration exists because the identical thing happened with a
     | `.env` file.
     |
     | Coarse on purpose: it asks only that a view naming an artisan command also names the seam, not
     | that the gate is correctly placed. A finer check would have to parse Blade. This one catches
     | the regression that actually happens — a new instruction written without the question being
     | asked at all.
     |
     | `manager-restore` is deliberately not covered. It runs on the reader's own machine, and on a
     | hosted edition it is the whole recovery story rather than something to hide.
     */
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (str_contains($contents, 'php artisan') && ! str_contains($contents, 'ServerAccess')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These views print an artisan command without asking whether the reader can run one:',
        '  '.implode("\n  ", $offenders),
        'Gate it on App\Contracts\ServerAccess, which is false on a hosted edition, where the shell',
        'belongs to whoever runs the service and the instruction cannot be followed.',
    ]));
});

it('lets a later binding win, which is what singletonIf is for', function (): void {
    // Package-discovered providers register before application providers, so the core's bindings
    // must not overwrite what is already there. If singletonIf ever becomes singleton again, an
    // overlay silently stops taking effect and backups get wrapped from APP_KEY on a cloud install.
    $replacement = new class implements Provisioner
    {
        public function provision(Organisation $organisation): void {}
    };

    app()->instance(Provisioner::class, $replacement);

    (new AppServiceProvider(app()))->register();

    expect(app(Provisioner::class))->toBe($replacement);
});

it('provisions exactly once when the first organisation is created', function (): void {
    $calls = 0;

    app()->instance(Provisioner::class, new class($calls) implements Provisioner
    {
        public function __construct(public int &$calls) {}

        public function provision(Organisation $organisation): void
        {
            $this->calls++;
        }
    });

    User::query()->delete();
    Organisation::query()->delete();

    $this->post('/setup', [
        'organisation' => 'Coysh Digital',
        'name' => 'Tim Coysh',
        'email' => 'owner@example.org',
        'password' => 'correct-horse-battery-staple-42',
        'password_confirmation' => 'correct-horse-battery-staple-42',
    ])->assertRedirect(route('account.show'));

    expect($calls)->toBe(1);
});

it('rolls the organisation back when provisioning fails', function (): void {
    // Provisioning inside the transaction is the whole point: an edition that allocates storage or a
    // billing record must not leave a half-created organisation behind when that allocation fails.
    app()->instance(Provisioner::class, new class implements Provisioner
    {
        public function provision(Organisation $organisation): void
        {
            throw new RuntimeException('storage unavailable');
        }
    });

    User::query()->delete();
    Organisation::query()->delete();

    try {
        $this->post('/setup', [
            'organisation' => 'Coysh Digital',
            'name' => 'Tim Coysh',
            'email' => 'owner@example.org',
            'password' => 'correct-horse-battery-staple-42',
            'password_confirmation' => 'correct-horse-battery-staple-42',
        ]);
    } catch (RuntimeException) {
        // Expected.
    }

    expect(User::query()->count())->toBe(0)
        ->and(Organisation::query()->count())->toBe(0)
        ->and(Membership::query()->count())->toBe(0);
});

it('has no notion of an edition at all', function (): void {
    /*
     | This repository is the self-hosted product, full stop.
     |
     | It used to carry a MANAGER_EDITION variable and a doctor check that failed when an installation
     | called itself "cloud" while still wrapping backup keys from APP_KEY. That check was useful, and
     | it was useful to *Cloud* — a self-hosted operator could never trip it, and the variable was not
     | even in .env.example, because there was nothing for them to set it to.
     |
     | Carrying it here meant somebody reading the health checks found one about a key service they
     | cannot have, and a settings screen with a badge that is always the same word. The check now
     | lives in the hosting layer, which is the thing it is actually about.
     |
     | What stays is the seam itself: contracts with self-hosted implementations bound behind
     | singletonIf. That is not Cloud scaffolding, it is how the core avoids caring.
     */
    expect(config()->has('manager.edition'))->toBeFalse();

    $names = collect(app(Diagnostics::class)->all())->pluck('name');

    expect($names)->not->toContain('Edition');
});

it('runs every check without one', function (): void {
    // The removal must not have left a doctor that half-works. Every check still reports, and none of
    // them reaches for a config key that is gone.
    $checks = app(Diagnostics::class)->all();

    expect($checks)->not->toBeEmpty();

    foreach ($checks as $check) {
        expect($check->status)->toBeIn(['pass', 'warn', 'fail']);
    }
});
