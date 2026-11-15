import { Controller } from '@hotwired/stimulus';
import { morphTurboFrameElements, renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';
import {
    LiveFetcher,
    liveRequest,
    onLostAccess,
    showStoppedBand,
} from '../lib/live_fetch.js';

const STREAM_TYPE = 'text/vnd.turbo-stream.html';

/**
 * Keeps one region of the page current. A signal of one of its types, which
 * another actor sent and which matches the `match` values, makes the region
 * read its url and apply the answer by its mode. `matchType` maps a signal type
 * to extra match values, and a type it does not list always matches. `quiet`
 * and `max` set the wait of the fetcher in milliseconds:
 *
 * - frame: the region is a turbo-frame, and the answer morphs into it.
 * - stream: the answer is a Turbo Stream.
 * - rows: like stream, and the read names the shown rows and the cursor.
 * - band: no read. The region unhides its band, and a reconnect compares the
 *   fingerprint of the region with the answer of the url.
 */
export default class extends Controller {
    static targets = ['band'];

    static values = {
        types: Array,
        url: String,
        mode: { type: String, default: 'frame' },
        match: Object,
        matchType: Object,
        quiet: Number,
        max: Number,
        stopped: Boolean,
    };

    connect() {
        if (this.stoppedValue || this.lost) {
            return;
        }
        this.halted = false;
        this.fetcher = new LiveFetcher({
            root: this.element,
            load: (signal) => this.load(signal),
            apply: (response) => this.apply(response),
            onStopped: () => this.stop(),
            hold: this.modeValue === 'band' ? () => false : undefined,
            quietMilliseconds: this.quietValue || undefined,
            maxWaitMilliseconds: this.maxValue || undefined,
        });
        this.unsubscribe = on(
            this.typesValue,
            (change) => this.changed(change),
            {
                onReconnect: () => this.reconnected(),
            },
        );
        this.removeLostListener = onLostAccess(() => {
            this.lost = true;
            this.halt();
        });
    }

    disconnect() {
        this.halt();
        this.removeLostListener?.();
        this.removeLostListener = undefined;
    }

    halt() {
        this.halted = true;
        this.fetcher?.stop();
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    changed(change) {
        if (this.halted || change.own || !this.matches(change)) {
            return;
        }
        if (this.modeValue === 'band') {
            this.showBand();

            return;
        }
        this.fetcher.request();
    }

    reconnected() {
        if (this.halted) {
            return;
        }
        if (this.modeValue === 'band' && !this.urlValue) {
            this.showBand();

            return;
        }
        this.fetcher.request();
    }

    matches(change) {
        const expected = [
            ...Object.entries(this.matchValue),
            ...Object.entries(this.matchTypeValue[change.type] ?? {}),
        ];

        return expected.every(
            ([key, value]) => String(change[key]) === String(value),
        );
    }

    showBand() {
        this.bandTargets.forEach((band) => (band.hidden = false));
    }

    stop() {
        this.halt();
        this.stoppedValue = true;
        showStoppedBand(this.element);
    }

    load(signal) {
        const url = new URL(this.urlValue, window.location.href);
        const headers = {};
        if (this.modeValue === 'frame') {
            headers['Turbo-Frame'] = this.element.id;
        } else if (this.modeValue === 'stream' || this.modeValue === 'rows') {
            headers.Accept = STREAM_TYPE;
        }
        if (this.modeValue === 'rows') {
            url.searchParams.set('ids', this.rowIds().join(','));
            url.searchParams.set('since', this.element.dataset.liveSince ?? '');
        }

        return liveRequest(url.toString(), { headers, signal });
    }

    rowIds() {
        return [...this.element.querySelectorAll('[data-live-row]')].map(
            (row) => row.dataset.liveRow,
        );
    }

    async apply(response) {
        const body = await response.text();
        if (this.modeValue === 'frame') {
            this.morphFrame(body);
        } else if (this.modeValue === 'band') {
            this.compareFingerprint(body);
        } else if (body.trim() !== '') {
            renderStreamMessage(body);
        }
    }

    morphFrame(html) {
        const fresh = new DOMParser()
            .parseFromString(html, 'text/html')
            .querySelector(`turbo-frame[id="${this.element.id}"]`);
        if (fresh) {
            morphTurboFrameElements(this.element, fresh);
        }
    }

    compareFingerprint(body) {
        if (body.trim() !== (this.element.dataset.liveFingerprint ?? '')) {
            this.showBand();
        }
    }
}
