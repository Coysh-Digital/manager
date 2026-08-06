/*
 * Selecting sites on the fleet screen.
 *
 * Enhancement only, and the distinction is load-bearing rather than decorative. The checkboxes are
 * an ordinary form's own state and the submit button is an ordinary submit button, so choosing six
 * sites and asking for backups works with this file blocked, missing or still downloading. What is
 * here is the three things a plain form cannot do: a select-all, a select-a-group, and a running
 * count.
 *
 * Written against data attributes rather than classes, so restyling the table cannot silently
 * detach the behaviour from it.
 */

const form = () => document.querySelector('[data-bulk-form]');

const boxes = () => Array.from(document.querySelectorAll('[data-bulk-site]'));

const groupBoxes = (group) =>
    Array.from(document.querySelectorAll(`[data-bulk-site="${group}"]`));

/*
 * Reflect the selection everywhere that describes it.
 *
 * The group and select-all boxes are made indeterminate rather than merely unchecked when part of
 * their set is selected. A half-selected group showing an empty box invites somebody to click it
 * expecting to add the rest and watch it clear what they had.
 */
function sync() {
    const all = boxes();
    const selected = all.filter((box) => box.checked);

    const count = document.querySelector('[data-bulk-count]');

    if (count) {
        count.textContent = String(selected.length);
    }

    // Hidden while nothing is selected. Blade renders it visible, so the no-JS case keeps the
    // button; this only takes it away once there is something to take it away from.
    const bar = document.querySelector('[data-bulk-bar]');

    if (bar) {
        bar.classList.toggle('hidden', selected.length === 0);
    }

    document.querySelectorAll('[data-bulk-group]').forEach((toggle) => {
        const group = groupBoxes(toggle.dataset.bulkGroup);
        const chosen = group.filter((box) => box.checked).length;

        toggle.checked = group.length > 0 && chosen === group.length;
        toggle.indeterminate = chosen > 0 && chosen < group.length;
    });

    const everything = document.querySelector('[data-bulk-all]');

    if (everything) {
        everything.checked = all.length > 0 && selected.length === all.length;
        everything.indeterminate = selected.length > 0 && selected.length < all.length;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    if (!form()) {
        return;
    }

    document.querySelector('[data-bulk-all]')?.addEventListener('change', (event) => {
        boxes().forEach((box) => {
            box.checked = event.target.checked;
        });

        sync();
    });

    document.querySelectorAll('[data-bulk-group]').forEach((toggle) => {
        toggle.addEventListener('change', () => {
            groupBoxes(toggle.dataset.bulkGroup).forEach((box) => {
                box.checked = toggle.checked;
            });

            sync();
        });
    });

    boxes().forEach((box) => box.addEventListener('change', sync));

    // Run once on load rather than starting from zero: the recent-authentication gate hands the
    // selection back through old('sites'), so boxes can already be ticked when this file arrives.
    sync();
});
