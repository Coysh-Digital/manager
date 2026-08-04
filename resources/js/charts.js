/*
 * Charts.
 *
 * The first dependency in this application's front end, and it earns its place on one screen: a
 * month of check-ins drawn as thirty divs was legible, but it could not carry an axis, a tooltip or
 * a second series, and every one of those was the next thing asked of it.
 *
 * Three rules keep it from becoming a framework:
 *
 *  - **Tree-shaken.** Only the controllers and elements actually used are registered. Importing
 *    `chart.js/auto` pulls in radar, polar area, doughnut and the whole date adapter stack for the
 *    sake of two chart types.
 *  - **Declarative.** Nothing here knows what a site is. A chart is a `<canvas>` carrying a
 *    `data-chart` payload that Blade rendered, so the data still comes from the server and the
 *    template stays the thing you read to find out what a screen shows.
 *  - **Themed from the stylesheet, not from constants.** Every colour is read from the same CSS
 *    custom properties the rest of the interface uses. A palette hard-coded here would be a second
 *    source of truth that drifts, and it would be wrong in whichever theme it was not written in.
 */

import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Filler,
    Tooltip,
);

/**
 * A colour from the stylesheet.
 *
 * Resolved at draw time rather than at import time, because the values change when the theme does.
 */
function token(name, fallback = '#888') {
    const value = getComputedStyle(document.documentElement).getPropertyValue(`--${name}`).trim();

    return value || fallback;
}

/**
 * Canvas wants rgba; the stylesheet holds hex. Only used for fills behind a line.
 */
function translucent(hex, alpha) {
    const match = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex.trim());

    if (!match) {
        return hex;
    }

    const [r, g, b] = match.slice(1).map((part) => parseInt(part, 16));

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/**
 * The look shared by every chart here: no chartjunk, no legend for a single series, no gridline
 * competing with the data it is behind.
 */
function baseOptions() {
    const grid = token('border', '#e5e0d8');
    const label = token('text-3', '#8a8580');

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 200 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: token('surface', '#fff'),
                titleColor: token('text', '#1c1a18'),
                bodyColor: token('text-2', '#5c5651'),
                borderColor: token('border-2', '#d2cdc6'),
                borderWidth: 1,
                cornerRadius: 7,
                padding: 9,
                displayColors: false,
                titleFont: { family: 'IBM Plex Mono, monospace', size: 11, weight: '500' },
                bodyFont: { family: 'IBM Plex Sans, sans-serif', size: 12 },
            },
        },
        scales: {
            x: {
                grid: { display: false },
                border: { color: grid },
                ticks: {
                    color: label,
                    font: { family: 'IBM Plex Mono, monospace', size: 10 },
                    maxRotation: 0,
                    autoSkipPadding: 16,
                },
            },
            y: {
                border: { display: false },
                grid: { color: grid, drawTicks: false },
                ticks: {
                    color: label,
                    font: { family: 'IBM Plex Mono, monospace', size: 10 },
                    padding: 8,
                    maxTicksLimit: 5,
                },
            },
        },
    };
}

/**
 * Check-ins received per period, as a percentage of those expected.
 *
 * Bars are coloured per value rather than as one series: the question the chart answers is "which
 * periods were bad", and a single-colour chart makes the reader compare heights to find out.
 */
function uptime(payload) {
    const options = baseOptions();

    options.scales.y.min = 0;
    options.scales.y.max = 100;
    options.scales.y.ticks.callback = (value) => `${value}%`;
    options.plugins.tooltip.callbacks = {
        label: (context) => {
            const point = payload.points[context.dataIndex];

            return `${point.received} of ${point.expected} check-ins · ${Math.round(point.value)}%`;
        },
    };

    return {
        type: 'bar',
        data: {
            labels: payload.points.map((point) => point.label),
            datasets: [
                {
                    data: payload.points.map((point) => point.value),
                    backgroundColor: payload.points.map((point) => {
                        if (point.value >= 90) return token('ok', '#15734f');
                        if (point.value >= 50) return token('amber', '#8a5a00');
                        if (point.value > 0) return token('danger', '#b3261e');

                        // A period with nothing in it still gets a mark. An absence the eye reads as
                        // "no data yet" is the wrong reading when the truth is "nothing arrived".
                        return token('border-2', '#d2cdc6');
                    }),
                    borderRadius: 2,
                    borderSkipped: false,
                    // A hairline floor, so an empty period is visible rather than invisible.
                    minBarLength: 2,
                    // Bars at their full category width read as a solid block of colour rather than
                    // as a series. A ceiling keeps a seven-day window from looking like a flag.
                    categoryPercentage: 0.82,
                    barPercentage: 0.86,
                    maxBarThickness: 44,
                },
            ],
        },
        options,
    };
}

