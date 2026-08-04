<?php

declare(strict_types=1);

use App\Domain\Health\Check;
use App\Domain\Health\Diagnostics;
use App\Models\CapabilityGrant;
use App\Models\Organisation;
use App\Models\Site;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\Sealing;

/**
 * What the health screen says about the platform's own artifact key.
 *
 * The key belongs to the v1 format, where the platform holds the recipient and can read every
 * artifact. An organisation on v2 seals to its own recovery keys and never consults it - so for those
 * sites its absence is correct and its presence is legacy.
 *
 * Reporting one answer for both was wrong in both directions at once, and the second direction is the
 * expensive one: `manager:doctor` runs on every deploy, so a check that fails when a key is correctly
 * absent does not merely mislead, it can stop a deploy.
 */
function backupEncryptionCheck(): Check
{
    foreach (app(Diagnostics::class)->all() as $check) {
        if ($check->name === 'Backup encryption key') {
            return $check;
        }
    }

    throw new RuntimeException('There is no backup encryption check.');
}

/** A site with backups:create granted, under an organisation on the given format. */
function siteGrantedBackupsOn(string $format): Site
{
    $site = Site::factory()
        ->for(Organisation::factory()->create(['backup_format_floor' => $format]))
        ->create();

    CapabilityGrant::query()->create([
        'site_id' => $site->id,
        'capability' => 'backups:create',
        'state' => CapabilityGrant::STATE_GRANTED,
        'granted_at' => now(),
    ]);

    return $site;
}

function configureBackupKeypair(): void
{
    $keypair = Sealing::generateBoxKeypair();

    config([
        'manager.backups.public_key' => $keypair['public'],
        'manager.backups.secret_key' => $keypair['secret'],
    ]);
}

function removeBackupKeypair(): void
{
    config(['manager.backups.public_key' => null, 'manager.backups.secret_key' => null]);
}

it('warns rather than fails when no key is set and nobody can back up', function (): void {
    removeBackupKeypair();

    expect(backupEncryptionCheck()->status)->toBe('warn');
});

it('fails when a v1 site has permission and there is no key', function (): void {
    // The original behaviour, and still correct: these sites genuinely cannot back up.
    removeBackupKeypair();
    siteGrantedBackupsOn(Protocol::BACKUP_FORMAT_V1);

    $check = backupEncryptionCheck();

    expect($check->status)->toBe('fail')
        ->and($check->detail)->toContain('v1');
});

it('passes when the only sites with permission seal to their own recovery keys', function (): void {
    /*
     * The regression that matters.
     *
     * Removing the legacy key is the right thing to do once no v1 artifacts remain, and before this
     * the check called that an outage - on a screen an operator trusts, and in a command that runs on
     * every deploy.
     */
    removeBackupKeypair();
    siteGrantedBackupsOn(Protocol::BACKUP_FORMAT_V2);

    $check = backupEncryptionCheck();

    expect($check->status)->toBe('pass')
        ->and($check->detail)->toContain('not needed');
});

it('still fails when v1 and v2 sites are mixed and there is no key', function (): void {
    // A v2 organisation does not excuse a v1 one that is broken. The failure wins.
    removeBackupKeypair();
    siteGrantedBackupsOn(Protocol::BACKUP_FORMAT_V1);
    siteGrantedBackupsOn(Protocol::BACKUP_FORMAT_V2);

    expect(backupEncryptionCheck()->status)->toBe('fail');
});

it('says backups are readable only while a v1 site is still using the key', function (): void {
    configureBackupKeypair();
    siteGrantedBackupsOn(Protocol::BACKUP_FORMAT_V1);

    expect(backupEncryptionCheck()->detail)->toContain('not end-to-end encrypted');
});

it('does not claim backups are readable once every site seals to its own keys', function (): void {
    /*
     * The other direction of the same bug: telling an operator their backups are readable when the
     * platform no longer holds a key that can read them understates the security they actually have,
     * which is its own kind of wrong.
     */
    configureBackupKeypair();
    siteGrantedBackupsOn(Protocol::BACKUP_FORMAT_V2);

    $check = backupEncryptionCheck();

    expect($check->status)->toBe('pass')
        ->and($check->detail)->not->toContain('not end-to-end encrypted')
        ->and($check->detail)->toContain('no longer used for new backups');
});

it('keeps the original wording when nobody has permission at all', function (): void {
    configureBackupKeypair();

    expect(backupEncryptionCheck()->detail)->toContain('not end-to-end encrypted');
});
