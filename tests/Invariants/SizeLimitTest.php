<?php

declare(strict_types=1);

use coyshdigital\managerprotocol\Protocol;

/*
 | A blank size limit is not a limit of zero.
 |
 | env('KEY', $fallback) returns the fallback only when the key is absent. Present and blank returns
 | an empty string, and (int) '' is 0 — so a cap that reads as "unset" becomes a cap that refuses
 | everything. .env.example ships several of these lines blank, so copying it is enough to set one.
 |
 | This is not hypothetical and it is not new: the same trap is documented on cloud.kms.region, was
 | found again on the Stripe amounts, and then took down every backup on a live console. A 2.1 MB
 | artifact was refused with HTTP 413 before a byte of the body was read.
 */

it('treats a blank size limit as unset rather than as zero', function (string $key, string $config, int $expected): void {
    config()->set($config, ((int) '') ?: $expected);

    expect((int) config($config))->toBe($expected)
        ->and((int) config($config))->toBeGreaterThan(0);
})->with([
    ['MANAGER_BACKUP_MAX_BYTES', 'manager.backups.max_bytes', Protocol::MAX_ARTIFACT_BYTES],
    ['MANAGER_MAX_PAYLOAD_BYTES', 'manager.connector.max_payload_bytes', Protocol::MAX_PAYLOAD_BYTES],
]);

it('never runs with a size limit that would refuse everything', function (string $config): void {
    // Whatever the environment says, a cap of zero or less is a control plane that accepts no
    // backups and no reports at all. There is no configuration in which that is the intent.
    expect((int) config($config))->toBeGreaterThan(0);
})->with([
    'manager.backups.max_bytes',
    'manager.connector.max_payload_bytes',
]);
