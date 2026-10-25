/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);
        return () => {
            subscription.removed = true;
        };
    },
}));

const { default: LivePillController, RETRY_MILLISECONDS } =
    await import('../../assets/controllers/live_pill_controller.js');

const PROJECT = '0199aaaa-0000-7000-8000-000000000001';
const URL = '/projects/' + PROJECT + '/activity/open-count';

let application;
let frame;
let link;

function mount(events = ['worker_run.changed'], project = PROJECT) {
    document.body.innerHTML = `
        <a data-controller="live-pill"
           data-live-pill-project-value="${project}"
           data-live-pill-url-value="${URL}"
           data-live-pill-events-value='${JSON.stringify(events)}'
           data-live-pill-stopped-value="Live updates stopped.">
            <turbo-frame id="pill" data-live-pill-target="frame"></turbo-frame>
            <p data-live-pill-target="status"></p>
        </a>`;
    link = document.querySelector('a');
    frame = document.querySelector('turbo-frame');
    frame.reload = vi.fn();
}

beforeEach(async () => {
    live.subscriptions.length = 0;
    application = Application.start();
    application.register('live-pill', LivePillController);
});

afterEach(() => {
    vi.useRealTimers();
    application.stop();
    document.body.innerHTML = '';
});

it('subscribes to the events it is given', async () => {
    mount(['inbox.open_count_changed']);
    await Promise.resolve();

    expect(live.subscriptions).toHaveLength(1);
    expect(live.subscriptions[0].types).toEqual(['inbox.open_count_changed']);
});

it('loads the frame from the url on the first open, then reloads it', async () => {
    mount();
    await Promise.resolve();
    const { options } = live.subscriptions[0];

    options.onOpen();
    expect(frame.src).toBe(URL);
    expect(link.hasAttribute('data-live-pill-connected')).toBe(true);

    // A real turbo-frame reflects src to its attribute, and jsdom does not.
    frame.setAttribute('src', URL);
    options.onOpen();
    expect(frame.reload).toHaveBeenCalledTimes(1);

    options.onError();
    expect(link.hasAttribute('data-live-pill-connected')).toBe(false);
});

it('reloads on a change with no project and on one of its project, and ignores another project', async () => {
    mount();
    await Promise.resolve();
    frame.setAttribute('src', URL);
    const { handler } = live.subscriptions[0];

    handler({ type: 'worker_run.changed' });
    handler({ type: 'inbox.open_count_changed', projectId: PROJECT });
    expect(frame.reload).toHaveBeenCalledTimes(2);

    handler({ type: 'inbox.open_count_changed', projectId: 'another' });
    expect(frame.reload).toHaveBeenCalledTimes(2);
});

it('reloads on a change of any project when it names none', async () => {
    mount(['inbox.open_count_changed'], '');
    await Promise.resolve();
    frame.setAttribute('src', URL);
    const { handler } = live.subscriptions[0];

    handler({ type: 'inbox.open_count_changed', projectId: 'another' });
    handler({ type: 'inbox.open_count_changed', projectId: PROJECT });
    expect(frame.reload).toHaveBeenCalledTimes(2);
});

it('stops listening when it disconnects', async () => {
    mount();
    await Promise.resolve();

    document.body.innerHTML = '';
    await Promise.resolve();

    expect(live.subscriptions[0].removed).toBe(true);
});

function fail(statusCode) {
    const event = new CustomEvent('turbo:frame-missing', {
        bubbles: true,
        cancelable: true,
        detail: { response: { statusCode } },
    });
    frame.dispatchEvent(event);

    return event;
}

async function mountLoaded() {
    vi.useFakeTimers();
    mount();
    await Promise.resolve();
    frame.setAttribute('src', URL);
    live.subscriptions[0].handler({ type: 'worker_run.changed' });
    expect(frame.reload).toHaveBeenCalledTimes(1);
}

it('retries a failed reload with a growing wait, then says live updates stopped', async () => {
    await mountLoaded();
    const status = document.querySelector('p');

    for (const [index, wait] of RETRY_MILLISECONDS.entries()) {
        expect(fail(500).defaultPrevented).toBe(true);
        vi.advanceTimersByTime(wait - 1);
        expect(frame.reload).toHaveBeenCalledTimes(index + 1);
        vi.advanceTimersByTime(1);
        expect(frame.reload).toHaveBeenCalledTimes(index + 2);
        expect(link.hasAttribute('data-live-pill-stopped')).toBe(false);
    }

    fail(503);
    vi.advanceTimersByTime(60000);
    expect(frame.reload).toHaveBeenCalledTimes(RETRY_MILLISECONDS.length + 1);
    expect(link.hasAttribute('data-live-pill-stopped')).toBe(true);
    expect(status.textContent).toBe('Live updates stopped.');

    frame.dispatchEvent(new CustomEvent('turbo:frame-load', { bubbles: true }));
    expect(link.hasAttribute('data-live-pill-stopped')).toBe(false);
    expect(status.textContent).toBe('');
});

it('retries a request that never got an answer', async () => {
    await mountLoaded();

    frame.dispatchEvent(
        new CustomEvent('turbo:fetch-request-error', { bubbles: true }),
    );
    vi.advanceTimersByTime(RETRY_MILLISECONDS[0]);

    expect(frame.reload).toHaveBeenCalledTimes(2);
});

it('says live updates stopped at once when a retry cannot help', async () => {
    await mountLoaded();

    fail(404);
    vi.advanceTimersByTime(60000);

    expect(frame.reload).toHaveBeenCalledTimes(1);
    expect(link.hasAttribute('data-live-pill-stopped')).toBe(true);
});

it('starts the retry count again on a new change', async () => {
    await mountLoaded();

    fail(500);
    vi.advanceTimersByTime(RETRY_MILLISECONDS[0]);
    fail(500);
    live.subscriptions[0].handler({ type: 'worker_run.changed' });
    expect(frame.reload).toHaveBeenCalledTimes(3);
    vi.advanceTimersByTime(60000);
    expect(frame.reload).toHaveBeenCalledTimes(3);

    fail(500);
    vi.advanceTimersByTime(RETRY_MILLISECONDS[0]);
    expect(frame.reload).toHaveBeenCalledTimes(4);
});

it('stops a pending retry when it disconnects', async () => {
    await mountLoaded();

    fail(500);
    document.body.innerHTML = '';
    await Promise.resolve();
    vi.advanceTimersByTime(60000);

    expect(frame.reload).toHaveBeenCalledTimes(1);
});
