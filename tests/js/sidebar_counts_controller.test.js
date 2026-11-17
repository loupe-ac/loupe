/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));
const turbo = vi.hoisted(() => ({ streams: [], renders: [] }));

vi.mock('../../assets/lib/live.js', () => ({
    on: (types, handler, options) => {
        const subscription = { types, handler, options, removed: false };
        live.subscriptions.push(subscription);

        return () => {
            subscription.removed = true;
        };
    },
}));

// Turbo renders a stream after the call returns, through the render function of its before-render event.
vi.mock('@hotwired/turbo', () => ({
    renderStreamMessage: (html) => {
        turbo.streams.push(html);
        const count = html.match(/<turbo-stream[\s>]/g).length;
        for (let index = 0; index < count; index += 1) {
            const event = new CustomEvent('turbo:before-stream-render', {
                detail: { render: async () => {} },
            });
            window.dispatchEvent(event);
            turbo.renders.push(event.detail.render);
        }
    },
}));

const { default: SidebarCountsController, APPLIED_EVENT } =
    await import('../../assets/controllers/sidebar_counts_controller.js');
const { QUIET_MILLISECONDS, RETRY_MILLISECONDS, reset } =
    await import('../../assets/lib/live_fetch.js');

const PROJECT = '0199aaaa-0000-7000-8000-000000000001';
const URL = `/projects/${PROJECT}/sidebar-counts`;
const STREAM =
    '<turbo-stream action="replace" target="a"><template></template></turbo-stream><turbo-stream action="replace" target="b"><template></template></turbo-stream>';
const OWN_SIGNAL = { type: 'sidebar.counts_changed', projectId: PROJECT };

let application;
let answers;

function answer(status, body = '') {
    answers.push({
        status,
        ok: status >= 200 && status < 300,
        headers: { get: () => null },
        text: async () => body,
    });
}

async function mount() {
    document.body.innerHTML = `
        <div data-controller="sidebar-counts"
             data-sidebar-counts-project-value="${PROJECT}"
             data-sidebar-counts-url-value="${URL}"
             data-sidebar-counts-since-value="v1.v2"
             data-sidebar-counts-stopped-value="Live updates stopped.">
            <p data-sidebar-counts-target="status"></p>
        </div>`;
    await vi.advanceTimersByTimeAsync(0);
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);

    return live.subscriptions[0];
}

async function settle() {
    await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
}

function requestedUrl(index) {
    return new globalThis.URL(fetch.mock.calls[index][0], 'http://localhost');
}

beforeEach(() => {
    vi.useFakeTimers();
    reset();
    live.subscriptions = [];
    turbo.streams = [];
    turbo.renders = [];
    answers = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => answers.shift() ?? { status: 404, ok: false }),
    );
    application = Application.start();
    application.register('sidebar-counts', SidebarCountsController);
});

afterEach(() => {
    application.stop();
    document.body.replaceChildren();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('listens to both signals', async () => {
    await mount();

    expect(subscription().types).toEqual([
        'sidebar.counts_changed',
        'inbox.sidebar_count_changed',
    ]);
});

it('sends the rendered versions on the first open and applies nothing on 204', async () => {
    await mount();
    const applied = vi.fn();
    window.addEventListener(APPLIED_EVENT, applied);
    answer(204);

    subscription().options.onOpen();
    await settle();

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(requestedUrl(0).searchParams.get('since')).toBe('v1.v2');
    expect(fetch.mock.calls[0][1].headers.Accept).toBe(
        'text/vnd.turbo-stream.html',
    );
    expect(turbo.streams).toEqual([]);
    expect(applied).not.toHaveBeenCalled();
    window.removeEventListener(APPLIED_EVENT, applied);
});

it('checks only on the first open, not on a later one', async () => {
    await mount();
    answer(204);

    subscription().options.onOpen();
    await settle();
    subscription().options.onOpen();
    await settle();

    expect(fetch).toHaveBeenCalledTimes(1);
});

it('applies a full answer and announces it on window after every stream rendered', async () => {
    await mount();
    answer(200, STREAM);
    const applied = vi.fn();
    window.addEventListener(APPLIED_EVENT, applied);

    subscription().options.onOpen();
    await settle();
    expect(turbo.streams).toEqual([STREAM]);
    expect(applied).not.toHaveBeenCalled();

    await turbo.renders[0]();
    expect(applied).not.toHaveBeenCalled();
    await turbo.renders[1]();
    expect(applied).toHaveBeenCalledTimes(1);
    window.removeEventListener(APPLIED_EVENT, applied);
});

it('waits for a quiet moment and sends no since after a signal', async () => {
    await mount();
    answer(200, STREAM);

    subscription().handler(OWN_SIGNAL);
    subscription().handler(OWN_SIGNAL);
    await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS - 1);
    expect(fetch).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(requestedUrl(0).searchParams.has('since')).toBe(false);
});

it('ignores the worker signal of another project and follows the inbox signal of any project', async () => {
    await mount();
    answer(204);

    subscription().handler({
        type: 'sidebar.counts_changed',
        projectId: 'another',
    });
    await settle();
    expect(fetch).not.toHaveBeenCalled();

    subscription().handler({
        type: 'inbox.sidebar_count_changed',
        projectId: 'another',
    });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('reacts to a change this page made itself', async () => {
    await mount();
    answer(204);

    subscription().handler({ ...OWN_SIGNAL, own: true });
    await settle();

    expect(fetch).toHaveBeenCalledTimes(1);
});

it('catches up after a reconnect with no since', async () => {
    await mount();
    answer(204);
    subscription().options.onOpen();
    await settle();

    answer(204);
    subscription().options.onReconnect();
    await settle();

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(requestedUrl(1).searchParams.has('since')).toBe(false);
});

it('retries, then stops with a status line, and clears the stop on an answer', async () => {
    await mount();
    const status = document.querySelector('p');
    const element = document.querySelector('[data-controller]');

    answer(503);
    subscription().handler(OWN_SIGNAL);
    await settle();
    for (const wait of RETRY_MILLISECONDS) {
        answer(503);
        await vi.advanceTimersByTimeAsync(wait);
    }

    expect(fetch).toHaveBeenCalledTimes(RETRY_MILLISECONDS.length + 1);
    expect(element.hasAttribute('data-sidebar-counts-stopped')).toBe(true);
    expect(status.textContent).toBe('Live updates stopped.');
});

it('stops listening when it disconnects', async () => {
    await mount();

    document.body.innerHTML = '';
    await vi.advanceTimersByTimeAsync(0);

    expect(subscription().removed).toBe(true);
});
