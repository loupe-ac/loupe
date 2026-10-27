import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import { on } from '../lib/live.js';

export const SIGNAL_TYPE = 'forge.repository_check_changed';

/** A check often signals twice in a row, a result and then the hold change. */
export const DEBOUNCE_MILLISECONDS = 300;

/** A read takes about two seconds, so a page that heard nothing in this time stops waiting. */
export const CHECKING_TIMEOUT_MILLISECONDS = 60000;

const STREAM_TYPE = 'text/vnd.turbo-stream.html';

/**
 * Keeps the repository rows of the Repositories page current. A signal names
 * a repository and holds no content, so the page fetches that row through its
 * own route and lets Turbo replace it. A burst of signals makes one fetch for
 * each repository, and a reconnect fetches every row again. A row that answers
 * 404 is gone, so the page drops it, and the empty state shows after the last
 * one. The row route never draws "Checking…", so a replaced row ends that
 * state.
 *
 * A signal that came before the first connection is lost, so the first open
 * fetches each row that still says "Checking…". After a minute with no signal
 * the page fetches those rows once more, and drops "Checking…" from a row
 * whose fetch fails. Only the newest response of a row is applied. A row with
 * an open dialog keeps its markup until the dialog closes, then the page
 * fetches it again.
 */
export default class extends Controller {
    static targets = ['populated', 'empty'];

    connect() {
        this.pending = new Set();
        this.held = new Set();
        this.issued = new Map();
        this.applied = new Map();
        this.everyRow = false;
        this.fallbackFired = false;
        this.dialogClosed = (event) => this.closed(event);
        this.element.addEventListener('close', this.dialogClosed, true);
        this.unsubscribe = on(SIGNAL_TYPE, (change) => this.changed(change), {
            onOpen: () => this.opened(),
            onReconnect: () => this.reconnected(),
        });
        if (this.element.querySelector('[data-repository-checking]')) {
            this.checkingTimeout = setTimeout(
                () => this.fallback(),
                CHECKING_TIMEOUT_MILLISECONDS,
            );
        }
    }

    disconnect() {
        clearTimeout(this.timeout);
        clearTimeout(this.checkingTimeout);
        this.element.removeEventListener('close', this.dialogClosed, true);
        this.unsubscribe?.();
        this.unsubscribe = undefined;
    }

    changed(change) {
        if (typeof change.repositoryId !== 'string') {
            return;
        }
        this.pending.add(change.repositoryId);
        this.schedule();
    }

    /** A signal that came before the connection opened is lost, so ask for the rows that still wait. */
    opened() {
        this.checkingIds().forEach((id) => this.pending.add(id));
        if (this.pending.size > 0) {
            this.schedule();
        }
    }

    /** The hub may have lost signals while the page was away. */
    reconnected() {
        this.everyRow = true;
        this.schedule();
    }

    closed(event) {
        const id = event.target.closest?.('[data-forge-repository]')?.dataset
            .forgeRepository;
        if (id !== undefined && this.held.delete(id)) {
            this.pending.add(id);
            this.schedule();
        }
    }

    schedule() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.refresh(), DEBOUNCE_MILLISECONDS);
    }

    async refresh() {
        const ids = this.everyRow ? this.rowIds() : [...this.pending];
        this.pending.clear();
        this.everyRow = false;

        await Promise.all(ids.map((id) => this.refreshRow(id)));
    }

    async fallback() {
        this.fallbackFired = true;
        await Promise.all(this.checkingIds().map((id) => this.refreshRow(id)));
    }

    async refreshRow(repositoryId) {
        const row = this.rowFor(repositoryId);
        if (row === undefined) {
            return;
        }
        if (this.dialogOpen(row)) {
            this.held.add(repositoryId);

            return;
        }

        const sequence = (this.issued.get(repositoryId) ?? 0) + 1;
        this.issued.set(repositoryId, sequence);

        let status;
        let body = null;
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of a row that Turbo then applies, not a mutation.
            const response = await fetch(row.dataset.repositoryRowUrl, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
            status = response.status;
            if (response.ok) {
                body = await response.text();
            }
        } catch {
            this.failed(repositoryId);

            return;
        }

        if (sequence <= (this.applied.get(repositoryId) ?? 0)) {
            return;
        }
        const current = this.rowFor(repositoryId);
        if (current === undefined) {
            return;
        }
        if (this.dialogOpen(current)) {
            this.held.add(repositoryId);

            return;
        }
        if (status === 404) {
            this.remove(repositoryId, current);

            return;
        }
        if (body === null) {
            this.failed(repositoryId);

            return;
        }

        this.applied.set(repositoryId, sequence);
        renderStreamMessage(body);
    }

    remove(repositoryId, row) {
        row.remove();
        [this.issued, this.applied].forEach((map) => map.delete(repositoryId));
        this.held.delete(repositoryId);
        if (
            this.element.querySelector('[data-forge-repository]') === null &&
            this.hasPopulatedTarget &&
            this.hasEmptyTarget
        ) {
            this.populatedTarget.replaceWith(
                this.emptyTarget.content.cloneNode(true),
            );
        }
    }

    /** After the minute with no signal, a row that cannot be read stops saying "Checking…". */
    failed(repositoryId) {
        if (!this.fallbackFired) {
            return;
        }
        this.rowFor(repositoryId)
            ?.querySelectorAll('[data-repository-checking]')
            .forEach((checking) => checking.remove());
    }

    dialogOpen(row) {
        return row.querySelector('dialog[open]') !== null;
    }

    rowIds() {
        return [
            ...this.element.querySelectorAll('[data-forge-repository]'),
        ].map((row) => row.dataset.forgeRepository);
    }

    checkingIds() {
        return [
            ...this.element.querySelectorAll(
                '[data-forge-repository]:has([data-repository-checking])',
            ),
        ].map((row) => row.dataset.forgeRepository);
    }

    rowFor(repositoryId) {
        return [
            ...this.element.querySelectorAll('[data-forge-repository]'),
        ].find((row) => row.dataset.forgeRepository === repositoryId);
    }
}
