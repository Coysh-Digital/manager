<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Notifications\NotificationEvent;
use App\Models\Site;

/**
 * What a person is told when a backup does not complete.
 *
 * One place, because a backup can fail in two quite different ways and both have to read like the
 * same event to whoever gets the message.
 *
 * A site can refuse the job outright - too large for what the connector is configured to take,
 * nothing to encrypt to, a dump that produced no file - and that happens before an artifact row
 * exists at all. Or an artifact is declared and then never arrives, or arrives and fails its
 * checksum, which happens after. The first is reported by the site through the job result; the
 * second is found by the platform. Different code paths, same sentence.
 *
 * The reason is included verbatim and is safe to send. Every string that can reach it is a fixed
 * message from the connector or from {@see BackupService}, never a database name, a path, or
 * anything a site told us about its contents.
 */
final class BackupFailureNotice
{
    public static function event(Site $site, string $reason, ?string $artifactId = null): NotificationEvent
    {
        return new NotificationEvent(
            type: NotificationEvent::BACKUP_FAILED,
            subject: "Backup did not complete: {$site->name}",

            /*
             | What happened, and what it did not happen to. The reason itself is a labelled row.
             |
             | This used to lead with the reason - "{$reason} ({$site->name})." - so that the varying
             | half came first. The cost was that the reason could then only appear once: the email
             | drops any context row whose value the summary already contains, which is what stopped
             | the same sentence printing twice. So the one fact somebody needs to act on was prose
             | in the middle of a paragraph, while "Environment: production" got a labelled row of
             | its own.
             |
             | Generic prose and a labelled Reason row is the other way round, and it is the better
             | one: the sentence is the same every time, so it is read once and skipped afterwards,
             | and the eye goes to the box where every varying fact now is - including the reason,
             | which the alert renders in red.
             |
             | The second sentence is here because it is the first question a failed backup raises
             | and the answer is reassuring. A failure stores nothing; it does not touch what is
             | already stored.
            */
            summary: "A backup of {$site->name} did not complete, so nothing new was stored. Earlier backups are unaffected.",
            site: $site,
            context: array_filter([
                'reason' => $reason,
                'artifact' => $artifactId,
            ], static fn ($value): bool => $value !== null),
        );
    }
}
