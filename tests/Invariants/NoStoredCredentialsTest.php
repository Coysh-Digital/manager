<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invariants 1 to 3.
 *
 *   1. Manager must never require a Craft administrator password.
 *   2. Manager must never require SSH credentials.
 *   3. Manager must never store a website's database password.
 *
 * These hold because there is nowhere in the schema to put such a thing. That is a property worth
 * testing rather than asserting in a comment: the failure mode is somebody adding a
 * well-intentioned "ssh_key" column two years from now, and this test is what stops that landing
 * quietly.
 *
 * The test walks the live schema rather than a hard-coded list of tables, so a new table is
 * covered the moment it is created.
 */
/**
 * Column names that would indicate a stored site credential.
 *
 * @return list<string>
 */
function forbiddenCredentialFragments(): array
{
    return [
        'admin_password',
        'administrator_password',
        'site_password',
        'db_password',
        'database_password',
        'ssh_key',
        'ssh_private_key',
        'ssh_password',
        'ssh_user',
        'private_key',
        'root_password',
        'ftp_password',
        'sftp_password',
    ];
}

/**
 * Every column in the application schema, as "table.column".
 *
 * @return list<string>
 */
function allSchemaColumns(): array
{
    $columns = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        foreach (Schema::getColumnListing($table) as $column) {
            $columns[] = "{$table}.{$column}";
        }
    }

    return $columns;
}

it('has nowhere to store a site credential', function (): void {
    $offenders = [];

    foreach (allSchemaColumns() as $qualified) {
        [, $column] = explode('.', $qualified, 2);

        foreach (forbiddenCredentialFragments() as $fragment) {
            if (str_contains($column, $fragment)) {
                $offenders[] = "{$qualified} (matched '{$fragment}')";
            }
        }
    }

    expect($offenders)->toBe([], implode(', ', $offenders));
});

it('stores only a public key for each connector', function (): void {
    $columns = Schema::getColumnListing('connectors');

    // A private key on the platform side would mean Manager could impersonate a site, and that a
    // database compromise would hand an attacker every site at once.
    expect($columns)->toContain('public_key')
        ->and($columns)->not->toContain('private_key')
        ->and($columns)->not->toContain('secret_key');
});

it('never stores an enrolment code in the clear', function (): void {
    $columns = Schema::getColumnListing('enrolment_codes');

    expect($columns)->toContain('code_hash')
        ->and($columns)->not->toContain('code')
        ->and($columns)->not->toContain('plaintext');
});

it('keeps the audit log append-only at the database, not merely in the application', function (): void {
    // Verified here rather than only in the audit test, because this is the mechanism that holds
    // even against a direct psql session by the table owner.
    $triggers = DB::select(
        'SELECT tgname FROM pg_trigger WHERE tgrelid = :table::regclass AND NOT tgisinternal',
        ['table' => 'audit_events']
    );

    $names = array_map(fn (object $row): string => $row->tgname, $triggers);

    expect($names)->toContain('audit_events_reject_mutation')
        ->and($names)->toContain('audit_events_reject_truncate');
});
