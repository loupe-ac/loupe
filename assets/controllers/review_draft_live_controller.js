import { Controller } from '@hotwired/stimulus';
import { morphBodyElements, renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';

/** A change often arrives with the next one close behind it. */
export const DEBOUNCE_MILLISECONDS = 300;

/** A failed fetch tries again after each wait. */
export const RETRY_MILLISECONDS = [1000, 3000, 9000];

/** A fetch that answers nothing in this time counts as a failure. */
export const READ_TIMEOUT_MILLISECONDS = 10000;

const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const STATUS = '[data-review-draft-live-status]';
const CONNECTED_ATTRIBUTE = 'data-review-draft-live-connected';

/**
 * Keeps the band of a draft and the warning of Finish review current. A signal
 * on the document topic, and a reconnect, fetch the two fragments as a Turbo
 * stream. A fetch that no longer finds a draft loads the page again with a
 * morph. An open dialog holds the update until it closes, but the Finish
 * review dialog takes the warning at once, because the stream replaces none
 * of its fields. A published draft clears that warning at once too.
 */
export default class extends Controller {
    static values = { url: String, stopped: String };

    connect() {
        this.held = false;
        this.pendingReload = false;
        this.onDialogClose = () => {
            if (this.held) {
                this.held = false;
                this.schedule();
            }
        };
        this.element.addEventListener('close', this.onDialogClose, true);
        this.unsubscribe = on(
            'review.draft_state_changed',
            () => this.schedule(),
            {
                onReconnect: () => this.schedule(),
                onOpen: () =>
                    this.element.setAttribute(CONNECTED_ATTRIBUTE, ''),
                onError: () =>
                    this.element.removeAttribute(CONNECTED_ATTRIBUTE),
            },
        );
    }

    disconnect() {
        clearTimeout(this.timeout);
        this.request?.abort();
        this.element.removeEventListener('close', this.onDialogClose, true);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute(CONNECTED_ATTRIBUTE);
    }

    /** A newer signal makes a fetch in flight stale, so it stops and counts as nothing. */
    schedule() {
        clearTimeout(this.timeout);
        this.request?.abort();
        this.timeout = setTimeout(() => this.refresh(), DEBOUNCE_MILLISECONDS);
    }

    retry(attempt) {
        if (attempt >= RETRY_MILLISECONDS.length) {
            this.showStopped();

            return;
        }
        clearTimeout(this.timeout);
        this.timeout = setTimeout(
            () => this.refresh(attempt + 1),
            RETRY_MILLISECONDS[attempt],
        );
    }

    async refresh(attempt = 0) {
        if (this.holding()) {
            this.held = true;

            return;
        }
        const request = this.begin();
        const fetched = await this.read(this.urlValue, STREAM_TYPE, request);
        if (fetched === null) {
            return;
        }
        if (fetched.failure) {
            this.fail(fetched, attempt);

            return;
        }
        if (this.holding()) {
            this.held = true;

            return;
        }
        if (fetched.status === 204) {
            this.withdrawWarning();
            await this.reload(attempt);

            return;
        }

        this.syncSecondConfirmation(fetched.body);
        renderStreamMessage(fetched.body);
        this.clearStopped();
    }

    async reload(attempt) {
        if (document.querySelector('dialog[open]')) {
            this.held = true;

            return;
        }
        const request = this.begin();
        const fetched = await this.read(
            window.location.href,
            'text/html',
            request,
        );
        if (fetched === null) {
            return;
        }
        if (fetched.failure) {
            this.fail(fetched, attempt);

            return;
        }
        if (document.querySelector('dialog[open]')) {
            this.held = true;

            return;
        }
        const fresh = new DOMParser().parseFromString(
            fetched.body,
            'text/html',
        );
        morphBodyElements(document.body, fresh.body);
    }

    begin() {
        this.request?.abort();
        const request = new AbortController();
        this.request = request;

        return request;
    }

    /**
     * Answers null when a newer signal or a disconnect superseded the read.
     * Otherwise the status and the body, or `failure` set to 'final' when a
     * retry cannot help and to 'transient' when one can.
     */
    async read(url, accept, request) {
        let timedOut = false;
        const timer = setTimeout(() => {
            timedOut = true;
            request.abort();
        }, READ_TIMEOUT_MILLISECONDS);
        try {
            // A read-only GET of two fragments, not a mutation.
            // eslint-disable-next-line no-restricted-syntax
            const response = await fetch(url, {
                headers: { Accept: accept },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (!response.ok) {
                return request.signal.aborted
                    ? null
                    : {
                          failure:
                              response.status >= 500 ||
                              response.status === 429 ||
                              response.status === 408
                                  ? 'transient'
                                  : 'final',
                      };
            }
            const body = await response.text();

            return request.signal.aborted
                ? null
                : { status: response.status, body };
        } catch {
            return timedOut || !request.signal.aborted
                ? { failure: 'transient' }
                : null;
        } finally {
            clearTimeout(timer);
        }
    }

    fail(fetched, attempt) {
        if (fetched.failure === 'final') {
            this.showStopped();

            return;
        }
        this.retry(attempt);
    }

    /** The Finish review dialog is not a reason to hold: the stream touches none of its fields. */
    holding() {
        return (
            this.element.querySelector(
                '#draft-band dialog[open], #review-stop-confirm dialog[open]',
            ) !== null
        );
    }

    /** A published draft has no agent review to warn about, so an open Finish review dialog drops the warning before the page loads again. */
    withdrawWarning() {
        this.element.querySelector('#draft-review-warning')?.replaceChildren();
        this.element
            .querySelector('form[data-controller~="review-stop-confirm"]')
            ?.setAttribute('data-review-stop-confirm-active-value', 'false');
    }

    /** Whether the second confirmation asks comes with the warning, as an attribute of its stream. */
    syncSecondConfirmation(body) {
        const active = new DOMParser()
            .parseFromString(body, 'text/html')
            .querySelector('turbo-stream[target="draft-review-warning"]')
            ?.getAttribute('data-stop-confirm-active');
        const form = this.element.querySelector(
            'form[data-controller~="review-stop-confirm"]',
        );
        if (active !== null && active !== undefined && form) {
            form.setAttribute('data-review-stop-confirm-active-value', active);
        }
    }

    showStopped() {
        this.element.querySelectorAll(STATUS).forEach((line) => {
            line.textContent = this.stoppedValue;
        });
    }

    clearStopped() {
        this.element.querySelectorAll(STATUS).forEach((line) => {
            line.textContent = '';
        });
    }
}
