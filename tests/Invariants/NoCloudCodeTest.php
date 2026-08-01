<?php

declare(strict_types=1);

use App\Contracts\BillingAdministration;
use App\Contracts\DirectUploadGrants;
use App\Contracts\KeyService;
use App\Contracts\MailAdministration;
use App\Contracts\ObjectStore;
use App\Contracts\ProductLabel;
use App\Contracts\Provisioner;
use App\Contracts\StorageQuota;
use App\Support\SelfHosted\ConfiguredQuota;
use App\Support\SelfHosted\DerivedKeyService;
use App\Support\SelfHosted\DiskObjectStore;
use App\Support\SelfHosted\NoDirectUploads;
use App\Support\SelfHosted\NullProvisioner;
use App\Support\SelfHosted\SelfHostedBilling;
use App\Support\SelfHosted\SelfHostedLabel;
use App\Support\SelfHosted\SelfHostedMail;
use Illuminate\Support\Facades\File;

/**
 * The public repository contains no Cloud code, and cannot ship any.
 *
 * This repository is public and is what self-hosted customers install. The Cloud overlay is private
 * and holds things that are nobody else's business: Stripe billing, the managed key service, the
 * per-organisation storage implementations, provisioning, suspension and purge.
 *
 * The seam between them is deliberate and works well — the core defines interfaces in `app/Contracts`
 * and the overlay binds implementations to them. A self-hosted installation sees the interface and a
 * self-hosted implementation, which is exactly right: `DirectUploadGrants` is a public contract and
 * `S3UploadGrants` is not.
 *
 * What this test guards is the accident, not the design. During development the overlay is mounted at
 * `/var/www/overlay` and installed from a path repository, but somebody working on both at once will
 * eventually end up with a copy sitting inside this checkout — and at that point two things happen by
 * default rather than by decision:
 *
 *  - `git add -A` stages it, and a push to a public repository cannot be undone. A force-push removes
 *    the ref; it does not remove the objects from anybody who fetched, or from GitHub's cache, or from
 *    the forks network.
 *  - `docker build` ships it. The Dockerfile is one `COPY . .` followed by removing a fixed list of
 *    development files, so anything new in the working directory is included by default. That is the
 *    right default for source and the wrong one for this: every self-hosted customer would receive the
 *    billing code inside their image.
 *
 * `.gitignore` and `.dockerignore` both exclude `cloud/`, and both are files somebody can edit. This
 * test is the thing that notices when they have been.
 */
/**
 * Whether this checkout is the private deployment rather than the public core.
 *
 * The two branches genuinely differ, and the difference has to be detectable from inside the tree: on
 * `console` in the private mirror, `cloud/` is committed on purpose and is the whole point of the
 * branch. On public `main` it must not exist. A check that fired on both would be wrong on one of
 * them, and the one it would be wrong on is the one somebody would then disable.
 *
 * `composer.json` is the signal because it is the thing that actually differs: the private deployment
 * requires the Cloud layer, and the public core cannot mention it.
 */
function isPrivateDeployment(): bool
{
    $manifest = @file_get_contents(base_path('composer.json')) ?: '';

    return str_contains($manifest, 'manager-cloud-overlay')
        || preg_match('~"autoload"[^}]*Cloud\\\\~s', $manifest) === 1;
}

it('has no Cloud implementation anywhere it could be committed or shipped', function (): void {
    // Skipped on the private deployment, where Cloud code is expected and correct.
    if (isPrivateDeployment()) {
        expect(true)->toBeTrue();

        return;
    }

    $root = base_path();

    /*
     | Directories that would be committed or copied into an image.
     |
     | `vendor/` is excluded because the overlay is legitimately installed there on a Cloud
     | deployment — that is how it is meant to arrive — and it is excluded from both the image build
     | and the git index already.
     */
    $offenders = [];

    foreach (['app', 'config', 'database', 'routes', 'resources', 'bootstrap', 'public'] as $directory) {
        $path = $root.'/'.$directory;

        if (! is_dir($path)) {
            continue;
        }

        foreach (File::allFiles($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getRealPath());

            // The overlay's own namespace. A file in the core declaring or importing it is either a
            // copy of overlay code or a core file that has grown a hard dependency on the private
            // edition — and both are the same problem for somebody self-hosting.
            if (preg_match('~^\s*(namespace|use)\s+Cloud\\\\~m', $contents) === 1) {
                $offenders[] = str_replace($root.'/', '', $file->getRealPath());
            }
        }
    }

    expect($offenders)->toBe([], 'Cloud code found in the public core: '.implode(', ', $offenders));
});

