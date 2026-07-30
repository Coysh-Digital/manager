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

it('fails the doctor when an installation calls itself cloud but still wraps keys from APP_KEY', function (): void {
    config()->set('manager.edition', 'cloud');

    $checks = collect(app(Diagnostics::class)->all())
        ->filter(fn ($check): bool => $check->name === 'Edition');

    expect($checks)->toHaveCount(1)
        ->and($checks->first()->status)->toBe('fail');
});

it('passes the doctor on a self-hosted installation', function (): void {
    config()->set('manager.edition', 'self-hosted');

    $check = collect(app(Diagnostics::class)->all())
        ->first(fn ($c): bool => $c->name === 'Edition');

    expect($check->status)->toBe('pass');
});
