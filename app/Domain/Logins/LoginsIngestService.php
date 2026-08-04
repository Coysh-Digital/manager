<?php

declare(strict_types=1);

namespace App\Domain\Logins;

use App\Models\LoginReport;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Accepts a sign-in report, or refuses it.
 *
 * The allowlist matters more here than anywhere else in the protocol. `logins.v1` has properties for
 * four integers and a timestamp and `additionalProperties: false`, so a connector that started
 * sending usernames - through a well-meaning change, or a compromised one - is refused at the door
 * rather than having them stripped and stored anyway.
 */
final class LoginsIngestService
{
    public const SCHEMA = 'logins.v1';

    public function __construct(private readonly CorrelationId $correlationId) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function validate(array $payload): array
    {
        return SchemaValidator::forSchema(self::SCHEMA)->validate($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function store(Site $site, array $payload): LoginReport
    {
        return DB::transaction(function () use ($site, $payload): LoginReport {
            $now = Carbon::now();

            return LoginReport::query()->create([
                'site_id' => $site->id,
                'schema_version' => (string) ($payload['schema_version'] ?? self::SCHEMA),
                'payload' => $payload,

                'window_hours' => (int) ($payload['window_hours'] ?? 24),
                'failed_attempts' => (int) ($payload['failed_attempts'] ?? 0),
                'accounts_with_failures' => (int) ($payload['accounts_with_failures'] ?? 0),
                'accounts_locked' => (int) ($payload['accounts_locked'] ?? 0),
                'admin_accounts_affected' => (int) ($payload['admin_accounts_affected'] ?? 0),
                'last_failure_at' => isset($payload['last_failure_at'])
                    ? Carbon::createFromTimestamp((int) $payload['last_failure_at'])
                    : null,

                'collected_at' => Carbon::createFromTimestamp((int) ($payload['collected_at'] ?? $now->getTimestamp())),
                'received_at' => $now,
                'correlation_id' => $this->correlationId->get(),
            ]);
        });
    }
}
