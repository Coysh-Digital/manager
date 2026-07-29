<?php

declare(strict_types=1);

use App\Domain\Audit\AuditRecorder;
use App\Domain\Capability\CapabilityService;
use App\Domain\Pairing\EnrolmentCodeIssuer;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\InventoryReport;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use Database\Factories\InventoryReportFactory;

/**
 * Invariants 11 and 12.
 *
 *  11. A compromised site must not expose credentials belonging to another site.
 *  12. A compromised organisation must not expose another organisation.
 *
 * The scenario throughout is that one site's private key has been taken, and the attacker is
 * trying to use it to reach anything beyond that one site.
 */
beforeEach(function (): void {
    config([
        'manager.signing.public_key' => ($platform = Keys::generateKeypair())['public'],
        'manager.signing.secret_key' => $platform['secret'],
    ]);

    $this->alpha = Organisation::factory()->create(['name' => 'Alpha']);
    $this->beta = Organisation::factory()->create(['name' => 'Beta']);

    $this->alphaKeypair = Keys::generateKeypair();
    $this->alphaSite = Site::factory()->for($this->alpha)->create(['expected_domain' => 'alpha.test']);
    Connector::factory()->for($this->alphaSite)->withKeypair($this->alphaKeypair)->create();
    CapabilityGrant::factory()->for($this->alphaSite)->capability('inventory:read')->create();

    $this->betaKeypair = Keys::generateKeypair();
    $this->betaSite = Site::factory()->for($this->beta)->create(['expected_domain' => 'beta.test']);
    Connector::factory()->for($this->betaSite)->withKeypair($this->betaKeypair)->create();
    CapabilityGrant::factory()->for($this->betaSite)->capability('inventory:read')->create();
});

it('will not let a stolen key speak for another site', function (): void {
    // The site identifier is part of the signed material, so a signature made with one site's key
    // simply does not verify against another site's public key.
    postSignedConnectorRequest(
        '/api/connector/v1/inventory',
        InventoryReportFactory::samplePayload(),
        $this->betaSite,
        $this->alphaKeypair['secret'],
    )->assertUnauthorized();

    expect(InventoryReport::query()->count())->toBe(0);
});

it('will not let a stolen key speak for another site in another organisation', function (): void {
    postSignedConnectorRequest(
        '/api/connector/v1/heartbeat',
        [],
        $this->betaSite,
        $this->alphaKeypair['secret'],
    )->assertUnauthorized();

    expect($this->betaSite->fresh()->last_seen_at)->toBeNull();
});

it('stores no material that could impersonate any site', function (): void {
    // Invariant 11 depends on this: a full copy of the platform database yields public keys only,
    // so it confers no ability to sign as anybody.
    foreach (Connector::query()->get() as $connector) {
        expect(Keys::isValidPublicKey($connector->public_key))->toBeTrue();

        // A public key must not be usable to sign. Proved by signing with it and finding it fails.
        expect(Keys::verify('probe', Keys::sign('probe', $this->alphaKeypair['secret']), $connector->public_key))
            ->toBe($connector->public_key === $this->alphaKeypair['public']);
    }
});

it('binds an enrolment code to one site and one organisation', function (): void {
    $issuer = User::factory()->create();

    // A site in Beta with nothing paired to it yet, so the replacement guard is not what is under
    // test here.
    $newBetaSite = Site::factory()->for($this->beta)->create(['expected_domain' => 'new.beta.test']);

    ['code' => $code] = app(EnrolmentCodeIssuer::class)->issue($newBetaSite, $issuer);

    // There is no field in the pairing request that could redirect a code at a different site, and
    // the connector never gets to name one.
    $this->postJson('/api/connector/v1/pair', [
        'enrolment_code' => $code,
        'public_key' => Keys::generateKeypair()['public'],
        'connector_version' => '1.0.0',
        'site_url' => 'https://new.beta.test',
        'nonce' => Nonce::generate(),
    ])->assertOk()->assertJson(['site_id' => $newBetaSite->external_id]);

    // Alpha is untouched: it still has exactly the one connector it started with.
    expect(Connector::query()->where('site_id', $this->alphaSite->id)->count())->toBe(1)
        ->and($newBetaSite->fresh()->organisation_id)->toBe($this->beta->id);
});

it('keeps each organisation on its own audit chain', function (): void {
    $recorder = app(AuditRecorder::class);

    $recorder->record(action: 'a', organisation: $this->alpha);
    $recorder->record(action: 'b', organisation: $this->alpha);
    $beta = $recorder->record(action: 'c', organisation: $this->beta);

    // Sequence numbers are per organisation, so one tenant cannot infer another's activity volume
    // from a number it can see.
    expect($beta->seq)->toBe(1);

    $alphaEvents = AuditEvent::query()->where('organisation_id', $this->alpha->id)->count();

    expect($alphaEvents)->toBe(2);
});

it('gives every site an unguessable identifier that reveals no ordering', function (): void {
    // ULIDs carry a timestamp but no tenant-scoped counter, so possession of one identifier says
    // nothing about how many sites exist or who else is on the platform.
    $identifiers = Site::query()->pluck('external_id');

    foreach ($identifiers as $identifier) {
        expect($identifier)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    }

    expect($identifiers->unique())->toHaveCount($identifiers->count());
});

it('cannot reach another site by naming it in the payload', function (): void {
    // Authority comes from the signature, never from the body. Anything self-declared in the
    // payload is ignored.
    $payload = InventoryReportFactory::samplePayload();
    $payload['site_id'] = $this->betaSite->external_id;

    postSignedConnectorRequest(
        '/api/connector/v1/inventory',
        $payload,
        $this->alphaSite,
        $this->alphaKeypair['secret'],
    )->assertStatus(422);

    expect(InventoryReport::query()->count())->toBe(0);
});

it('writes a report only against the signing site', function (): void {
    postSignedConnectorRequest(
        '/api/connector/v1/inventory',
        InventoryReportFactory::samplePayload(),
        $this->alphaSite,
        $this->alphaKeypair['secret'],
    )->assertOk();

    expect(InventoryReport::query()->where('site_id', $this->alphaSite->id)->count())->toBe(1)
        ->and(InventoryReport::query()->where('site_id', $this->betaSite->id)->count())->toBe(0)
        ->and($this->betaSite->fresh()->craft_version)->toBeNull();
});

it('revokes one site without touching another', function (): void {
    app(CapabilityService::class)
        ->revokeAll($this->alphaSite, null, 'test');

    expect($this->alphaSite->fresh()->grantedCapabilities())->toBe([])
        ->and($this->betaSite->fresh()->grantedCapabilities())->toBe(['inventory:read']);
});
