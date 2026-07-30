@props(['site', 'open' => false])

@php
    $cron = <<<'CRON'
*/5 * * * * cd /path/to/site && php craft manager-connector/heartbeat
*/5 * * * * cd /path/to/site && php craft manager-connector/jobs
0 * * * *   cd /path/to/site && php craft manager-connector/report
0 6 * * *   cd /path/to/site && php craft manager-connector/updates
CRON;
@endphp

{{--
    What to put on the site so it reports at all.

    Always on the page, opened by default while the site has sent nothing. The first version of this was
    only rendered in that state, which meant the instructions disappeared the moment they had worked —
    no use to somebody whose cron broke three months later.

    It gives the lines rather than naming a command: "check that manager-connector/jobs is running" tells
    a reader what to look for and not where it goes, which is how a paired site sits silent for a week
    and looks broken.
--}}
<details class="group/schedule" @if ($open) open @endif>
    <summary class="cursor-pointer list-none text-[13px] font-medium">
        <span class="group-open/schedule:hidden">Scheduled tasks — required for this site to report</span>
        <span class="hidden group-open/schedule:inline">Scheduled tasks</span>
    </summary>

<div class="mt-2.5 flex flex-col gap-3">
    {{-- The no-cron path first, because it is the one somebody without server access needs and the one
         they will otherwise conclude is impossible. --}}
    <div class="rounded-lg border border-ok-line bg-ok-bg px-3 py-2.5">
        <p class="mb-1 text-[12.5px] font-medium text-ok">No cron? Nothing to do.</p>
        <p class="text-[12px] text-text-2">
            Connector 1.5.0 and later drive this from ordinary traffic to the site: when something is
            due, the next visitor's request queues it and Craft's queue does the work. Each task runs at
            most once per interval however busy the site is, and nobody waits for it.
            It needs Craft's queue to be running, which is Craft's default.
        </p>
    </div>

    <p class="text-[12.5px] text-text-2">
        <strong>With cron it is more predictable</strong> — it does not depend on the queue, and it does
        not go quiet when the site does. Add these four lines on
        <span class="font-mono text-[12px]">{{ $site->expected_domain }}</span>, replacing
        <span class="font-mono text-[12px]">/path/to/site</span> with the directory holding
        <span class="font-mono text-[12px]">craft</span>:
    </p>

    <div class="flex flex-wrap items-start gap-2">
        <pre id="connector-cron-{{ $site->external_id }}"
             class="flex-1 overflow-x-auto rounded-lg bg-surface p-3"><code class="font-mono text-[11.5px] leading-relaxed">{{ $cron }}</code></pre>

        <button type="button"
                data-copy="{{ $cron }}"
                data-copy-from="connector-cron-{{ $site->external_id }}"
                class="h-8 flex-none rounded-[7px] border border-border-2 bg-surface px-3 text-[12.5px] text-text hover:bg-row-hover">
            <span data-copy-label>Copy</span>
        </button>
    </div>

    <details class="group">
        <summary class="cursor-pointer list-none text-[12.5px] text-text-2 hover:text-text">
            <span class="group-open:hidden">What each one does</span>
            <span class="hidden group-open:inline">Hide</span>
        </summary>

        <dl class="mt-2 flex flex-col gap-1.5 text-[12px]">
            <div class="flex flex-wrap gap-x-2">
                <dt class="font-mono text-text">heartbeat</dt>
                <dd class="text-text-2">
                    Says "still here". Without it this site is eventually reported as not reporting.
                </dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
                <dt class="font-mono text-text">jobs</dt>
                <dd class="text-text-2">
                    Collects work queued here — including the Refresh button above. Without it, pressing
                    Refresh queues something the site never comes to collect.
                </dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
                <dt class="font-mono text-text">report</dt>
                <dd class="text-text-2">Sends versions, plugins and whatever else is permitted.</dd>
            </div>
            <div class="flex flex-wrap gap-x-2">
                <dt class="font-mono text-text">updates</dt>
                <dd class="text-text-2">
                    Checks for available updates. Daily is plenty — it asks a third party.
                </dd>
            </div>
        </dl>

        <p class="mt-2 text-[12px] text-text-3">
            On managed hosting without cron, a scheduled-task panel works the same way: the command is
            <span class="font-mono">php craft manager-connector/heartbeat</span> and friends, run from the
            site's root.
        </p>
    </details>
</div>
</details>
