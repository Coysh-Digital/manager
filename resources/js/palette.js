/*
 * The command palette.
 *
 * A control plane is a place people come to with a site already in mind. Everything before this made
 * them find it: open the fleet, scan or filter, click, then click a tab. That is four actions to
 * reach a page they could have named.
 *
 * Vanilla, and small enough to read in one sitting. Chart.js earned its place by drawing things this
 * could not; a combobox does not need a framework.
 *
 * Two decisions worth stating:
 *
 *  - **The whole list is fetched once**, on first open, not per keystroke. A fleet is tens or
 *    hundreds of sites — a few kilobytes — so filtering happens in the browser. That makes it
 *    instant, works on a bad connection, and keeps what somebody typed out of the access log.
 *  - **It never acts, it only navigates.** No "revoke this connector" from a text box. Everything
 *    destructive in this product asks for a typed confirmation on the screen that owns it, and a
 *    palette that could fire those would quietly undo that.
 */

const ENDPOINT = document.querySelector('meta[name="palette-endpoint"]')?.content;

let cache = null;
let loading = null;
let active = 0;
let results = [];

const el = {};

function build() {
    const overlay = document.createElement('div');
    overlay.className =
        'fixed inset-0 z-50 hidden items-start justify-center bg-black/40 p-4 pt-[12vh]';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', 'Search sites and screens');

    overlay.innerHTML = `
        <div class="flex max-h-[70vh] w-full max-w-[560px] flex-col overflow-hidden rounded-[12px] border border-border bg-surface shadow-[var(--shadow-lg,var(--shadow))]">
            <input type="text" data-palette-input autocomplete="off" spellcheck="false"
                   placeholder="Jump to a site or screen…"
                   aria-controls="palette-results" aria-expanded="true" aria-autocomplete="list"
                   class="h-[52px] w-full flex-none border-b border-border bg-surface px-4 text-[15px] text-text outline-none placeholder:text-text-3">
            <ul id="palette-results" role="listbox" data-palette-results
                class="m-0 flex list-none flex-col overflow-y-auto p-1.5"></ul>
            <div class="flex flex-none items-center gap-4 border-t border-border bg-surface-2 px-4 py-2 font-mono text-[10.5px] text-text-3">
                <span>↑↓ move</span><span>↵ open</span><span>esc close</span>
            </div>
        </div>`;

    document.body.append(overlay);

    el.overlay = overlay;
    el.input = overlay.querySelector('[data-palette-input]');
    el.list = overlay.querySelector('[data-palette-results]');

    el.input.addEventListener('input', render);
    el.input.addEventListener('keydown', onKey);

    // Clicking the backdrop closes; clicking the panel must not.
    overlay.addEventListener('mousedown', (event) => {
        if (event.target === overlay) {
            close();
        }
    });
}

