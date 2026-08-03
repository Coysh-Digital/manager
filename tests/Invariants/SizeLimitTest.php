<?php

declare(strict_types=1);

use coyshdigital\managerprotocol\Protocol;
use Illuminate\Support\Env;

/*
 | A size limit must never be a limit of zero.
 |
 | env('KEY', $fallback) returns the fallback only when the key is absent. Present and blank returns
 | an empty string, and (int) '' is 0 — so a cap that reads as "unset" becomes a cap that refuses
 | everything. .env.example ships several of these lines blank, so copying it is enough to set one.
 |
 | This is not hypothetical and it is not new: the same trap is documented on cloud.kms.region, was
 | found again on the Stripe amounts, and then took down every backup on a live console. A 2.1 MB
 | artifact was refused with HTTP 413 before a byte of the body was read.
 |
 | What this file asserts changed with the ceiling itself, and the protection did not. It used to
 | say "max_bytes is always a positive number", because the only way to express "no ceiling" was a
 | number so large nobody reached it. A ceiling is now null when the operator has not set one, so
 | the assertion is "null, or a positive number — never zero". Same guarantee: **there is no
 | configuration in which this platform refuses every backup it is asked to store.**
 |
 | It also stopped reimplementing the thing it checks. The previous version asserted against
 | `((int) '') ?: $expected` written out in the test body, which is a copy of the config line rather
 | than the config line — it would have stayed green through any change to the real one, including
 | this one. These load config/manager.php with a controlled environment and read what it actually
 | produces.
 */

/**
 * The real config file, evaluated with one environment variable set to a chosen value.
 *
 * `null` means absent rather than blank, which is the distinction this whole file is about.
 */
function configuredWith(string $key, ?string $value): mixed
{
    $repository = Env::getRepository();
    $had = $repository->has($key);
    $previous = $had ? $repository->get($key) : null;

    $value === null ? $repository->clear($key) : $repository->set($key, $value);

    try {
        return require base_path('config/manager.php');
    } finally {
        $had ? $repository->set($key, (string) $previous) : $repository->clear($key);
    }
}

it('treats every way of saying nothing as no backup ceiling rather than a ceiling of zero', function (?string $value): void {
    $config = configuredWith('MANAGER_BACKUP_MAX_BYTES', $value);

    expect($config['backups']['max_bytes'])->toBeNull();
})->with([
    'absent' => [null],
    'blank' => [''],
    'whitespace' => [' '],
    'zero' => ['0'],
    'negative' => ['-1'],
    'not a number' => ['unlimited'],
]);

it('keeps a backup ceiling the operator actually set', function (): void {
    $config = configuredWith('MANAGER_BACKUP_MAX_BYTES', '10737418240');

    expect($config['backups']['max_bytes'])->toBe(10737418240);
});

it('treats a blank payload limit as unset rather than as zero', function (): void {
    // Unchanged, and deliberately still a number. This one bounds a JSON report held in memory, so
    // "no limit" is not a thing it may express — see config/manager.php.
    $config = configuredWith('MANAGER_MAX_PAYLOAD_BYTES', '');

    expect($config['connector']['max_payload_bytes'])->toBe(Protocol::MAX_PAYLOAD_BYTES)
        ->and($config['connector']['max_payload_bytes'])->toBeGreaterThan(0);
});

it('never runs with a size limit that would refuse everything', function (string $config): void {
    // Whatever the environment says. A cap of zero or less is a control plane that accepts no
    // backups and no reports at all, and there is no configuration in which that is the intent.
    // Null is not that: it is the absence of a cap, which accepts everything rather than nothing.
    $value = config($config);

    expect($value === null || (is_int($value) && $value > 0))->toBeTrue(
        "{$config} resolved to a limit that would refuse every request."
    );
})->with([
    'manager.backups.max_bytes',
    'manager.connector.max_payload_bytes',
]);

it('leaves no way for the payload limit to be absent', function (): void {
    // The two limits are deliberately different shapes, and this is what stops the difference being
    // read as an oversight later: a null here would not mean "unlimited", it would mean an
    // unbounded string read into memory.
    expect(config('manager.connector.max_payload_bytes'))->toBeInt()->toBeGreaterThan(0);
});
