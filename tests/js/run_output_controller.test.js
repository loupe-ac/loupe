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

const {
    default: RunOutputController,
    DEBOUNCE_MILLISECONDS,
    RETRY_MILLISECONDS,
} = await import('../../assets/controllers/run_output_controller.js');

const RUN = '0199aaaa-0000-7000-8000-000000000001';

let application;
let answers;

const block = (text = 'one\n', length = 4) =>
    `<div data-controller="run-output" data-run-output-url-value="/projects/p/worker-runs/${RUN}/output" data-run-output-run-id-value="${RUN}" data-run-output-length-value="${length}"><pre><code>${text}</code><span class="cursor"></span></pre></div>`;

const answer = (text, length) =>
    answers.push({
        ok: true,
        json: async () => ({ text, length, cut: false }),
    });

const mount = async (html = block()) => {
    document.body.innerHTML = html;
    await vi.advanceTimersByTimeAsync(0);
};

const output = (change = {}) =>
    live.subscriptions[0].handler({
        type: 'worker_run.output',
        runId: RUN,
        length: 10,
        local: false,
        own: false,
        ...change,
    });

const settle = () => vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS);

const codeText = () => document.querySelector('code').textContent;

beforeEach(() => {
    vi.useFakeTimers();
    live.subscriptions = [];
    answers = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => answers.shift() ?? { ok: false, status: 404 }),
    );
    application = Application.start();
    application.register('run-output', RunOutputController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('listens for output messages and stops on disconnect', async () => {
    await mount();
    expect(live.subscriptions[0].types).toBe('worker_run.output');
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(live.subscriptions[0].removed).toBe(true);
});

it('ignores the output of another run', async () => {
    answer('', 4);
    await mount();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    output({ runId: 'another' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('reads once on connect, for the output that came before the subscription', async () => {
    answer('two\n', 8);
    answer('', 8);
    await mount();
    await settle();
    expect(
        new URL(fetch.mock.calls[0][0], 'http://x').searchParams.get('from'),
    ).toBe('4');
    expect(codeText()).toBe('one\ntwo\n');
});

it('reads again after a failed read, with a longer wait each time', async () => {
    answers.push({ ok: false, status: 500 });
    answers.push({ ok: false, status: 502 });
    answer('two\n', 8);
    answer('', 8);
    await mount();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS);
    expect(fetch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS);
    expect(codeText()).toBe('one\ntwo\n');
});

it('stops retrying on disconnect', async () => {
    await mount();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(RETRY_MILLISECONDS * 4);
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('reads from the stored length and appends the text after the shown text', async () => {
    await mount();
    answer('two\n', 8);
    answer('', 8);
    output();
    await settle();
    expect(
        new URL(fetch.mock.calls[0][0], 'http://x').searchParams.get('from'),
    ).toBe('4');
    expect(codeText()).toBe('one\ntwo\n');
    expect(
        document.querySelector('[data-controller]').dataset
            .runOutputLengthValue,
    ).toBe('8');
    expect(document.querySelector('.cursor')).not.toBeNull();
});

it('reads once for a burst of messages and again until the server has no more text', async () => {
    await mount();
    answer('two\n', 8);
    answer('three\n', 14);
    answer('', 14);
    output();
    await vi.advanceTimersByTimeAsync(DEBOUNCE_MILLISECONDS / 2);
    output();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(codeText()).toBe('one\ntwo\nthree\n');
});

it('builds the address with the read offset only, and never writes markup', async () => {
    await mount();
    answer('<b>x</b>', 12);
    answer('', 12);
    output();
    await settle();
    expect(document.querySelector('code b')).toBeNull();
    expect(codeText()).toBe('one\n<b>x</b>');
});

it('reads again after a reconnect', async () => {
    await mount();
    answer('two\n', 8);
    answer('', 8);
    live.subscriptions[0].options.onReconnect();
    await settle();
    expect(codeText()).toBe('one\ntwo\n');
});

it('keeps the shown text when a read fails', async () => {
    await mount();
    answers.push({ ok: false, status: 500 });
    output();
    await settle();
    expect(codeText()).toBe('one\n');
});

it('drops an answer for an offset that a page morph moved on', async () => {
    await mount();
    let release;
    fetch.mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                release = () =>
                    resolve({
                        ok: true,
                        json: async () => ({
                            text: 'stale\n',
                            length: 10,
                            cut: false,
                        }),
                    });
            }),
    );
    output();
    await settle();
    document.querySelector('[data-controller]').dataset.runOutputLengthValue =
        '20';
    document.querySelector('code').textContent = 'fresh tail\n';
    release();
    answer('', 20);
    await vi.advanceTimersByTimeAsync(0);
    expect(codeText()).toBe('fresh tail\n');
    expect(
        new URL(fetch.mock.calls[1][0], 'http://x').searchParams.get('from'),
    ).toBe('20');
});

it('follows the end of the log unless the reader scrolled up', async () => {
    await mount();
    const body = document.querySelector('pre');
    const height = () => 100 + codeText().length * 10;
    Object.defineProperty(body, 'scrollHeight', {
        get: height,
        configurable: true,
    });
    Object.defineProperty(body, 'clientHeight', {
        value: 100,
        configurable: true,
    });

    body.scrollTop = height() - 100;
    answer('two\n', 8);
    answer('', 8);
    output();
    await settle();
    expect(body.scrollTop).toBe(180);

    body.scrollTop = 50;
    answer('three\n', 14);
    answer('', 14);
    output();
    await settle();
    expect(body.scrollTop).toBe(50);
});
