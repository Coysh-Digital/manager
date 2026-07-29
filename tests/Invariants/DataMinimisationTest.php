<?php

declare(strict_types=1);

use App\Domain\Capability\CapabilityService;
use App\Models\AuditEvent;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\InventoryReport;
use App\Models\Site;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\SchemaValidator;
use Database\Factories\InventoryReportFactory;

/**
 * Invariant 18: no application content or user records may be collected for ordinary monitoring.
 *
 * The schema in the protocol package is the boundary. These tests prove it is actually enforced on
 * the way in, and that rejecting a payload does not itself become a way to store one.
 */
beforeEach(function (): void {
    $this->keypair = Keys::generateKeypair();
    $this->site = Site::factory()->create();
    Connector::factory()->for($this->site)->withKeypair($this->keypair)->create();
    CapabilityGrant::factory()->for($this->site)->capability('inventory:read')->create();

    $this->path = '/api/connector/v1/inventory';
});

it('accepts a report containing only permitted operational metadata', function (): void {
    postSignedConnectorRequest($this->path, InventoryReportFactory::samplePayload(), $this->site, $this->keypair['secret'])
        ->assertOk()
        ->assertJson(['received' => true, 'schema_version' => 'inventory.v1']);

    expect(InventoryReport::query()->count())->toBe(1)
        ->and($this->site->fresh()->craft_version)->toBe('5.10.8.1')
        ->and($this->site->fresh()->php_version)->toBe('8.3.14');
});

it('rejects a report carrying site content or user records', function (string $field, mixed $value): void {
    $payload = InventoryReportFactory::samplePayload();
    $payload[$field] = $value;

    postSignedConnectorRequest($this->path, $payload, $this->site, $this->keypair['secret'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'payload_rejected');

    // Nothing is stored. A rejected report leaves no trace of its contents anywhere.
    expect(InventoryReport::query()->count())->toBe(0);
})->with([
    'entries' => ['entries', [['id' => 1, 'title' => 'Draft']]],
    'assets' => ['assets', [['filename' => 'private.pdf']]],
    'users' => ['users', [['email' => 'admin@example.org']]],
    'password hashes' => ['password_hashes', ['$2y$13$abc']],
    'sessions' => ['sessions', [['id' => 'abc']]],
    'environment values' => ['env', ['CRAFT_SECURITY_KEY' => 'secret']],
    'licence keys' => ['licence_keys', ['ABCD-1234']],
    'full logs' => ['logs', ['line one', 'line two']],
    'config files' => ['config_files', ['general.php' => '<?php return [];']],
]);

it('rejects database credentials smuggled into a permitted section', function (): void {
    $payload = InventoryReportFactory::samplePayload();
    $payload['database']['password'] = 'hunter2';
    $payload['database']['host'] = 'db.internal';

    postSignedConnectorRequest($this->path, $payload, $this->site, $this->keypair['secret'])
        ->assertStatus(422);

    expect(InventoryReport::query()->count())->toBe(0);
});

it('never records a rejected payload, only the field paths', function (): void {
    $payload = InventoryReportFactory::samplePayload();
    $payload['env'] = ['CRAFT_SECURITY_KEY' => 'a-real-looking-secret-value'];

    postSignedConnectorRequest($this->path, $payload, $this->site, $this->keypair['secret'])
        ->assertStatus(422);

    // A report that failed validation is exactly where forbidden data would be if a connector were
    // misbehaving, so logging it "to help debugging" would defeat the point of rejecting it.
    $audited = AuditEvent::query()->where('action', 'inventory.rejected')->firstOrFail();

    expect($audited->toJson())->not->toContain('a-real-looking-secret-value')
        ->and($audited->toJson())->not->toContain('CRAFT_SECURITY_KEY')
        ->and(implode(' ', $audited->after['problems']))->toContain('env');
});

it('rejects a report from a site without the capability', function (): void {
    $bare = Site::factory()->create();
    $keypair = Keys::generateKeypair();
    Connector::factory()->for($bare)->withKeypair($keypair)->create();

    // Absence of a grant is a denial: a site that has never been granted anything can do nothing.
    postSignedConnectorRequest($this->path, InventoryReportFactory::samplePayload(), $bare, $keypair['secret'])
        ->assertStatus(403)
        ->assertJsonPath('capability', 'inventory:read');

    expect(InventoryReport::query()->count())->toBe(0);
});

it('rejects a report once the capability is revoked', function (): void {
    postSignedConnectorRequest($this->path, InventoryReportFactory::samplePayload(), $this->site, $this->keypair['secret'])
        ->assertOk();

    app(CapabilityService::class)->revoke(
        $this->site, 'inventory:read', null, 'test', 'System'
    );

    // Revocation takes effect on the next request, not at some cache expiry.
    postSignedConnectorRequest($this->path, InventoryReportFactory::samplePayload(), $this->site->fresh(), $this->keypair['secret'])
        ->assertStatus(403);

    expect(InventoryReport::query()->count())->toBe(1);
});

it('requires a signature even with the capability granted', function (): void {
    // Capability and identity are separate checks, and the identity one comes first.
    $this->postJson($this->path, InventoryReportFactory::samplePayload())
        ->assertUnauthorized();

    expect(InventoryReport::query()->count())->toBe(0);
});

it('distinguishes when the connector gathered data from when it arrived', function (): void {
    $payload = InventoryReportFactory::samplePayload();
    $payload['collected_at'] = now()->subHour()->getTimestamp();

    postSignedConnectorRequest($this->path, $payload, $this->site, $this->keypair['secret'])->assertOk();

    $report = InventoryReport::query()->firstOrFail();

    // A report that sat in a queue for an hour must not read as current.
    expect($report->collected_at->diffInMinutes($report->received_at, absolute: true))
        ->toBeGreaterThanOrEqual(59);
});

it('stores nothing outside the agreed field set', function (): void {
    postSignedConnectorRequest($this->path, InventoryReportFactory::samplePayload(), $this->site, $this->keypair['secret'])
        ->assertOk();

    $permitted = array_keys(json_decode(
        (string) file_get_contents(SchemaValidator::schemaDirectory().'/inventory.v1.json'),
        true,
    )['properties']);

    $stored = array_keys(InventoryReport::query()->firstOrFail()->payload);

    // Whatever is in the table came through the allowlist and nothing widened on the way.
    expect(array_diff($stored, $permitted))->toBe([]);
});
