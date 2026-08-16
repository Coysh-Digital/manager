<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Notifications\FailureReason;
use App\Models\RemoteJob;
use App\Models\Site;
use coyshdigital\managerprotocol\Jobs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What became of the backups a screen was watching.
 *
 * {@see InFlightBackups} answers "what is outstanding", which is enough to draw the stepper and was
 * enough while a finished job meant reloading the page. It is not enough to say what happened,
 * because by the time anybody asks, the job has left that set - and "it is no longer in the list" is
 * true of a backup that was stored, one that failed, and one somebody cancelled.
 *
 * **Answered by identifier rather than by time.** The caller names the jobs it was already showing,
 * and gets those back. A "everything that settled in the last fifteen minutes" query would be
 * shorter and would also announce jobs to a tab that never saw them start - somebody else's manual
 * backup, or a scheduled one that ran while the screen sat open - which is a notification nobody
 * asked for about work they were not doing.
 *
 * Scoped to the organisation on the way in, like everything else that takes an identifier from a
 * browser. An identifier from another tenant matches nothing; it does not 404, because the caller is
 * a poller reconciling a list and one stale entry should not fail the other four.
 */
final class SettledBackups
{
    /**
     * How many identifiers will be looked up at once.
     *
     * The screen cannot show more in-flight backups than an organisation has sites, and the poller
     * only ever asks about cards it drew. This is a bound on the query, not a product rule.
     */
    private const MAX_LOOKUP = 100;

    /**
     * @param  list<string>  $jobExternalIds
     * @return Collection<int, SettledBackup>
     */
    public function forOrganisation(int $organisationId, array $jobExternalIds): Collection
    {
        return $this->assemble(
            $organisationId,
            $jobExternalIds,
            static fn ($query) => $query->whereHas(
                'site',
                static fn ($sites) => $sites->where('organisation_id', $organisationId),
            ),
        );
    }

    /**
     * @param  list<string>  $jobExternalIds
     * @return Collection<int, SettledBackup>
     */
    public function forSite(Site $site, array $jobExternalIds): Collection
    {
        return $this->assemble(
            $site->organisation_id,
            $jobExternalIds,
            static fn ($query) => $query->where('site_id', $site->id),
        );
    }

    /**
     * @param  list<string>  $jobExternalIds
     * @param  callable(Builder<RemoteJob>): mixed  $scope
     * @return Collection<int, SettledBackup>
     */
    private function assemble(int $organisationId, array $jobExternalIds, callable $scope): Collection
    {
        $wanted = array_values(array_unique(array_filter(
            $jobExternalIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));

        if ($wanted === []) {
            return collect();
        }

        $query = RemoteJob::query()
            ->where('type', Jobs::BACKUP_CREATE)
            ->whereIn('external_id', array_slice($wanted, 0, self::MAX_LOOKUP))
            ->whereIn('state', Jobs::terminalStates())
            ->with('site');

        $scope($query);

        /** @var Collection<int, RemoteJob> $jobs */
        $jobs = $query->get();

        return $jobs->map(fn (RemoteJob $job): SettledBackup => new SettledBackup(
            jobId: $job->external_id,
            siteName: $job->site->name,
            sentence: $this->sentenceFor($job),
            tone: $job->state === Jobs::STATE_SUCCEEDED || $job->state === Jobs::STATE_CANCELLED
                ? 'ok'
                : 'warning',
        ))->values();
    }

    /**
     * What to say about a job that has stopped.
     *
     * A failure borrows {@see FailureReason::sentence()} rather than writing its own copy, so the
     * toast, the "Did not complete" panel and the alert email all describe one event the same way.
     * What a connector reports is the message of an exception it caught, and that is not a sentence
     * to put in front of somebody as an explanation.
     */
    private function sentenceFor(RemoteJob $job): string
    {
        $name = $job->site->name;

        return match ($job->state) {
            Jobs::STATE_SUCCEEDED => "Backup of {$name} stored.",
            Jobs::STATE_CANCELLED => "Backup of {$name} was cancelled.",

            // Not a failure the site reported - a request nobody ever came to collect. Said as what
            // it is, because "the backup failed" would send somebody looking at a site that may be
            // perfectly healthy and simply switched off.
            Jobs::STATE_EXPIRED => "Backup of {$name} was never collected, and is no longer being waited for.",

            default => trim("Backup of {$name} did not complete. ".FailureReason::sentence($job->failure_reason)),
        };
    }
}
