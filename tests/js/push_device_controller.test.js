/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import PushDeviceController from '../../assets/controllers/push_device_controller.js';

vi.mock('@hotwired/turbo', () => ({ visit: vi.fn() }));
const { visit } = await import('@hotwired/turbo');

const PUBLIC_KEY = 'BEl62iUYgUivxIkv69yViEuiBIa-ib37J8xQmCdJmH0';
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
const flush = async () => {
    for (let round = 0; round < 8; round += 1) {
        await settle();
    }
};

const MARKUP = (deviceKnown = false) => `
<section id="root" data-controller="push-device"
    data-push-device-public-key-value="${PUBLIC_KEY}"
    data-push-device-create-url-value="/account/notifications/devices"
    data-push-device-device-known-value="${deviceKnown}"
    data-push-device-denied-message-value="Notifications are off for Loupe."
    data-push-device-failed-message-value="Push did not turn on."
    data-push-device-throttled-message-value="Wait a little."
    data-push-device-service-worker-url-value="/sw.js">
    <form data-push-device-target="form"><input type="hidden" name="token" value="submit"></form>
    <div data-push-device-target="panel" data-panel="not-supported" hidden></div>
    <div data-push-device-target="panel" data-panel="ios-tab" hidden></div>
    <div data-push-device-target="panel" data-panel="blocked" hidden>
        <ol data-push-device-target="platformSteps" data-platform="chromium" hidden></ol>
        <ol data-push-device-target="platformSteps" data-platform="safari" hidden></ol>
        <ol data-push-device-target="platformSteps" data-platform="other" hidden></ol>
        <button id="retry" data-action="push-device#refresh">Retry</button>
    </div>
    <div data-push-device-target="panel" data-panel="off" hidden>
        <button id="turn-on" data-push-device-target="button" data-action="push-device#turnOn">Turn on</button>
    </div>
    <div data-push-device-target="panel" data-panel="on" hidden></div>
    <p id="error" data-push-device-target="error" role="alert" hidden></p>
</section>`;

let application;

const visiblePanels = () =>
    Array.from(document.querySelectorAll('[data-panel]'))
        .filter((panel) => !panel.hidden)
        .map((panel) => panel.dataset.panel);

function stubBrowser({
    permission = 'default',
    requestResult = 'granted',
    subscription = null,
    created = {
        endpoint: 'https://push.example/abc',
        getKey: (name) =>
            new Uint8Array(name === 'p256dh' ? [1, 2, 3] : [4, 5, 6]).buffer,
    },
    userAgent = 'Mozilla/5.0 (Macintosh) Chrome/130.0 Safari/537.36',
} = {}) {
    const subscribe = vi.fn().mockResolvedValue(created);
    const pushManager = {
        getSubscription: vi.fn().mockResolvedValue(subscription),
        subscribe,
    };
    const registration = { pushManager };
    const register = vi.fn().mockResolvedValue(registration);
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            register,
            ready: Promise.resolve(registration),
            getRegistration: vi.fn().mockResolvedValue(registration),
        },
    });
    Object.defineProperty(navigator, 'userAgent', {
        configurable: true,
        value: userAgent,
    });
    Object.defineProperty(navigator, 'maxTouchPoints', {
        configurable: true,
        value: 0,
    });
    vi.stubGlobal(
        'PushManager',
        class {
            static supportedContentEncodings = ['aes128gcm'];
        },
    );
    const requestPermission = vi.fn().mockResolvedValue(requestResult);
    vi.stubGlobal('Notification', { permission, requestPermission });
    return { subscribe, register, requestPermission, pushManager };
}

async function start(markup = MARKUP()) {
    document.body.innerHTML = markup;
    application = Application.start();
    application.register('push-device', PushDeviceController);
    await flush();
}

beforeEach(() => {
    visit.mockClear();
    window.matchMedia = vi.fn().mockReturnValue({ matches: false });
});

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    delete navigator.serviceWorker;
});

it('shows only the off panel in a browser that can push and has not yet', async () => {
    stubBrowser();
    await start();
    expect(visiblePanels()).toEqual(['off']);
});

it('shows the on panel when the browser holds a subscription the server knows', async () => {
    stubBrowser({ subscription: { endpoint: 'https://push.example/abc' } });
    await start(MARKUP(true));
    expect(visiblePanels()).toEqual(['on']);
});

it('shows the blocked panel and the steps for the platform', async () => {
    stubBrowser({ permission: 'denied' });
    await start();
    expect(visiblePanels()).toEqual(['blocked']);
    const shown = Array.from(
        document.querySelectorAll('[data-platform]'),
    ).filter((steps) => !steps.hidden);
    expect(shown.map((steps) => steps.dataset.platform)).toEqual(['chromium']);
});

it('shows the not supported panel when there is no service worker', async () => {
    stubBrowser();
    delete navigator.serviceWorker;
    await start();
    expect(visiblePanels()).toEqual(['not-supported']);
});

