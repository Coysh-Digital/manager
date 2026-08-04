<?php

declare(strict_types=1);

use App\Domain\Health\Check;
use App\Domain\Health\Diagnostics;

/**
 * Where a self-hosted operator's backups go, and whether that destination is somewhere sensible.
 *
 * "Bring your own bucket" on this edition means pointing `MANAGER_BACKUP_DRIVER` at an S3-compatible
 * service. That makes the endpoint a URL this application connects to with credentials, so it gets the
 * same scrutiny a webhook destination does - and the one that matters is `169.254.169.254`, where a
 * misconfigured endpoint turns every backup upload into a request for cloud instance credentials.
 *
 * Warnings rather than failures throughout. Some operators genuinely run MinIO on a private network
 * beside Manager, and a check that refused to start would be overruling somebody who knows their own
 * topology. It says so once, where they will see it.
 */
function backupStorageCheck(): Check
{
    foreach (app(Diagnostics::class)->all() as $check) {
        if ($check->name === 'Backup storage') {
            return $check;
        }
    }

    throw new RuntimeException('There is no backup storage check.');
}

it('says where backups go when the destination is a local disk', function (): void {
    config(['manager.backups.disk' => 'backups', 'filesystems.disks.backups.driver' => 'local']);

    expect(backupStorageCheck()->status)->toBe('pass');
});

it('refuses an S3 destination with no bucket', function (): void {
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'secret-example',
        'filesystems.disks.backups.bucket' => '',
    ]);

    expect(backupStorageCheck()->status)->toBe('fail');
});

it('accepts AWS itself without checking anything', function (): void {
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'secret-example',
        'filesystems.disks.backups.bucket' => 'acme-backups',
        'filesystems.disks.backups.endpoint' => null,
    ]);

    expect(backupStorageCheck()->status)->toBe('pass');
});

it('warns about an endpoint pointing at a cloud metadata service', function (string $endpoint): void {
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'secret-example',
        'filesystems.disks.backups.bucket' => 'acme-backups',
        'filesystems.disks.backups.endpoint' => $endpoint,
    ]);

    // The single most valuable SSRF target there is. An endpoint here would send every upload, with
    // credentials attached, to something that answers with more credentials.
    expect(backupStorageCheck()->status)->toBe('warn');
})->with([
    'metadata service' => 'https://169.254.169.254',
    'loopback' => 'https://127.0.0.1:9000',
    'private range' => 'https://10.0.0.5:9000',
    'localhost by name' => 'https://localhost:9000',
]);

it('warns about an endpoint that is not encrypted', function (): void {
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'secret-example',
        'filesystems.disks.backups.bucket' => 'acme-backups',
        'filesystems.disks.backups.endpoint' => 'http://storage.example.com',
    ]);

    // Artifacts are encrypted before they leave a site, so this does not expose their contents. It
    // does expose their sizes, their names and the operator's credentials, and the check says which.
    $check = backupStorageCheck();

    expect($check->status)->toBe('warn')
        ->and($check->detail.$check->remedy)->toContain('credentials');
});

it('accepts a genuine S3-compatible endpoint', function (): void {
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'secret-example',
        'filesystems.disks.backups.bucket' => 'acme-backups',
        'filesystems.disks.backups.endpoint' => 'https://s3.eu-west-2.amazonaws.com',
    ]);

    expect(backupStorageCheck()->status)->toBe('pass');
});

it('warns when an S3 destination has no credentials at all', function (string $missing): void {
    /*
     | Reported from a live deployment, and the reason this check exists.
     |
     | The AWS SDK ends its credential chain at the EC2 instance metadata service. With no key and
     | no secret the upload does not refuse - it stalls for a second against 169.254.169.254 and
     | then fails, leaving the artifact at "uploading" and the operator holding
     |
     |     cURL error 28: Connection timed out ... 169.254.169.254
     |
     | which reads as a network fault rather than a missing variable. Meanwhile this check passed,
     | because a bucket was set and a bucket was all it looked at.
     |
     | It also caught nobody who had set AWS_ACCESS_KEY_ID instead, which .env.example described as
     | the backup credentials. It never was: the backups disk reads MANAGER_BACKUP_S3_* alone.
    */
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.bucket' => 'acme-backups',
        'filesystems.disks.backups.endpoint' => null,
        'filesystems.disks.backups.key' => in_array($missing, ['key', 'both'], true) ? '' : 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => in_array($missing, ['secret', 'both'], true) ? '' : 'secret-example',
    ]);

    $check = backupStorageCheck();

    // A warning rather than a failure: an EC2 host with an instance role attached is a legitimate
    // configuration, and this cannot tell the two apart from configuration alone.
    expect($check->status)->toBe('warn')
        ->and($check->remedy)->toContain('MANAGER_BACKUP_S3_KEY')
        // Names the variable people actually set by mistake, because the sample file told them to.
        ->and($check->remedy)->toContain('AWS_*');
})->with(['key', 'secret', 'both']);
