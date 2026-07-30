/*
 * The navigation drawer, on narrow screens.
 *
 * The drawer itself is a checkbox and a CSS transform — it opens, closes and is reachable with the
 * keyboard whether or not this file ever loads. That matters more than it usually would: this is a
 * control plane, and the screen somebody reaches for at 2am is often a phone on a bad connection.
 *
 * What is here is enhancement only. A label is not a button as far as assistive technology is
 * concerned, so the toggle is given the button semantics it cannot carry on its own, plus the two
 * behaviours people expect from a drawer and CSS cannot provide: Escape closes it, and space or
 * enter works on the handle.
 */

const drawer = () => document.getElementById('nav-drawer');

function sync() {
    const open = drawer()?.checked ?? false;

    document.querySelectorAll('[data-nav-toggle]').forEach((toggle) => {
        toggle.setAttribute('aria-expanded', String(open));
    });

    // While the drawer is over the page, the page behind it should not scroll under it.
    document.body.classList.toggle('overflow-hidden', open);
    document.body.classList.toggle('lg:overflow-auto', open);
}

/*
 * Bring the current tab into view.
 *
 * The site tab bar scrolls sideways on a phone, and it starts at the left. Landing on Audit — the
 * last of seven — and being shown Overview is the tab bar telling you where you are not.
 */
function revealCurrentTab() {
    const current = document.querySelector('nav[aria-label="Site sections"] [aria-current="page"]');

    current?.scrollIntoView({ block: 'nearest', inline: 'center' });
}

document.addEventListener('DOMContentLoaded', () => {
    revealCurrentTab();

    const checkbox = drawer();

    if (!checkbox) {
        return;
    }

    sync();
    checkbox.addEventListener('change', sync);

    // A <label role="button"> is reached by the keyboard but not activated by it: browsers give
    // labels click behaviour, not key behaviour.
    document.querySelectorAll('[data-nav-toggle]').forEach((toggle) => {
        toggle.addEventListener('keydown', (event) => {
            if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                checkbox.checked = !checkbox.checked;
                sync();
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && checkbox.checked) {
            checkbox.checked = false;
            sync();

            // Focus goes back to what opened it, rather than to the top of the document.
            document.querySelector('[data-nav-toggle]')?.focus();
        }
    });
});
