/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const live = vi.hoisted(() => ({ subscriptions: [] }));
const turbo = vi.hoisted(() => ({ streams: [], morphs: [] }));

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
    morphTurboFrameElements: (current, fresh) => {
        turbo.morphs.push({ current, fresh });
        turbo.onMorph?.(current);
    },
}));

const { default: LiveRegionController } =
    await import('../../assets/controllers/live_region_controller.js');
const { QUIET_MILLISECONDS, RETRY_MILLISECONDS, handleLostAccess, reset } =
    await import('../../assets/lib/live_fetch.js');

let application;
let answers;

function answer(status, body = '', headers = {}) {
    answers.push({
        status,
        ok: status >= 200 && status < 300,
        headers: { get: (name) => headers[name] ?? null },
        text: async () => body,
    });
}

const BANDS =
    '<script type="text/html" data-live-band-template="stopped"><div class="lp-flash" data-testid="stopped">Stopped</div></script><script type="text/html" data-live-band-template="signed-out"><div class="lp-flash">Signed out</div></script>';

const TYPES = 'data-live-region-types-value=\'["artifact.changed"]\'';
const STREAM = `${TYPES} data-live-region-url-value="/x" data-live-region-mode-value="stream"`;

async function mount(attributes, inner = '', tag = 'div') {
    document.body.innerHTML = `${BANDS}<main id="main-content"><${tag} id="region" data-controller="live-region" ${attributes}>${inner}</${tag}></main>`;
    await vi.advanceTimersByTimeAsync(0);
}

function subscription() {
    expect(live.subscriptions).toHaveLength(1);

    return live.subscriptions[0];
}

function signal(change = {}) {
    subscription().handler({ type: 'artifact.changed', own: false, ...change });
}

async function settle() {
    await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
}

function bandElement() {
    return document.querySelector('[data-live-region-target="band"]');
}

beforeEach(() => {
    vi.useFakeTimers();
    reset();
    live.subscriptions = [];
    turbo.streams = [];
    turbo.morphs = [];
    turbo.onMorph = undefined;
    answers = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => answers.shift() ?? { status: 404, ok: false }),
    );
    application = Application.start();
    application.register('live-region', LiveRegionController);
});

