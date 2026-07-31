<?php

declare(strict_types=1);

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
