import { Controller } from '@hotwired/stimulus';

const DEBOUNCE_MILLISECONDS = 300;
const DRAWER_FRAME = 'card-drawer-frame';

export default class extends Controller {
    static targets = [
        'icon',
        'form',
        'field',
        'panel',
        'status',
        'progress',
        'error',
        'frame',
    ];
    static values = { url: String };

    connect() {
        document.addEventListener('click', this.#onDocumentClick);
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('click', this.#onDocumentClick);
    }

    shortcut(event) {
        if (event.key === 'Escape' && this.#isActive()) {
            event.preventDefault();
            this.close();
            this.fieldTarget.blur();
            return;
        }
        if (
            !(event.ctrlKey || event.metaKey) ||
            event.altKey ||
            event.key.toLowerCase() !== 'k'
        )
            return;
        if (document.querySelector('dialog[open]')) return;
        event.preventDefault();
        if (this.#iconShows()) {
            this.openIcon();
        } else {
            this.fieldTarget.focus();
        }
    }

    openIcon() {
        this.element.classList.add('lp-topsearch--open');
        this.iconTarget.setAttribute('aria-expanded', 'true');
        this.fieldTarget.focus();
        if (this.#query() !== '') this.#showPanel();
    }

    close() {
        clearTimeout(this.timer);
        this.element.classList.remove('lp-topsearch--open');
        this.iconTarget.setAttribute('aria-expanded', 'false');
        this.fieldTarget.setAttribute('aria-expanded', 'false');
        this.panelTarget.hidden = true;
        this.progressTarget.hidden = true;
    }

    focus() {
        if (this.#query() === '') return;
        this.#showPanel();
        if (this.#answeredQuery() === this.#query()) return;
        clearTimeout(this.timer);
        this.#showPending();
        this.#load();
    }

    input() {
        clearTimeout(this.timer);
        if (this.#query() === '') {
            this.panelTarget.hidden = true;
            this.progressTarget.hidden = true;
            this.fieldTarget.setAttribute('aria-expanded', 'false');
            return;
        }
        this.#showPanel();
        this.#showPending();
        this.timer = setTimeout(() => this.#load(), DEBOUNCE_MILLISECONDS);
    }

    retry() {
        this.#showPending();
        this.#load();
    }

    /** Skips the render of an answer to a query that the field no longer holds. */
    beforeRender(event) {
        const answered = event.detail.newFrame.querySelector(
            '[data-search-query]',
        )?.dataset.searchQuery;
        if (answered !== this.#query()) {
            event.detail.render = () => {};
        }
    }

    loaded() {
        this.#routeCardRows();
        if (this.#answeredQuery() !== this.#query()) return;
        this.statusTarget.hidden = true;
        this.progressTarget.hidden = true;
        this.errorTarget.hidden = true;
    }

    /** Turbo fires no frame event for a failed answer that is not HTML, so the status code decides. */
    response(event) {
        if (!event.detail.fetchResponse.succeeded) this.#showError();
    }

    failed(event) {
        event.preventDefault();
        this.#showError();
    }

    /** A failure of a request for an older query leaves the panel to the request that follows. */
    #showError() {
        if (this.requestedQuery !== this.#query()) return;
        this.statusTarget.hidden = true;
        this.progressTarget.hidden = true;
        this.errorTarget.hidden = false;
    }

    #load() {
        this.requestedQuery = this.#query();
        const url = `${this.urlValue}?query=${encodeURIComponent(this.requestedQuery)}`;
        const frame = this.frameTarget;
        if (frame.getAttribute('src') === url) {
            frame.reload();
        } else {
            frame.setAttribute('src', url);
        }
    }

    #query() {
        return this.fieldTarget.value.replace(
            /^[\p{Z}\p{Cc}\p{Cf}]+|[\p{Z}\p{Cc}\p{Cf}]+$/gu,
            '',
        );
    }

    #answeredQuery() {
        return (
            this.frameTarget.querySelector('[data-search-query]')?.dataset
                .searchQuery ?? null
        );
    }

    #showPanel() {
        this.panelTarget.hidden = false;
        this.fieldTarget.setAttribute('aria-expanded', 'true');
    }

    /** Old rows stay under the progress line, and the status line shows only before the first answer. */
    #showPending() {
        this.errorTarget.hidden = true;
        this.progressTarget.hidden = false;
        this.statusTarget.hidden = this.#answeredQuery() !== null;
    }

    #isActive() {
        return (
            this.element.classList.contains('lp-topsearch--open') ||
            !this.panelTarget.hidden
        );
    }

    #iconShows() {
        return getComputedStyle(this.iconTarget).display !== 'none';
    }

    /** The card drawer exists on the board only, so only there does a card row open it. */
    #routeCardRows() {
        const onBoard =
            document.querySelector('[data-controller~="board-view"]') !== null;
        this.frameTarget
            .querySelectorAll('[data-search-kind="card"]')
            .forEach((row) => {
                row.dataset.turboFrame = onBoard ? DRAWER_FRAME : '_top';
                if (onBoard) row.setAttribute('data-card-drawer-opener', '');
                else row.removeAttribute('data-card-drawer-opener');
            });
    }

    #onDocumentClick = (event) => {
        if (!this.element.contains(event.target)) {
            if (this.#isActive()) this.close();
            return;
        }
        if (event.target.closest?.('[role="option"]')) {
            this.element.classList.remove('lp-topsearch--open');
            this.panelTarget.hidden = true;
        }
    };
}
