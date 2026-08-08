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

    /** Something is broken and somebody has to act. */
    public const TONE_BAD = 'bad';

    /** Something has changed for the worse, or needs looking at. */
    public const TONE_WARN = 'warn';

    /** A record of something that happened, correctly. */
    public const TONE_INFO = 'info';

    /**
     * How loud this event is, for anything rendering it.
     *
     * Derived from the type rather than passed in by each factory, because it is a property of the
     * *kind* of event and not of one occurrence: two backup failures are never one urgent and one
     * routine. Deriving it also means a new entry in the catalogue cannot be given a tone in one
     * place and forgotten in another - there is only one place.
     *
     * The three match the interface's own status badges, so an alert about a failed backup is the
     * same red as the row it will be looked at on. A reader moving from an inbox to a screen should
     * not have to learn a second colour language on the way.
     *
     * `default` is INFO rather than an exception: an unrecognised type is a programming error, and
     * the right consequence of one is an alert that arrives looking calm, not an alert that throws
     * on the way out. `tests/Invariants/NotificationToneTest.php` is what stops it staying wrong.
     */
    public function tone(): string
    {
        return self::toneFor($this->type);
    }

    public static function toneFor(string $type): string
    {
        return match ($type) {
            // Nothing is being backed up, or something is exploitable. Both are "act now".
            self::BACKUP_FAILED, self::FINDING_OPENED => self::TONE_BAD,

            // Monitoring has a hole in it. Serious, and not the same as a site being broken - a
            // silent site may be perfectly healthy behind a cron that stopped.
            self::SITE_SILENT, self::CONNECTOR_REVOKED => self::TONE_WARN,

            // Somebody authorised a copy of a customer database, deliberately. Worth a record and
            // worth reading; not worth a red box, which would make the red ones mean less.
            self::CAPABILITY_CONFIRMED => self::TONE_INFO,

            default => self::TONE_INFO,
        };
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
