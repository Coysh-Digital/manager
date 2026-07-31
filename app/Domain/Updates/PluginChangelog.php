<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Models\PluginReleaseNote;
use Illuminate\Support\Carbon;

/**
 * What a plugin changed between the version a site runs and the one it could run.
 *
 * The plugin counterpart to {@see ChangelogFetcher}, reaching the same screen by a different route
 * and for a specific reason. Craft has one changelog at one address, so fetching it is a single
 * request for a public file on a constant cache key, and tells whoever serves it nothing about this
 * installation. Plugins have no equivalent: resolving several hundred handles against a plugin store
 * would tell that store exactly which plugins a fleet runs and which of them are behind.
 *
 * So nothing is fetched. Every site running an outdated plugin already downloaded the release notes
 * when Craft checked its own updates, and since `updates.v2` the connector forwards what it already
 * has. The platform makes no outbound request at all for this feature.
 *
 * The association is what stays out of the database — see the migration for the full argument. Notes
 * are stored against a plugin and a version, this class reads them back by handle, and the join to a
 * particular site happens in the controller, from the versions that site's report already carried.
 */
final class PluginChangelog
{
    /**
     * Take the releases from an update report and remember what they said.
     *
     * Idempotent: reports repeat the same releases every few hours, so this upserts on
     * (handle, version) and touches `updated_at` so pruning can tell a note still in use from one
     * describing a plugin nobody has installed any more.
     *
     * @param  list<array<string, mixed>>  $plugins  The report's plugin entries, as sent.
     */
    public function remember(array $plugins): void
    {
        $rows = [];
        $now = Carbon::now();

        foreach ($plugins as $plugin) {
            $handle = $plugin['handle'] ?? null;

            if (! is_string($handle) || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $handle) !== 1) {
                continue;
            }

            foreach ($this->releasesIn($plugin) as $release) {
                $version = $release['version'] ?? null;

                if (! is_string($version) || $version === '') {
                    continue;
                }

                $notes = $release['notes'] ?? null;

                // A release with no note is not worth a row. The version is already in the report,
                // and an empty panel is worse than the Plugin Store link it would replace.
                if (! is_string($notes) || trim($notes) === '') {
                    continue;
                }

                // Keyed so a report listing the same release twice cannot produce two rows and make
                // the upsert ambiguous.
                $rows[$handle.'@'.$version] = [
                    'handle' => $handle,
                    'version' => mb_substr($version, 0, 32),
                    'notes' => mb_substr(trim($notes), 0, 4000),
                    'critical' => (bool) ($release['critical'] ?? false),
                    'released_on' => is_string($release['date'] ?? null) && $release['date'] !== ''
                        ? mb_substr($release['date'], 0, 32)
                        : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows === []) {
            return;
        }

        PluginReleaseNote::query()->upsert(
            array_values($rows),
            ['handle', 'version'],
            ['notes', 'critical', 'released_on', 'updated_at'],
        );
    }

    /**
     * Strip the notes out of a report before anything stores the report itself.
     *
     * `update_reports.payload` keeps the whole body a site sent, which is exactly the column that
     * must not end up holding "this site is behind on these fixes". The notes have already been
     * written somewhere that cannot express that, so they come out here.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function withoutReleases(array $payload): array
    {
        if (! is_array($payload['plugins'] ?? null)) {
            return $payload;
        }

        foreach ($payload['plugins'] as $index => $plugin) {
            if (is_array($plugin)) {
                unset($payload['plugins'][$index]['releases']);
            }
        }

        return $payload;
    }

    /**
     * The notes for one plugin, rendered, covering the versions between current and latest.
     *
     * Returns null when there is nothing to show, which the panel treats the same way it treats a
     * changelog it could not fetch: it falls back to the Plugin Store link that was always there.
     */
    public function between(string $handle, ?string $current, ?string $latest): ?string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $handle) !== 1) {
            return null;
        }

        $sections = [];

        /** @var list<PluginReleaseNote> $releases */
        $releases = PluginReleaseNote::query()
            ->where('handle', $handle)
            ->whereNotNull('notes')
            ->get()
            ->all();

        // Sorted in PHP rather than SQL: these are semantic versions, and no database orders "3.0.9"
        // before "3.0.10" on its own.
        usort($releases, static fn (PluginReleaseNote $a, PluginReleaseNote $b): int => version_compare($b->version, $a->version));

        foreach ($releases as $release) {
            if (! ChangelogMarkdown::isBetween($release->version, $current, $latest)) {
                continue;
            }

            $heading = '## '.$release->version;

            if ($release->released_on !== null) {
                $heading .= ' — '.$release->released_on;
            }

            $sections[] = $heading."\n\n".$release->notes;

            if (count($sections) >= ChangelogMarkdown::MAX_SECTIONS) {
                break;
            }
        }

        return ChangelogMarkdown::render($sections);
    }

    /**
     * Which of these plugins have notes worth offering to expand.
     *
     * One query for the whole table, not one per plugin: the updates screen renders every installed
     * plugin, a busy site has a couple of hundred, and asking per row is how a screen quietly becomes
     * two hundred queries. Only handles and versions are selected — the note bodies are loaded by
     * {@see between} when somebody actually opens one.
     *
     * @param  list<array{handle: string, current: string|null, latest: string|null, ...}>  $plugins
     * @return array<string, true> Keyed by handle, for an isset() in the view.
     */
    public function handlesWithNotes(array $plugins): array
    {
        $wanted = [];

        foreach ($plugins as $plugin) {
            $handle = $plugin['handle'] ?? null;

            if (is_string($handle) && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $handle) === 1) {
                $wanted[$handle] = $plugin;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $found = [];

        PluginReleaseNote::query()
            ->whereIn('handle', array_keys($wanted))
            ->whereNotNull('notes')
            ->select(['handle', 'version'])
            ->each(function (PluginReleaseNote $release) use ($wanted, &$found): void {
                if (isset($found[$release->handle])) {
                    return;
                }

                $plugin = $wanted[$release->handle];

                if (ChangelogMarkdown::isBetween($release->version, $plugin['current'] ?? null, $plugin['latest'] ?? null)) {
                    $found[$release->handle] = true;
                }
            });

        return $found;
    }

    /**
     * @param  array<string, mixed>  $plugin
     * @return list<array<string, mixed>>
     */
    private function releasesIn(array $plugin): array
    {
        $releases = $plugin['releases'] ?? null;

        if (! is_array($releases)) {
            return [];
        }

        return array_values(array_filter($releases, is_array(...)));
    }
}
