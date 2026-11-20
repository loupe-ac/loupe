/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));
const turbo = vi.hoisted(() => ({ streams: [] }));

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
    renderStreamMessage: (html) => turbo.streams.push(html),
}));

const { default: ReviewLiveController, keepLocalForm } =
    await import('../../assets/controllers/review_live_controller.js');
const { QUIET_MILLISECONDS, RETRY_MILLISECONDS, reset } =
    await import('../../assets/lib/live_fetch.js');

const DOCUMENT = 'd-1';
const BAND_URL = '/projects/p/documents/d-1/review/live-band';
const THREADS_URL = '/projects/p/documents/d-1/review/threads';

let application;
let answers;

function answer(status = 200, body = '<turbo-stream></turbo-stream>') {
    answers.push({
        status,
        ok: status >= 200 && status < 300,
        headers: { get: () => null },
        text: async () => body,
    });
}

async function mount({ threadsLive = true, shown = 2, extra = '' } = {}) {
    document.body.innerHTML = `
    <script type="text/html" data-live-band-template="stopped"><div data-testid="stopped">Stopped</div></script>
    <div class="lp-review" id="root" data-controller="review-live" data-live-shown-version="${shown}"
         data-review-live-document-value="${DOCUMENT}"
         data-review-live-band-url-value="${BAND_URL}"
         data-review-live-threads-url-value="${THREADS_URL}"
         data-review-live-threads-live-value="${threadsLive}">
        <div id="comment-thread-c-1"><form id="reply"><textarea>typed</textarea></form></div>
        ${extra}
    </div>`;
    await vi.advanceTimersByTimeAsync(0);
}

function signal(change) {
    expect(live.subscriptions).toHaveLength(1);
    live.subscriptions[0].handler({
        own: false,
        documentId: DOCUMENT,
        ...change,
    });
}

async function settle() {
    await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
}

function fetched() {
    return fetch.mock.calls.map(([url]) => url);
}

