<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Site;

/**
 * Something worth telling somebody about.
 *
 * The catalogue is short on purpose. A notification channel that fires on everything is one people
 * filter into a folder they never open, at which point the channel is worse than nothing because it
 * looks like coverage. These are the things worth interrupting somebody for.
 *
 * The payload carries no site content and no secrets - the same discipline as everything else that
 * leaves the platform. A webhook destination is, by assumption, possibly malicious.
 */
final class NotificationEvent
{
    /** A critical or high finding has opened. */
    public const FINDING_OPENED = 'finding.opened';

    /** A site has stopped checking in, so it is no longer being monitored. */
    public const SITE_SILENT = 'site.silent';

    /** A connector was revoked, whether deliberately or not. */
    public const CONNECTOR_REVOKED = 'connector.revoked';

    /**
     * A capability that needed explicit confirmation was granted.
     *
     * Separate from any read-only grant, because the thing worth hearing about is not "permissions
     * changed" but "somebody authorised a copy of a customer database".
     */
    public const CAPABILITY_CONFIRMED = 'capability.confirmed';

    /**
     * A backup was asked for and did not arrive.
     *
     * The one event on this list that was promised before it existed. The pricing page has said
     * "with a notification when a run does not complete" since launch, and nothing dispatched
     * anything: a site refusing the job, an artifact declared and never uploaded, and bytes that
     * failed their checksum all ended as a row in the audit log and silence everywhere else.
     *
     * A backup nobody knows failed is indistinguishable from a backup that worked, right up until
     * somebody needs it. That makes this the highest-consequence entry in the catalogue even though
     * it is the least dramatic.
     */
    public const BACKUP_FAILED = 'backup.failed';

    /**
     * Everything a destination can subscribe to.
     *
     * @return array<string, string>
     */
    public static function catalogue(): array
    {
        return [
            self::FINDING_OPENED => 'A serious finding is raised',
            self::SITE_SILENT => 'A site stops reporting',
            self::CONNECTOR_REVOKED => 'A connector is revoked',
            self::CAPABILITY_CONFIRMED => 'A permission needing confirmation is granted, such as backups',
            self::BACKUP_FAILED => 'A backup does not complete',
        ];
    }

    public static function isKnown(string $type): bool
    {
        return array_key_exists($type, self::catalogue());
    }

    /**
     * @param  array<string, mixed>  $context  counts, versions and identifiers only
     */
    public function __construct(
        public readonly string $type,
        public readonly string $subject,
        public readonly string $summary,
        public readonly ?Site $site = null,
        public readonly array $context = [],
    ) {}

    /**
     * The webhook body.
     *
     * Note what is here: an event type, a human summary, and identifiers. No configuration values, no
     * version detail beyond what the summary states, and nothing that would tell a reader more about
     * an unpatched site than they need in order to go and look at it.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'event' => $this->type,
            'subject' => $this->subject,
            'summary' => $this->summary,
            'site' => $this->site === null ? null : [
                'id' => $this->site->external_id,
                'name' => $this->site->name,
                'domain' => $this->site->expected_domain,
                'environment' => $this->site->environment,
            ],
            'context' => $this->context === [] ? null : $this->context,
            'occurred_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
