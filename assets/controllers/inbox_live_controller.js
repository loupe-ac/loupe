import { Controller } from '@hotwired/stimulus';
import { morphElements } from '@hotwired/turbo';
import { on } from '../lib/live.js';

/** An agent often asks or withdraws several items in a row. */
export const DEBOUNCE_MILLISECONDS = 300;

/** A failed read tries again after each wait, and a new change starts the count again. */
export const RETRY_MILLISECONDS = [1000, 3000, 9000];

/** A read that answers nothing in this time stops, and counts as a failure. */
export const READ_TIMEOUT_MILLISECONDS = 10000;

const VIEW_FRAME = 'inbox-view';

/**
 * Reads the view of the inbox page again when an ask of the project changes,
 * and after a reconnect, and morphs it in. A form with typed input keeps its
 * fields. When its item closed in the meantime, the typed values stay as an
 * unsent reply with a Discard button. An open
 * dialog holds the update until it closes.
 */
export default class extends Controller {
    connect() {
        this.held = false;
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
        keepNotices(current, fresh);
        keepReplyDrafts(current, fresh);
        this.morph(current, fresh);
    }

    holding() {
        this.held = this.element.querySelector('dialog[open]') !== null;

        return this.held;
    }

    morph(current, fresh) {
        const keepElement = (event) => {
            const target = event.target;
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
 * reader moves to another view.
 */
function keepNotices(current, fresh) {
    const detail = fresh.querySelector('.lp-inbox-request-detail');
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
