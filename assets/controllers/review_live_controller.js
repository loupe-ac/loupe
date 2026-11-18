import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';
import {
    /* stimulusFetch: 'lazy' */
    LiveFetcher,
    liveRequest,
    onLostAccess,
    showStoppedBand,
} from '../lib/live_fetch.js';

const STREAM_TYPE = 'text/vnd.turbo-stream.html';
const VERSION_PUBLISHED = 'review.version_published';
const THREAD_CHANGED = 'review.thread_changed';
const DOCUMENT_STATE_CHANGED = 'review.document_state_changed';

const TEXT_FIELDS =
    'textarea, input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="button"], [type="submit"])';

/**
 * A form that holds text the reader typed, or that a script opened, is not the
 * server's to redraw: the new markup renders it empty and hidden.
 */
export function keepLocalForm(event) {
    const { currentElement, newElement } = event.detail;
    if (!(currentElement instanceof HTMLFormElement) || !newElement) {
        return;
    }
    const typed = [...currentElement.querySelectorAll(TEXT_FIELDS)].some(
        (field) => field.value !== field.defaultValue,
    );
    const opened = newElement.hidden && !currentElement.hidden;
    if (typed || opened) {
        event.preventDefault();
    }
}

/**
 * Keeps a review page current while it keeps its text. A signal of another
 * actor on the document topic reads a Turbo Stream:
 *
 * - A version above the shown one, and a change of the document state, read
 *   the band stream, which fills #live-band.
 * - A thread change reads one thread, or every thread when the signal names a
 *   thread the page does not hold or a change of the collection.
 *
 * Once a higher version is published, the page reads no more threads, because
 * its text no longer matches them. The thread streams morph, and a reply box with typed text stays as it is.
 * Only a drag holds a thread read, because the read touches no other form.
 */
export default class extends Controller {
    static values = {
        document: String,
        bandUrl: String,
        threadsUrl: String,
        threadsLive: Boolean,
    };

    connect() {
        this.halted = false;
        this.threadsLiveFlag = this.threadsLiveValue;
        this.pendingIds = new Set();
        this.pendingCollection = false;
        this.bandFetcher = this.fetcher(
            (signal) => this.loadBand(signal),
            () => false,
            (response) => this.apply(response),
        );
        this.threadFetcher = this.fetcher(
            (signal) => this.loadThreads(signal),
            () => document.querySelector('[data-dragging]') !== null,
            (response) => this.applyThreads(response),
        );
        this.unsubscribe = on(
            [VERSION_PUBLISHED, THREAD_CHANGED, DOCUMENT_STATE_CHANGED],
            (change) => this.changed(change),
            { onReconnect: () => this.reconnected() },
        );
        this.removeLostListener = onLostAccess(() => this.halt());
        document.addEventListener('turbo:before-morph-element', keepLocalForm);
    }

    disconnect() {
        this.halt();
        this.removeLostListener?.();
        this.removeLostListener = undefined;
    }

    halt() {
        this.halted = true;
        this.bandFetcher?.stop();
        this.threadFetcher?.stop();
        this.unsubscribe?.();
        this.unsubscribe = undefined;
        document.removeEventListener(
            'turbo:before-morph-element',
            keepLocalForm,
        );
    }

    fetcher(load, hold, apply) {
        return new LiveFetcher({
            root: this.element,
            load,
            hold,
            apply,
            onStopped: () => this.stop(),
        });
    }

    stop() {
        this.halt();
        showStoppedBand(this.element);
    }

    shownVersion() {
        return Number(this.element.dataset.liveShownVersion ?? 0);
    }

    changed(change) {
        if (
            this.halted ||
            change.own ||
            String(change.documentId) !== this.documentValue
        ) {
            return;
        }
        if (change.type === VERSION_PUBLISHED) {
            if (Number(change.versionNumber) > this.shownVersion()) {
                this.threadsLiveFlag = false;
                this.bandFetcher.request();
            }

            return;
        }
        if (change.type === DOCUMENT_STATE_CHANGED) {
            this.bandFetcher.request();

            return;
        }
        this.threadChanged(change);
    }

    threadChanged(change) {
        if (!this.threadsLiveFlag) {
            return;
        }
        const id = String(change.commentId);
        if (
            change.scope === 'thread' &&
            document.getElementById(`comment-thread-${id}`)
        ) {
            this.pendingIds.add(id);
        } else {
            this.pendingCollection = true;
        }
        this.threadFetcher.request();
    }

    reconnected() {
        if (this.halted) {
            return;
        }
        this.bandFetcher.request();
        if (this.threadsLiveFlag) {
            this.pendingCollection = true;
            this.threadFetcher.request();
        }
    }

    loadBand(signal) {
        const url = new URL(this.bandUrlValue, window.location.href);
        url.searchParams.set('shown', String(this.shownVersion()));

        return liveRequest(url.toString(), {
            headers: { Accept: STREAM_TYPE },
            signal,
        });
    }

    loadThreads(signal) {
        const single = !this.pendingCollection && this.pendingIds.size === 1;
        const path = single
            ? `${this.threadsUrlValue}/${[...this.pendingIds][0]}`
            : this.threadsUrlValue;

        const url = new URL(path, window.location.href);
        url.searchParams.set('shown', String(this.shownVersion()));

        return liveRequest(url.toString(), {
            headers: { Accept: STREAM_TYPE },
            signal,
        });
    }

    async apply(response) {
        const body = await response.text();
        if (body.trim() !== '') {
            renderStreamMessage(body);
        }
    }

    async applyThreads(response) {
        this.pendingIds.clear();
        this.pendingCollection = false;
        await this.apply(response);
    }
}
