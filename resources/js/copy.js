/*
 * Copy to clipboard.
 *
 * Delegated from the document rather than bound per button, so it works for anything rendered later
 * without re-running a setup pass.
 *
 * The clipboard API needs a secure context, which Manager always is - it refuses to be useful over
 * plain HTTP. If it is unavailable anyway the button says so rather than appearing to succeed: an
 * enrolment code shown once, with a copy button that silently did nothing, is the worst possible
 * combination.
 */

const RESET_AFTER = 2000;

function label(button, text) {
    const target = button.querySelector('[data-copy-label]') ?? button;

    target.textContent = text;
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');

    if (! button) {
        return;
    }

    event.preventDefault();

    const value = button.dataset.copy;
    const original = button.dataset.copyOriginal ?? (button.querySelector('[data-copy-label]') ?? button).textContent.trim();

    button.dataset.copyOriginal = original;

    try {
        await navigator.clipboard.writeText(value);
        label(button, button.dataset.copyDone ?? 'Copied');
    } catch {
        // Select it instead, so there is still a way to get it out by hand.
        label(button, 'Press Ctrl+C');

        const source = document.getElementById(button.dataset.copyFrom ?? '');

        if (source) {
            const range = document.createRange();
            range.selectNodeContents(source);
            window.getSelection()?.removeAllRanges();
            window.getSelection()?.addRange(range);
        }
    }

    window.setTimeout(() => label(button, original), RESET_AFTER);
});
