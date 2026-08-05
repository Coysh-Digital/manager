<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Invariant 17: connector and platform updates must use verifiable release artifacts.
 *
 * This was the last invariant with no test, and it stayed that way deliberately for a while - there
 * was nothing to test until releases existed, and a test that merely asserted a workflow file was
 * present would have proved nothing.
 *
 * What is tested here is the actual behaviour an operator depends on: that the build produces the same
 * bytes twice, that verification passes on a good download, and that it *fails* on a bad one. The last
 * of those is the only one that matters if the other two are wrong.
 *
 * The scripts are run for real, in a temporary directory, against a real tag object.
 */
/**
 * @param  list<string>  $command
 * @return array{ok: bool, exit: int, output: string}
 */
function runRelease(array $command, ?string $cwd = null): array
{
    $process = new Process($command, $cwd ?? base_path());
    $process->setTimeout(120);
    $process->run();

    return [
        'ok' => $process->isSuccessful(),
        'exit' => (int) $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];
}

beforeEach(function (): void {
    $this->tag = '_test_'.Str::lower(Str::random(8));
    $this->out = sys_get_temp_dir().'/manager-release-'.Str::random(8);

    // An annotated tag on the current HEAD. Annotated rather than lightweight because that is what a
    // release uses, and because tag.gpgsign refuses a lightweight one anyway.
    //
    // The identity is supplied explicitly: an annotated tag records a tagger, and a CI runner has no
    // git identity configured, so `git tag -a` there fails with "Committer identity unknown". Left
    // implicit, that failure surfaces as six unrelated-looking assertion failures further down —
    // missing archives, missing manifests - rather than as the one thing that actually went wrong.
    $tagged = runRelease([
        'git',
        '-c', 'user.name=Manager Invariants',
        '-c', 'user.email=invariants@manager.example.org',
        'tag', '-a', $this->tag, '-m', 'invariant test', '--no-sign',
    ]);

    // Asserted rather than assumed, so a tag that could not be created says so here.
    expect($tagged['ok'])->toBeTrue($tagged['output']);
});

