<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use App\Domain\Backup\BackupTimeline;
use App\Models\BackupArtifact;
use App\Models\BackupEvent;
use App\Models\RemoteJob;
use App\Models\Site;
use App\Support\CorrelationId;
use coyshdigital\managerprotocol\Jobs;
use coyshdigital\managerprotocol\Protocol;
use coyshdigital\managerprotocol\SchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A site saying which phase of a backup it has reached. Requires `backups:create`.
 *
 * Exactly one stage is sent today, and the restraint is the point. The obvious design gives every
 * phase its own report, and every one of those is a signed request consuming a nonce, a rate-limit
 * slot and an Ed25519 verification to record something the next report already implies. Worse, each is
 * a report that can be lost: a dropped "encryption completed" leaves an artifact stuck in a state that
 * never arrives, and a state machine that lies is worse than one with fewer states.
 *
 * `dump_started` earns its place because it is the only one whose information is not derivable later.
 * It turns "the job was claimed forty minutes ago and we have heard nothing" from ambiguous - site
 * down, or dump still running? - into a definite answer, on the longest phase.
 *
 * Nothing decides anything on what arrives here. A stage is a claim about a site's own state, the site
 * may be wrong or lying, and the artifact's own `state` column is the only thing that gates behaviour.
 */
final class BackupProgressController
{
    public function __invoke(
        Request $request,
        BackupTimeline $timeline,
        CorrelationId $correlationId,
    ): JsonResponse {
        /** @var Site $site */
        $site = $request->attributes->get('manager.site');

        $payload = $request->json()->all();

        $problems = SchemaValidator::forSchema('backup-progress.v1')->validate($payload);

        if ($problems !== []) {
            return response()->json([
                'error' => 'payload_rejected',
                // Field paths only, never values - the protocol validator is written never to quote a
                // rejected value, and this keeps that property at the boundary.
                'problems' => array_slice($problems, 0, 20),
                'correlation_id' => $correlationId->get(),
            ], 422, [Protocol::HEADER_CORRELATION_ID => $correlationId->get()]);
        }

        $job = RemoteJob::query()
            ->where('external_id', $payload['job_id'])
            ->where('site_id', $site->id)
            ->where('type', Jobs::BACKUP_CREATE)
            ->where('state', Jobs::STATE_CLAIMED)
            ->first();

        // Accepted silently when the job is not one this site currently holds. A progress report is
        // telemetry: refusing it would tell a caller which job identifiers exist, and there is nothing
        // to protect by doing so because nothing acts on the contents either way.
        if ($job !== null) {
            $artifact = BackupArtifact::query()->where('remote_job_id', $job->id)->first();

            $timeline->connector(
                event: match ($payload['stage']) {
                    'dump' => BackupEvent::DUMP_STARTED,
                    'encrypt' => BackupEvent::ENCRYPTED,
                    default => BackupEvent::UPLOAD_STARTED,
                },
                site: $site,
                artifact: $artifact,
                job: $job,
                occurredAt: Carbon::createFromTimestamp((int) $payload['at']),
            );

            if ($artifact !== null) {
                $artifact->forceFill([
                    'stage' => $payload['stage'],
                    'stage_at' => Carbon::now(),
                ])->save();
            }
        }

        return response()->json(
            ['received' => true],
            headers: [Protocol::HEADER_CORRELATION_ID => $correlationId->get()],
        );
    }
}
