<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditChainVerifier;
use App\Models\Organisation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Walks every audit chain and reports any break.
 *
 * Worth running on a schedule and after any database restore: a restore from an older backup is
 * indistinguishable from deliberate truncation unless somebody checks.
 */
final class VerifyAuditChainCommand extends Command
{
    protected $signature = 'manager:audit:verify
                            {--organisation= : External ID of a single organisation to check}';

    protected $description = 'Verify the integrity of the append-only audit chains';

    public function handle(AuditChainVerifier $verifier): int
    {
        $chains = $this->chainsToCheck();

        if ($chains === []) {
            $this->components->info('No audit chains to verify.');

            return self::SUCCESS;
        }

        $intact = true;
        $broken = [];

        foreach ($chains as $label => $organisationId) {
            $result = $verifier->verify($organisationId);

            if ($result->isIntact()) {
                $this->components->info("{$label}: {$result->eventsChecked} events, chain intact.");

                continue;
            }

            $intact = false;
            $broken[$label] = $result->problems;

            $this->components->error("{$label}: chain verification FAILED after {$result->eventsChecked} events.");

            foreach ($result->problems as $problem) {
                $this->line("  - {$problem}");
            }
        }

        if (! $intact) {
            /*
             | Written to the log as well as to the terminal, because on the run that matters there is
             | no terminal.
             |
             | This command is scheduled (routes/console.php), and a scheduled command's output goes
             | nowhere by default. So the one execution most likely to find a broken chain - the
             | unattended nightly one - reported it to nobody, while docs/troubleshooting.md tells the
             | reader what to do "if this fails" as though they would find out.
             |
             | `critical` rather than `error`, and that is the whole reason for choosing a level here:
             | a broken audit chain is not a failed job to retry, it is evidence that history has been
             | rewritten. Every log aggregator worth having alerts on critical and samples error.
             |
             | The problems themselves are included. An alert saying "the chain is broken" and making
             | somebody open a shell to find out where is an alert that gets acknowledged and left.
            */
            Log::critical('Audit chain verification failed.', [
                'chains' => $broken,
                'remedy' => 'The audit history can no longer be trusted. Follow the runbook in docs/security.md.',
            ]);

            $this->newLine();
            $this->components->warn('A broken chain means the audit history can no longer be trusted. Treat this as a security incident and follow the runbook in docs/security.md.');
        }

        return $intact ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, int|null>
     */
    private function chainsToCheck(): array
    {
        if ($external = $this->option('organisation')) {
            $organisation = Organisation::query()->where('external_id', $external)->first();

            if (! $organisation) {
                $this->components->error("No organisation with external ID {$external}.");

                return [];
            }

            return [$organisation->name => $organisation->id];
        }

        $chains = ['Platform' => null];

        foreach (Organisation::query()->orderBy('id')->get() as $organisation) {
            $chains[$organisation->name] = $organisation->id;
        }

        return $chains;
    }
}