beforeEach(() => {
    vi.useFakeTimers();
    reset();
    live.subscriptions = [];
    turbo.streams = [];
    answers = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => answers.shift() ?? { status: 404, ok: false }),
    );
    application = Application.start();
    application.register('review-live', ReviewLiveController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('listens for the three document signals', async () => {
    await mount();
    expect(live.subscriptions[0].types).toEqual([
        'review.version_published',
        'review.thread_changed',
        'review.document_state_changed',
    ]);
});

it('reads the band stream when a higher version is published', async () => {
    await mount({ shown: 2 });
    answer();
    signal({ type: 'review.version_published', versionNumber: 3 });
    await settle();
    expect(fetched()).toEqual(
        [`${BAND_URL}?shown=2`].map((u) => `http://localhost:3000${u}`),
    );
    expect(turbo.streams).toHaveLength(1);
});

it('ignores a version that the page already shows', async () => {
    await mount({ shown: 3 });
    signal({ type: 'review.version_published', versionNumber: 3 });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
});

it('reads the band stream when the document state changes', async () => {
    await mount();
    answer();
    signal({ type: 'review.document_state_changed' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetched()[0]).toContain('/review/live-band?shown=2');
});

it('skips its own change and a signal of another document', async () => {
    await mount();
    signal({ type: 'review.document_state_changed', own: true });
    signal({ type: 'review.document_state_changed', documentId: 'other' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
});

it('reads one thread for a thread signal that the page holds', async () => {
    await mount();
    answer();
    signal({
        type: 'review.thread_changed',
        scope: 'thread',
        commentId: 'c-1',
    });
    await settle();
    expect(fetched()).toEqual([
        `http://localhost:3000${THREADS_URL}/c-1?shown=2`,
    ]);
});

it('reads every thread for a thread signal that names a thread the page lacks', async () => {
    await mount();
    answer();
    signal({
        type: 'review.thread_changed',
        scope: 'thread',
        commentId: 'c-9',
    });
    await settle();
    expect(fetched()).toEqual([`http://localhost:3000${THREADS_URL}?shown=2`]);
});

it('reads every thread for a collection signal', async () => {
    await mount();
    answer();
    signal({
        type: 'review.thread_changed',
        scope: 'collection',
        commentId: 'c-1',
    });
    await settle();
    expect(fetched()).toEqual([`http://localhost:3000${THREADS_URL}?shown=2`]);
});

it('reads every thread when a burst names two threads', async () => {
    await mount({
        extra: '<div id="comment-thread-c-2"></div>',
    });
    answer();
    signal({
        type: 'review.thread_changed',
        scope: 'thread',
        commentId: 'c-1',
    });
    signal({
        type: 'review.thread_changed',
        scope: 'thread',
        commentId: 'c-2',
    });
    await settle();
    expect(fetched()).toEqual([`http://localhost:3000${THREADS_URL}?shown=2`]);
});

it('does not hold a thread read for typed text in a reply box', async () => {
    await mount();
    answer();
    signal({
        type: 'review.thread_changed',
        scope: 'thread',
        commentId: 'c-1',
    });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('reads no thread once a higher version is published', async () => {
    await mount();
    answer();
    signal({ type: 'review.version_published', versionNumber: 3 });
    signal({
        type: 'review.thread_changed',
        scope: 'collection',
        commentId: 'c-1',
    });
    live.subscriptions[0].options.onReconnect();
    await settle();
    expect(fetched().every((url) => url.includes('live-band'))).toBe(true);
});

it('reads no thread on a page that is not live for threads', async () => {
    await mount({ threadsLive: false });
    signal({
        type: 'review.thread_changed',
        scope: 'collection',
        commentId: 'c-1',
    });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
});

it('holds a thread read while a drag runs', async () => {
    await mount({ extra: '<div data-dragging></div>' });
    answer();
    signal({
        type: 'review.thread_changed',
        scope: 'collection',
        commentId: 'c-1',
    });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    document.querySelector('[data-dragging]').remove();
    await vi.advanceTimersByTimeAsync(300);
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('reads the band and every thread after a reconnect', async () => {
    await mount();
    answer();
    answer();
    live.subscriptions[0].options.onReconnect();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(2);
    expect(
        fetched().some((url) => url.endsWith(`${THREADS_URL}?shown=2`)),
    ).toBe(true);
    expect(fetched().some((url) => url.includes('live-band?shown=2'))).toBe(
        true,
    );
});

it('stops and shows the stopped band after the last retry', async () => {
    await mount();
    for (let attempt = 0; attempt <= RETRY_MILLISECONDS.length; attempt += 1) {
        answer(500, '');
    }
    signal({ type: 'review.document_state_changed' });
    await settle();
    for (const wait of RETRY_MILLISECONDS) {
        await vi.advanceTimersByTimeAsync(wait);
    }
    expect(document.querySelector('[data-testid="stopped"]')).not.toBeNull();
    expect(live.subscriptions[0].removed).toBe(true);
});

it('guards forms against a morph while connected only', async () => {
    const add = vi.spyOn(document, 'addEventListener');
    const remove = vi.spyOn(document, 'removeEventListener');
    await mount();
    expect(add).toHaveBeenCalledWith(
        'turbo:before-morph-element',
        keepLocalForm,
    );
    expect(remove).not.toHaveBeenCalledWith(
        'turbo:before-morph-element',
        keepLocalForm,
    );
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(remove).toHaveBeenCalledWith(
        'turbo:before-morph-element',
        keepLocalForm,
    );
});

it('drops its subscription on disconnect', async () => {
    await mount();
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(live.subscriptions[0].removed).toBe(true);
});

function morphEvent(currentElement, newElement) {
    const event = new CustomEvent('turbo:before-morph-element', {
        cancelable: true,
        detail: { currentElement, newElement },
    });
    keepLocalForm(event);

    return event;
}

it('keeps a form that holds typed text out of a morph', () => {
    document.body.innerHTML =
        '<form id="old"><textarea></textarea></form><form id="new"><textarea></textarea></form>';
    const current = document.getElementById('old');
    current.querySelector('textarea').value = 'typed';
    expect(
        morphEvent(current, document.getElementById('new')).defaultPrevented,
    ).toBe(true);
});

it('keeps a form that a script opened out of a morph', () => {
    document.body.innerHTML =
        '<form id="old"></form><form id="new" hidden></form>';
    expect(
        morphEvent(
            document.getElementById('old'),
            document.getElementById('new'),
        ).defaultPrevented,
    ).toBe(true);
});

it('lets an untouched form morph', () => {
    document.body.innerHTML =
        '<form id="old"><textarea></textarea></form><form id="new"><textarea></textarea></form>';
    expect(
        morphEvent(
            document.getElementById('old'),
            document.getElementById('new'),
        ).defaultPrevented,
    ).toBe(false);
});

it('reads the threads flag again when a morph lands on the page', async () => {
    await mount({ threadsLive: true });
    answer();
    signal({ type: 'review.version_published', versionNumber: 3 });
    await settle();

    document
        .querySelector('#root')
        .dispatchEvent(new CustomEvent('turbo:morph-element'));
    answer();
    signal({
        type: 'review.thread_changed',
        commentId: 'c-1',
        scope: 'thread',
    });
    await settle();

    expect(fetched().some((url) => url.includes('/review/threads'))).toBe(true);
});
