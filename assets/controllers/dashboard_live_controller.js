import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';

export const SIGNAL_TYPE = 'dashboard.graph_changed';

/** A burst of signals costs one fetch, after this quiet time. */
export const SETTLE_MILLISECONDS = 300;

/** A steady stream of signals still fetches after this long. */
export const MAX_WAIT_MILLISECONDS = 2000;

/** A fetch held by a drag, an open form or an open dialog looks again after this long. */
export const BUSY_RETRY_MILLISECONDS = 200;

/** A failed fetch tries again after each wait. */
export const RETRY_MILLISECONDS = [1000, 3000, 9000];

const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const BUSY_SELECTOR = '[data-dragging], [data-renaming], dialog[open]';

/**
 * Keeps the tile grid of the Dashboard current. A signal names a graph and
 * holds no content, so the page fetches the whole grid as a Turbo stream. A
 * burst of signals makes one fetch. A signal that this tab caused is skipped,
 * because the page already shows that change. A reconnect fetches once, since
 * the hub may have lost signals. A drag, an open rename form or an open dialog
 * holds the fetch until it ends. A failed fetch tries again with a growing
 * wait, and after the last try the page says that live updates stopped.
 */
export default class extends Controller {
    static targets = ['status'];

    static values = { url: String, stopped: String };

    connect() {
        this.sequence = 0;
        this.deadline = null;
        this.unsubscribe = on(SIGNAL_TYPE, (change) => this.changed(change), {
            onReconnect: () => this.schedule(),
        });
    }

    disconnect() {
        clearTimeout(this.timer);
        this.sequence += 1;
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    changed(change) {
        if (change.own) {
            return;
        }
        this.schedule();
    }

    schedule() {
        clearTimeout(this.timer);
        this.deadline ??= Date.now() + MAX_WAIT_MILLISECONDS;
        const wait = Math.max(
            0,
            Math.min(SETTLE_MILLISECONDS, this.deadline - Date.now()),
        );
        this.timer = setTimeout(() => this.refresh(), wait);
    }

    busy() {
        return this.element.querySelector(BUSY_SELECTOR) !== null;
    }

    async refresh(attempt = 0) {
        if (this.busy()) {
            this.timer = setTimeout(
                () => this.refresh(attempt),
                BUSY_RETRY_MILLISECONDS,
            );

            return;
        }
        this.deadline = null;
        this.sequence += 1;
        const sequence = this.sequence;

        let status = null;
        let body = null;
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of the grid that Turbo then applies, not a mutation.
            const response = await fetch(this.urlValue, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
            status = response.status;
            if (response.ok) {
                body = await response.text();
            }
        } catch {
            body = null;
        }

        if (sequence !== this.sequence) {
            return;
        }
        if (body === null) {
            this.failed(status, attempt);

            return;
        }
        if (this.busy()) {
            this.timer = setTimeout(
                () => this.refresh(attempt),
                BUSY_RETRY_MILLISECONDS,
            );

            return;
        }
        renderStreamMessage(body);
        this.showStatus('');
    }

    /** A null status is a network error. */
    failed(status, attempt) {
        const transient =
            status === null ||
            status >= 500 ||
            status === 408 ||
            status === 429;
        if (transient && attempt < RETRY_MILLISECONDS.length) {
            this.timer = setTimeout(
                () => this.refresh(attempt + 1),
                RETRY_MILLISECONDS[attempt],
            );

            return;
        }
        this.showStatus(this.stoppedValue);
    }

    showStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }
}
