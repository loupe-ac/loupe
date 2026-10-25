import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';
import {
    generateCsrfHeaders,
    generateCsrfToken,
    removeCsrfToken,
} from './csrf_protection_controller.js';
import {
    base64UrlDecode,
    base64UrlEncode,
    blockedPlatform,
    chooseContentEncoding,
    detectState,
    isIosDevice,
} from '../lib/push_device.js';

/**
 * The push controls for this browser. It picks one panel to show, and on a tap
 * it asks for permission, subscribes and sends the subscription to the server.
 * The public VAPID key is the only key that reaches the page.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['panel', 'platformSteps', 'form', 'button', 'error'];

    static values = {
        publicKey: String,
        createUrl: String,
        serviceWorkerUrl: String,
        deviceKnown: Boolean,
        deniedMessage: String,
        failedMessage: String,
        throttledMessage: String,
        previewState: String,
    };

    connect() {
        this.refresh();
    }

    async refresh() {
        this.clearError();
        this.show(await this.currentState());
    }

    async turnOn() {
        this.clearError();
        this.buttonTargets.forEach((button) => {
            button.disabled = true;
        });
        try {
            await this.subscribeAndRegister();
        } catch {
            this.showError(this.failedMessageValue);
        } finally {
            this.buttonTargets.forEach((button) => {
                button.disabled = false;
            });
        }
    }

    async subscribeAndRegister() {
        const permission = await Notification.requestPermission();
        if (permission === 'denied') {
            await this.refresh();
            return;
        }
        if (permission !== 'granted') {
            this.showError(this.deniedMessageValue);
            return;
        }

        const registration = await navigator.serviceWorker.register(
            this.serviceWorkerUrlValue,
        );
        await navigator.serviceWorker.ready;
        const { pushManager } = registration;
        const options = {
            userVisibleOnly: true,
            applicationServerKey: base64UrlDecode(this.publicKeyValue),
        };
        let subscription =
            (await pushManager.getSubscription()) ??
            (await pushManager.subscribe(options));
        let response = await this.register(subscription);
        if (response.status === 409) {
            // An account that signed in here before holds this endpoint, so this browser takes a new one.
            await subscription.unsubscribe();
            subscription = await pushManager.subscribe(options);
            response = await this.register(subscription);
        }

        await this.answer(response);
    }

    async register(subscription) {
        const form = this.formTarget;
        generateCsrfToken(form);
        const body = new FormData(form);
        body.set('endpoint', subscription.endpoint);
        body.set('publicKey', base64UrlEncode(subscription.getKey('p256dh')));
        body.set('authToken', base64UrlEncode(subscription.getKey('auth')));
        body.set(
            'contentEncoding',
            chooseContentEncoding(
                window.PushManager?.supportedContentEncodings,
            ),
        );

        try {
            // The browser creates the subscription, and the endpoint answers JSON with a cookie. No form can carry that.
            // eslint-disable-next-line no-restricted-syntax
            return await fetch(this.createUrlValue, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...generateCsrfHeaders(form),
                },
            });
        } finally {
            removeCsrfToken(form);
        }
    }

    async answer(response) {
        if (response.status === 201) {
            visit(window.location.href, { action: 'replace' });
            return;
        }
        if (response.status === 429) {
            this.showError(this.throttledMessageValue);
            return;
        }
        const answer = await response.json().catch(() => ({}));
        this.showError(answer.message || this.failedMessageValue);
    }

    async currentState() {
        if (this.previewStateValue) {
            return this.previewStateValue;
        }
        const hasServiceWorker = 'serviceWorker' in navigator;
        const hasPushManager = 'PushManager' in window;
        const hasNotification = 'Notification' in window;
        let subscribed = false;
        if (hasServiceWorker && hasPushManager) {
            try {
                const registration =
                    await navigator.serviceWorker.getRegistration();
                subscribed = Boolean(
                    await registration?.pushManager.getSubscription(),
                );
            } catch {
                subscribed = false;
            }
        }

        return detectState({
            isIos: isIosDevice(navigator.userAgent, navigator.maxTouchPoints),
            standalone:
                navigator.standalone === true ||
                window.matchMedia('(display-mode: standalone)').matches,
            hasServiceWorker,
            hasPushManager,
            hasNotification,
            permission: hasNotification ? Notification.permission : 'default',
            subscribed,
            deviceKnown: this.deviceKnownValue,
        });
    }

    show(state) {
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.panel !== state;
        });
        const platform = blockedPlatform(
            navigator.userAgent,
            navigator.maxTouchPoints,
        );
        this.platformStepsTargets.forEach((steps) => {
            steps.hidden = steps.dataset.platform !== platform;
        });
    }

    showError(message) {
        if (!this.hasErrorTarget) {
            return;
        }
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    clearError() {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = '';
            this.errorTarget.hidden = true;
        }
    }
}
