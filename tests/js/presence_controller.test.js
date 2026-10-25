/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const MARKUP = `
<form id="presence" hidden method="post" action="/account/presence"
    data-controller="presence"
    data-presence-open-tags-url-value="/account/notifications/open-tags">
    <input type="hidden" name="_csrf_token" value="csrf-token">
</form>`;

const flush = async () => {
    for (let round = 0; round < 8; round += 1) {
        await vi.advanceTimersByTimeAsync(0);
    }
};

let application;
let fetchMock;

function stubPage({ visible = true, focused = true } = {}) {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        get: () => (visible ? 'visible' : 'hidden'),
    });
    document.hasFocus = () => focused;
}

function stubServiceWorker(registration) {
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { getRegistration: vi.fn().mockResolvedValue(registration) },
    });
}

async function mount() {
    document.body.innerHTML = MARKUP;
    const { default: PresenceController } =
        await import('../../assets/controllers/presence_controller.js');
    application = Application.start();
    application.register('presence', PresenceController);
    await flush();
}

const posts = () =>
    fetchMock.mock.calls.filter(([, options]) => options?.method === 'POST');
const reads = () =>
    fetchMock.mock.calls.filter(([, options]) => options?.method !== 'POST');

beforeEach(() => {
    vi.resetModules();
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-25T12:00:00Z'));
    fetchMock = vi.fn((url) =>
        Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ tags: ['ask-open'] }),
            url,
        }),
    );
    vi.stubGlobal('fetch', fetchMock);
    stubPage();
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: undefined,
    });
});

afterEach(() => {
    application?.stop();
    application = undefined;
    document.body.innerHTML = '';
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

it('posts the presence on connect with the CSRF token', async () => {
    await mount();

    expect(posts()).toHaveLength(1);
    const [url, options] = posts()[0];
    expect(url).toContain('/account/presence');
    expect(options.credentials).toBe('same-origin');
    expect(options.body.get('_csrf_token')).toMatch(/.+/);
});

it('posts again every 30 seconds while the user keeps working', async () => {
    await mount();

    for (let tick = 0; tick < 2; tick += 1) {
        document.dispatchEvent(new Event('keydown', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(30000);
    }

    expect(posts()).toHaveLength(3);
});

it('posts nothing while the page is hidden', async () => {
    stubPage({ visible: false });

    await mount();
    await vi.advanceTimersByTimeAsync(60000);

    expect(posts()).toHaveLength(0);
});

it('posts nothing while the window has no focus', async () => {
    stubPage({ focused: false });

    await mount();
    await vi.advanceTimersByTimeAsync(60000);

    expect(posts()).toHaveLength(0);
});

it('stops posting once the user has been idle for more than 60 seconds', async () => {
    await mount();
    expect(posts()).toHaveLength(1);

    await vi.advanceTimersByTimeAsync(30000);
    expect(posts()).toHaveLength(2);
    await vi.advanceTimersByTimeAsync(30000);
    expect(posts()).toHaveLength(3);
    await vi.advanceTimersByTimeAsync(30000);

    expect(posts()).toHaveLength(3);
});

it('counts a pointer move and a scroll as activity', async () => {
    await mount();
    await vi.advanceTimersByTimeAsync(55000);
    document.dispatchEvent(new Event('pointermove', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(30000);
    document.dispatchEvent(new Event('scroll'));
    await vi.advanceTimersByTimeAsync(30000);

    expect(posts()).toHaveLength(4);
});

it('stops its timer when the element goes away', async () => {
    await mount();
    document.getElementById('presence').remove();
    await flush();
    const before = posts().length;

    document.dispatchEvent(new Event('keydown', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(90000);

    expect(posts()).toHaveLength(before);
});

it('closes the notifications whose tag is not open, once per load', async () => {
    const stale = { tag: 'ask-gone', close: vi.fn() };
    const open = { tag: 'ask-open', close: vi.fn() };
    stubServiceWorker({
        getNotifications: vi.fn().mockResolvedValue([stale, open]),
    });

    await mount();

    expect(reads()).toHaveLength(1);
    expect(reads()[0][0]).toBe('/account/notifications/open-tags');
    expect(stale.close).toHaveBeenCalledTimes(1);
    expect(open.close).not.toHaveBeenCalled();

    document.getElementById('presence').remove();
    await flush();
    document.body.innerHTML = MARKUP;
    await flush();

    expect(reads()).toHaveLength(1);
});

it('reads no open tags when the browser has no service worker registration', async () => {
    stubServiceWorker(undefined);

    await mount();

    expect(reads()).toHaveLength(0);
});

it('reads no open tags when the browser has no service worker at all', async () => {
    await mount();

    expect(reads()).toHaveLength(0);
});

it('survives a failing open tags read', async () => {
    const stale = { tag: 'ask-gone', close: vi.fn() };
    stubServiceWorker({
        getNotifications: vi.fn().mockResolvedValue([stale]),
    });
    fetchMock.mockImplementation((url, options) =>
        options?.method === 'POST'
            ? Promise.resolve({ ok: true })
            : Promise.resolve({ ok: false }),
    );

    await mount();

    expect(stale.close).not.toHaveBeenCalled();
});
