/*
 * Saying what an action did, where the person is standing.
 *
 * Every one of these sentences already existed. They were flashed to the session and rendered as a
 * full-width band on the page the redirect landed on - which worked, and is still what happens with
 * this file blocked, because the bands in layouts/app.blade.php were not touched. What they could
 * not do is say anything at all when there is no redirect, and an action answered in place has no
 * redirect by definition.
 *
 * Not a component library and not a queue. Two live regions rendered by Blade, a function that puts
 * a bordered paragraph in one of them, and a timer.
 *
 * The urgency split is real rather than decorative. "Backup requested" should wait for a gap in
 * whatever a screen reader is already saying; "this site has no active connector" should not, and
 * should not disappear on a timer either, because it is the only one of the three somebody has to
 * do something about.
 */

// Long enough to read a sentence and glance back at it. Errors are not on a timer at all, and
// neither is anything carrying an action - see below.
const LIFETIME = { ok: 6000, warning: 10000, error: null };

// Four is already more than an action produces: a bulk backup returns a status and a warning, and
// the poller announces one settled job at a time. The cap is a guard against a loop, not a routine
// path, so evicting the oldest is the whole of the policy.
const MAX_VISIBLE = 4;

const TONES = {
    ok: { classes: 'border-ok-line bg-ok-bg text-ok', glyph: '✓', region: 'polite' },
    warning: { classes: 'border-amber-line bg-amber-bg text-amber', glyph: '!', region: 'polite' },
    error: { classes: 'border-danger-line bg-danger-bg text-danger', glyph: '✕', region: 'assertive' },
};

function regions() {
    return Array.from(document.querySelectorAll('[data-toasts]'));
}

function trim() {
    const all = regions().flatMap((region) => Array.from(region.children));

    // Oldest first across both regions. A refusal outliving a "requested" it arrived after is the
    // right outcome, and dropping by age alone would sometimes take the wrong one - but an error
    // has no timer, so the only way it leaves is by being dismissed or by being the oldest thing
    // on screen when a fifth message arrives, which is late enough.
    while (all.length > MAX_VISIBLE) {
        all.shift()?.remove();
    }
}

/**
 * Put a sentence on the screen.
 *
 * @param {string} message
 * @param {'ok'|'warning'|'error'} tone
 * @param {{label: string, run: () => void}} [action] a button inside the toast; suppresses the timer
 */
export function toast(message, tone = 'ok', action = null) {
    const spec = TONES[tone] ?? TONES.ok;
    const region = document.querySelector(`[data-toasts="${spec.region}"]`);

    // No container means a layout that does not have one - the sign-in pages use their own. Nothing
    // to do, and nothing worth throwing over.
    if (region === null) {
        return;
    }

    const node = document.createElement('div');

    node.className =
        'pointer-events-auto flex items-start gap-2 rounded-[10px] border px-3.5 py-2.5 '
        + 'text-[12.5px] leading-relaxed shadow-[var(--shadow)] toast-enter '
        + spec.classes;

    // The glyph is here for the reason the status badge carries one: state is never left to hue
    // alone. aria-hidden because the region already announces the tone through its own role.
    const glyph = document.createElement('span');
    glyph.className = 'mt-px font-mono text-[11px] leading-none';
    glyph.setAttribute('aria-hidden', 'true');
    glyph.textContent = spec.glyph;

    const body = document.createElement('div');
    body.className = 'flex-1';
    body.textContent = message;

    node.append(glyph, body);

    if (action !== null) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mt-1.5 block text-left font-medium underline underline-offset-2';
        button.textContent = action.label;
        button.addEventListener('click', action.run);
        body.append(button);
    }

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.className = 'shrink-0 font-mono text-[13px] leading-none opacity-60 hover:opacity-100';
    dismiss.setAttribute('aria-label', 'Dismiss');
    dismiss.textContent = '×';
    dismiss.addEventListener('click', () => node.remove());
    node.append(dismiss);

    region.append(node);
    trim();

    /*
     | The timer, and the two things that suspend it.
     |
     | A message that vanishes while it is being read is the single reason people learn to distrust
     | these, so pointing at one or tabbing into it stops the clock and leaving restarts it. A toast
     | carrying an action never runs one at all: the whole point of the Reload button is that it is
     | still there when somebody comes back to the tab.
    */
    const lifetime = action === null ? LIFETIME[tone] : null;

    if (lifetime === null || lifetime === undefined) {
        return;
    }

    let timer = null;

    const go = () => {
        timer = window.setTimeout(() => node.remove(), lifetime);
    };

    const hold = () => {
        window.clearTimeout(timer);
        timer = null;
    };

    node.addEventListener('mouseenter', hold);
    node.addEventListener('mouseleave', go);
    node.addEventListener('focusin', hold);
    node.addEventListener('focusout', go);

    go();
}

/*
 | Escape clears what is on screen, and only while focus is inside the stack.
 |
 | Not a global key handler: Escape closes the command palette and the navigation drawer, and a
 | third listener taking it from either of those would be a worse trade than making somebody reach
 | for a dismiss button.
*/
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') {
        return;
    }

    const region = document.activeElement?.closest('[data-toasts]');

    if (region) {
        region.replaceChildren();
    }
});
