<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Support\CorrelationId;
use Illuminate\Support\Carbon;

/**
 * Recording what happened to a backup, as distinct from who decided it.
 *
 * The audit log answers "who did what, and can we prove it". This answers "what happened to this
 * artifact", which is a different question with different requirements: it is high volume, it may be
 * incomplete, and none of it needs to be provable. Keeping them apart is what lets the audit log stay
 * hash-chained and unprunable without a nightly fleet of backups making it either.
 *
 * Two rules this class enforces so call sites cannot get them wrong:
 *
 *  - **A site's clock is never authoritative.** Both timestamps are kept and ordering always uses ours,
 *    so a site with a wrong clock - or a deliberately wrong one - cannot reorder its own timeline.
 *  - **A timeline write never fails a backup.** Losing the record that a dump started is annoying;
 *    failing the backup because the record could not be written is worse. Nothing here throws.
 */
final class BackupTimeline
{
    public function __construct(private readonly CorrelationId $correlationId) {}

    /**
     * Note something the platform saw for itself.
     */
    public function platform(
        string $event,
        Site $site,
        ?BackupArtifact $artifact = null,
        ?RemoteJob $job = null,
        ?string $detail = null,
        ?int $bytes = null,
        string $outcome = 'success',
    ): void {
        $this->write($event, BackupEvent::SOURCE_PLATFORM, $site, $artifact, $job, $detail, $bytes, null, $outcome);
    }

    /**
     * Note something a site reported.
     *
     * The site's own timestamp is recorded beside ours rather than instead of it. A report claiming to
     * have happened last Tuesday is still filed where it arrived.
     */
    public function connector(
        string $event,
        Site $site,
        ?BackupArtifact $artifact = null,
        ?RemoteJob $job = null,
        ?string $detail = null,
        ?int $bytes = null,
        ?Carbon $occurredAt = null,
        string $outcome = 'success',
    ): void {
        $this->write(
            $event,
            BackupEvent::SOURCE_CONNECTOR,
            $site,
            $artifact,
            $job,
            $detail,
            $bytes,
            $occurredAt,
            $outcome,
        );
    }

    private function write(
        string $event,
        string $source,
        Site $site,
        ?BackupArtifact $artifact,
        ?RemoteJob $job,
        ?string $detail,
        ?int $bytes,
        ?Carbon $occurredAt,
        string $outcome,
    ): void {
        $now = Carbon::now();

        try {
            BackupEvent::query()->create([
                'backup_artifact_id' => $artifact?->id,
                // The job comes from the caller when there is no artifact yet, and from the artifact
                // once there is. Either way an event stays findable by job, which is how a failed
                // backup that never produced an artifact is still explicable.
                'remote_job_id' => $job === null ? $artifact?->remote_job_id : $job->id,
                'organisation_id' => $site->organisation_id,
                'site_id' => $site->id,
                'event' => $event,
                'source' => $source,
                'outcome' => $outcome,

                // Truncated rather than rejected. A detail is a short human phrase from a closed set on
                // our side and a sanitised class-and-message on the connector's; if one ever arrives
                // long, storing 255 characters of it beats losing the event.
                'detail' => $detail === null ? null : mb_substr($detail, 0, 255),

                'bytes' => $bytes,
                'correlation_id' => $this->correlationId->get(),

                // Falls back to our clock when a site did not state one, so the column is never null and
                // a reader never has to decide what a missing timestamp meant.
                'occurred_at' => $occurredAt ?? $now,
                'recorded_at' => $now,
            ]);
        } catch (\Throwable) {
            // Deliberately swallowed. This is telemetry about a backup, and a backup that succeeded but
            // could not be narrated is still a backup. The states that matter live on the artifact.
        }
    }
}
