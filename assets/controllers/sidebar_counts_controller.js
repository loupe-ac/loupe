import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';
import { LiveFetcher, liveRequest } from '../lib/live_fetch.js';

const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const STOPPED_ATTRIBUTE = 'data-sidebar-counts-stopped';

export const APPLIED_EVENT = 'sidebar-counts:applied';

/**
 * Keeps the four pills of the project sidebar current with one request. A
 * signal that the worker counts of this project changed, or that the inbox
 * count of any project changed, makes it fetch a Turbo Stream that replaces
 * the pills. Its own changes count too. The first open sends the versions the
 * page rendered, and the server answers 204 when no count moved since.
 */
export default class extends Controller {
    static targets = ['status'];
    static values = {
        project: String,
        url: String,
        since: String,
        stopped: String,
    };

    connect() {
        this.pendingSince = this.sinceValue;
        this.checkedOnOpen = false;
        this.fetcher = new LiveFetcher({
            root: this.element,
            load: (signal) => this.load(signal),
            apply: (response) => this.apply(response),
            onStopped: () => this.showStopped(),
            hold: () => false,
        });
        this.unsubscribe = on(
            ['sidebar.counts_changed', 'inbox.sidebar_count_changed'],
            (change) => this.changed(change),
            {
                onOpen: () => this.opened(),
                onReconnect: () => this.catchUp(),
            },
        );
    }

    disconnect() {
        this.fetcher?.stop();
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    opened() {
        if (this.checkedOnOpen) {
            return;
        }
        this.checkedOnOpen = true;
        this.fetcher.request();
    }

    changed(change) {
        if (
            change.type === 'sidebar.counts_changed' &&
            String(change.projectId) !== this.projectValue
        ) {
            return;
        }
        this.catchUp();
    }

    catchUp() {
        this.pendingSince = '';
        this.fetcher.request();
    }

    load(signal) {
        const url = new URL(this.urlValue, window.location.href);
        if (this.pendingSince) {
            url.searchParams.set('since', this.pendingSince);
        }

        return liveRequest(url.toString(), {
            headers: { Accept: STREAM_TYPE },
            signal,
        });
    }

    async apply(response) {
        this.pendingSince = '';
        const body = response.status === 204 ? '' : await response.text();
        this.clearStopped();
        if (body.trim() === '') {
            return;
        }
        this.render(body);
    }

    render(body) {
        let remaining = (body.match(/<turbo-stream[\s>]/g) ?? []).length;
        const announce = () => {
            remaining -= 1;
            if (remaining <= 0) {
                window.removeEventListener(
                    'turbo:before-stream-render',
                    wrapRender,
                );
                window.dispatchEvent(new CustomEvent(APPLIED_EVENT));
            }
        };
        const wrapRender = (event) => {
            const original = event.detail.render;
            event.detail.render = async (stream) => {
                try {
                    await original(stream);
                } finally {
                    announce();
                }
            };
        };
        if (remaining === 0) {
            window.dispatchEvent(new CustomEvent(APPLIED_EVENT));

            return;
        }
        window.addEventListener('turbo:before-stream-render', wrapRender);
        renderStreamMessage(body);
    }

    showStopped() {
        this.element.setAttribute(STOPPED_ATTRIBUTE, '');
        this.statusTargets.forEach((line) => {
            line.textContent = this.stoppedValue;
        });
    }

    clearStopped() {
        this.element.removeAttribute(STOPPED_ATTRIBUTE);
        this.statusTargets.forEach((line) => {
            line.textContent = '';
        });
    }
}
