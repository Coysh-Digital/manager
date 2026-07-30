<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Backup\BackupService;
use App\Models\BackupArtifact;
use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Deletes artifacts past their retention date.
 *
 * A backup kept indefinitely is personal data kept indefinitely, so retention is not optional and the
 * default is not "forever".
 *
 * Two rules, and the interaction between them is the point. An artifact goes when it is past its expiry
 * date *and* the organisation still has its minimum number of newer ones. Expiry alone would let a short
 * retention window leave somebody with nothing after a quiet fortnight; a count alone would keep the
 * oldest backup of a departed client on disk forever.
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

        foreach (Organisation::query()->cursor() as $organisation) {
            $keep = max(0, $organisation->backup_keep_count);

            // Newest first, so the ones being kept as the floor are the ones worth keeping.
            $artifacts = BackupArtifact::query()
                ->stored()
                // Eager loaded because deleting one audits against its site, and a sweep over a
                // fleet's worth of artifacts would otherwise be a query each.
                ->with('site')
                ->where('organisation_id', $organisation->id)
                ->orderByDesc('taken_at')
                ->get();

            foreach ($artifacts->values() as $index => $artifact) {
                if (! $artifact->hasExpired()) {
                    continue;
                }

                // The floor. Whichever rule keeps an artifact alive wins, so an organisation that has
                // taken nothing recently still has its last few.
                if ($index < $keep) {
                    $kept++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("  would delete {$artifact->external_id} ({$artifact->humanSize()}, taken {$artifact->taken_at->toDateString()})");
                    $deleted++;

                    continue;
                }

                $backups->delete($artifact, 'Past the organisation\'s retention period');
                $deleted++;
            }
        }

        // Declared but never delivered. The window is generous, because a large dump on a slow
        // connection is not a failure — but it is not indefinite either.
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
