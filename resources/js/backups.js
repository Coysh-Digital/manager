/*
 * Keeping the in-progress backup list current.
 *
 * A backup waits for the site to collect it, which is up to five minutes away and longer on a site
 * whose scheduler is driven by web traffic. For that whole time the screen is correct and looks
 * broken, and the observable consequence is somebody pressing "Back up now" again - which is another
 * full dump of a production database.
 *
 * So: poll, narrowly. Only while there is something outstanding, only for as long as that is
 * plausible, and stopping at the first sign the answer is not coming.
 *
 * **This used to reload the page when a job finished.** The comment said the stored artifact belongs
 * in a table this script does not own, which was true and is still true - so the region is swapped
 * with markup the server rendered, and everything outside it is left alone and said to be left
 * alone. A settled backup produces a sentence and a Reload button rather than taking the page out
 * from under somebody who is reading it. The table, the summary tiles and the "Last backup" strip
 * are stale until that button is pressed, and pretending otherwise is the one thing a live screen
 * must not do.
 */
import { toast } from './toast.js';

const INTERVAL = 10000;

// Half an hour. Long enough for a dump and an upload on a large site; short enough that a tab left
// open overnight is not still asking.
const GIVE_UP_AFTER = 30 * 60 * 1000;

const MAX_FAILURES = 3;

function reload() {
    window.location.reload();
}

function announce(settled) {
    settled.forEach((entry) => {
        toast(entry.sentence, entry.tone === 'warning' ? 'warning' : 'ok', {
            label: 'Reload to see it',
            run: reload,
        });
    });
}

function start(list) {
    const url = list.dataset.backupStatusUrl;

    if (!url) {
        return;
    }

    const items = list.querySelector('[data-backup-progress-items]') ?? list;
    const badge = document.querySelector('[data-backup-badge]');

    /*
     | The jobs this tab has actually drawn.
     |
     | Kept so that a job leaving the outstanding set can be asked about by name. Without it the
     | server would have to answer "what settled recently", which would announce a scheduled backup
     | that ran while this screen sat open, or a colleague's manual one - work this tab never showed
     | and nobody here is waiting on.
    */
    const watching = new Set();

    const remember = () => {
        items.querySelectorAll('[data-backup-progress]').forEach((node) => {
            watching.add(node.dataset.backupJob);
        });
    };

    remember();

    let startedAt = Date.now();
    let failures = 0;
    let timer = null;

    /*
     | Which run of the loop is current.
     |
     | A press can land while a request is already in the air, and that request is about to answer
     | "nothing outstanding" and shut the loop down - taking the job just queued with it, so the card
     | would never appear. A stale request checks this before deciding anything and drops out.
    */
    let generation = 0;

    const stop = () => {
        if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
        }
    };

    const tick = async () => {
        const mine = generation;

        if (Date.now() - startedAt > GIVE_UP_AFTER) {
            stop();

            return;
        }

        try {
            const query = watching.size > 0
                ? `${url}${url.includes('?') ? '&' : '?'}jobs=${encodeURIComponent(Array.from(watching).join(','))}`
                : url;

            const response = await fetch(query, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const answer = await response.json();

            // Superseded while this was in the air. The run that replaced it is already scheduled.
            if (mine !== generation) {
                return;
            }

            failures = 0;

            // Rendered by Blade, from the same component the page was built with. Swapped whole
            // rather than patched, so the elapsed line and the "No change" badge move too - patching
            // only the phase is why a card used to read "2m at this phase" twenty minutes in.
            if (typeof answer.html === 'string') {
                items.innerHTML = answer.html;
            }

            const outstanding = answer.in_flight || [];

            list.hidden = outstanding.length === 0;

            if (badge !== null && typeof answer.badge_html === 'string') {
                badge.innerHTML = answer.badge_html;
            }

            // Only jobs this tab was watching. The server was asked about exactly these, so anything
            // that comes back is something somebody here saw start.
            announce(answer.settled || []);

            (answer.settled || []).forEach((entry) => watching.delete(entry.job_id));
            outstanding.forEach((entry) => watching.add(entry.job_id));

            if (outstanding.length === 0) {
                stop();

                return;
            }
        } catch {
            if (mine !== generation) {
                return;
            }

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

    /*
     | Started by something happening, not by the page loading.
     |
     | A screen with nothing outstanding polls not at all, which is most screens most of the time.
     | Pressing a button restarts the clock as well as the loop, so a tab somebody keeps working in
     | keeps answering rather than reaching the half-hour ceiling once and staying quiet.
    */
    const wake = () => {
        generation += 1;
        startedAt = Date.now();
        failures = 0;

        stop();

        // A second rather than immediately. The action that fired this has only just returned, and
        // the row it wrote is committed - but asking on the same breath reads as a flicker, and a
        // job's first phase is never going to have changed in that time anyway.
        timer = window.setTimeout(tick, 1000);
    };

    if (items.querySelector('[data-backup-progress]') !== null) {
        timer = window.setTimeout(tick, INTERVAL);
    }

    document.addEventListener('manager:acted', wake);

    // Nothing to poll for once the tab is closed or navigated away from.
    window.addEventListener('pagehide', stop);
}

document.addEventListener('DOMContentLoaded', () => {
    const lists = document.querySelectorAll('[data-backup-progress-list]');

    if (lists.length > 0) {
        lists.forEach(start);

        return;
    }

    /*
     | Every other screen.
     |
     | There is no progress region to fill here, but there is a sidebar count and there are settled
     | backups worth being told about - somebody presses Refresh on a site's Overview tab, or leaves
     | the fleet screen open while a backup they started elsewhere finishes. The endpoint is the
     | organisation-wide one, named in a meta tag by the layout.
     |
     | An element rather than a bare loop, so `start` has the same shape of thing to work with
     | either way: it is never rendered, so hiding and filling it are both no-ops.
    */
    const endpoint = document.querySelector('meta[name="backup-status-endpoint"]')?.content;

    if (!endpoint) {
        return;
    }

    const shadow = document.createElement('div');
    shadow.dataset.backupStatusUrl = endpoint;

    start(shadow);
});
