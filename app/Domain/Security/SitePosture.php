<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\BackupArtifact;
use App\Models\CapabilityGrant;
use App\Models\Connector;
use App\Models\Finding;
use App\Models\Heartbeat;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The three things a security screen should say that a findings list does not.
 *
 * A list of findings answers "what rules fired". It does not answer "is the thing reporting to us
 * still the thing we paired with", "is this getting better or worse", or "what does Manager itself
 * hold on this site" — and the last of those is the one an operator is asked in an audit and cannot
 * currently answer from the interface.
 */
final class SitePosture
{
    /**
     * Whether the connector still looks like the one that was paired.
     *
     * Source addresses are the part worth surfacing. A connector is authenticated by an Ed25519
     * signature, so a new address is not an alarm on its own — sites move, hosts rotate egress IPs,
     * a CDN changes region. But a key that has never rotated, reporting from an address it has never
     * used before, is the shape a compromise takes, and nothing in the interface said so.
     *
     * @return array{
     *     connector: Connector|null,
     *     fingerprint: string|null,
     *     addresses: list<array{ip: string, first: Carbon, last: Carbon, count: int}>,
     *     newAddressSeen: bool,
     *     keyAgeDays: int|null,
     * }
     */
    public function trust(Site $site, ?Connector $connector): array
    {
        // Aggregated in the database rather than pulled back and folded in PHP: a busy site has tens
        // of thousands of heartbeats in the window and only a handful of distinct addresses.
        //
        // On the base builder rather than the model, because nothing here wants a Heartbeat — it
        // wants four aggregate columns, and hydrating models to reach them would be pretending.
        $addresses = array_map(
            static fn (object $row): array => [
                'ip' => (string) $row->source_ip,
                'first' => Carbon::parse((string) $row->first_seen),
                'last' => Carbon::parse((string) $row->last_seen),
                'count' => (int) $row->beats,
            ],
            DB::table((new Heartbeat)->getTable())
                ->where('site_id', $site->id)
                ->whereNotNull('source_ip')
                ->where('received_at', '>=', Carbon::now()->subDays(30))
                ->selectRaw('source_ip, min(received_at) as first_seen, max(received_at) as last_seen, count(*) as beats')
                ->groupBy('source_ip')
                ->orderByRaw('max(received_at) desc')
                ->limit(8)
                ->get()
                ->all(),
        );

        // "New" means it first appeared in the last day but is not the only address on record — a
        // site that has only ever reported from one place has not changed anything.
        $newAddressSeen = count($addresses) > 1 && collect($addresses)
            ->contains(fn (array $address): bool => $address['first']->gt(Carbon::now()->subDay()));

        $rotatedAt = $connector === null
            ? null
            : ($connector->key_rotated_at ?? $connector->paired_at);

        return [
            'connector' => $connector,
            'fingerprint' => $connector === null ? null : $this->fingerprint($connector->public_key),
            'addresses' => $addresses,
            'newAddressSeen' => $newAddressSeen,
            'keyAgeDays' => $rotatedAt === null ? null : (int) $rotatedAt->diffInDays(Carbon::now()),
        ];
    }

    /**
     * A short, comparable fingerprint of the connector's public key.
     *
     * SHA-256 of the raw key, first sixteen hex characters, spaced in fours. The full public key is
     * not a secret, but printing it invites pasting it around as though it were meaningful; a
     * fingerprint is for the one job anybody actually does with it, which is checking whether two
     * of them match.
     */
    public function fingerprint(string $publicKey): string
    {
        $raw = base64_decode($publicKey, true);

        $digest = substr(hash('sha256', $raw === false ? $publicKey : $raw), 0, 16);

        return implode(' ', str_split($digest, 4));
    }

    /**
     * Findings opened and resolved per week, oldest first.
     *
     * Posture as a direction rather than a snapshot. A site with four open findings that had eleven
     * a month ago is being looked after; one with four that had none is not, and the current number
     * is identical.
     *
     * @return list<array{label: string, value: int, opened: int, resolved: int, text: string}>
     */
    public function timeline(Site $site, int $weeks = 12): array
    {
        $from = Carbon::now()->startOfWeek()->subWeeks($weeks - 1);

        $findings = Finding::query()
            ->where('site_id', $site->id)
            ->where(fn ($query) => $query
                ->where('first_seen_at', '>=', $from)
                ->orWhere('resolved_at', '>=', $from))
            ->get(['first_seen_at', 'resolved_at']);

        // Open at the start of the window: everything raised before it and not resolved before it.
        $running = Finding::query()
            ->where('site_id', $site->id)
            ->where('first_seen_at', '<', $from)
            ->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $from))
            ->count();

        $points = [];

        for ($week = 0; $week < $weeks; $week++) {
            $start = $from->copy()->addWeeks($week);
            $end = $start->copy()->addWeek();

            $opened = $findings->filter(fn ($finding): bool => $finding->first_seen_at->gte($start) && $finding->first_seen_at->lt($end))->count();
            $resolved = $findings->filter(fn ($finding): bool => $finding->resolved_at !== null && $finding->resolved_at->gte($start) && $finding->resolved_at->lt($end))->count();

            $running = max(0, $running + $opened - $resolved);

            $points[] = [
                'label' => $start->format('j M'),
                'value' => $running,
                'opened' => $opened,
                'resolved' => $resolved,
                'text' => $running.' outstanding, '.$opened.' opened, '.$resolved.' resolved',
            ];
        }

        return $points;
    }

    /**
     * What Manager can currently do to this site, and what it therefore holds.
     *
     * The question an operator gets asked in an audit — "what does your monitoring platform have
     * access to, and what has it kept" — and the one place in the interface it should be answerable
     * without reading a capability list and inferring.
     *
     * @return array{
     *     grants: Collection<int, CapabilityGrant>,
     *     readsContent: bool,
     *     backupCount: int,
     *     backupBytes: int,
     *     oldestBackup: Carbon|null,
     * }
     */
    public function exposure(Site $site): array
    {
        $grants = $site->capabilityGrants()
            ->where('state', CapabilityGrant::STATE_GRANTED)
            ->with('grantedBy')
            ->orderBy('capability')
            ->get();

        $backups = BackupArtifact::query()
            ->where('site_id', $site->id)
            ->stored()
            ->get(['plaintext_bytes', 'taken_at']);

        return [
            'grants' => $grants,

            // The distinction that matters. Every read capability reports metadata; `backups:create`
            // takes a copy of the database, which is a different order of thing and is why it has its
            // own confirmation.
            'readsContent' => $grants->contains('capability', 'backups:create'),
            'backupCount' => $backups->count(),
            'backupBytes' => (int) $backups->sum('plaintext_bytes'),
            'oldestBackup' => $backups->min('taken_at'),
        ];
    }
}
