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
    default: RepositoryChecksLiveController,
    DEBOUNCE_MILLISECONDS,
    CHECKING_TIMEOUT_MILLISECONDS,
    SIGNAL_TYPE,
} = await import('../../assets/controllers/repository_checks_live_controller.js');

const FIRST = '0199aaaa-0000-7000-8000-000000000001';
const SECOND = '0199aaaa-0000-7000-8000-000000000002';

let application;
let responses;

function row(id, { checking = false } = {}) {
    return `<div id="forge-repository-${id}" data-forge-repository="${id}" data-repository-row-url="/projects/p/repositories/${id}/row">
        <span>acme/${id.slice(-1)}</span>
        ${checking ? '<p data-repository-checking>Checking…</p>' : ''}
    </div>`;
}

async function mount(rows) {
    document.body.innerHTML = `<section data-controller="repository-checks-live">${rows.join('')}</section>`;
    await vi.advanceTimersByTimeAsync(0);
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);

    return live.subscriptions[0];
}

function signal(repositoryId) {
    subscription().handler({
        type: SIGNAL_TYPE,
        repositoryId,
        local: false,
        own: false,
    });
}

function answer(status, body = '<turbo-stream></turbo-stream>') {
    responses.push({
        ok: status >= 200 && status < 300,
        status,
        text: async () => body,
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions = [];
    turbo.rendered = [];
    responses = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => responses.shift() ?? { ok: false, status: 500 }),
    );
    application = Application.start();
    application.register(
        'repository-checks-live',
        RepositoryChecksLiveController,
    );
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('listens for the repository check signal', async () => {
    await mount([row(FIRST)]);

    expect(subscription().types).toBe(SIGNAL_TYPE);
    expect(subscription().options.onReconnect).toEqual(expect.any(Function));
});

it('fetches the row of the signal as a Turbo stream and applies it', async () => {
    await mount([row(FIRST), row(SECOND)]);
    answer(200, '<turbo-stream action="replace"></turbo-stream>');

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe(`/projects/p/repositories/${FIRST}/row`);
    expect(options.credentials).toBe('same-origin');
    expect(options.headers.Accept).toBe('text/vnd.turbo-stream.html');
    expect(turbo.rendered).toEqual([
        '<turbo-stream action="replace"></turbo-stream>',
    ]);
});

it('merges a burst of signals for one repository into one fetch', async () => {
    await mount([row(FIRST)]);
    answer(200);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS - 50);
    signal(FIRST);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS - 50);
    expect(fetch).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(100);

    expect(fetch).toHaveBeenCalledTimes(1);
});

it('fetches each repository of a burst once', async () => {
    await mount([row(FIRST), row(SECOND)]);
    answer(200);
    answer(200);

    signal(FIRST);
    signal(SECOND);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch.mock.calls.map(([url]) => url).sort()).toEqual([
        `/projects/p/repositories/${FIRST}/row`,
        `/projects/p/repositories/${SECOND}/row`,
    ]);
});

it('ignores a signal for a repository the page does not show', async () => {
    await mount([row(FIRST)]);

    signal(SECOND);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch).not.toHaveBeenCalled();
});

it('fetches every row again after a reconnect', async () => {
    await mount([row(FIRST), row(SECOND)]);
    answer(200);
    answer(200);

    subscription().options.onReconnect();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(turbo.rendered).toHaveLength(2);
});

it('drops the row that answers 404', async () => {
    await mount([row(FIRST), row(SECOND)]);
    answer(404);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(
        document.querySelector(`[data-forge-repository="${FIRST}"]`),
    ).toBeNull();
    expect(
        document.querySelector(`[data-forge-repository="${SECOND}"]`),
    ).not.toBeNull();
    expect(turbo.rendered).toEqual([]);
});

it('keeps the row and applies nothing when the fetch fails', async () => {
    await mount([row(FIRST)]);
    answer(500);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(
        document.querySelector(`[data-forge-repository="${FIRST}"]`),
    ).not.toBeNull();
    expect(turbo.rendered).toEqual([]);
});

it('keeps the row when the network fails', async () => {
    await mount([row(FIRST)]);
    fetch.mockRejectedValueOnce(new Error('offline'));

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(
        document.querySelector(`[data-forge-repository="${FIRST}"]`),
    ).not.toBeNull();
});

it('drops "Checking…" after 60 seconds when no signal arrived', async () => {
    await mount([
        row(FIRST, { checking: true }),
        row(SECOND, { checking: true }),
    ]);

    await vi.advanceTimersByTimeAsync(CHECKING_TIMEOUT_MILLISECONDS - 1000);
    expect(
        document.querySelectorAll('[data-repository-checking]'),
    ).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(1000);

    expect(
        document.querySelectorAll('[data-repository-checking]'),
    ).toHaveLength(0);
    expect(
        document.querySelector(`[data-forge-repository="${FIRST}"]`),
    ).not.toBeNull();
});

it('stops listening and timing when it leaves the page', async () => {
    await mount([row(FIRST, { checking: true })]);

    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);

    expect(subscription().removed).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
});
