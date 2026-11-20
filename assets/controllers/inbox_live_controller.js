/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { morphElements, renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';
import { handleLostAccess, onLostAccess } from '../lib/live_fetch.js';

/** An agent often asks or withdraws several items in a row. */
export const DEBOUNCE_MILLISECONDS = 300;

/** A failed read tries again after each wait, and a new change starts the count again. */
export const RETRY_MILLISECONDS = [1000, 3000, 9000];

/** A read that answers nothing in this time stops, and counts as a failure. */
export const READ_TIMEOUT_MILLISECONDS = 10000;

const VIEW_FRAME = 'inbox-view';

const STREAM_TYPE = 'text/vnd.turbo-stream.html';

/**
 * Reads the view of the inbox page again when an ask of the project changes,
 * and after a reconnect, and morphs it in. A form with typed input keeps its
 * fields. When its item closed in the meantime, the typed values stay as an
 * unsent reply with a Discard button. An open
 * dialog holds the update until it closes. The detail pane that the address
 * names stays as it is when the signal names other asks only. Then the list
 * reads only the rows of those asks, as a stream.
 */
export default class extends Controller {
    static values = { rowsUrl: String };

    connect() {
        // A lost access ends the live part, as a disconnect does.
        this.removeLostListener = onLostAccess(() => this.disconnect());
        this.held = false;
        this.askIds = new Set();
        this.allAsks = false;
        // A dialog's close event does not bubble, so the listener captures it.
        this.onDialogClose = () => {
            if (this.held) {
                this.refresh();
            }
        };
        this.element.addEventListener('close', this.onDialogClose, true);
        this.unsubscribe = on(
            'inbox.changed',
            (change) => this.changed(change),
            { onReconnect: () => this.schedule() },
        );
    }

    disconnect() {
        this.removeLostListener?.();
        this.removeLostListener = undefined;
        clearTimeout(this.timeout);
        this.request?.abort();
        this.element.removeEventListener('close', this.onDialogClose, true);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    /** A change this page made leaves through a redirect that reads the view itself. */
    changed(change) {
        if (change.own) {
            return;
        }
        const askIds = change.askIds ?? [];
        if (askIds.length === 0) {
            this.allAsks = true;
        }
        askIds.forEach((askId) => this.askIds.add(askId));
        this.schedule();
    }

    /** A newer change makes a read in flight stale, so it stops, and its failure retries nothing. */
    schedule() {
        clearTimeout(this.timeout);
        this.request?.abort();
        this.timeout = setTimeout(() => this.refresh(), DEBOUNCE_MILLISECONDS);
    }

    retry(attempt) {
        if (attempt >= RETRY_MILLISECONDS.length) {
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
            return;
        }
        this.request?.abort();
        const request = new AbortController();
        this.request = request;
        const address = window.location.href;
        const pane = this.element.querySelector(
            '[data-inbox-ask-id], [data-inbox-item-number]',
        );
        if (await this.refreshRows(pane, request)) {
            return;
        }
        if (request.signal.aborted) {
            return;
        }
        const url = new URL(address);
        // A view with no ask in its address shows the first row, which a new ask can take over.
        const pinned =
            pane !== null &&
            !url.searchParams.has('ask') &&
            !url.searchParams.has('item');
        if (pinned) {
            if (pane.dataset.inboxAskId) {
                url.searchParams.set('ask', pane.dataset.inboxAskId);
            } else {
                url.searchParams.set('item', pane.dataset.inboxItemNumber);
            }
        }
        // The refresh header keeps the subscriber cookie and the waiting flashes.
        const headers = {
            Accept: 'text/html',
            'X-Loupe-Live-Refresh': '1',
            'Turbo-Frame': VIEW_FRAME,
        };
        if (pane?.dataset.inboxAskOpen === 'true') {
            headers['X-Loupe-Inbox-Watching'] = pane.dataset.inboxAskId;
        }

        let html;
        let timedOut = false;
        const timer = setTimeout(() => {
            timedOut = true;
            request.abort();
        }, READ_TIMEOUT_MILLISECONDS);
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of the view to morph, not a mutation.
            const response = await fetch(url.toString(), {
                headers,
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (handleLostAccess(response)) {
                return;
            }
            if (!response?.ok) {
                // A page the reader may no longer see answers the same on every try.
                if (!request.signal.aborted && response?.status >= 500) {
                    this.retry(attempt);
                }

                return;
            }
            html = await response.text();
        } catch {
            if (timedOut || !request.signal.aborted) {
                this.retry(attempt);
            }

            return;
        } finally {
            clearTimeout(timer);
        }
        if (request.signal.aborted || this.holding()) {
            return;
        }
        // The reader moved to another view while the read ran, so the read shows the old one.
        if (window.location.href !== address) {
            this.schedule();

            return;
        }

        const current = this.element.querySelector(`turbo-frame#${VIEW_FRAME}`);
        const fresh = new DOMParser()
            .parseFromString(html, 'text/html')
            .querySelector(`turbo-frame#${VIEW_FRAME}`);
        if (!current || !fresh) {
            return;
        }
        if (pinned) {
            keepUnpicked(fresh);
        }
        const keepDetail = this.keepsDetail(address, pane);
        this.allAsks = false;
        this.askIds.clear();
        keepNotices(current, fresh);
        keepReplyDrafts(current, fresh);
        this.morph(current, fresh, keepDetail);
    }

    /**
     * Whether the signal names asks that the pane does not show, on the open
     * side of an unsearched list. Then the pane stays, and the list changes
     * only in the rows of those asks.
     */
    rowsOnly(pane) {
        const askId = pane?.dataset.inboxAskId;
        const params = new URL(window.location.href).searchParams;

        return (
            this.hasRowsUrlValue &&
            !this.allAsks &&
            this.askIds.size > 0 &&
            askId !== undefined &&
            !this.askIds.has(askId) &&
            !params.has('q') &&
            (params.get('side') ?? 'open') === 'open' &&
            this.element.querySelector('[data-inbox-request]') !== null
        );
    }

    /**
     * Reads the rows of the changed asks as a stream, and plays it. The stream
     * puts each row before the next row that the read did not change, so it
     * gives the right order only when the unchanged rows on the page are the
     * ones of the read, in the same order. Any other case, and any failure,
     * returns false and leaves the full read to the caller.
     */
    async refreshRows(pane, request) {
        if (!this.rowsOnly(pane)) {
            return false;
        }
        const address = window.location.href;
        const here = new URL(address).searchParams;
        const url = new URL(this.rowsUrlValue, address);
        ['side', 'type'].forEach((name) => {
            if (here.has(name)) {
                url.searchParams.set(name, here.get(name));
            }
        });
        url.searchParams.set('ask', pane.dataset.inboxAskId);
        const changed = [...this.askIds];
        changed.forEach((id) => url.searchParams.append('changed[]', id));

        let html;
        let keys;
        // A newer change aborts the request, and a slow read aborts only this read.
        const read = new AbortController();
        request.signal.addEventListener('abort', () => read.abort());
        const timer = setTimeout(() => read.abort(), READ_TIMEOUT_MILLISECONDS);
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of the rows to play, not a mutation.
            const response = await fetch(url.toString(), {
                headers: { Accept: STREAM_TYPE, 'X-Loupe-Live-Refresh': '1' },
                credentials: 'same-origin',
                signal: read.signal,
            });
            if (handleLostAccess(response)) {
                return true;
            }
            if (!response?.ok) {
                return false;
            }
            keys = (response.headers?.get('X-Loupe-Inbox-Row-Keys') ?? '')
                .split(',')
                .filter((key) => key !== '');
            html = await response.text();
        } catch {
            return request.signal.aborted;
        } finally {
            clearTimeout(timer);
        }
        if (request.signal.aborted || this.holding()) {
            return true;
        }
        if (window.location.href !== address) {
            this.schedule();

            return true;
        }
        const unchanged = (list) =>
            list.filter((key) => !changed.includes(key));
        const shown = [
            ...this.element.querySelectorAll('[data-inbox-request]'),
        ].map((row) => row.dataset.inboxRequest);
        if (
            keys.length === 0 ||
            unchanged(shown).join() !== unchanged(keys).join()
        ) {
            return false;
        }
        this.askIds.clear();
        renderStreamMessage(html);

        return true;
    }

    /**
     * The pane shows the ask that the address names. A signal that names other
     * asks leaves it alone, and a signal with no name, or one that comes with
     * a read that went wrong before, reads it again.
     */
    keepsDetail(address, pane) {
        const askId = pane?.dataset.inboxAskId;

        return (
            !this.allAsks &&
            this.askIds.size > 0 &&
            askId !== undefined &&
            new URL(address).searchParams.get('ask') === askId &&
            !this.askIds.has(askId)
        );
    }

    holding() {
        this.held = this.element.querySelector('dialog[open]') !== null;

        return this.held;
    }

    morph(current, fresh, keepDetail = false) {
        const keepElement = (event) => {
            const target = event.target;
            if (
                keepDetail &&
                target.classList?.contains('lp-inbox-request-detail')
            ) {
                event.preventDefault();

                return;
            }
            if (
                target.classList?.contains('lp-flash') ||
                target.hasAttribute?.('data-inbox-reply-draft') ||
                (target instanceof HTMLFormElement &&
                    hasUnsavedInput(target) &&
                    holdsSameForm(target, event.detail?.newElement))
            ) {
                event.preventDefault();
            }
        };
        const keepOpenDisclosure = (event) => {
            if (
                event.target instanceof HTMLDetailsElement &&
                event.detail.attributeName === 'open' &&
                event.detail.mutationType === 'remove'
            ) {
                event.preventDefault();
            }
        };
        current.addEventListener(
            'turbo:before-morph-element',
            keepElement,
            true,
        );
        current.addEventListener(
            'turbo:before-morph-attribute',
            keepOpenDisclosure,
            true,
        );
        try {
            morphElements(current, fresh, { ignoreActiveValue: true });
        } finally {
            current.removeEventListener(
                'turbo:before-morph-element',
                keepElement,
                true,
            );
            current.removeEventListener(
                'turbo:before-morph-attribute',
                keepOpenDisclosure,
                true,
            );
        }
    }
}

/**
 * The read names the shown ask, so the answer renders it picked. The address
 * names no pick, so the view stays unpicked, and a phone keeps the list.
 */
function keepUnpicked(fresh) {
    fresh
        .querySelector('[data-inbox-picked]')
        ?.setAttribute('data-inbox-picked', 'false');
    fresh.querySelector('.lp-inbox-back')?.remove();
}

/**
 * A status line says what the last action did, or why the open ask closed. The
 * fresh view has none of its own, so the lines the reader saw stay until the
 * reader moves to another view. They go inside the detail frame, so a row
 * pick, which swaps only that frame, clears them.
 */
function keepNotices(current, fresh) {
    const detail =
        fresh.querySelector('turbo-frame#inbox-detail') ??
        fresh.querySelector('.lp-inbox-request-detail');
    ['data-inbox-status', 'data-inbox-closed-notice'].forEach((marker) => {
        current.querySelectorAll(`[${marker}]`).forEach((notice) => {
            if (detail && !fresh.querySelector(`[${marker}]`)) {
                detail.prepend(notice.cloneNode(true));
            }
        });
    });
}

/**
 * Turbo fires the same event when a morph removes a form, with no new element.
 * A form with typed input stays only when the new view holds the form of the
 * same item, which its id names.
 */
function holdsSameForm(form, newElement) {
    return (
        newElement instanceof HTMLFormElement &&
        form.id !== '' &&
        newElement.id === form.id
    );
}

/**
 * A reply card with typed input goes away when its item closed. The values
 * stay: the fresh view gets the unsent reply in the thread of that item, in
 * place of the draft that the form memory would show. An item that is still
 * open gets no copy, so the morph removes a card its new view dropped.
 */
function keepReplyDrafts(current, fresh) {
    current.querySelectorAll('form.lp-inbox-thread__reply').forEach((form) => {
        const article = form.closest('[data-inbox-item]');
        const template = form
            .closest('.lp-inbox-item__actions')
            ?.querySelector('template[data-inbox-reply-draft-template]');
        if (!article || !template || !hasUnsavedInput(form)) {
            return;
        }
        const twin = fresh.querySelector(
            `[data-inbox-item="${CSS.escape(article.dataset.inboxItem)}"]`,
        );
        const thread = twin?.querySelector('[data-inbox-thread]');
        if (
            !thread ||
            twin.dataset.inboxItemOpen !== 'false' ||
            thread.querySelector('[data-inbox-reply-draft]')
        ) {
            return;
        }
        const draft = template.content.firstElementChild.cloneNode(true);
        draft.dataset.inboxReplyDraftChoicesValue = JSON.stringify(
            [
                ...form.querySelectorAll(
                    'input[type="radio"]:checked, input[type="checkbox"]:checked',
                ),
            ]
                .map((field) => field.labels?.[0]?.textContent.trim())
                .filter(Boolean),
        );
        draft.dataset.inboxReplyDraftNotesValue = JSON.stringify(
            [...form.querySelectorAll('textarea')]
                .filter(
                    (field) =>
                        field.value.trim() !== '' &&
                        field.value !== field.defaultValue,
                )
                .map((field) => field.value),
        );
        draft.dataset.inboxReplyDraftKeyValue =
            form.dataset.formDraftKeyValue ?? '';
        twin.querySelectorAll(
            '[data-controller="form-draft-recovery"]',
        ).forEach((shell) => shell.remove());
        thread.append(draft);
    });
}

function hasUnsavedInput(form) {
    return [...form.elements].some((field) => {
        if (field instanceof HTMLSelectElement) {
            const options = [...field.options];
            if (field.multiple) {
                return options.some(
                    (option) => option.selected !== option.defaultSelected,
                );
            }
            const initial =
                options.findLast((option) => option.defaultSelected) ??
                options[0];

            return field.options[field.selectedIndex] !== initial;
        }
        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked !== field.defaultChecked;
        }
        if (
            field instanceof HTMLInputElement ||
            field instanceof HTMLTextAreaElement
        ) {
            return field.value !== field.defaultValue;
        }

        return false;
    });
}
