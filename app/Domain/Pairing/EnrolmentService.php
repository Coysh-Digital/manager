<?php

declare(strict_types=1);

namespace App\Domain\Pairing;

use App\Domain\Audit\AuditRecorder;
use App\Models\Connector;
use App\Models\EnrolmentCode;
use App\Models\Organisation;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Nonce;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issuing sites and the codes that pair them.
 *
 * The counterpart to {@see PairingService}, which consumes what this produces. Kept apart because they
 * are reached by different callers under different authority: issuing is a person with recent
 * authentication, consuming is an unauthenticated request carrying a code.
 *
 * A code is returned in plaintext exactly once, from the method that created it, and only its SHA-256
 * is stored. There is no route, command or column that will reveal it again - lose it and issue another.
 * That is the whole reason it is safe to display: a code that could be retrieved later would be a
 * standing credential sitting in a database, which is precisely what pairing exists to avoid.
 */
final class EnrolmentService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Create a site and issue its first enrolment code.
     *
     * @return array{site: Site, code: string}
     */
    public function createSite(
        Organisation $organisation,
        string $name,
        string $expectedDomain,
        string $environment,
        User $actor,
    ): array {
        return DB::transaction(function () use ($organisation, $name, $expectedDomain, $environment, $actor): array {
            $site = Site::query()->create([
                'organisation_id' => $organisation->id,
                'name' => $name,

                // Stored as a bare host. Pairing compares what the connector reports against this, and
                // a scheme or a path here would make that comparison fail for the wrong reason.
                'expected_domain' => $this->normaliseDomain($expectedDomain),

                'environment' => $environment,
                // Never rather than not: a site that has never paired is a different thing from one
                // that has stopped reporting, and the fleet table says so.
                'status' => Site::STATUS_NEVER_CONNECTED,
            ]);

            $this->audit->record(
                action: 'site.created',
                site: $site,
                actor: $actor,
                targetType: 'site',
                targetId: $site->external_id,
                after: [
                    'name' => $site->name,
                    'expected_domain' => $site->expected_domain,
                    'environment' => $site->environment,
                ],
            );

            return ['site' => $site, 'code' => $this->issue($site, $actor)];
        });
    }

    /**
     * Issue an enrolment code for a site.
     *
     * Any code already outstanding is consumed first. Two live codes for one site would mean a leaked
     * one stayed usable after somebody reissued in response to the leak, which is the situation
     * reissuing is usually a response to.
     *
     * @param  bool  $authoriseReplacement  permit this code to replace an active connector
     */
    public function issue(Site $site, User $actor, bool $authoriseReplacement = false): string
    {
        $code = Nonce::generateEnrolmentCode();

        DB::transaction(function () use ($site, $actor, $authoriseReplacement, $code): void {
            EnrolmentCode::query()
                ->where('site_id', $site->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => Carbon::now()]);

            EnrolmentCode::query()->create([
                'site_id' => $site->id,

                // The hash only. Nothing stores the code itself, here or anywhere.
                'code_hash' => Nonce::hashEnrolmentCode($code),

                'expires_at' => Carbon::now()->addSeconds((int) config('manager.enrolment.ttl')),
                'created_by' => $actor->id,

                // Replacing a working connector is a separate decision from issuing a code, so it is
                // recorded as one. Without this, a code cannot displace an active connector however it
                // is used - which stops a compromised site from silently re-pairing itself.
                'replace_authorised_by' => $authoriseReplacement ? $actor->id : null,
                'replace_authorised_at' => $authoriseReplacement ? Carbon::now() : null,
            ]);

            $this->audit->record(
                action: 'enrolment_code.issued',
                site: $site,
                actor: $actor,
                targetType: 'site',
                targetId: $site->external_id,
                // Never the code, and never its hash - a hash of a 256-bit secret is not sensitive, but
                // recording it would invite somebody to treat the audit log as a place to look one up.
                after: [
                    'expires_in_seconds' => (int) config('manager.enrolment.ttl'),
                    'authorises_replacement' => $authoriseReplacement,
                ],
            );
        });

        return $code;
    }

    /**
     * Whether issuing a code for this site would need replacement authorisation.
     */
    public function wouldReplaceConnector(Site $site): bool
    {
        return $site->connectors()->where('state', Connector::STATE_ACTIVE)->exists();
    }

    /**
     * Reduce whatever somebody typed to a bare hostname.
     *
     * People paste URLs. Accepting one and storing it whole would mean the domain comparison at pairing
     * compares a host against a URL and always disagrees, which presents as a mysterious
     * pending-confirmation rather than as the typo it is.
     */
    public function normaliseDomain(string $value): string
    {
        $value = trim($value);

        if (str_contains($value, '//')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        // A trailing path, port or user info, and any leading www., all removed for the same reason.
        $value = (string) preg_replace('~[/:].*$~', '', $value);
        $value = (string) preg_replace('~^www\.~i', '', $value);

        return strtolower($value);
    }
}
