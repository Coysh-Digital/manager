<?php

declare(strict_types=1);

use App\Contracts\DirectUploadGrants;
use App\Contracts\KeyService;
use App\Contracts\ObjectStore;
use App\Contracts\Provisioner;
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

/*
 * The seam between the editions.
 *
 * Four contracts are bound with singletonIf so that an edition shipped as a Composer package can
 * replace them. That is only safe if two things stay true: with nothing installed, this repository
 * resolves entirely to its own implementations; and an installation whose wiring disagrees with the
 * edition it claims to be says so out loud rather than quietly wrapping keys the wrong way.
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
]);

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
