<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Models\LoginReport;
use App\Models\RuntimeReport;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Deletes telemetry past its retention window.
 *
 * Three tables, all of which grow on a schedule and none of which anything else would ever remove:
 *
 *  - **Heartbeats.** The smallest record the platform keeps — a site id, a version, a source address
 *    and a time — arriving every five minutes per site. The table's own migration said they were
 *    "pruned on a schedule" from the day it was written; nothing pruned them until the Health screen
 *    started reading them, at which point unbounded growth stopped being merely untidy.
 *  - **Runtime reports.** Four a day per site. Small individually, and the interface only ever reads
 *    the latest one.
 *  - **Login reports.** Forty-eight a day per site — the fastest-growing of the three, and the one
 *    whose rows are almost all identical zeros.
 *
 * Only the latest of each report is ever shown, so keeping ninety days of them is already generous:
 * the history exists so somebody investigating an incident can look back, not because a screen needs
 * it.
 *
 * Deleted in batches rather than one statement. A fleet that has been running a year has millions of
 * rows, and a single unbounded DELETE on a table being written to every few seconds is how a
 * maintenance task becomes an outage.
 *
 * Inventory and update reports are deliberately absent. They are the record of what a site actually
 * sent about itself, and if they ever need a retention rule it will be a stated policy with its own
 * setting rather than a line added quietly here.
 */
final class PruneTelemetryCommand extends Command
{
    protected $signature = 'manager:telemetry:prune
                            {--dry-run : Report what would be removed without removing it}';

    protected $description = 'Delete heartbeats and reported telemetry past their retention window';

    /**
     * Rows per DELETE. Large enough to finish, small enough not to hold a lock anybody notices.
     */
    private const BATCH = 5000;

    public function handle(): int
    {
        $days = max(1, (int) config('manager.health.telemetry_retention_days', 90));
        $before = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $tables = [
            'heartbeats' => new Heartbeat,
            'runtime reports' => new RuntimeReport,
            'sign-in reports' => new LoginReport,
        ];

        $anything = false;

        foreach ($tables as $label => $model) {
            $removed = $this->prune($model, $before, $dryRun);

            if ($removed === 0) {
                continue;
            }

            $anything = true;

            $this->line(sprintf(
                '%s %d %s received before %s.',
                $dryRun ? 'Would delete' : 'Deleted',
                $removed,
                $label,
                $before->toDateTimeString(),
            ));
        }

        if (! $anything) {
            $this->info("Nothing older than {$days} days.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  Model&object{received_at: Carbon}  $model
     */
    private function prune(Model $model, Carbon $before, bool $dryRun): int
    {
        /** @var Builder<Model> $query */
        $query = $model->newQuery()->where('received_at', '<', $before);

        if ($dryRun) {
            return $query->count();
        }

        $deleted = 0;

        // Ids first, then delete by key. `delete()` with a limit is grammar-specific, and this reads
        // as what it is rather than relying on one.
        do {
            $ids = $query->clone()->orderBy('id')->limit(self::BATCH)->pluck('id')->all();

            if ($ids !== []) {
                $deleted += $model->newQuery()->whereIn('id', $ids)->delete();
            }
        } while ($ids !== []);

        return $deleted;
    }
}
