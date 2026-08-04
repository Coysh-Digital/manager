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

            // The reason first, because it is the only part that varies and the only part that says
            // what to do about it. "Larger than this connector is configured to back up" is a
            // setting somebody can change; "declared but never uploaded" is not.
            summary: "{$reason} ({$site->name}).",
            site: $site,
            context: array_filter([
                'reason' => $reason,
                'artifact' => $artifactId,
            ], static fn ($value): bool => $value !== null),
        );
    }
}