afterEach(function (): void {
    runRelease(['git', 'tag', '-d', $this->tag]);

    if (is_dir($this->out)) {
        foreach (glob($this->out.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->out);
    }
});

it('produces an archive and a checksum manifest', function (): void {
    $result = runRelease([base_path('bin/build-release.sh'), $this->tag, $this->out]);

    expect($result['ok'])->toBeTrue($result['output']);

    $version = ltrim($this->tag, 'v');

    expect($this->out."/manager-{$version}.tar.gz")->toBeFile()
        ->and($this->out.'/SHA256SUMS')->toBeFile();

    // The manifest names the file with no directory component, so `sha256sum -c` works from wherever
    // the operator downloaded it to.
    $manifest = (string) file_get_contents($this->out.'/SHA256SUMS');

    expect($manifest)->toContain("manager-{$version}.tar.gz")
        ->and($manifest)->not->toContain($this->out);
});

it('builds the same bytes twice', function (): void {
    $first = $this->out.'-a';
    $second = $this->out.'-b';

    runRelease([base_path('bin/build-release.sh'), $this->tag, $first]);
    runRelease([base_path('bin/build-release.sh'), $this->tag, $second]);

    $version = ltrim($this->tag, 'v');

    $a = hash_file('sha256', $first."/manager-{$version}.tar.gz");
    $b = hash_file('sha256', $second."/manager-{$version}.tar.gz");

    // Reproducibility is what lets somebody who is not us confirm that a published artifact was built
    // from the published source. Without it a checksum only proves the download was not corrupted in
    // transit, which is the least interesting of the things that could be wrong.
    expect($a)->toBe($b);

    foreach ([$first, $second] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
});

it('contains only what the tag contains', function (): void {
    runRelease([base_path('bin/build-release.sh'), $this->tag, $this->out]);

    $version = ltrim($this->tag, 'v');

    $listing = runRelease(['tar', '-tzf', $this->out."/manager-{$version}.tar.gz"]);
    $files = $listing['output'];

    // Nothing from the working tree, and nothing that only exists locally. An artifact carrying a
    // developer's uncommitted edit is not built from the tag it claims.
    expect($files)->toContain("manager-{$version}/app/")
        ->and($files)->toContain("manager-{$version}/composer.lock")

        /*
         | Installed dependencies, which means the directory Composer writes at the root.
         |
         | This was `not->toContain('/vendor/')`, matching the substring at any depth — so it also
         | caught `resources/views/vendor/`, which is Laravel's own location for overriding a
         | package's views and is source rather than an installed dependency. It has to ship: the
         | email theme lives there, and without it every MailMessage renders in stock framework
         | styling.
         |
         | Anchored to the archive root instead, which is what the rule was always about. A tarball
         | carrying `vendor/` still fails, and that is the thing that would actually be wrong —
         | dependencies resolved on a build machine rather than by the operator installing it.
         */
        ->and($files)->not->toContain("manager-{$version}/vendor/")
        ->and($files)->not->toContain('/node_modules/')
        ->and($files)->not->toContain('/.git/');

    // A real .env, matched precisely. Testing for the substring ".env" would also match .env.example,
    // which has to be there - the install instructions begin by copying it.
    $paths = preg_split('/\R/', trim($files)) ?: [];

    expect(array_filter($paths, static fn (string $path): bool => str_ends_with($path, '/.env')))
        ->toBe([])
        ->and($files)->toContain("manager-{$version}/.env.example");

    // The compiled assets must be in there, or a fresh install renders nothing - which is exactly the
    // bug the clean-room install found.
    expect($files)->toContain("manager-{$version}/public/build/manifest.json");
});

it('verifies a good download', function (): void {
    runRelease([base_path('bin/build-release.sh'), $this->tag, $this->out]);

    $result = runRelease([base_path('bin/verify-release.sh'), $this->out]);

    expect($result['ok'])->toBeTrue($result['output'])
        ->and($result['output'])->toContain('Integrity confirmed')
        // And it tells the operator that integrity is not authenticity, because a script that implied
        // otherwise would be worse than no script. It used to answer the second question by pointing
        // at a signed tag; there is no longer one, so it says so rather than going quiet.
        ->and($result['output'])->toContain('not authenticity');
});

it('refuses a download that does not match its checksum', function (): void {
    runRelease([base_path('bin/build-release.sh'), $this->tag, $this->out]);

    $version = ltrim($this->tag, 'v');
    $archive = $this->out."/manager-{$version}.tar.gz";

    file_put_contents($archive, 'tampered', FILE_APPEND);

    $result = runRelease([base_path('bin/verify-release.sh'), $this->out]);

    // The one assertion in this file that would matter on the day it mattered. A non-zero exit, so
    // automation stops rather than installing it.
    expect($result['ok'])->toBeFalse()
        ->and($result['exit'])->toBe(1)
        ->and($result['output'])->toContain('Do not install it');
});

it('refuses when the checksum manifest is missing entirely', function (): void {
    runRelease([base_path('bin/build-release.sh'), $this->tag, $this->out]);

    unlink($this->out.'/SHA256SUMS');

    $result = runRelease([base_path('bin/verify-release.sh'), $this->out]);

    // Absent, not merely mismatched. Somebody who removed the manifest must not get a pass by default.
    expect($result['ok'])->toBeFalse()
        ->and($result['output'])->toContain('no SHA256SUMS');
});

it('refuses to build from something that is not a tag', function (): void {
    $result = runRelease([base_path('bin/build-release.sh'), 'v99.99.99-does-not-exist', $this->out]);

    expect($result['ok'])->toBeFalse()
        ->and($result['exit'])->toBe(65);
});

// --------------------------------------------------------------------------------------------------
// The release workflow's own refusals
//
// Release signature verification was removed deliberately: there is no signed tag, no allowed_signers
// and no key to cross-check, so a release now proves integrity and not authorship. The tests that
// asserted the signing gate went with it rather than being softened into warnings.
// --------------------------------------------------------------------------------------------------

it('re-runs the security suite against the tree being published', function (): void {
    $workflow = (string) file_get_contents(base_path('.github/workflows/release.yml'));

    // Ordinary CI runs on the commit, but a tag can be moved afterwards. The release has to check the
    // thing it is actually publishing.
    expect($workflow)->toContain('--testsuite=Invariants');
});
