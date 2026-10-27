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
 * 404 is gone, so the page drops it. The row route never draws "Checking…",
 * so a replaced row ends that state. A row that heard no signal drops it
 * after a minute and keeps the time of its last read.
 */
export default class extends Controller {
    connect() {
        this.pending = new Set();
        this.everyRow = false;
        this.unsubscribe = on(SIGNAL_TYPE, (change) => this.changed(change), {
            onReconnect: () => this.reconnected(),
        });
        if (this.element.querySelector('[data-repository-checking]')) {
            this.checkingTimeout = setTimeout(
                () => this.dropChecking(),
                CHECKING_TIMEOUT_MILLISECONDS,
            );
        }
    }

    disconnect() {
        clearTimeout(this.timeout);
        clearTimeout(this.checkingTimeout);
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

    /** The hub may have lost signals while the page was away. */
    reconnected() {
        this.everyRow = true;
        this.schedule();
    }

    schedule() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.refresh(), DEBOUNCE_MILLISECONDS);
    }

    async refresh() {
        const rows = this.everyRow
            ? [...this.element.querySelectorAll('[data-forge-repository]')]
            : [...this.pending].map((id) => this.rowFor(id));
        this.pending.clear();
        this.everyRow = false;

        await Promise.all(
            rows
                .filter((row) => row !== undefined)
                .map((row) => this.refreshRow(row)),
        );
    }

    async refreshRow(row) {
        let response;
        try {
            // eslint-disable-next-line no-restricted-syntax -- a read-only GET of a row that Turbo then applies, not a mutation.
            response = await fetch(row.dataset.repositoryRowUrl, {
                headers: { Accept: STREAM_TYPE },
                credentials: 'same-origin',
            });
        } catch {
            return;
        }
        if (response.status === 404) {
            row.remove();

            return;
        }
        if (!response.ok) {
            return;
        }
        renderStreamMessage(await response.text());
    }

    rowFor(repositoryId) {
        return [
            ...this.element.querySelectorAll('[data-forge-repository]'),
        ].find((row) => row.dataset.forgeRepository === repositoryId);
    }

    dropChecking() {
        this.element
            .querySelectorAll('[data-repository-checking]')
            .forEach((checking) => checking.remove());
    }
}
