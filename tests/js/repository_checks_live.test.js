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
    RETRY_MILLISECONDS,
    SIGNAL_TYPE,
} = await import('../../assets/controllers/repository_checks_live_controller.js');

const RETRIES_TOTAL = RETRY_MILLISECONDS.reduce((sum, wait) => sum + wait, 0);
const STOPPED = 'Live updates stopped.';

const FIRST = '0199aaaa-0000-7000-8000-000000000001';
const SECOND = '0199aaaa-0000-7000-8000-000000000002';

let application;
let responses;

function row(id, { checking = false } = {}) {
    return `<div id="forge-repository-${id}" data-forge-repository="${id}" data-repository-row-url="/projects/p/repositories/${id}/row">
        <span>acme/${id.slice(-1)}</span>
        ${checking ? '<p data-repository-checking>Checking…</p>' : ''}
        <dialog data-testid="remove-${id}"></dialog>
    </div>`;
}

function emptyStateMount(rows) {
    return `<div data-repository-checks-live-target="populated">${rows.join('')}</div>
        <template data-repository-checks-live-target="empty"><p data-testid="empty">No repository</p></template>`;
}

function deferred() {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });

    return { promise, resolve };
}

async function mount(rows, { withEmptyState = false } = {}) {
    const content = withEmptyState ? emptyStateMount(rows) : rows.join('');
    document.body.innerHTML = `<section data-controller="repository-checks-live" data-repository-checks-live-stopped-value="${STOPPED}"><p data-repository-checks-live-target="status"></p>${content}</section>`;
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

function stoppedText() {
    return document.querySelector(
        '[data-repository-checks-live-target="status"]',
    ).textContent;
}

it.each([
    ['a network error', () => fetch.mockRejectedValue(new Error('offline'))],
    ['a 500 answer', () => {}],
])(
    'retries %s with a growing wait, then says that live updates stopped',
    async (_, fail) => {
        await mount([row(FIRST)]);
        fail();

        signal(FIRST);
        await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
        expect(fetch).toHaveBeenCalledTimes(1);
        for (const [index, wait] of RETRY_MILLISECONDS.entries()) {
            await vi.advanceTimersByTimeAsync(wait - 1);
            expect(fetch).toHaveBeenCalledTimes(index + 1);
            expect(stoppedText()).toBe('');
            await vi.advanceTimersByTimeAsync(1);
            expect(fetch).toHaveBeenCalledTimes(index + 2);
        }

        expect(stoppedText()).toBe(STOPPED);
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetch).toHaveBeenCalledTimes(RETRY_MILLISECONDS.length + 1);
    },
);

it('applies the row when a retry works, and says nothing stopped', async () => {
    await mount([row(FIRST)]);
    fetch.mockRejectedValueOnce(new Error('offline'));
    answer(200, '<retried/>');

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS[0]);

    expect(turbo.rendered).toEqual(['<retried/>']);
    expect(stoppedText()).toBe('');
});

it('says at once that live updates stopped when the answer is one a retry cannot fix', async () => {
    await mount([row(FIRST)]);
    answer(403);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(stoppedText()).toBe(STOPPED);
    await vi.advanceTimersByTimeAsync(RETRIES_TOTAL);
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('clears the stopped notice when a later fetch of a row works', async () => {
    await mount([row(FIRST)]);
    answer(403);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(stoppedText()).toBe(STOPPED);

    answer(200);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(stoppedText()).toBe('');
});

it('drops a waiting retry when a new signal fetches the row', async () => {
    await mount([row(FIRST)]);
    answer(500);
    answer(200);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(RETRIES_TOTAL);

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(turbo.rendered).toHaveLength(1);
});

it('does not retry a row that answers 404', async () => {
    await mount([row(FIRST), row(SECOND)]);
    answer(404);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS + RETRIES_TOTAL);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(stoppedText()).toBe('');
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

it('drops "Checking…" after 60 seconds when no signal arrived and the fetch fails', async () => {
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
    ).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(RETRIES_TOTAL);

    expect(
        document.querySelectorAll('[data-repository-checking]'),
    ).toHaveLength(0);
    expect(fetch).toHaveBeenCalledTimes(2 * (RETRY_MILLISECONDS.length + 1));
    expect(
        document.querySelector(`[data-forge-repository="${FIRST}"]`),
    ).not.toBeNull();
});

it('fetches the rows that still say "Checking…" when the 60 seconds end, so the fresh result shows', async () => {
    await mount([row(FIRST, { checking: true }), row(SECOND)]);
    answer(200, '<turbo-stream action="replace"></turbo-stream>');

    await vi.advanceTimersByTimeAsync(CHECKING_TIMEOUT_MILLISECONDS);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toBe(
        `/projects/p/repositories/${FIRST}/row`,
    );
    expect(turbo.rendered).toHaveLength(1);
    expect(
        document.querySelectorAll('[data-repository-checking]'),
    ).toHaveLength(1);
});

it('fetches the rows that say "Checking…" when the connection first opens', async () => {
    await mount([row(FIRST, { checking: true }), row(SECOND)]);
    answer(200);

    subscription().options.onOpen();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][0]).toBe(
        `/projects/p/repositories/${FIRST}/row`,
    );
    expect(turbo.rendered).toHaveLength(1);
});

