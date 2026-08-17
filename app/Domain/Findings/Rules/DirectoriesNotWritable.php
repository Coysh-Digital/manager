<?php

declare(strict_types=1);

namespace App\Domain\Findings\Rules;

use App\Domain\Findings\Rule;
use App\Domain\Findings\RuleCategory;
use App\Domain\Findings\RuleMatch;
use App\Domain\Findings\Severity;
use App\Domain\Findings\Snapshot;

/**
 * A directory Craft has to write to that it cannot.
 *
 * Each of the three fails differently, and the difference is what makes this worth a finding rather
 * than a note:
 *
 *  - **storage** - logs, caches, compiled templates and Craft's own backups. The site keeps serving
 *    from whatever is already compiled, so this fails at the next deploy rather than now.
 *  - **cpresources** - where control-panel assets are published. Nothing breaks until somebody opens
 *    the control panel and finds it unstyled, with an error in nobody's log.
 *  - **config/project** - project config cannot be written, so an administrator's change is accepted
 *    on screen and silently not persisted.
 *
 * All three share the property that the site looks fine right up until the moment somebody needs it
 * not to be, which is the argument for reporting it rather than waiting to be told.
 *
 * High rather than critical. Nothing is exposed and nothing is lost that was already saved; what is
 * at stake is the next deploy or the next change.
 */
final class DirectoriesNotWritable implements Rule
{
    /**
     * How each directory is described to somebody who has to fix it.
     *
     * Keyed on the names `system.v3` uses, which are Craft's own rather than paths.
     *
     * @var array<string, string>
     */
    private const CONSEQUENCES = [
        'storage' => 'storage - logs, caches and compiled templates. The site keeps serving what is '
            .'already compiled, so this surfaces at the next deploy rather than now',
        'cpresources' => 'cpresources - where control-panel assets are published. Nothing breaks '
            .'until somebody opens the control panel and finds it unstyled',
        'config_project' => 'the project config directory - an administrator\'s change is accepted '
            .'on screen and then not persisted',
    ];

    public function key(): string
    {
        return 'directories_not_writable';
    }

    public function category(): string
    {
        return RuleCategory::OPERATIONAL;
    }

    public function requiresCapability(): string
    {
        return 'runtime:read';
    }

    public function evaluate(Snapshot $snapshot): ?RuleMatch
    {
        if (! $snapshot->hasRecentRuntime()) {
            return null;
        }

        $unwritable = $snapshot->runtime?->unwritablePaths() ?? [];

        if ($unwritable === []) {
            return null;
        }

        $consequences = [];

        foreach ($unwritable as $path) {
            $consequences[] = self::CONSEQUENCES[$path] ?? $path;
        }

        return new RuleMatch(
            severity: Severity::HIGH,
            title: count($unwritable) === 1
                ? 'A directory Craft needs to write to is not writable'
                : count($unwritable).' directories Craft needs to write to are not writable',
            detail: 'The web server cannot write to '.implode('; ', $consequences).'. Each of these '
                .'leaves the site looking fine until the moment it needs not to be, which is why it '
                .'is worth fixing before somebody discovers it the hard way. Usually ownership or '
                .'permissions after a deploy, or a container that mounted the directory read-only.',
            evidence: ['unwritable' => $unwritable],
        );
    }
}
