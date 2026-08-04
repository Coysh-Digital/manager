/*
 * The backup schedule form.
 *
 * Only some of its controls apply to any given frequency: the hour means nothing for "only when
 * asked", and the day means nothing for anything but weekly - the scheduler reads it for weekly and
 * ignores it otherwise. All three used to be on screen at once, so a daily schedule showed a day
 * selector that saved, appeared in the audit diff as a change, and then did nothing.
 *
 * Progressive enhancement, not a dependency. The server already renders the correct `hidden`
 * attributes from the saved value, so with JavaScript off the form is right on load and merely
 * static; this keeps it right while somebody is changing their mind.
 */
function applies(field, frequency) {
    if (field === 'hour') {
        return frequency !== 'off';
    }

    return frequency === 'weekly';
}

function sync(form) {
    const frequency = form.querySelector('[data-backup-schedule-frequency]');

    if (frequency === null) {
        return;
    }

    form.querySelectorAll('[data-backup-schedule-field]').forEach((field) => {
        field.hidden = !applies(field.dataset.backupScheduleField, frequency.value);
    });

    // The recovery-key warning is only true of a schedule that will actually fire.
    document.querySelectorAll('[data-backup-schedule-note]').forEach((note) => {
        note.hidden = frequency.value === 'off';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-backup-schedule]').forEach((form) => {
        sync(form);

        form.addEventListener('change', (event) => {
            if (event.target.matches('[data-backup-schedule-frequency]')) {
                sync(form);
            }
        });
    });
});