async function load() {
    if (cache) return cache;
    if (loading) return loading;

    loading = fetch(ENDPOINT, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((response) => (response.ok ? response.json() : { sites: [], screens: [] }))
        .then((data) => {
            cache = data;

            return data;
        })
        .catch(() => ({ sites: [], screens: [] }));

    return loading;
}

/**
 * Subsequence matching, so "acmeh" finds "Acme — Health".
 *
 * Deliberately not fuzzy scoring with a library. The useful behaviours are: typing part of a name,
 * typing part of a domain, and typing a name then a tab. Ranking is by where the match starts,
 * because a site beginning with what you typed is almost always the one you meant.
 */
function score(haystack, needle) {
    const text = haystack.toLowerCase();
    const index = text.indexOf(needle);

    if (index !== -1) {
        return index === 0 ? 0 : 1 + index;
    }

    // Fall back to in-order characters, ranked below any direct substring hit.
    let cursor = 0;

    for (const character of needle) {
        cursor = text.indexOf(character, cursor);

        if (cursor === -1) return null;
        cursor++;
    }

    return 500;
}

const TABS = ['overview', 'health', 'updates', 'security', 'backups', 'settings', 'audit'];

function match(data, query) {
    const needle = query.trim().toLowerCase();

    if (!needle) {
        return [
            ...data.sites.slice(0, 6).map((site) => ({ label: site.name, hint: site.domain, url: site.url })),
            ...data.screens.map((screen) => ({ label: screen.name, hint: 'Screen', url: screen.url })),
        ];
    }

    // "acme health" — the last word may name a tab. Only treated as one if something is left over to
    // match the site with, so typing "health" alone still finds a site called Health Ltd.
    const words = needle.split(/\s+/);
    const last = words[words.length - 1];
    const tab = words.length > 1 ? TABS.find((name) => name.startsWith(last)) : null;
    const siteNeedle = tab ? words.slice(0, -1).join(' ') : needle;

    const found = [];

    for (const site of data.sites) {
        const best = Math.min(
            score(site.name, siteNeedle) ?? Infinity,
            score(site.domain, siteNeedle) ?? Infinity,
        );

        if (best === Infinity) continue;

        found.push({
            label: site.name,
            hint: tab ? `${site.domain} · ${tab}` : site.domain,
            badge: site.environment === 'production' ? null : site.environment,
            url: tab ? site.tabs[tab] : site.url,
            rank: best,
        });
    }

    for (const screen of data.screens) {
        const best = score(screen.name, needle);

        if (best === null) continue;

        found.push({ label: screen.name, hint: 'Screen', url: screen.url, rank: best + 0.5 });
    }

    return found.sort((a, b) => a.rank - b.rank).slice(0, 12);
}

function render() {
    results = match(cache ?? { sites: [], screens: [] }, el.input.value);
    active = 0;

    if (results.length === 0) {
        el.list.innerHTML =
            '<li class="px-3 py-6 text-center text-[13px] text-text-2">Nothing matches that.</li>';

        return;
    }

    el.list.innerHTML = results
        .map(
            (result, index) => `
            <li role="option" id="palette-option-${index}" aria-selected="${index === active}"
                data-index="${index}"
                class="flex cursor-pointer items-baseline gap-2.5 rounded-[7px] px-3 py-2 ${
                    index === active ? 'bg-pale text-primary' : 'text-text'
                }">
                <span class="text-[13.5px] font-medium">${escapeHtml(result.label)}</span>
                ${result.badge ? `<span class="rounded-[4px] border border-border px-1 py-px font-mono text-[10px] text-text-3">${escapeHtml(result.badge)}</span>` : ''}
                <span class="ml-auto truncate font-mono text-[11px] text-text-3">${escapeHtml(result.hint)}</span>
            </li>`,
        )
        .join('');

    el.list.querySelectorAll('[data-index]').forEach((node) => {
        node.addEventListener('mouseenter', () => {
            active = Number(node.dataset.index);
            highlight();
        });
        node.addEventListener('click', () => go(Number(node.dataset.index)));
    });

    el.input.setAttribute('aria-activedescendant', 'palette-option-0');
}

function escapeHtml(value) {
    return String(value ?? '').replace(
        /[&<>"']/g,
        (character) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character],
    );
}

function highlight() {
    el.list.querySelectorAll('[data-index]').forEach((node) => {
        const isActive = Number(node.dataset.index) === active;

        node.setAttribute('aria-selected', String(isActive));
        node.classList.toggle('bg-pale', isActive);
        node.classList.toggle('text-primary', isActive);
        node.classList.toggle('text-text', !isActive);

        if (isActive) {
            node.scrollIntoView({ block: 'nearest' });
            el.input.setAttribute('aria-activedescendant', node.id);
        }
    });
}

function onKey(event) {
    if (event.key === 'ArrowDown' || (event.key === 'n' && event.ctrlKey)) {
        event.preventDefault();
        active = Math.min(active + 1, results.length - 1);
        highlight();
    } else if (event.key === 'ArrowUp' || (event.key === 'p' && event.ctrlKey)) {
        event.preventDefault();
        active = Math.max(active - 1, 0);
        highlight();
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(active);
    } else if (event.key === 'Escape') {
        event.preventDefault();
        close();
    }
}

function go(index) {
    const result = results[index];

    if (result) {
        window.location.href = result.url;
    }
}

let returnFocusTo = null;

async function open() {
    if (!el.overlay) build();

    returnFocusTo = document.activeElement;

    el.overlay.classList.remove('hidden');
    el.overlay.classList.add('flex');
    document.body.classList.add('overflow-hidden');
    el.input.value = '';
    el.input.focus();

    // Rendered twice: once immediately so the panel is never empty, once when the fleet arrives.
    render();
    await load();
    render();
}

function close() {
    if (!el.overlay) return;

    el.overlay.classList.add('hidden');
    el.overlay.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');

    // Back to whatever opened it, rather than to the top of the document.
    returnFocusTo?.focus?.();
}

function isTyping(target) {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

document.addEventListener('DOMContentLoaded', () => {
    if (!ENDPOINT) return;

    document.querySelectorAll('[data-palette-open]').forEach((trigger) => {
        trigger.addEventListener('click', open);
    });

    document.addEventListener('keydown', (event) => {
        const isOpen = el.overlay && !el.overlay.classList.contains('hidden');

        if ((event.key === 'k' || event.key === 'K') && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            isOpen ? close() : open();

            return;
        }

        // "/" is the other convention people reach for, but only when they are not already typing —
        // otherwise it would swallow a slash in a search box or a reason field.
        if (event.key === '/' && !isOpen && !isTyping(event.target)) {
            event.preventDefault();
            open();
        }
    });
});