it('carries no working copy of the overlay in the checkout', function (): void {
    // The specific accident: `cloud/` beside `app/`, holding a checkout of the private repository.
    // It has happened, which is why this is a test rather than a comment in a README.
    //
    // On the private deployment it is not an accident, it is the branch's reason for existing.
    if (isPrivateDeployment()) {
        expect(is_dir(base_path('cloud')))->toBeTrue(
            'This is the private deployment and cloud/ is missing, so the Cloud layer would not load.'
        );

        return;
    }

    $path = base_path('cloud');

    if (! is_dir($path)) {
        expect(true)->toBeTrue();

        return;
    }

    // If one is present it must be invisible to both git and Docker. Read the ignore files directly
    // rather than shelling out to git, so this passes in a container without a .git directory.
    $gitignore = @file_get_contents(base_path('.gitignore')) ?: '';
    $dockerignore = @file_get_contents(base_path('.dockerignore')) ?: '';

    expect(preg_match('~^/?cloud/~m', $gitignore))->toBe(
        1,
        'A cloud/ directory exists and .gitignore does not exclude it. `git add -A` would publish the '
        .'private overlay to a public repository, and that cannot be undone.',
    );

    expect(preg_match('~^/?cloud/~m', $dockerignore))->toBe(
        1,
        'A cloud/ directory exists and .dockerignore does not exclude it. The self-hosted image would '
        .'ship the billing and key-service code to every customer who runs it.',
    );
});

it('keeps the edition seams as contracts rather than as dependencies', function (): void {
    /*
     | The other half of the same idea, stated as an assertion.
     |
     | Every Cloud-replaceable concern is an interface here with a self-hosted implementation beside
     | it. A self-hosted installation is complete: it never resolves to something it does not have,
     | and it never carries code it cannot run.
     |
     | A new seam belongs in this list, and the set-equality assertion below is what makes that
     | mandatory rather than customary. One added without a self-hosted implementation is a
     | self-hosted installation that boots and then fails somewhere unhelpful.
     |
     | Not numbered any more. It said "a fifth, sixth or seventh" and the count moved twice while the
     | sentence stayed put, which is the failure mode this whole file exists to prevent.
     */
    $seams = [
        KeyService::class => DerivedKeyService::class,
        ObjectStore::class => DiskObjectStore::class,
        Provisioner::class => NullProvisioner::class,
        StorageQuota::class => ConfiguredQuota::class,
        DirectUploadGrants::class => NoDirectUploads::class,
        ProductLabel::class => SelfHostedLabel::class,
        MailAdministration::class => SelfHostedMail::class,
        BillingAdministration::class => SelfHostedBilling::class,
    ];

    foreach ($seams as $contract => $implementation) {
        expect(interface_exists($contract))->toBeTrue("{$contract} is not an interface.")
            ->and(class_exists($implementation))->toBeTrue(
                "{$contract} has no self-hosted implementation, so a self-hosted installation would "
                .'resolve it to nothing.'
            );
    }

    // And every contract in app/Contracts is one of them, so a new seam cannot be added without
    // appearing here and being given a self-hosted answer.
    $declared = collect(File::files(app_path('Contracts')))
        ->map(fn ($file): string => 'App\\Contracts\\'.$file->getFilenameWithoutExtension())
        ->sort()
        ->values()
        ->all();

    expect($declared)->toBe(collect(array_keys($seams))->sort()->values()->all());
});