it('shows the ios tab panel on an iPhone outside the Home Screen app', async () => {
    stubBrowser({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)' });
    await start();
    expect(visiblePanels()).toEqual(['ios-tab']);
});

it('moves from blocked to off when retry finds the permission changed', async () => {
    const browser = stubBrowser({ permission: 'denied' });
    await start();
    Notification.permission = 'default';
    document.getElementById('retry').click();
    await flush();
    expect(visiblePanels()).toEqual(['off']);
    expect(browser.requestPermission).not.toHaveBeenCalled();
});

it('subscribes on tap and posts the subscription, then reloads the page', async () => {
    const browser = stubBrowser();
    const fetchMock = vi.fn().mockResolvedValue({
        status: 201,
        ok: true,
        json: async () => ({ id: 'device-1', label: 'Chrome on macOS' }),
    });
    vi.stubGlobal('fetch', fetchMock);
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(browser.requestPermission).toHaveBeenCalledTimes(1);
    expect(browser.register).toHaveBeenCalledWith('/sw.js');
    const options = browser.subscribe.mock.calls[0][0];
    expect(options.userVisibleOnly).toBe(true);
    expect(options.applicationServerKey).toBeInstanceOf(Uint8Array);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/account/notifications/devices');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    const body = init.body;
    expect(body.get('endpoint')).toBe('https://push.example/abc');
    expect(body.get('publicKey')).toBe('AQID');
    expect(body.get('authToken')).toBe('BAUG');
    expect(body.get('contentEncoding')).toBe('aes128gcm');
    expect(body.get('token')).toBe('submit');
    expect(visit).toHaveBeenCalledTimes(1);
});

it('reuses a subscription the browser already holds', async () => {
    const held = {
        endpoint: 'https://push.example/held',
        getKey: () => new Uint8Array([9]).buffer,
    };
    const browser = stubBrowser({ subscription: held });
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            status: 201,
            ok: true,
            json: async () => ({}),
        }),
    );
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(browser.subscribe).not.toHaveBeenCalled();
    expect(fetch.mock.calls[0][1].body.get('endpoint')).toBe(
        'https://push.example/held',
    );
});

it('takes a new subscription when another account holds the endpoint', async () => {
    const held = {
        endpoint: 'https://push.example/earlier-account',
        getKey: () => new Uint8Array([9]).buffer,
        unsubscribe: vi.fn().mockResolvedValue(true),
    };
    const browser = stubBrowser({ subscription: held });
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce({
            status: 409,
            ok: false,
            json: async () => ({ message: 'taken', errors: {} }),
        })
        .mockResolvedValueOnce({
            status: 201,
            ok: true,
            json: async () => ({}),
        });
    vi.stubGlobal('fetch', fetchMock);
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(held.unsubscribe).toHaveBeenCalledTimes(1);
    expect(browser.subscribe).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[0][1].body.get('endpoint')).toBe(
        'https://push.example/earlier-account',
    );
    expect(fetchMock.mock.calls[1][1].body.get('endpoint')).toBe(
        'https://push.example/abc',
    );
    expect(document.getElementById('error').hidden).toBe(true);
    expect(visit).toHaveBeenCalledTimes(1);
});

it('retries a taken endpoint once and then shows the message', async () => {
    const browser = stubBrowser({
        created: {
            endpoint: 'https://push.example/same',
            getKey: () => new Uint8Array([9]).buffer,
            unsubscribe: vi.fn().mockResolvedValue(true),
        },
    });
    const fetchMock = vi.fn().mockResolvedValue({
        status: 409,
        ok: false,
        json: async () => ({
            message:
                'This browser already receives notifications for another account.',
            errors: {},
        }),
    });
    vi.stubGlobal('fetch', fetchMock);
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(browser.subscribe).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(document.getElementById('error').textContent).toBe(
        'This browser already receives notifications for another account.',
    );
    expect(visit).not.toHaveBeenCalled();
});

it('shows the blocked panel when the user denies the prompt', async () => {
    const browser = stubBrowser({ requestResult: 'denied' });
    await start();

    Notification.permission = 'denied';
    document.getElementById('turn-on').click();
    await flush();

    expect(browser.subscribe).not.toHaveBeenCalled();
    expect(visiblePanels()).toEqual(['blocked']);
});

it('says so when the user dismisses the prompt without an answer', async () => {
    const browser = stubBrowser({ requestResult: 'default' });
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(browser.subscribe).not.toHaveBeenCalled();
    const error = document.getElementById('error');
    expect(error.hidden).toBe(false);
    expect(error.textContent).toBe('Notifications are off for Loupe.');
});

it('shows the message of a 422 answer', async () => {
    stubBrowser();
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            status: 422,
            ok: false,
            json: async () => ({
                message:
                    'This browser uses a push service that Loupe does not send to.',
                errors: {},
            }),
        }),
    );
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(document.getElementById('error').textContent).toBe(
        'This browser uses a push service that Loupe does not send to.',
    );
    expect(visit).not.toHaveBeenCalled();
});

it('shows the throttle message on a 429 answer', async () => {
    stubBrowser();
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            status: 429,
            ok: false,
            json: async () => ({}),
        }),
    );
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(document.getElementById('error').textContent).toBe('Wait a little.');
});

it('shows the failure message when the browser refuses to subscribe', async () => {
    const browser = stubBrowser();
    browser.subscribe.mockRejectedValue(new Error('push service error'));
    vi.stubGlobal('fetch', vi.fn());
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(fetch).not.toHaveBeenCalled();
    expect(document.getElementById('error').textContent).toBe(
        'Push did not turn on.',
    );
});

it('shows the failure message when the request fails', async () => {
    stubBrowser();
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));
    await start();

    document.getElementById('turn-on').click();
    await flush();

    expect(document.getElementById('error').textContent).toBe(
        'Push did not turn on.',
    );
});

it('enables the button again after a failure', async () => {
    const browser = stubBrowser();
    browser.subscribe.mockRejectedValue(new Error('push service error'));
    await start();

    const button = document.getElementById('turn-on');
    button.click();
    await flush();

    expect(button.disabled).toBe(false);
});

it('shows the preview state and ignores the browser when one is set', async () => {
    stubBrowser({ permission: 'granted' });
    await start(
        MARKUP().replace(
            'data-controller="push-device"',
            'data-controller="push-device" data-push-device-preview-state-value="ios-tab"',
        ),
    );
    expect(visiblePanels()).toEqual(['ios-tab']);

    document.getElementById('retry').click();
    await flush();
    expect(visiblePanels()).toEqual(['ios-tab']);
});
