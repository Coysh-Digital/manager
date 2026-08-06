<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use App\Domain\Backup\RetentionPolicy;
use App\Models\BackupArtifact;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes artifacts past their retention date.
 *
 * A backup kept indefinitely is personal data kept indefinitely, so retention is not optional and the
 * default is not "forever".
 *
 * Retention is by period rather than by count - see {@see RetentionPolicy} for why that distinction is
 * the whole point. An artifact goes when its own expiry has passed *and* the policy does not want it as
 * the representative of a week or a month. Whichever rule keeps it alive wins.
 *
 * Expiry is computed when an artifact is stored, so shortening the policy today does not retroactively
 * re-date backups already taken. An operator shortening retention is saying what should happen to
 * future backups; deciding it also applies to the past is not theirs to assume.
 *
 * Also sweeps declarations whose bytes never arrived. A pending artifact is not a backup, and leaving
 * them accumulating would make the interface claim protection that does not exist.
 */
final class PruneBackupsCommand extends Command
{
    protected $signature = 'manager:backups:prune
                            {--dry-run : List what would be removed without removing it}';

    protected $description = 'Delete backup artifacts past their retention date';

    public function handle(BackupService $backups): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $deleted = 0;
        $abandoned = 0;
        $kept = 0;

        /*
         | Per site, not per organisation, and the grouping is the policy rather than a detail.
         |
         | Retention keeps the last backup of each period, so the set it reasons over decides which
         | backups compete with each other. Grouped by organisation, a busy site taking one every
         | night would satisfy every period on its own and a quiet site's monthly copy could be the
         | one discarded - a site losing its history because a different site was healthy.
        */
        foreach (Site::query()->cursor() as $site) {
            $artifacts = BackupArtifact::query()
                ->stored()
                // Eager loaded because deleting one audits against its site, and a sweep over a
                // fleet's worth of artifacts would otherwise be a query each.
                ->with('site')
                ->where('site_id', $site->id)
                ->orderByDesc('taken_at')
                ->get();

            // Computed over the whole set rather than artifact by artifact. Whether a backup survives
            // depends on whether a newer one exists in the same week, which is not a question a single
            // row can answer about itself.
            $protected = RetentionPolicy::forSite($site)->keep($artifacts);

            foreach ($artifacts as $artifact) {
                if (! $artifact->hasExpired()) {
                    continue;
                }

                if (isset($protected[$artifact->id])) {
                    $kept++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  would delete {$artifact->external_id} ({$artifact->humanSize()}, taken {$artifact->taken_at->toDateString()})");
                    $deleted++;

                    continue;
                }

                $backups->delete($artifact, 'Past this site\'s retention period');
                $deleted++;
            }
        }

        // Declared but never delivered. The window is generous, because a large dump on a slow
        // connection is not a failure - but it is not indefinite either.
        $cutoff = Carbon::now()->subSeconds((int) config('manager.backups.upload_window'));

        /*
         | Measured from the last part that arrived, when parts have been arriving.
         |
         | Against `created_at` alone this would write off an upload that is working. That is not
         | hypothetical caution: the `upload_window` config comment records this platform having
         | already made the mistake once, with an hour-long window that failed large backups "on a
         | site that had done every part of the work correctly". Chunked ingest brings it back in a
         | new form, because it makes an upload longer than six hours possible for the first time.
         |
         | An artifact with no parts keeps the old rule exactly. What the window means in both cases
         | is the same - "nothing has happened for this long" - and only what counts as something
         | happening has changed.
        */
        $stale = BackupArtifact::query()
            ->with('site')
            ->where('state', BackupArtifact::STATE_PENDING)
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where(fn ($sub) => $sub->whereNull('staged_at')->where('created_at', '<', $cutoff))
                    ->orWhere(fn ($sub) => $sub->whereNotNull('staged_at')->where('staged_at', '<', $cutoff));
            })
            ->get();

        foreach ($stale as $artifact) {
            if ($dryRun) {
                $this->line("  would write off {$artifact->external_id} (declared but never uploaded)");
                $abandoned++;

                continue;
            }

            $backups->fail($artifact, 'The artifact was declared but never uploaded');
            $abandoned++;
        }

        $orphans = $this->sweepStaging($dryRun);

        $verb = $dryRun ? 'would remove' : 'removed';

        $this->components->info(sprintf(
            '%s %d expired %s; %s kept by the minimum-count rule; %d %s written off as never uploaded; %d orphaned %s cleared.',
            ucfirst($verb),
            $deleted,
            $deleted === 1 ? 'artifact' : 'artifacts',
            $kept === 0 ? 'none' : (string) $kept,
            $abandoned,
            $abandoned === 1 ? 'declaration' : 'declarations',
            $orphans,
            $orphans === 1 ? 'part file' : 'part files',
        ));

        return self::SUCCESS;
    }

    /**
     * Remove part files with no artifact still waiting for them.
     *
     * The belt to {@see BackupService::fail()}'s braces. Failing an artifact already removes its parts,
     * and that covers every ordinary way an upload ends - but a row deleted outside the application, a
     * crash between writing a part and recording it, or a restore of the database over a filesystem
     * that kept its files all leave a file with nothing pointing at it. What is left behind is an
     * encrypted copy of part of a customer's database, on the control plane, and enough of them fill
     * the disk that monitors the whole fleet.
     *
     * Deletes only inside the one directory this application writes parts to, and only files matching
     * the name it writes. A sweep that removed whatever it found in a path from anywhere else is the
     * kind of thing that eventually gets pointed at a directory somebody cared about.
     */
    private function sweepStaging(bool $dryRun): int
    {
        $directory = storage_path('app/private/backup-staging');

        if (! is_dir($directory)) {
            return 0;
        }

        $files = glob($directory.'/*.part');

        if ($files === false || $files === []) {
            return 0;
        }

        // One query rather than one per file. A sweep over a fleet's worth of interrupted uploads
        // should not be a round trip each.
        $awaited = BackupArtifact::query()
            ->where('state', BackupArtifact::STATE_PENDING)
            ->whereNotNull('staged_bytes')
            ->pluck('external_id')
            ->flip();

        $cleared = 0;

        foreach ($files as $file) {
            $id = basename($file, '.part');

            // Written from an identifier this platform generated, so anything that is not shaped like
            // one was not written here and is not this sweep's to delete.
            if (preg_match('~^[0-9A-Za-z]{20,40}$~', $id) !== 1) {
                continue;
            }

            if ($awaited->has($id)) {
                continue;
            }

            if ($dryRun) {
                $this->line("  would clear orphaned parts for {$id}");
            } else {
                @unlink($file);
            }

            $cleared++;
        }

        return $cleared;
    }
}
