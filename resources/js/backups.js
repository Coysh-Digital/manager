/*
 * Keeping the in-progress backup list current.
 *
 * A backup waits for the site to collect it, which is up to five minutes away and longer on a site
 * whose scheduler is driven by web traffic. For that whole time the screen is correct and looks
 * broken, and the observable consequence is somebody pressing "Back up now" again - which is another
 * full dump of a production database.
 *
 * So: poll, narrowly. Only while there is something outstanding, only for the phase and the elapsed
 * time, and only for a while. When a job leaves the outstanding set its artifact row needs to appear
 * in a table this does not own, so that one case reloads the page rather than pretending to know how
 * to render it.
 */
const INTERVAL = 10000;

// Half an hour. Long enough for a dump and an upload on a large site; short enough that a tab left
// open overnight is not still asking.
const GIVE_UP_AFTER = 30 * 60 * 1000;

const MAX_FAILURES = 3;

function phaseFor(node) {
    return node.dataset.backupPhase;
}

function render(node, entry) {
    if (phaseFor(node) === entry.phase) {
        return;
    }

    node.dataset.backupPhase = entry.phase;

    const badge = node.querySelector('[data-status-badge-label]');

    if (badge !== null) {
        badge.textContent = entry.label;
    }

    node.querySelectorAll('[data-backup-step]').forEach((step) => {
        const reached = Number(step.dataset.backupStep) <= entry.step;

        step.classList.toggle('bg-primary', reached);
        step.classList.toggle('bg-border-2', !reached);
    });
}

function start(list) {
    const url = list.dataset.backupStatusUrl;

    if (!url) {
        return;
    }

    const startedAt = Date.now();
    let failures = 0;
    let timer = null;

    const stop = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    };

    const tick = async () => {
        if (Date.now() - startedAt > GIVE_UP_AFTER) {
            stop();

            return;
        }

        try {
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const { in_flight: entries } = await response.json();

            failures = 0;

            const byJob = new Map(entries.map((entry) => [entry.job_id, entry]));
            const nodes = list.querySelectorAll('[data-backup-progress]');

            // Something finished. The stored artifact belongs in a table this script does not own,
            // so hand the whole page back to the server rather than half-updating it here.
            const finished = Array.from(nodes).some((node) => !byJob.has(node.dataset.backupJob));

            if (finished || entries.length > nodes.length) {
                stop();
                window.location.reload();

                return;
            }

            nodes.forEach((node) => {
                const entry = byJob.get(node.dataset.backupJob);

                if (entry !== undefined) {
                    render(node, entry);
                }
            });

            if (entries.length === 0) {
                stop();

                return;
            }
        } catch {
            failures += 1;

            // A signed-out session or a server that has gone away. Backing off forever is worse than
            // stopping: the page is still readable, it is just no longer live.
            if (failures >= MAX_FAILURES) {
                stop();

                return;
            }
        }

        timer = window.setTimeout(tick, INTERVAL);
    };

    timer = window.setTimeout(tick, INTERVAL);

    // Nothing to poll for once the tab is closed or navigated away from.
    window.addEventListener('pagehide', stop);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-backup-progress-list]').forEach(start);
});
