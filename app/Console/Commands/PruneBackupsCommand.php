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

        $stale = BackupArtifact::query()
            ->with('site')
            ->where('state', BackupArtifact::STATE_PENDING)
            ->where('created_at', '<', $cutoff)
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

        $verb = $dryRun ? 'would remove' : 'removed';

        $this->components->info(sprintf(
            '%s %d expired %s; %s kept by the minimum-count rule; %d %s written off as never uploaded.',
            ucfirst($verb),
            $deleted,
            $deleted === 1 ? 'artifact' : 'artifacts',
            $kept === 0 ? 'none' : (string) $kept,
            $abandoned,
            $abandoned === 1 ? 'declaration' : 'declarations',
        ));

        return self::SUCCESS;
    }
}
