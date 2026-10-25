import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/** A failed reload tries again after each wait, and a new change starts the count again. */
export const RETRY_MILLISECONDS = [1000, 3000, 9000];

const STOPPED_ATTRIBUTE = 'data-live-pill-stopped';

/**
 * Reloads a sidebar pill when the hub reports one of its events, and on each
 * open of the connection for any change made while it was not listening, the
 * first open included. A message that names a project is ignored when it
 * names another one, because one topic can carry every project of the user.
 * With no project value it follows every project.
 */
export default class extends Controller {
    static targets = ['frame', 'status'];
    static values = {
        project: String,
        url: String,
        events: { type: Array, default: [] },
        stopped: String,
    };

    connect() {
        this.attempt = 0;
        this.onMissing = (event) => this.missing(event);
        this.onRequestError = () => this.failed(true);
        this.onLoad = () => this.loaded();
        this.element.addEventListener('turbo:frame-missing', this.onMissing);
        this.element.addEventListener(
            'turbo:fetch-request-error',
            this.onRequestError,
        );
        this.element.addEventListener('turbo:frame-load', this.onLoad);
        this.unsubscribe = on(
            this.eventsValue,
            (data) => {
                if (
                    !this.projectValue ||
                    !data?.projectId ||
                    data.projectId === this.projectValue
                ) {
                    this.restart();
                }
            },
            {
                onOpen: () => {
                    this.restart();
                    this.element.setAttribute('data-live-pill-connected', '');
                },
                onError: () =>
                    this.element.removeAttribute('data-live-pill-connected'),
            },
        );
    }

    disconnect() {
        clearTimeout(this.timeout);
        this.element.removeEventListener('turbo:frame-missing', this.onMissing);
        this.element.removeEventListener(
            'turbo:fetch-request-error',
            this.onRequestError,
        );
        this.element.removeEventListener('turbo:frame-load', this.onLoad);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        this.element.removeAttribute('data-live-pill-connected');
    }

    restart() {
        clearTimeout(this.timeout);
        this.attempt = 0;
        this.reload();
    }

    /** A failed reload has no frame to show, and Turbo would write "Content missing" into the frame. */
    missing(event) {
        event.preventDefault();
        const status = event.detail?.response?.statusCode;
        this.failed(status >= 500 || status === 429 || status === 408);
    }

    failed(transient) {
        if (!transient || this.attempt >= RETRY_MILLISECONDS.length) {
            this.showStopped();

            return;
        }
        clearTimeout(this.timeout);
        this.timeout = setTimeout(
            () => this.reload(),
            RETRY_MILLISECONDS[this.attempt],
        );
        this.attempt += 1;
    }

    loaded() {
        this.attempt = 0;
        this.element.removeAttribute(STOPPED_ATTRIBUTE);
        this.statusTargets.forEach((line) => {
            line.textContent = '';
        });
    }

    showStopped() {
        this.element.setAttribute(STOPPED_ATTRIBUTE, '');
        this.statusTargets.forEach((line) => {
            line.textContent = this.stoppedValue;
        });
    }

    reload() {
        // A new load cancels the one in flight, so the last change wins.
        if (this.frameTarget.getAttribute('src') === null) {
            this.frameTarget.src = this.urlValue;
        } else {
            this.frameTarget.reload();
        }
    }
}
