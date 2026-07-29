<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Health\Check;
use App\Domain\Health\Diagnostics;
use Illuminate\Console\Command;

/**
 * The command an operator runs after installing or upgrading.
 *
 * Exits non-zero on any failure so it can gate a deployment. Warnings do not fail the command:
 * something an operator should know about must not block a deploy, or the warnings get suppressed
 * and then the failures get ignored too.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'manager:doctor {--strict : Treat warnings as failures}';

    protected $description = 'Check this installation for configuration and security problems';

    public function handle(Diagnostics $diagnostics): int
    {
        $checks = $diagnostics->all();

        $this->newLine();
        $this->line('  <options=bold>Manager diagnostics</>');
        $this->newLine();

        $failures = 0;
        $warnings = 0;

        foreach ($checks as $check) {
            [$symbol, $colour] = match ($check->status) {
                Check::PASS => ['✓', 'green'],
                Check::WARN => ['!', 'yellow'],
                default => ['✕', 'red'],
            };

            $this->line(sprintf('  <fg=%s>%s</> %-28s %s', $colour, $symbol, $check->name, $check->detail));

            if ($check->remedy !== null) {
                $this->line(sprintf('    <fg=gray>→ %s</>', $check->remedy));
            }

            $check->failed() and $failures++;
            $check->warned() and $warnings++;
        }

        $this->newLine();

        if ($failures > 0) {
            $this->line("  <fg=red;options=bold>{$failures} ".($failures === 1 ? 'failure' : 'failures')."</>, {$warnings} ".($warnings === 1 ? 'warning' : 'warnings'));
            $this->newLine();

            return self::FAILURE;
        }

        if ($warnings > 0 && $this->option('strict')) {
            $this->line("  <fg=yellow;options=bold>{$warnings} ".($warnings === 1 ? 'warning' : 'warnings').'</> (strict mode)');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line($warnings > 0
            ? "  <fg=green>All checks passed</> with {$warnings} ".($warnings === 1 ? 'warning' : 'warnings').'.'
            : '  <fg=green;options=bold>All checks passed.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
