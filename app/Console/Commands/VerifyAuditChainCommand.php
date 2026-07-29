<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditChainVerifier;
use App\Models\Organisation;
use Illuminate\Console\Command;

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

        foreach ($chains as $label => $organisationId) {
            $result = $verifier->verify($organisationId);

            if ($result->isIntact()) {
                $this->components->info("{$label}: {$result->eventsChecked} events, chain intact.");

                continue;
            }

            $intact = false;

            $this->components->error("{$label}: chain verification FAILED after {$result->eventsChecked} events.");

            foreach ($result->problems as $problem) {
                $this->line("  - {$problem}");
            }
        }

        if (! $intact) {
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
