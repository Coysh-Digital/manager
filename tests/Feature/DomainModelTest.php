<?php

declare(strict_types=1);

use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Membership;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;

it('gives every externally visible model an unguessable identifier', function (string $class): void {
    $model = $class::factory()->create();

    // A ULID rather than a sequential integer, so holding one resource tells you nothing about
    // how many others exist.
    expect($model->external_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($model->getRouteKeyName())->toBe('external_id');
})->with([Organisation::class, Site::class, User::class]);

it('permits at most one active connector per site', function (): void {
    $site = Site::factory()->create();

    Connector::factory()->for($site)->create();

    // Enforced by a partial unique index rather than by application code, because "two connectors
    // both believed they were live" is precisely the state a compromised site would aim for.
    expect(fn () => Connector::factory()->for($site)->create())
        ->toThrow(QueryException::class);
});

it('allows a revoked connector to coexist with an active one', function (): void {
    $site = Site::factory()->create();

    Connector::factory()->for($site)->revoked()->create();
    $active = Connector::factory()->for($site)->create();

    expect($site->activeConnector()->first()->id)->toBe($active->id)
        ->and($site->connectors()->count())->toBe(2);
});

it('treats a site with no grants as able to do nothing', function (): void {
    $site = Site::factory()->create();

    expect($site->grantedCapabilities())->toBe([])
        ->and($site->hasCapability('inventory:read'))->toBeFalse();
});

it('reports only granted capabilities', function (): void {
    $site = Site::factory()->create();

    CapabilityGrant::factory()->for($site)->capability('inventory:read')->create();
    CapabilityGrant::factory()->for($site)->capability('backups:create')->revoked()->create();

    expect($site->grantedCapabilities())->toBe(['inventory:read'])
        ->and($site->hasCapability('backups:create'))->toBeFalse();
});

it('treats revoked membership as no access from the next request', function (): void {
    $user = User::factory()->create();
    $organisation = Organisation::factory()->create();

    $membership = Membership::factory()->for($user)->for($organisation)->admin()->create();

    expect($user->membershipFor($organisation))->not->toBeNull()
        ->and($membership->canAdminister())->toBeTrue();

    $membership->update(['revoked_at' => now()]);

    // The row survives so audit records still resolve, but access ends immediately.
    expect($user->fresh()->membershipFor($organisation))->toBeNull()
        ->and($membership->fresh()->canAdminister())->toBeFalse();
});

it('does not count an unconfirmed second factor as enrolled', function (): void {
    $user = User::factory()->create(['totp_secret' => 'ABCDEFGHIJKLMNOP']);

    // Starting enrolment and abandoning it must not satisfy an MFA requirement.
    expect($user->hasConfirmedTotp())->toBeFalse();

    // Assigned explicitly rather than mass-assigned, because that is the only way it can be set:
    // see the test below.
    $user->totp_confirmed_at = now();
    $user->save();

    expect($user->fresh()->hasConfirmedTotp())->toBeTrue();
});

it('refuses to mass-assign security attributes', function (string $attribute, mixed $value): void {
    $user = User::factory()->create();

    // Request input must never be able to confirm a second factor, rewrite an account's public
    // identifier, or backdate the recent-authentication check that gates sensitive actions.
    $user->update([$attribute => $value]);

    expect($user->fresh()->getAttribute($attribute))->not->toEqual($value);
})->with([
    'totp confirmation' => ['totp_confirmed_at', '2020-01-01 00:00:00'],
    'external identifier' => ['external_id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'recent authentication' => ['last_authenticated_at', '2020-01-01 00:00:00'],
]);

it('encrypts the second-factor secret at rest', function (): void {
    $user = User::factory()->create(['totp_secret' => 'ABCDEFGHIJKLMNOP']);

    $stored = DB::table('users')->where('id', $user->id)->value('totp_secret');

    // A database dump on its own must not yield working second factors.
    expect($stored)->not->toBe('ABCDEFGHIJKLMNOP')
        ->and($user->fresh()->totp_secret)->toBe('ABCDEFGHIJKLMNOP');
});

it('excludes secrets from a serialised user', function (): void {
    $user = User::factory()->create(['totp_secret' => 'ABCDEFGHIJKLMNOP']);

    $array = $user->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('totp_secret')
        ->and($array)->not->toHaveKey('remember_token');
});
