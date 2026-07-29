<?php

declare(strict_types=1);

use App\Domain\Pairing\EnrolmentCodeIssuer;
use App\Domain\Pairing\PairingRejected;
use App\Domain\Pairing\PairingService;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\EnrolmentCode;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\CanonicalResponse;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use coyshdigital\managerprotocol\Protocol;

/**
 * The pairing protocol, and invariants 6 and 12.
 *
 *   6. Monitoring access must be read-only by default.
 *  12. A compromised organisation must not expose another organisation.
 */
beforeEach(function (): void {
    config([
        'manager.signing.public_key' => ($platform = Keys::generateKeypair())['public'],
        'manager.signing.secret_key' => $platform['secret'],
    ]);

    $this->platformKeypair = $platform;
    $this->site = Site::factory()->create(['expected_domain' => 'example.org']);
    $this->issuer = User::factory()->create();
    $this->connectorKeypair = Keys::generateKeypair();
});

/**
 * @return array{code: string, record: EnrolmentCode}
 */
function issueCodeFor(Site $site, User $issuer, bool $authoriseReplacement = false): array
{
    return app(EnrolmentCodeIssuer::class)->issue($site, $issuer, $authoriseReplacement);
}

function pairPayload(string $code, string $publicKey, array $overrides = []): array
{
    return array_merge([
        'enrolment_code' => $code,
        'public_key' => $publicKey,
        'connector_version' => '1.0.0',
        'site_url' => 'https://example.org',
        'nonce' => Nonce::generate(),
    ], $overrides);
}

it('pairs a connector and grants read-only access', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $response = $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']));

    $response->assertOk()->assertJson([
        'site_id' => $this->site->external_id,
        'state' => 'active',
        // Invariant 6: what a freshly paired site receives is read-only, and nothing else.
        'capabilities' => ['inventory:read'],
    ]);

    expect($this->site->fresh()->activeConnector()->first()->public_key)
        ->toBe($this->connectorKeypair['public']);
});

it('never grants a capability that could modify a site', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();

    foreach ($this->site->fresh()->grantedCapabilities() as $capability) {
        expect(Protocol::isReadOnlyCapability($capability))->toBeTrue();
    }

    expect($this->site->fresh()->hasCapability('backups:create'))->toBeFalse();
});

it('signs the pairing response against the nonce the connector chose', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);
    $nonce = Nonce::generate();

    $response = $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public'], ['nonce' => $nonce]));

    $signature = str_replace(Protocol::SIGNATURE_SCHEME.'=', '', $response->headers->get(Protocol::HEADER_SIGNATURE));

    $canonical = new CanonicalResponse(
        siteId: $this->site->external_id,
        requestNonce: $nonce,
        status: 200,
        body: $response->getContent(),
    );

    // This signature is the connector's first proof it is talking to the right server.
    expect($canonical->verify($signature, $this->platformKeypair['public']))->toBeTrue();

    // And it is bound to this request: the same body against a different nonce must not verify.
    $replayed = new CanonicalResponse($this->site->external_id, Nonce::generate(), 200, $response->getContent());

    expect($replayed->verify($signature, $this->platformKeypair['public']))->toBeFalse();
});

it('refuses to reuse an enrolment code', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();

    $second = Keys::generateKeypair();

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $second['public']))
        ->assertStatus(422);

    expect(Connector::query()->count())->toBe(1);
});

it('consumes a code with a statement only one caller can win', function (): void {
    // The read-then-check in the service is advisory: under concurrency both callers can pass it.
    // What actually decides is the conditional UPDATE, so that statement is tested directly here
    // rather than through two sequential calls, which would only re-exercise the advisory check.
    ['record' => $record] = issueCodeFor($this->site, $this->issuer);

    $consume = fn (): ?object => DB::selectOne(
        'UPDATE enrolment_codes SET consumed_at = now() WHERE id = ? AND consumed_at IS NULL AND expires_at > now() RETURNING id',
        [$record->id],
    );

    expect($consume())->not->toBeNull()
        // The loser of a real race lands here: the row no longer matches, so it gets nothing back
        // and the service turns that into a rejection.
        ->and($consume())->toBeNull();
});

it('rejects the caller that loses the race for a code', function (): void {
    ['code' => $code, 'record' => $record] = issueCodeFor($this->site, $this->issuer);

    // Stand in for the winner having committed between this caller's read and its own write.
    $record->forceFill(['consumed_at' => now()])->save();

    expect(fn () => app(PairingService::class)->pair(
        $code, $this->connectorKeypair['public'], '1.0.0', 'example.org', '127.0.0.1'
    ))->toThrow(PairingRejected::class);

    expect(Connector::query()->count())->toBe(0);
});

it('refuses an expired code', function (): void {
    ['code' => $code, 'record' => $record] = issueCodeFor($this->site, $this->issuer);

    $record->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))
        ->assertStatus(422);

    expect(Connector::query()->count())->toBe(0);
});

it('refuses a code that was never issued', function (): void {
    $this->postJson('/api/connector/v1/pair', pairPayload(Nonce::generateEnrolmentCode(), $this->connectorKeypair['public']))
        ->assertStatus(422);
});

it('tells a caller nothing about which codes exist', function (): void {
    ['code' => $consumed, 'record' => $record] = issueCodeFor($this->site, $this->issuer);
    $record->forceFill(['consumed_at' => now()])->save();

    $unknown = $this->postJson('/api/connector/v1/pair', pairPayload(Nonce::generateEnrolmentCode(), $this->connectorKeypair['public']));
    $used = $this->postJson('/api/connector/v1/pair', pairPayload($consumed, $this->connectorKeypair['public']));

    $strip = fn (array $json): array => array_diff_key($json, ['correlation_id' => null]);

    expect($unknown->getStatusCode())->toBe($used->getStatusCode())
        ->and($strip($unknown->json()))->toBe($strip($used->json()));
});

