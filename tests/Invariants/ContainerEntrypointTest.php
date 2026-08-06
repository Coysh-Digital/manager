<?php

declare(strict_types=1);

/*
 | What the shipped image refuses to start on.
 |
 | The specification asks a self-hosted deployment to refuse an insecure configuration, and the only
 | reliable moment is before the first request. These checks existed and were conditional on
 | APP_ENV=production, which is the hole rather than the guard: the shipped .env.example said
 | APP_ENV=local and docs/install.md told an operator to copy it, so following the documentation
 | exactly produced an installation where every check written to catch a dangerous configuration was
 | skipped by the setting that made it dangerous.
 |
 | Asserted by running the entrypoint rather than by reading it. A grep for "APP_ENV" would have
 | passed against both the broken version and this one - the audit that found this defect also found
 | an invariant script that asserted a cleanup and passed while the cleanup did not happen, which is
 | the same mistake one level up.
 |
 | Each case supplies a configuration that is otherwise complete, so the only reason to fail is the
 | one under test. Failure is expected *before* anything is exec'd, so no artisan command runs.
 */

/**
 * Run the entrypoint with an environment, and return [exit code, output].
 *
 * @param  array<string, string>  $environment
 * @return array{0: int, 1: string}
 */
function runEntrypoint(array $environment): array
{
    $base = [
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'APP_URL' => 'https://manager.example',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'DB_PASSWORD' => 'a-password-nobody-shipped',
    ];

    $exported = '';
    foreach (array_merge($base, $environment) as $name => $value) {
        $exported .= $name.'='.escapeshellarg($value).' ';
    }

    $script = escapeshellarg(dirname(__DIR__, 2).'/deploy/docker/entrypoint.sh');

    // `doctor` rather than `web`: it is the one command that neither migrates nor starts a
    // supervisor, so a case that unexpectedly *passes* the guards fails the test on a missing
    // database instead of leaving a process behind.
    exec("env {$exported} sh {$script} doctor 2>&1", $output, $code);

    return [$code, implode("\n", $output)];
}

it('refuses to start with debug on', function (): void {
    [$code, $output] = runEntrypoint(['APP_DEBUG' => 'true']);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('APP_DEBUG is on');
});

it('refuses to start on a well-known database password', function (): void {
    [$code, $output] = runEntrypoint(['DB_PASSWORD' => 'password']);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('DB_PASSWORD');
});

it('refuses to start outside production', function (): void {
    // Not a security check. Model::preventLazyLoading() is enabled when the environment is not
    // production, so a lazy load that is merely inefficient in development throws here - and nothing
    // in the resulting stack trace points at APP_ENV.
    [$code, $output] = runEntrypoint(['APP_ENV' => 'local']);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('APP_ENV');
});

it('refuses each of those regardless of what APP_ENV says', function (string $variable, string $value): void {
    // The regression this file exists for. Both of these used to be skipped entirely when APP_ENV
    // was anything other than production, which is exactly the state the shipped example put an
    // installation in.
    [$code, $output] = runEntrypoint(['APP_ENV' => 'staging', $variable => $value]);

    expect($code)->not->toBe(0)
        ->and($output)->toContain($variable);
})->with([
    'debug' => ['APP_DEBUG', 'true'],
    'database password' => ['DB_PASSWORD', 'changeme'],
]);

it('still refuses to start without a key or a URL', function (): void {
    // The checks that were never conditional, asserted so that unpicking the condition did not
    // disturb them.
    expect(runEntrypoint(['APP_KEY' => ''])[1])->toContain('APP_KEY')
        ->and(runEntrypoint(['APP_URL' => ''])[1])->toContain('APP_URL');
});

it('ships an example configuration that satisfies its own entrypoint', function (): void {
    /*
     | The other half, and the one that actually bit. The checks above are worth nothing if the file
     | the documentation tells people to copy is the thing that trips them.
    */
    $example = (string) file_get_contents(dirname(__DIR__, 2).'/.env.example');

    expect($example)->toContain('APP_ENV=production')
        ->and($example)->toContain('APP_DEBUG=false')
        ->and($example)->not->toContain('APP_DEBUG=true');
});