it('keeps "Checking…" when the first open fetch fails, before the 60 seconds end', async () => {
    await mount([row(FIRST, { checking: true })]);
    answer(500);

    subscription().options.onOpen();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(
        document.querySelectorAll('[data-repository-checking]'),
    ).toHaveLength(1);
});

it('fetches nothing on open when no row waits', async () => {
    await mount([row(FIRST)]);

    subscription().options.onOpen();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch).not.toHaveBeenCalled();
});

it('holds a row while its dialog is open and fetches it when the dialog closes', async () => {
    await mount([row(FIRST)]);
    const dialog = document.querySelector(`[data-testid="remove-${FIRST}"]`);
    dialog.setAttribute('open', '');

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(fetch).not.toHaveBeenCalled();
    expect(turbo.rendered).toEqual([]);

    answer(200);
    dialog.removeAttribute('open');
    dialog.dispatchEvent(new Event('close'));
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(turbo.rendered).toHaveLength(1);
});

it('holds the response of a fetch when the dialog opened during it', async () => {
    await mount([row(FIRST)]);
    const dialog = document.querySelector(`[data-testid="remove-${FIRST}"]`);
    const slow = deferred();
    fetch.mockImplementationOnce(() => slow.promise);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    dialog.setAttribute('open', '');
    slow.resolve({ ok: true, status: 200, text: async () => '<one/>' });
    await vi.advanceTimersByTimeAsync(0);
    expect(turbo.rendered).toEqual([]);

    answer(200, '<two/>');
    dialog.removeAttribute('open');
    dialog.dispatchEvent(new Event('close'));
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(turbo.rendered).toEqual(['<two/>']);
});

it('does not drop a row that answers 404 while its dialog is open', async () => {
    await mount([row(FIRST)]);
    document
        .querySelector(`[data-testid="remove-${FIRST}"]`)
        .setAttribute('open', '');

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(
        document.querySelector(`[data-forge-repository="${FIRST}"]`),
    ).not.toBeNull();
});

it('applies only the newest response when fetches of one row overlap', async () => {
    await mount([row(FIRST)]);
    const older = deferred();
    const newer = deferred();
    fetch.mockImplementationOnce(() => older.promise);
    fetch.mockImplementationOnce(() => newer.promise);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(fetch).toHaveBeenCalledTimes(2);

    newer.resolve({ ok: true, status: 200, text: async () => '<newer/>' });
    await vi.advanceTimersByTimeAsync(0);
    older.resolve({ ok: true, status: 200, text: async () => '<older/>' });
    await vi.advanceTimersByTimeAsync(0);

    expect(turbo.rendered).toEqual(['<newer/>']);
});

it('shows the empty state when the last row is dropped', async () => {
    await mount([row(FIRST), row(SECOND)], { withEmptyState: true });
    answer(404);
    answer(404);

    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);
    expect(document.querySelector('[data-testid="empty"]')).toBeNull();

    signal(SECOND);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    expect(document.querySelector('[data-testid="empty"]')).not.toBeNull();
    expect(document.querySelector('[data-forge-repository]')).toBeNull();
});

it('stops listening and timing when it leaves the page', async () => {
    await mount([row(FIRST, { checking: true })]);
    answer(500);
    signal(FIRST);
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);

    expect(subscription().removed).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
});
