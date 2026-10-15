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

const { default: LivePillController } =
    await import('../../assets/controllers/live_pill_controller.js');

const PROJECT = '0199aaaa-0000-7000-8000-000000000001';
const URL = '/projects/' + PROJECT + '/activity/open-count';

let application;
let frame;
let link;

function mount(events = ['worker_run.changed']) {
    document.body.innerHTML = `
        <a data-controller="live-pill"
           data-live-pill-project-value="${PROJECT}"
           data-live-pill-url-value="${URL}"
           data-live-pill-events-value='${JSON.stringify(events)}'>
            <turbo-frame id="pill" data-live-pill-target="frame"></turbo-frame>
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

it('stops listening when it disconnects', async () => {
    mount();
    await Promise.resolve();

    document.body.innerHTML = '';
    await Promise.resolve();

    expect(live.subscriptions[0].removed).toBe(true);
});
