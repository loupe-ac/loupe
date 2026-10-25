import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { beforeEach, expect, it, vi } from 'vitest';

const source = readFileSync(
    new URL('../../public/sw.js', import.meta.url),
    'utf8',
);

let handlers;
let shown;
let registration;
let clients;

function load() {
    handlers = {};
    shown = [];
    registration = {
        getNotifications: vi.fn(async () => shown),
        showNotification: vi.fn(async () => {}),
    };
    clients = {
        matchAll: vi.fn(async () => []),
        openWindow: vi.fn(async () => {}),
    };
    const self = {
        location: { origin: 'https://app.example' },
        registration,
        clients,
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
    };
    vm.runInNewContext(source, { self, URL, Array });
}

async function fire(type, event) {
    let pending = Promise.resolve();
    handlers[type]({ ...event, waitUntil: (promise) => (pending = promise) });
    await pending;
}

function notification(tag) {
    return { tag, close: vi.fn() };
}

beforeEach(load);

it('closes stale notifications on a declarative push and shows nothing itself', async () => {
    const stale = notification('ask-old');
    const open = notification('ask-new');
    shown = [stale, open];

    await fire('push', {
        data: null,
        notification: {
            data: { openTags: ['ask-new'], url: 'https://app.example/x' },
        },
    });

    expect(stale.close).toHaveBeenCalled();
    expect(open.close).not.toHaveBeenCalled();
    expect(registration.showNotification).not.toHaveBeenCalled();
});

it('shows a legacy push with its data and closes stale ones first', async () => {
    const stale = notification('ask-old');
    shown = [stale];
    const data = {
        url: 'https://app.example/inbox?ask=1',
        openTags: ['ask-1'],
    };

    await fire('push', {
        data: {
            json: () => ({
                web_push: 8030,
                notification: {
                    title: 'Acme',
                    body: 'Question',
                    tag: 'ask-1',
                    icon: 'https://app.example/icons/push-192.png',
                    navigate: 'https://app.example/inbox?ask=1',
                    data,
                },
            }),
        },
    });

    expect(stale.close).toHaveBeenCalled();
    expect(registration.showNotification).toHaveBeenCalledWith('Acme', {
        body: 'Question',
        tag: 'ask-1',
        icon: 'https://app.example/icons/push-192.png',
        data,
    });
});

it('closes nothing when openTags is missing', async () => {
    const shownOne = notification('ask-1');
    shown = [shownOne];

    await fire('push', { data: null, notification: { data: {} } });

    expect(shownOne.close).not.toHaveBeenCalled();
});

it('ignores a legacy push with no readable payload', async () => {
    await fire('push', {
        data: {
            json: () => {
                throw new Error('bad');
            },
        },
    });

    expect(registration.showNotification).not.toHaveBeenCalled();
});

it('opens notification.data.url on a tap', async () => {
    const tapped = {
        close: vi.fn(),
        data: { url: 'https://app.example/inbox?ask=1' },
    };

    await fire('notificationclick', { notification: tapped });

    expect(tapped.close).toHaveBeenCalled();
    expect(clients.openWindow).toHaveBeenCalledWith(
        'https://app.example/inbox?ask=1',
    );
});

it('focuses and navigates an open Loupe window', async () => {
    const navigate = vi.fn(async () => {});
    const client = {
        url: 'https://app.example/projects',
        focus: vi.fn(async () => ({ navigate })),
    };
    clients.matchAll = vi.fn(async () => [client]);

    await fire('notificationclick', {
        notification: {
            close: vi.fn(),
            data: { url: 'https://app.example/inbox?ask=1' },
        },
    });

    expect(client.focus).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith('https://app.example/inbox?ask=1');
    expect(clients.openWindow).not.toHaveBeenCalled();
});

it('opens the root for a foreign or missing url', async () => {
    await fire('notificationclick', {
        notification: {
            close: vi.fn(),
            data: { url: 'https://evil.example/' },
        },
    });
    await fire('notificationclick', {
        notification: { close: vi.fn(), data: null },
    });

    expect(clients.openWindow).toHaveBeenNthCalledWith(
        1,
        'https://app.example/',
    );
    expect(clients.openWindow).toHaveBeenNthCalledWith(
        2,
        'https://app.example/',
    );
});