it('holds a pairing from an unexpected domain for confirmation', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $response = $this->postJson('/api/connector/v1/pair', pairPayload(
        $code,
        $this->connectorKeypair['public'],
        ['site_url' => 'https://not-the-expected-domain.test'],
    ));

    $response->assertOk()->assertJson(['state' => 'pending_confirmation']);

    $connector = Connector::query()->firstOrFail();

    expect($connector->state)->toBe(Connector::STATE_PENDING_CONFIRMATION)
        ->and($connector->submitted_domain)->toBe('https://not-the-expected-domain.test')
        // Nothing is granted until a person has looked at it.
        ->and($this->site->fresh()->grantedCapabilities())->toBe([])
        ->and($this->site->fresh()->activeConnector()->first())->toBeNull();
});

it('accepts cosmetic differences in how the domain is written', function (string $submitted): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public'], ['site_url' => $submitted]))
        ->assertOk()
        ->assertJson(['state' => 'active']);
})->with([
    'bare host' => ['example.org'],
    'with scheme' => ['https://example.org'],
    'with trailing slash' => ['https://example.org/'],
    'with port' => ['https://example.org:443'],
    'with www' => ['https://www.example.org'],
    'mixed case' => ['HTTPS://Example.ORG'],
]);

it('refuses to displace a live connector without explicit authorisation', function (): void {
    Connector::factory()->for($this->site)->create();

    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    // A compromised site must not be able to re-pair itself and lock out the real one.
    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))
        ->assertStatus(422);

    expect(Connector::query()->where('state', Connector::STATE_ACTIVE)->count())->toBe(1);
});

it('leaves the code usable after refusing an unauthorised replacement', function (): void {
    Connector::factory()->for($this->site)->create();
    ['code' => $code, 'record' => $record] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertStatus(422);

    // Not burned. An operator can now authorise the replacement and use the code they already
    // hold, rather than having it destroyed by whoever found it.
    expect($record->fresh()->isConsumed())->toBeFalse();
});

it('displaces a live connector when a person has authorised it', function (): void {
    $old = Connector::factory()->for($this->site)->create();

    ['code' => $code] = issueCodeFor($this->site, $this->issuer, authoriseReplacement: true);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();

    expect($old->fresh()->state)->toBe(Connector::STATE_SUPERSEDED)
        ->and($this->site->fresh()->activeConnector()->first()->public_key)->toBe($this->connectorKeypair['public']);
});

it('rejects a pairing request carrying fields the platform did not agree to read', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    // Closing the door on silent capability expansion: a connector cannot ask for more than the
    // platform decided to give it.
    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public'], [
        'capabilities' => ['backups:create'],
    ]))->assertStatus(422);

    expect(Connector::query()->count())->toBe(0);
});

it('rejects a malformed public key', function (string $key): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $key))->assertStatus(422);
})->with([
    'not base64' => ['!!!not-a-key!!!'],
    'wrong length' => [base64_encode('too short')],
    'empty' => [''],
]);

it('cannot pair a site into another organisation', function (): void {
    // Invariant 12. The code is bound to a site, and the site to an organisation, so there is no
    // field in the request that could redirect it.
    $otherOrganisation = Site::factory()->create(['expected_domain' => 'example.org']);

    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();

    expect(Connector::query()->firstOrFail()->site_id)->toBe($this->site->id)
        ->and($otherOrganisation->fresh()->activeConnector()->first())->toBeNull();
});

it('audits both a successful pairing and a rejected one', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();
    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertStatus(422);

    $actions = AuditEvent::query()->pluck('action')->all();

    // A rejected attempt is more interesting than a successful one, not less.
    expect($actions)->toContain('site.paired')
        ->and($actions)->toContain('site.pairing.rejected');
});

it('never records the enrolment code itself', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();

    $serialised = AuditEvent::query()->get()->toJson();

    expect($serialised)->not->toContain($code)
        ->and($serialised)->not->toContain(substr($code, 10, 20));
});

it('counts attempts against a code that does exist', function (): void {
    ['code' => $code, 'record' => $record] = issueCodeFor($this->site, $this->issuer);
    $record->forceFill(['expires_at' => now()->subMinute()])->save();

    foreach (range(1, 3) as $ignored) {
        $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']));
    }

    // Repeated probing against a real code is visible, not merely rate-limited away.
    expect($record->fresh()->attempts)->toBe(3);
});

it('refuses to pair an archived site', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->site->forceFill(['archived_at' => now()])->save();

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))
        ->assertStatus(422);
});

it('grants a capability only through the service that records it', function (): void {
    ['code' => $code] = issueCodeFor($this->site, $this->issuer);

    $this->postJson('/api/connector/v1/pair', pairPayload($code, $this->connectorKeypair['public']))->assertOk();

    $grant = CapabilityGrant::query()->where('capability', 'inventory:read')->firstOrFail();

    // Every transition leaves a history entry carrying who, when, from what and to what.
    expect($grant->state)->toBe(CapabilityGrant::STATE_GRANTED)
        ->and($this->site->capabilityGrants()->count())->toBe(1);

    $event = DB::table('capability_events')->where('capability', 'inventory:read')->first();

    expect($event->previous_state)->toBeNull()
        ->and($event->new_state)->toBe(CapabilityGrant::STATE_GRANTED)
        ->and($event->correlation_id)->not->toBeNull();
});
