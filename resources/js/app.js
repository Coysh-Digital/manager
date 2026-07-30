/*
 * Theme switching.
 *
 * Deliberately tiny. The rest of the interface is server-rendered Blade, and a control plane is
 * somewhere every dependency has to earn its place — this is a dozen lines rather than a framework.
 * Charts are the one exception, and charts.js says what it is paying for.
 *
 * The initial theme is applied by an inline script in the layout head, before first paint, so
 * there is no flash of the wrong theme. This file only handles changing it afterwards.
 */

import './passkeys.js';
import './copy.js';
import './charts.js';
import './nav.js';
import './palette.js';

const STORAGE_KEY = 'manager.theme';

function resolve(preference) {
    if (preference === 'dark' || preference === 'light') {
        return preference;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function apply(preference) {
    document.documentElement.setAttribute('data-theme', resolve(preference));

    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.themeOption === preference));
    });
}

function stored() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) || 'system';
    } catch {
        // Private browsing, or storage disabled. Falling back to the system preference is better
        // than failing to render.
        return 'system';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    apply(stored());

    document.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.addEventListener('click', () => {
            const preference = button.dataset.themeOption;

            try {
                window.localStorage.setItem(STORAGE_KEY, preference);
            } catch {
                // Not fatal: the choice simply will not persist.
            }

            apply(preference);
        });
    });
});

// Follow the operating system while the preference is "system", so a machine that switches at
// dusk takes the interface with it.
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (stored() === 'system') {
        apply('system');
    }
});
