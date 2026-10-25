import { Controller } from '@hotwired/stimulus';
import {
    generateCsrfHeaders,
    generateCsrfToken,
    removeCsrfToken,
} from './csrf_protection_controller.js';
import {
    ACTIVITY_EVENTS,
    POST_INTERVAL_MILLISECONDS,
    closeStaleNotifications,
    isActive,
    shouldPost,
} from '../lib/presence.js';

// Module state outlives the controller, which Turbo reconnects on every visit.
let lastActivityAt = Date.now();
let lastPostAt = 0;
let tracking = false;
let openTagsRead = false;

function trackActivity() {
    if (tracking) {
        return;
    }
    tracking = true;
    const note = () => {
        lastActivityAt = Date.now();
    };
    ACTIVITY_EVENTS.forEach((name) =>
        document.addEventListener(name, note, { capture: true, passive: true }),
    );
}

/**
 * Tells the server that this page is in use, so a push is held back while the
 * owner looks at Loupe. On the first page of a load it also closes the
 * notifications of asks that have closed.
 *
 * The element is a hidden form that carries the CSRF token.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { openTagsUrl: String };

    _timer = null;

    connect() {
        trackActivity();
        this.beat();
        this._timer = setInterval(
            () => this.beat(),
            POST_INTERVAL_MILLISECONDS,
        );
        this.closeStale();
    }

    disconnect() {
        clearInterval(this._timer);
        this._timer = null;
    }

    async beat() {
        const now = Date.now();
        const active = isActive({
            visible: document.visibilityState === 'visible',
            focused: document.hasFocus(),
            lastActivityAt,
            now,
        });
        if (!shouldPost({ active, lastPostAt, now })) {
            return;
        }
        lastPostAt = now;

        const form = this.element;
        generateCsrfToken(form);
        const body = new URLSearchParams();
        form.querySelectorAll('input[name]').forEach((input) =>
            body.append(input.name, input.value),
        );
        try {
            // A heartbeat in the background, with no page to update. No form fits.
            // eslint-disable-next-line no-restricted-syntax
            await fetch(form.action, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: generateCsrfHeaders(form),
            });
        } catch {
            // The next beat tries again.
        } finally {
            removeCsrfToken(form);
        }
    }

    async closeStale() {
        if (openTagsRead || !navigator.serviceWorker) {
            return;
        }
        openTagsRead = true;

        try {
            const registration =
                await navigator.serviceWorker.getRegistration();
            if (!registration) {
                return;
            }
            // A read of the open pushes, with no page to update. No form fits.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(this.openTagsUrlValue, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                return;
            }
            const { tags } = await response.json();
            await closeStaleNotifications(registration, tags);
        } catch {
            // A stale notification costs a tap. It never breaks the page.
        }
    }
}
