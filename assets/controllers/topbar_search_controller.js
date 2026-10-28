import { Controller } from '@hotwired/stimulus';

const DEBOUNCE_MILLISECONDS = 300;
const DRAWER_FRAME = 'card-drawer-frame';
const OPTION_SELECTOR = '[role="option"], [data-search-all]';

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
        if (event.key === 'Escape' && event.defaultPrevented) return;
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
        this.element.classList.remove('lp-topsearch--closing');
        this.element.classList.add('lp-topsearch--open');
        this.iconTarget.setAttribute('aria-expanded', 'true');
        this.fieldTarget.focus();
        if (this.#query() !== '') this.#showPanel();
    }

    /** Arrow keys move the active row while the focus stays in the field. */
    navigate(event) {
        if (this.panelTarget.hidden) return;
        const options = this.#options();
        const index = options.findIndex(
            (option) => option.getAttribute('aria-selected') === 'true',
        );
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (options.length === 0) return;
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const next = index + step;
            this.#activate(
                next < -1 ? options.length - 1 : next >= options.length ? -1 : next,
            );
            return;
        }
        if (index === -1) return;
        if (event.key === 'Enter') {
            event.preventDefault();
            options[index].click();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.#activate(-1);
        }
    }

    close() {
        clearTimeout(this.timer);
        this.#activate(-1);
        this.#collapse();
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
        this.#activate(-1);
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
        this.#options().forEach((option, index) => {
            option.id = `topbar-search-option-${index}`;
            option.setAttribute('aria-selected', 'false');
        });
        this.fieldTarget.removeAttribute('aria-activedescendant');
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

    /** The open icon form plays its closing animation before it hides. */
    #collapse() {
        const classes = this.element.classList;
        if (!classes.contains('lp-topsearch--open')) return;
        classes.add('lp-topsearch--closing');
        const animation = getComputedStyle(this.formTarget).animationName;
        if (!animation || animation === 'none') {
            classes.remove('lp-topsearch--open', 'lp-topsearch--closing');
            return;
        }
        this.formTarget.addEventListener(
            'animationend',
            () => {
                if (!classes.contains('lp-topsearch--closing')) return;
                classes.remove('lp-topsearch--open', 'lp-topsearch--closing');
            },
            { once: true },
        );
    }

    #options() {
        return [...this.frameTarget.querySelectorAll(OPTION_SELECTOR)];
    }

    #activate(index) {
        const options = this.#options();
        options.forEach((option, position) =>
            option.setAttribute('aria-selected', String(position === index)),
        );
        const active = options[index];
        if (active) {
            this.fieldTarget.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView?.({ block: 'nearest' });
        } else {
            this.fieldTarget.removeAttribute('aria-activedescendant');
        }
    }

    #isActive() {
        return (
            this.element.classList.contains('lp-topsearch--open') ||
            !this.panelTarget.hidden ||
            document.activeElement === this.fieldTarget
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