/**
 * Backup size over time.
 *
 * A line rather than bars: the useful signal is the shape - a database growing steadily, or one that
 * halved overnight because a table was dropped.
 *
 * Drawn in the info tone rather than the brand primary. The primary here is a rust red, which is
 * correct for a button and wrong for a size: a neutral measurement drawn in something the eye reads
 * as an alarm makes a perfectly ordinary database look like an incident.
 */
function backupSize(payload) {
    const options = baseOptions();
    const line = token('info', '#1b5a9e');

    options.scales.y.min = 0;
    options.scales.y.ticks.callback = (value) => `${Math.round(value)} MB`;
    options.plugins.tooltip.callbacks = {
        label: (context) => {
            const point = payload.points[context.dataIndex];

            return `${point.size} · ${point.state}`;
        },
    };

    return {
        type: 'line',
        data: {
            labels: payload.points.map((point) => point.label),
            datasets: [
                {
                    data: payload.points.map((point) => point.value),
                    borderColor: line,
                    backgroundColor: translucent(line, 0.12),
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: token('surface', '#fff'),
                    pointBorderColor: line,
                    pointBorderWidth: 2,
                    fill: true,
                    tension: 0.25,
                },
            ],
        },
        options,
    };
}

/**
 * Outstanding findings, week by week.
 *
 * A count on its own is a snapshot, and a snapshot cannot tell "four findings, down from eleven"
 * apart from "four findings, up from none" - which are opposite situations reading identically.
 *
 * Coloured by direction rather than by value: the useful signal is the slope. A site sitting
 * steadily on two low-severity findings nobody intends to fix is not the thing to draw in red.
 */
function findings(payload) {
    const options = baseOptions();
    const line = token('amber', '#8a5a00');

    options.scales.y.min = 0;
    options.scales.y.ticks.precision = 0;
    options.plugins.tooltip.callbacks = {
        label: (context) => {
            const point = payload.points[context.dataIndex];
            const parts = [`${point.value} outstanding`];

            if (point.opened) parts.push(`${point.opened} opened`);
            if (point.resolved) parts.push(`${point.resolved} resolved`);

            return parts.join(' · ');
        },
    };

    return {
        type: 'line',
        data: {
            labels: payload.points.map((point) => point.label),
            datasets: [
                {
                    data: payload.points.map((point) => point.value),
                    borderColor: line,
                    backgroundColor: translucent(line, 0.12),
                    borderWidth: 2,
                    pointRadius: 2,
                    pointBackgroundColor: line,
                    fill: true,
                    stepped: true,
                },
            ],
        },
        options,
    };
}

const builders = { uptime, backupSize, findings };

const live = new Map();

function draw(canvas) {
    let payload;

    try {
        payload = JSON.parse(canvas.dataset.chart);
    } catch {
        // Malformed payload. Leaving the canvas blank is better than throwing and taking the rest of
        // the page's scripts down with it.
        return;
    }

    const build = builders[payload.kind];

    if (!build || !payload.points?.length) {
        return;
    }

    live.get(canvas)?.destroy();
    live.set(canvas, new Chart(canvas, build(payload)));
}

function drawAll() {
    document.querySelectorAll('canvas[data-chart]').forEach(draw);
}

document.addEventListener('DOMContentLoaded', drawAll);

/*
 * Redraw when the theme changes.
 *
 * Chart.js resolves colours once, at construction, so a chart built in the light theme stays light
 * after the toggle is pressed - a dark page with a chart still drawn in ink meant for paper. The
 * theme toggle sets data-theme on the root element, so watching that attribute is enough and needs
 * no coupling to the code that sets it.
 */
new MutationObserver(drawAll).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme'],
});
