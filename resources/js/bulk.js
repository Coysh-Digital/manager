/*
 * Selecting rows in a table that has a bulk action beneath it.
 *
 * Enhancement only, and the distinction is load-bearing rather than decorative. The checkboxes are
 * an ordinary form's own state and the submit button is an ordinary submit button, so choosing six
 * sites and asking for backups works with this file blocked, missing or still downloading. What is
 * here is the three things a plain form cannot do: a select-all, a select-a-group, and a running
 * count.
 *
 * Written against data attributes rather than classes, so restyling a table cannot silently detach
 * the behaviour from it.
 *
 * The scope is a *region*, not a form, and that is the whole reason one file can serve two screens.
 * On the fleet the two are the same element. On the backups screen they cannot be: that table
 * already carries a form per row for single deletion, forms cannot nest, and so the bulk form sits
 * empty beside the table while the checkboxes join it with `form="..."`. Scoping to the form element
 * would find no checkboxes there at all.
 *
 * Groups are optional. The backups screen has none, so `[data-bulk-group]` matches nothing inside
 * that scope and the group machinery costs it nothing - no branching required.
 */

const boxes = (scope) => Array.from(scope.querySelectorAll('[data-bulk-item]'));

const groupBoxes = (scope, group) =>
    Array.from(scope.querySelectorAll(`[data-bulk-item="${group}"]`));

/*
 * Reflect the selection everywhere that describes it.
 *
 * The group and select-all boxes are made indeterminate rather than merely unchecked when part of
 * their set is selected. A half-selected group showing an empty box invites somebody to click it
 * expecting to add the rest and watch it clear what they had.
 */
function sync(scope) {
    const all = boxes(scope);
    const selected = all.filter((box) => box.checked);

    const count = scope.querySelector('[data-bulk-count]');

    if (count) {
        count.textContent = String(selected.length);
    }

    // Hidden while nothing is selected. Blade renders it visible, so the no-JS case keeps the
    // button; this only takes it away once there is something to take it away from.
    const bar = scope.querySelector('[data-bulk-bar]');

    if (bar) {
        bar.classList.toggle('hidden', selected.length === 0);
    }

    scope.querySelectorAll('[data-bulk-group]').forEach((toggle) => {
        const group = groupBoxes(scope, toggle.dataset.bulkGroup);
        const chosen = group.filter((box) => box.checked).length;

        toggle.checked = group.length > 0 && chosen === group.length;
        toggle.indeterminate = chosen > 0 && chosen < group.length;
    });

    const everything = scope.querySelector('[data-bulk-all]');

    if (everything) {
        everything.checked = all.length > 0 && selected.length === all.length;
        everything.indeterminate = selected.length > 0 && selected.length < all.length;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-bulk-scope]').forEach((scope) => {
        scope.querySelector('[data-bulk-all]')?.addEventListener('change', (event) => {
            boxes(scope).forEach((box) => {
                box.checked = event.target.checked;
            });

            sync(scope);
        });

        scope.querySelectorAll('[data-bulk-group]').forEach((toggle) => {
            toggle.addEventListener('change', () => {
                groupBoxes(scope, toggle.dataset.bulkGroup).forEach((box) => {
                    box.checked = toggle.checked;
                });

                sync(scope);
            });
        });

        boxes(scope).forEach((box) => box.addEventListener('change', sync.bind(null, scope)));

        // Run once on load rather than starting from zero: the recent-authentication gate hands a
        // selection back through `old()`, so boxes can already be ticked when this file arrives.
        // That applies to deleting backups, which is still gated; the fleet's backup button no
        // longer is, and gets the same treatment after a validation error.
        sync(scope);
    });
});
