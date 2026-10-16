import { Controller } from '@hotwired/stimulus';
import { on } from '../lib/live.js';

/** A burst of output messages makes one read. */
export const DEBOUNCE_MILLISECONDS = 200;

/** The reader counts as at the bottom within this many pixels. */
const BOTTOM_TOLERANCE = 8;

/**
 * Streams the output log of a running command into its code block. A
 * `worker_run.output` message of this run starts a read from the stored
 * length, and the read repeats until the server has nothing more. The length
 * lives in a value, so a morph of the page that renders the server's tail
 * also moves the offset to match it.
 */
export default class extends Controller {
    static values = {
        url: String,
        runId: String,
        length: Number,
    };

    connect() {
        this.code = this.element.querySelector('pre code');
        this.body = this.element.querySelector('pre');
        this.reading = false;
        this.again = false;
        this.stopped = false;
        this.scrollToEnd();
        this.unsubscribe = on(
            'worker_run.output',
            (change) => {
                if (change.runId === this.runIdValue) {
                    this.schedule();
                }
            },
            { onReconnect: () => this.schedule() },
        );
    }

    disconnect() {
        this.stopped = true;
        clearTimeout(this.timeout);
        this.request?.abort();
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    schedule() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.catchUp(), DEBOUNCE_MILLISECONDS);
    }

    async catchUp() {
        if (this.reading) {
            this.again = true;

            return;
        }
        this.reading = true;
        try {
            do {
                this.again = false;
                while (await this.readOnce()) {
                    // Each read moves the offset, so the loop ends when the server has no more text.
                }
            } while (this.again && !this.stopped);
        } finally {
            this.reading = false;
        }
    }

    /** @returns {Promise<boolean>} whether to read again */
    async readOnce() {
        const from = this.lengthValue;
        const url = new URL(this.urlValue, window.location.href);
        url.searchParams.set('from', String(from));
        this.request = new AbortController();
        let answer;
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of the log, not a mutation.
            const response = await fetch(url.toString(), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: this.request.signal,
            });
            if (!response?.ok) {
                return false;
            }
            answer = await response.json();
        } catch {
            return false;
        }
        if (this.stopped) {
            return false;
        }
        // A morph of the page moved the offset while the read ran, so the text no longer fits.
        if (from !== this.lengthValue) {
            return true;
        }
        if (!answer.text) {
            return false;
        }
        this.append(answer.text);
        this.lengthValue = answer.length;

        return true;
    }

    append(text) {
        const stuck = this.atEnd();
        const last = this.code.lastChild;
        if (last instanceof Text) {
            last.appendData(text);
        } else {
            this.code.append(document.createTextNode(text));
        }
        if (stuck) {
            this.scrollToEnd();
        }
    }

    atEnd() {
        return (
            this.body.scrollHeight -
                this.body.scrollTop -
                this.body.clientHeight <=
            BOTTOM_TOLERANCE
        );
    }

    scrollToEnd() {
        this.body.scrollTop = this.body.scrollHeight;
    }
}
