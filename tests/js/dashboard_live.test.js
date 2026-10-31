/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));
const turbo = vi.hoisted(() => ({ rendered: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);

        return () => {
            subscription.removed = true;
        };
    },
}));

vi.mock('@hotwired/turbo', () => ({
    renderStreamMessage: (html) => turbo.rendered.push(html),
}));

const {
    default: DashboardLiveController,
    SETTLE_MILLISECONDS,
    MAX_WAIT_MILLISECONDS,
    BUSY_RETRY_MILLISECONDS,
    RETRY_MILLISECONDS,
    SIGNAL_TYPE,
} = await import('../../assets/controllers/dashboard_live_controller.js');

const STOPPED = 'Live updates stopped.';
const URL = '/projects/p/analytics/dashboard/grid';

let application;
let responses;
let fetchCalls;

async function mount(inside = '') {
    document.body.innerHTML = `<div data-controller="dashboard-live" data-dashboard-live-url-value="${URL}" data-dashboard-live-stopped-value="${STOPPED}">
        <p data-dashboard-live-target="status"></p>
        <div id="dashboard-grid">${inside}</div>
    </div>`;
    await vi.advanceTimersByTimeAsync(0);
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);

    return live.subscriptions[0];
}

function signal({ own = false } = {}) {
    subscription().handler({
        type: SIGNAL_TYPE,
        graphId: 'g1',
        kind: 'renamed',
        local: false,
        own,
    });
}

function answer(status, body = '<turbo-stream></turbo-stream>') {
    responses.push({
        ok: status >= 200 && status < 300,
        status,
        text: async () => body,
    });
}

const status = () => document.querySelector('[data-dashboard-live-target]');

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions.length = 0;
    turbo.rendered.length = 0;
    responses = [];
    fetchCalls = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (...call) => {
            fetchCalls.push(call);
            const next = responses.shift();
            if (next === undefined) {
                throw new Error('network');
            }

            return next;
        }),
    );
    application = Application.start();
    application.register('dashboard-live', DashboardLiveController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('listens to the signal of a graph change', async () => {
    await mount();

    expect(subscription().types).toBe(SIGNAL_TYPE);
});

it('fetches the grid once after the settle delay and renders the stream', async () => {
    await mount();
    answer(200, '<turbo-stream action="replace"></turbo-stream>');

    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS - 1);
    expect(fetchCalls).toHaveLength(0);

    await vi.advanceTimersByTimeAsync(1);
    expect(fetchCalls).toHaveLength(1);
    expect(fetchCalls[0][0]).toBe(URL);
    expect(fetchCalls[0][1].headers.Accept).toBe('text/vnd.turbo-stream.html');
    expect(turbo.rendered).toEqual([
        '<turbo-stream action="replace"></turbo-stream>',
    ]);
});

it('merges a burst of signals into one fetch', async () => {
    await mount();
    answer(200);

    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS - 50);
    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS - 50);
    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS);

    expect(fetchCalls).toHaveLength(1);
});

it('fetches after the longest wait when signals never stop', async () => {
    await mount();
    answer(200);

    for (let elapsed = 0; elapsed < MAX_WAIT_MILLISECONDS; elapsed += 100) {
        signal();
        await vi.advanceTimersByTimeAsync(100);
    }

    expect(fetchCalls).toHaveLength(1);
});

it('skips a signal that this tab caused', async () => {
    await mount();
    answer(200);

    signal({ own: true });
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS * 2);

    expect(fetchCalls).toHaveLength(0);
});

it('fetches once on a reconnect to catch up', async () => {
    await mount();
    answer(200);

    subscription().options.onReconnect();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS);

    expect(fetchCalls).toHaveLength(1);
    expect(turbo.rendered).toHaveLength(1);
});

it.each([
    ['a drag', '<div data-dragging></div>'],
    ['an open rename form', '<article data-renaming></article>'],
    ['an open dialog', '<dialog open></dialog>'],
])('holds the fetch during %s until it ends', async (_name, held) => {
    await mount(held);
    answer(200);

    signal();
    await vi.advanceTimersByTimeAsync(
        SETTLE_MILLISECONDS + BUSY_RETRY_MILLISECONDS * 5,
    );
    expect(fetchCalls).toHaveLength(0);

    document.getElementById('dashboard-grid').replaceChildren();
    await vi.advanceTimersByTimeAsync(BUSY_RETRY_MILLISECONDS);

    expect(fetchCalls).toHaveLength(1);
    expect(turbo.rendered).toHaveLength(1);
});

it('holds an answer that arrives after a drag began', async () => {
    await mount();
    let release;
    responses.push({
        ok: true,
        status: 200,
        text: () =>
            new Promise((resolve) => {
                release = () => resolve('<turbo-stream></turbo-stream>');
            }),
    });

    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS);
    document.getElementById('dashboard-grid').dataset.dragging = '';
    release();
    await vi.advanceTimersByTimeAsync(0);

    expect(turbo.rendered).toHaveLength(0);

    delete document.getElementById('dashboard-grid').dataset.dragging;
    answer(200);
    await vi.advanceTimersByTimeAsync(BUSY_RETRY_MILLISECONDS);

    expect(turbo.rendered).toHaveLength(1);
});

it('retries a failed fetch with a growing wait and then says that live updates stopped', async () => {
    await mount();

    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS);
    expect(fetchCalls).toHaveLength(1);

    for (const [index, wait] of RETRY_MILLISECONDS.entries()) {
        await vi.advanceTimersByTimeAsync(wait - 1);
        expect(fetchCalls).toHaveLength(index + 1);
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchCalls).toHaveLength(index + 2);
    }

    expect(status().textContent).toBe(STOPPED);
});

it('clears the stopped message when a later fetch works', async () => {
    await mount();

    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS);
    for (const wait of RETRY_MILLISECONDS) {
        await vi.advanceTimersByTimeAsync(wait);
    }
    expect(status().textContent).toBe(STOPPED);

    answer(200);
    signal();
    await vi.advanceTimersByTimeAsync(SETTLE_MILLISECONDS);

    expect(status().textContent).toBe('');
});

it('does not retry an answer that a retry cannot change', async () => {
    await mount();
    answer(403);

    signal();
    await vi.advanceTimersByTimeAsync(
        SETTLE_MILLISECONDS + RETRY_MILLISECONDS[0],
    );

    expect(fetchCalls).toHaveLength(1);
    expect(status().textContent).toBe(STOPPED);
});

it('stops listening when the page leaves', async () => {
    await mount();

    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);

    expect(subscription().removed).toBe(true);
});
