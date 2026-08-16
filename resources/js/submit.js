/*
 * Performing an action without leaving the page it was pressed on.
 *
 * Reported from use: pressing "Back up now" reloads the screen, and because the job waits for the
 * site to check in - up to five minutes, longer on a site whose scheduler runs off web traffic —
 * nothing has changed when it comes back. So people press it again, which is another full dump of a
 * production database, and refresh in between waiting for something to appear.
 *
 * None of that was ever synchronous. The row is written and the request is over; the reload was
 * only the redirect the form post followed. This posts the same form, reads the sentence the
 * redirect was carrying, and stays put.
 *
 * Enhancement only, and the distinction is load-bearing rather than decorative. Each of these is an
 * ordinary form with an ordinary submit button and a CSRF field, so every one of them works with
 * this file blocked, missing or still downloading - it navigates, exactly as it did before. What is
 * here is the two things a plain form cannot do: keep the page, and say what happened on it.
 *
 * Anything it cannot read the answer to hands the browser back the form and lets it post the old
 * way. That is only safe because every route carrying `in-place` is one it is harmless to perform
 * twice - see the block above them in routes/web.php, which is where that rule is written down.
 */
import { toast } from './toast.js';

const HEADER = 'X-Manager-Async';

function busy(form, state) {
    form.setAttribute('aria-busy', String(state));

    // Left as a plain disabled button rather than swapping the label for "Requesting…". These
    // already carry `disabled:` classes so the state is visible, and a label that changes width
    // inside a table cell shifts every row beside it.
    form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
        button.disabled = state;
    });
}

async function send(form) {
    const response = await fetch(form.action, {
        method: 'POST',

        // The form's own @csrf field travels in the body. No Content-Type header: FormData sets the
        // multipart boundary, and naming one by hand removes it.
        body: new FormData(form),
        headers: { Accept: 'application/json', [HEADER]: '1' },
        credentials: 'same-origin',
    });

    // An error page, a sign-in page, anything that is not the answer. Throwing here is what reaches
    // the fallback below, which is the only honest response to a reply we cannot read.
    if (!(response.headers.get('content-type') || '').includes('application/json')) {
        throw new Error(`unreadable: ${response.status}`);
    }

    return response.json();
}

function announce(answer) {
    /*
     | Where the server wanted the browser to go.
     |
     | Compared by path rather than in full, so a query string on the current screen cannot look
     | like a different destination. Same path means `back()`, which is the ordinary case and the
     | whole point; anywhere else is a real instruction - a password confirmation, a sign-in - and
     | is followed.
    */
    if (answer.redirect) {
        const target = new URL(answer.redirect, window.location.href);

        if (target.origin !== window.location.origin || target.pathname !== window.location.pathname) {
            window.location.assign(answer.redirect);

            return false;
        }
    }

    // Refusals first, so the one thing somebody has to act on is nearest the content it is about.
    (answer.errors || []).forEach((message) => toast(message, 'error'));

    if (answer.warning) {
        toast(answer.warning, 'warning');
    }

    if (answer.status) {
        toast(answer.status, 'ok');
    }

    return true;
}

/*
 | Delegated from the document rather than bound per form on load.
 |
 | The in-progress region is replaced wholesale every time the poller sees a change, and the Cancel
 | button lives inside it - a listener attached at DOMContentLoaded would go with the first swap.
*/
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-async')) {
        return;
    }

    /*
     | An inline `onsubmit="return confirm(...)"` is registered first and declines by preventing the
     | default. Cancelling a backup carries one. Honouring it is a single check, and getting it
     | wrong would mean a dialog somebody said no to and an action that happened anyway.
    */
    if (event.defaultPrevented) {
        return;
    }

    event.preventDefault();
    busy(form, true);

    send(form)
        .then((answer) => {
            if (!announce(answer)) {
                return;
            }

            // Something was queued or called off. Whatever is watching this page should look now
            // rather than at the end of its ten-second cycle. Announced rather than called, so this
            // file needs to know nothing about backups.
            document.dispatchEvent(new CustomEvent('manager:acted'));
        })
        .catch(() => {
            /*
             | A session that expired while the tab was open, a network that went away, an error
             | page instead of an answer.
             |
             | Hand the browser back the form: whatever would have happened without this file
             | happens now, which is the only honest fallback and is why the routes carrying
             | `in-place` all have to be safe to perform twice. form.submit() does not fire a submit
             | event, so this cannot re-enter the handler or ask the confirmation a second time.
            */
            form.submit();
        })
        .finally(() => {
            // Re-enabled even on success. A second press produces a truthful refusal rather than a
            // second database dump - JobService returns the outstanding job for the same
            // idempotency key - and leaving the button dead after a cancelled backup would need a
            // reload to recover from.
            busy(form, false);
        });
});
