/** @vitest-environment jsdom */
import { Application } from '@hotwired/stimulus';
import { afterEach, expect, it, vi } from 'vitest';
import PushUnsubscribeController from '../../assets/controllers/push_unsubscribe_controller.js';

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));
const flush = async () => {
    for (let round = 0; round < 6; round += 1) {
        await settle();
    }
};

let application;

function logoutForm() {
    document.body.innerHTML = `
        <form id="logout" data-controller="push-unsubscribe" data-action="submit->push-unsubscribe#unsubscribe">
            <button type="submit">Log out</button>
        </form>`;
    const form = document.getElementById('logout');
    form.requestSubmit = vi.fn(() => {
        const event = new Event('submit', { cancelable: true, bubbles: true });
        form.dispatchEvent(event);
    });
    return form;
}

function startApplication() {
    application = Application.start();
    application.register('push-unsubscribe', PushUnsubscribeController);
}

function stubWorker(subscription) {
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            getRegistration: vi.fn().mockResolvedValue({
                pushManager: {
                    getSubscription: vi.fn().mockResolvedValue(subscription),
                },
            }),
        },
    });
    vi.stubGlobal('PushManager', class {});
}

afterEach(() => {
    application?.stop();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
    vi.useRealTimers();
    delete navigator.serviceWorker;
});

it('unsubscribes this browser, then submits the form once', async () => {
    const unsubscribe = vi.fn().mockResolvedValue(true);
    stubWorker({ unsubscribe });
    const form = logoutForm();
    startApplication();
    await flush();

    const event = new Event('submit', { cancelable: true, bubbles: true });
    form.dispatchEvent(event);
    await flush();

    expect(event.defaultPrevented).toBe(true);
    expect(unsubscribe).toHaveBeenCalledTimes(1);
    expect(form.requestSubmit).toHaveBeenCalledTimes(1);
});

it('lets the submit through at once when the browser cannot push', async () => {
    const form = logoutForm();
    startApplication();
    await flush();

    const event = new Event('submit', { cancelable: true, bubbles: true });
    form.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
    expect(form.requestSubmit).not.toHaveBeenCalled();
});

it('still submits when the browser has no subscription', async () => {
    stubWorker(null);
    const form = logoutForm();
    startApplication();
    await flush();

    form.dispatchEvent(
        new Event('submit', { cancelable: true, bubbles: true }),
    );
    await flush();

    expect(form.requestSubmit).toHaveBeenCalledTimes(1);
});

it('still submits when the unsubscribe call fails', async () => {
    stubWorker({ unsubscribe: vi.fn().mockRejectedValue(new Error('boom')) });
    const form = logoutForm();
    startApplication();
    await flush();

    form.dispatchEvent(
        new Event('submit', { cancelable: true, bubbles: true }),
    );
    await flush();

    expect(form.requestSubmit).toHaveBeenCalledTimes(1);
});

it('does not wait longer than the limit for a stuck unsubscribe call', async () => {
    stubWorker({ unsubscribe: vi.fn(() => new Promise(() => {})) });
    const form = logoutForm();
    startApplication();
    await flush();

    vi.useFakeTimers();
    form.dispatchEvent(
        new Event('submit', { cancelable: true, bubbles: true }),
    );
    await vi.advanceTimersByTimeAsync(5000);

    expect(form.requestSubmit).toHaveBeenCalledTimes(1);
});
