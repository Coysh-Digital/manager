<?php

declare(strict_types=1);

namespace App\Domain\Pairing;

use App\Domain\Audit\AuditRecorder;
use App\Models\EnrolmentCode;
use App\Models\Site;
use App\Models\User;
use coyshdigital\managerprotocol\Nonce;
use Illuminate\Support\Carbon;

/**
 * Mints enrolment codes.
 *
 * The plaintext is returned to the caller exactly once and never stored, so an operator who loses
 * it issues a new one rather than recovering the old.
 */
final class EnrolmentCodeIssuer
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Issue a code for a site.
     *
     * @param  bool  $authoriseReplacement  allow this code to displace a live connector. Requires
     *                                      the caller to have recently proved their password.
     * @return array{code: string, record: EnrolmentCode}
     */
    public function issue(Site $site, User $issuer, bool $authoriseReplacement = false): array
    {
        $code = Nonce::generateEnrolmentCode();

        $record = EnrolmentCode::query()->create([
            'site_id' => $site->id,
            'code_hash' => Nonce::hashEnrolmentCode($code),
            'expires_at' => Carbon::now()->addSeconds((int) config('manager.enrolment.ttl')),
            'created_by' => $issuer->id,
            'replace_authorised_by' => $authoriseReplacement ? $issuer->id : null,
            'replace_authorised_at' => $authoriseReplacement ? Carbon::now() : null,
        ]);

        $this->audit->record(
            action: 'site.enrolment_code.issued',
            site: $site,
            actor: $issuer,
            targetType: 'enrolment_code',
            targetId: (string) $record->id,
            // The code itself is never recorded. Only the fact that one was issued, when it
            // expires, and whether it may displace a live connector.
            after: [
                'expires_at' => $record->expires_at->toIso8601String(),
                'authorises_replacement' => $authoriseReplacement,
            ],
        );

        return ['code' => $code, 'record' => $record];
    }
}
