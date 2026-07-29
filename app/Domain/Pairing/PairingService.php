<?php

declare(strict_types=1);

namespace App\Domain\Pairing;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Capability\CapabilityService;
use App\Models\AuditEvent;
use App\Models\Connector;
use App\Models\EnrolmentCode;
use App\Models\Site;
use coyshdigital\managerprotocol\Keys;
use coyshdigital\managerprotocol\Nonce;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a single-use enrolment code and a connector's public key into a paired site.
 *
 * The whole security of the fleet rests on this being hard to abuse, so the order of checks
 * matters:
 *
 *   1. Reject anything malformed before touching the database.
 *   2. Look up the code by hash and check the guards that should not burn it.
 *   3. Consume the code atomically. A losing race here is a rejection.
 *   4. Create the connector, in a state that reflects whether the domain matched.
 *
 * Step 2 before step 3 is deliberate: rejecting an unauthorised replacement without consuming the
 * code means a legitimate operator can authorise the replacement and reuse the code they already
 * hold, rather than having it silently destroyed by an attacker who found it.
 */
final class PairingService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CapabilityService $capabilities,
    ) {}

    /**
     * @throws PairingRejected
     */
    public function pair(string $code, string $publicKey, string $connectorVersion, string $submittedHost, ?string $ip): PairingResult
    {
        // Shape checks first, so a malformed request never reaches a query.
        if (! Nonce::isValidEnrolmentCode($code)) {
            $this->recordFailure(null, PairingRejected::MALFORMED);

            throw new PairingRejected(PairingRejected::MALFORMED);
        }

        if (! Keys::isValidPublicKey($publicKey)) {
            $this->recordFailure(null, PairingRejected::MALFORMED);

            throw new PairingRejected(PairingRejected::MALFORMED);
        }

        $hash = Nonce::hashEnrolmentCode($code);

        $enrolment = EnrolmentCode::query()->where('code_hash', $hash)->first();

        if ($enrolment === null) {
            $this->recordFailure(null, PairingRejected::UNKNOWN_CODE);

            throw new PairingRejected(PairingRejected::UNKNOWN_CODE);
        }

        $site = $enrolment->site;

        // Counted even when the attempt fails, so repeated probing against a code that does exist
        // is visible rather than only rate-limited away.
        $enrolment->increment('attempts');

        if ($enrolment->isConsumed()) {
            $this->recordFailure($site, PairingRejected::ALREADY_CONSUMED);

            throw new PairingRejected(PairingRejected::ALREADY_CONSUMED);
        }

        if ($enrolment->isExpired()) {
            $this->recordFailure($site, PairingRejected::EXPIRED);

            throw new PairingRejected(PairingRejected::EXPIRED);
        }

        if ($site->isArchived()) {
            $this->recordFailure($site, PairingRejected::SITE_ARCHIVED);

            throw new PairingRejected(PairingRejected::SITE_ARCHIVED);
        }

        $existing = $site->activeConnector()->first();

        // A live connector is not displaced without a person having said so. Otherwise a
        // compromised site could re-pair itself and quietly lock out the real one.
        if ($existing !== null && ! $enrolment->authorisesReplacement()) {
            $this->recordFailure($site, PairingRejected::REPLACEMENT_NOT_AUTHORISED);

            throw new PairingRejected(PairingRejected::REPLACEMENT_NOT_AUTHORISED);
        }

        return DB::transaction(function () use ($enrolment, $site, $existing, $publicKey, $connectorVersion, $submittedHost, $ip): PairingResult {
            // The authoritative check. Everything above is advisory: only this statement decides,
            // and it does so in a way two concurrent requests cannot both win.
            $consumed = DB::selectOne(
                <<<'SQL'
                    UPDATE enrolment_codes
                       SET consumed_at = now(), consumed_ip = ?
                     WHERE id = ?
                       AND consumed_at IS NULL
                       AND expires_at > now()
                 RETURNING id
                SQL,
                [$ip, $enrolment->id],
            );

            if ($consumed === null) {
                // Somebody else consumed it between the read above and this write.
                $this->recordFailure($site, PairingRejected::ALREADY_CONSUMED);

                throw new PairingRejected(PairingRejected::ALREADY_CONSUMED);
            }

            // A host that does not match what the operator expected does not silently succeed. The
            // connector is created dormant and a person is shown both values before it goes live.
            $domainMatches = $this->hostMatches($submittedHost, $site->expected_domain);

            if ($existing !== null) {
                $existing->forceFill(['state' => Connector::STATE_SUPERSEDED])->save();
            }

            $connector = Connector::query()->create([
                'site_id' => $site->id,
                'public_key' => $publicKey,
                'connector_version' => $connectorVersion,
                'state' => $domainMatches ? Connector::STATE_ACTIVE : Connector::STATE_PENDING_CONFIRMATION,
                'submitted_domain' => $submittedHost,
                'pending_reason' => $domainMatches ? null : Connector::REASON_DOMAIN_MISMATCH,
                'paired_at' => $domainMatches ? Carbon::now() : null,
            ]);

            // Capabilities are granted only once the connector is genuinely live. A pairing held
            // for confirmation must not be able to read anything in the meantime.
            $capabilities = $domainMatches ? $this->capabilities->grantDefaultsForPairing($site) : [];

            if ($domainMatches) {
                $site->forceFill(['connector_version' => $connectorVersion])->save();
            }

            $this->audit->record(
                action: $domainMatches ? 'site.paired' : 'site.pairing.held_for_confirmation',
                site: $site,
                actorType: AuditEvent::ACTOR_CONNECTOR,
                actorLabel: 'Connector '.$connectorVersion,
                targetType: 'connector',
                targetId: (string) $connector->id,
                after: [
                    'public_key' => $publicKey,
                    'submitted_domain' => $submittedHost,
                    'expected_domain' => $site->expected_domain,
                    'replaced_connector' => $existing?->id,
                    'capabilities' => $capabilities,
                ],
            );

            return new PairingResult($site, $connector, $capabilities, $domainMatches);
        });
    }

    /**
     * Compare the host a connector paired from with the one the operator expected.
     *
     * Compared as hosts rather than as strings: an operator types "example.org", while the
     * connector reports whatever Craft has configured, which may carry a scheme, a port, a
     * trailing slash or a "www." that nobody considers meaningful.
     */
    private function hostMatches(string $submitted, string $expected): bool
    {
        return $this->normaliseHost($submitted) === $this->normaliseHost($expected);
    }

    private function normaliseHost(string $value): string
    {
        $value = trim(mb_strtolower($value));

        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }

        $value = explode('/', $value)[0];
        $value = explode(':', $value)[0];

        return preg_replace('~^www\.~', '', $value) ?? $value;
    }

    private function recordFailure(?Site $site, string $reason): void
    {
        $this->audit->record(
            action: 'site.pairing.rejected',
            site: $site,
            actorType: AuditEvent::ACTOR_CONNECTOR,
            actorLabel: 'Connector',
            outcome: AuditEvent::OUTCOME_FAILURE,
            failureReason: $reason,
        );
    }
}
