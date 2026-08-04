<?php

declare(strict_types=1);

use App\Support\SelfHosted\DiskObjectStore;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * The S3 backup disk actually works.
 *
 * `MANAGER_BACKUP_DRIVER=s3` has been documented as a supported destination for a while, and until
 * now it was not: `league/flysystem-aws-s3-v3` was never a dependency, so pointing the backup disk at
 * S3 threw a driver-not-supported error on the first upload. An operator would have found out when
 * their first backup failed, which is the worst moment to learn that the documentation was wrong.
 *
 * This is a self-hosted concern rather than a Cloud one. Cloud has its own object store binding; a
 * self-hoster reaches for this exact config key because the documentation tells them to.
 */
it('builds an S3-backed backup disk', function (): void {
    config([
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.bucket' => 'example-backups',
        'filesystems.disks.backups.region' => 'eu-west-2',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'not-a-real-secret',
    ]);

    // No network call: building the adapter is what used to fail, and it is what a self-hoster hits.
    $disk = Storage::disk('backups');

    expect($disk)->not->toBeNull()
        ->and($disk->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
});

it('builds one for an S3-compatible service with a custom endpoint', function (): void {
    // MinIO, Backblaze, DigitalOcean Spaces and the rest. The endpoint and path-style options are the
    // ones that separate "S3" from "S3-compatible", and both have to survive into the adapter.
    config([
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.bucket' => 'example-backups',
        'filesystems.disks.backups.region' => 'us-east-1',
        'filesystems.disks.backups.key' => 'minioadmin',
        'filesystems.disks.backups.secret' => 'minioadmin',
        'filesystems.disks.backups.endpoint' => 'https://storage.example.com',
        'filesystems.disks.backups.use_path_style_endpoint' => true,
    ]);

    expect(Storage::disk('backups')->getAdapter())
        ->toBeInstanceOf(AwsS3V3Adapter::class);
});

/*
|--------------------------------------------------------------------------------------------------
| Handing the browser a URL of its own
|--------------------------------------------------------------------------------------------------
|
| Downloading an artifact streams it through the application unless the store can sign a URL, and on a
| multi-gigabyte backup that difference is a worker held for the length of the transfer against one
| that is not involved at all. It is not a Cloud-only capability and should not be: a self-hoster who
| has already pointed the backup disk at MinIO or Backblaze has paid for an object store and should
| get the benefit of it.
*/

it('gives no temporary URL for a local volume, rather than pretending', function (): void {
    config(['manager.backups.disk' => 'local']);

    /*
     | Null is an ordinary answer here, not a failure: the caller streams instead, which is correct and
     | is the reason the contract allows null at all.
     |
     | This assertion is load-bearing rather than decorative. Laravel's local driver *does* answer
     | providesTemporaryUrls(), signing a URL to a route it serves itself - so the obvious
     | implementation hands out a link that ignores the filename we asked for and serves a customer's
     | artifact from outside this application's authorisation and audit. This test failed on exactly
     | that before the driver check went in.
    */
    expect(app(DiskObjectStore::class)->temporaryUrl('org-1/site-1/2026/08/a.artifact', 300))->toBeNull();
});

it('signs a URL when the backup disk is S3-compatible', function (): void {
    config([
        'manager.backups.disk' => 'backups',
        'filesystems.disks.backups.driver' => 's3',
        'filesystems.disks.backups.bucket' => 'example-backups',
        'filesystems.disks.backups.region' => 'eu-west-2',
        'filesystems.disks.backups.key' => 'AKIAEXAMPLE',
        'filesystems.disks.backups.secret' => 'not-a-real-secret',
    ]);

    $url = app(DiskObjectStore::class)->temporaryUrl('org-1/site-1/2026/08/01JZXABCDEF.artifact', 300);

    expect($url)->toBeString()
        ->and($url)->toContain('X-Amz-Signature=')
        ->and($url)->toContain('X-Amz-Expires=300')
        // The browser saves it under the name `manager-restore` expects to be handed. A redirect
        // leaves nowhere to set a filename afterwards, so it has to be inside the signature.
        ->and(urldecode((string) $url))->toContain('attachment; filename="01JZXABCDEF.artifact"');

    // The secret signs the URL; it must never appear in one.
    expect($url)->not->toContain('not-a-real-secret');
});