afterEach(async () => {
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    application.stop();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('listens for its types and stops on disconnect', async () => {
    await mount(
        `data-live-region-types-value='["artifact.changed","board.card_changed"]' data-live-region-url-value="/x" data-live-region-mode-value="stream"`,
    );
    expect(subscription().types).toEqual([
        'artifact.changed',
        'board.card_changed',
    ]);
    document.body.replaceChildren();
    await vi.advanceTimersByTimeAsync(0);
    expect(subscription().removed).toBe(true);
});

it('resumes after the same element is removed and reinserted', async () => {
    await mount(STREAM);
    const region = document.getElementById('region');
    const parent = region.parentElement;
    region.remove();
    await vi.advanceTimersByTimeAsync(0);
    expect(live.subscriptions[0].removed).toBe(true);
    parent.appendChild(region);
    await vi.advanceTimersByTimeAsync(0);
    expect(live.subscriptions).toHaveLength(2);
    answer(200, '<turbo-stream action="update"></turbo-stream>');
    live.subscriptions[1].handler({ type: 'artifact.changed', own: false });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('skips a change that this page made', async () => {
    await mount(STREAM);
    signal({ own: true });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
});

it('skips a change whose payload does not match', async () => {
    await mount(`${STREAM} data-live-region-match-value='{"artifactId":"a1"}'`);
    signal({ artifactId: 'a2' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    signal({ artifactId: 'a1' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('a list of match values takes any of them, and null takes a signal without the key', async () => {
    await mount(
        `${STREAM} data-live-region-match-value='{"area":["projects",null]}'`,
    );
    signal({ area: 'apps' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    signal({ area: 'projects' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('null in a list of match values takes a signal without the key', async () => {
    await mount(
        `${STREAM} data-live-region-match-value='{"area":["projects",null]}'`,
    );
    signal({});
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('matchType filters the types it lists and always passes the others', async () => {
    await mount(
        `data-live-region-types-value='["worker_run.changed","analytics.changed"]' data-live-region-url-value="/x" data-live-region-mode-value="stream" data-live-region-match-type-value='{"analytics.changed":{"area":"reports"}}'`,
    );
    answer(200, '<turbo-stream></turbo-stream>');
    answer(200, '<turbo-stream></turbo-stream>');
    signal({ type: 'analytics.changed', area: 'experiments' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    signal({ type: 'worker_run.changed' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    signal({ type: 'analytics.changed', area: 'reports' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(2);
});

it('matchType and match both have to pass', async () => {
    await mount(
        `${STREAM} data-live-region-match-value='{"artifactId":"a1"}' data-live-region-match-type-value='{"artifact.changed":{"kind":"page"}}'`,
    );
    signal({ artifactId: 'a1', kind: 'file' });
    signal({ artifactId: 'a2', kind: 'page' });
    await settle();
    expect(fetch).not.toHaveBeenCalled();
    signal({ artifactId: 'a1', kind: 'page' });
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('quiet and max set the wait of the fetcher', async () => {
    await mount(`${STREAM} data-live-region-quiet-value="1000"`);
    signal();
    await vi.advanceTimersByTimeAsync(QUIET_MILLISECONDS);
    expect(fetch).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1000 - QUIET_MILLISECONDS);
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('max caps the wait of a steady stream of signals', async () => {
    await mount(
        `${STREAM} data-live-region-quiet-value="1000" data-live-region-max-value="1500"`,
    );
    for (let elapsed = 0; elapsed < 1500; elapsed += 500) {
        signal();
        await vi.advanceTimersByTimeAsync(500);
    }
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('stream mode reads a Turbo Stream and renders it', async () => {
    await mount(
        `${TYPES} data-live-region-url-value="/band" data-live-region-mode-value="stream"`,
    );
    answer(200, '<turbo-stream action="update"></turbo-stream>');
    signal();
    signal();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe(`${window.location.origin}/band`);
    expect(options.headers).toMatchObject({
        Accept: 'text/vnd.turbo-stream.html',
        'X-Loupe-Live-Refresh': '1',
    });
    expect(turbo.streams).toEqual([
        '<turbo-stream action="update"></turbo-stream>',
    ]);
});

it('frame mode sends the frame header and morphs the fresh frame in', async () => {
    await mount(
        `${TYPES} data-live-region-url-value="/frame"`,
        '<p>old</p>',
        'turbo-frame',
    );
    answer(
        200,
        '<html><body><turbo-frame id="region"><p>new</p></turbo-frame></body></html>',
    );
    signal();
    await settle();
    expect(fetch.mock.calls[0][1].headers['Turbo-Frame']).toBe('region');
    expect(turbo.morphs).toHaveLength(1);
    expect(turbo.morphs[0].current.id).toBe('region');
    expect(turbo.morphs[0].fresh.textContent).toBe('new');
});

it('frame mode keeps an open disclosure open through the morph', async () => {
    await mount(
        `${TYPES} data-live-region-url-value="/frame"`,
        '<details open><summary>Table</summary></details>',
        'turbo-frame',
    );
    const attributeEvent = (mutationType) =>
        new CustomEvent('turbo:before-morph-attribute', {
            bubbles: true,
            cancelable: true,
            detail: { attributeName: 'open', mutationType },
        });
    const events = {
        removed: attributeEvent('remove'),
        updated: attributeEvent('update'),
    };
    turbo.onMorph = (current) => {
        const details = current.querySelector('details');
        details.dispatchEvent(events.removed);
        details.dispatchEvent(events.updated);
    };
    answer(
        200,
        '<html><body><turbo-frame id="region"><details><summary>Table</summary></details></turbo-frame></body></html>',
    );
    signal();
    await settle();
    expect(turbo.morphs).toHaveLength(1);
    expect(events.removed.defaultPrevented).toBe(true);
    expect(events.updated.defaultPrevented).toBe(false);
});

it('reads again after a reconnect', async () => {
    await mount(STREAM);
    answer(200, '<turbo-stream></turbo-stream>');
    subscription().options.onReconnect();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
});

it('rows mode sends no cursor when the region has none', async () => {
    await mount(
        `data-live-region-types-value='["activity.changed"]' data-live-region-url-value="/rows" data-live-region-mode-value="rows"`,
        '<li data-live-row="r1"></li>',
    );
    answer(200, '<turbo-stream></turbo-stream>');
    signal({ type: 'activity.changed' });
    await settle();
    const url = new URL(fetch.mock.calls[0][0]);
    expect(url.searchParams.get('ids')).toBe('r1');
    expect(url.searchParams.has('since')).toBe(false);
    expect(url.searchParams.has('sinceId')).toBe(false);
    expect(url.searchParams.has('total')).toBe(false);
});

it('rows mode names the shown rows and the cursor', async () => {
    await mount(
        `data-live-region-types-value='["activity.changed"]' data-live-region-url-value="/rows" data-live-region-mode-value="rows" data-live-since="2026-01-01T00:00:00Z" data-live-since-id="abc" data-live-total="42"`,
        '<li data-live-row="r1"></li><li data-live-row="r2"></li>',
    );
    answer(200, '<turbo-stream action="remove" target="r2"></turbo-stream>');
    signal({ type: 'activity.changed' });
    await settle();
    const url = new URL(fetch.mock.calls[0][0]);
    expect(url.searchParams.get('ids')).toBe('r1,r2');
    expect(url.searchParams.get('since')).toBe('2026-01-01T00:00:00Z');
    expect(url.searchParams.get('sinceId')).toBe('abc');
    expect(url.searchParams.get('total')).toBe('42');
    expect(fetch.mock.calls[0][1].headers.Accept).toBe(
        'text/vnd.turbo-stream.html',
    );
    expect(turbo.streams).toHaveLength(1);
});

it('rows mode posts the ids the page knew at load', async () => {
    await mount(
        `data-live-region-types-value='["activity.changed"]' data-live-region-url-value="/rows" data-live-region-mode-value="rows" data-live-since="1" data-live-known='["k1","k2"]'`,
        '<li data-live-row="r1"></li>',
    );
    answer(200, '<turbo-stream></turbo-stream>');
    signal({ type: 'activity.changed' });
    await settle();
    expect(fetch.mock.calls[0][1].method).toBe('POST');
    expect(fetch.mock.calls[0][1].body.get('known')).toBe('k1,k2');
    expect(new URL(fetch.mock.calls[0][0]).searchParams.get('ids')).toBe('r1');
});

it('band mode unhides its band at once, even while a field holds typed text', async () => {
    await mount(
        `data-live-region-types-value='["project.updated"]' data-live-region-mode-value="band"`,
        '<input value="a"><div hidden data-live-region-target="band">Changed</div>',
    );
    document.querySelector('input').value = 'typed';
    signal({ type: 'project.updated' });
    expect(bandElement().hidden).toBe(false);
    expect(fetch).not.toHaveBeenCalled();
});

it('band mode checks the fingerprint after a reconnect', async () => {
    await mount(
        `data-live-region-types-value='["project.updated"]' data-live-region-url-value="/check" data-live-region-mode-value="band" data-live-fingerprint="v1"`,
        '<div hidden data-live-region-target="band">Changed</div>',
    );
    answer(200, 'v1');
    subscription().options.onReconnect();
    await settle();
    expect(bandElement().hidden).toBe(true);
    answer(200, 'v2');
    subscription().options.onReconnect();
    await settle();
    expect(bandElement().hidden).toBe(false);
});

it('band mode with no check route shows the band after a reconnect', async () => {
    await mount(
        `data-live-region-types-value='["project.updated"]' data-live-region-mode-value="band"`,
        '<div hidden data-live-region-target="band">Changed</div>',
    );
    subscription().options.onReconnect();
    expect(bandElement().hidden).toBe(false);
});

it('shows the stopped band after the last retry and sets the stopped value', async () => {
    await mount(STREAM);
    for (let attempt = 0; attempt <= RETRY_MILLISECONDS.length; attempt += 1) {
        answer(503);
    }
    signal();
    await settle();
    for (const wait of RETRY_MILLISECONDS) {
        await vi.advanceTimersByTimeAsync(wait);
    }
    expect(fetch).toHaveBeenCalledTimes(4);
    expect(document.querySelector('[data-testid="stopped"]')).not.toBeNull();
    expect(
        document.querySelector('#region').dataset.liveRegionStoppedValue,
    ).toBe('true');
});

it('does not start when it is stopped', async () => {
    await mount(`${TYPES} data-live-region-stopped-value="true"`);
    expect(live.subscriptions).toHaveLength(0);
});

it('stops after a lost-access answer and shows one band', async () => {
    await mount(STREAM);
    answer(401, '', { 'X-Loupe-Live-Lost': 'signed-out' });
    signal();
    await settle();
    expect(document.querySelectorAll('[data-live-lost-band]')).toHaveLength(1);
    expect(turbo.streams).toHaveLength(0);
    signal();
    await settle();
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(subscription().removed).toBe(true);
});

it('band mode shows no band after the page lost access', async () => {
    await mount(
        `${TYPES} data-live-region-mode-value="band"`,
        '<div data-live-region-target="band" hidden></div>',
    );
    handleLostAccess({
        status: 401,
        headers: { get: () => 'signed-out' },
    });
    expect(subscription().removed).toBe(true);
    signal();
    subscription().options.onReconnect();
    expect(bandElement().hidden).toBe(true);
});
