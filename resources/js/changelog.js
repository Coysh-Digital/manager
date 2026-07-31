/*
 * Release notes, loaded when somebody asks for them.
 *
 * Deliberately on open rather than on page load. Rendering the updates screen must not cause an
 * outbound request — an installation with no internet access is a supported way to run this, and a
 * screen that hangs for eight seconds on a timeout would be the visible symptom of a feature nobody
 * had opted into.
 *
 * The response is a server-rendered fragment, already put through commonmark with HTML stripped and
 * unsafe links refused, so there is nothing to parse or sanitise here.
 */
async function load(details) {
    const body = details.querySelector('[data-changelog-body]');
    const url = details.dataset.changelogUrl;

    if (body === null || !url) {
        return;
    }

    // Once. Reopening shows what was already fetched rather than asking again.
    details.dataset.changelogLoaded = 'true';

    try {
        const response = await fetch(url, { credentials: 'same-origin' });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        body.innerHTML = await response.text();
    } catch {
        // The link is still on the row above, which is where these always lived.
        body.textContent = 'The release notes could not be read. The changelog link above still works.';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-changelog]').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (details.open && details.dataset.changelogLoaded !== 'true') {
                load(details);
            }
        });
    });
});
