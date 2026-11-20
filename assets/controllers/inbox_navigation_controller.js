import { Controller } from '@hotwired/stimulus';

const VIEW_FRAME = 'inbox-view';
const DETAIL_FRAME = 'inbox-detail';
const FOCUS_TITLE = 'h2.lp-inbox-ask__title';
const SEARCH_FIELD_ID = 'inbox-search';
const SEARCH_FORM = '[data-inbox-search-form]';

/* stimulusFetch: 'eager' */
/**
 * A row swaps only the detail frame, and a filter, a tab, the search or the
 * pager reloads the whole view frame. The row the owner picked shows as
 * current at once, the page shows a spinner until the frame renders, and the
 * request list keeps its scroll across a view render. Focus follows the action:
 * a row moves it to the title of the new request, and a filter, a tab or the
 * search returns it to the control that was used. The search form sits outside
 * the view frame, so a render never replaces the field being typed into. After
 * a render the form copies the query, the side and the type from the address
 * the frame loaded, and leaves the field alone while it has focus.
 */
export default class extends Controller {
    connect() {
        this.scrollTop = null;
        this.focusSelector = null;
        this.pick = (event) => {
            if (
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }
            const row = event.target.closest('a.lp-list-row');
            if (row === null) {
                this.focusSelector = this.#controlSelector(event.target);
                return;
            }
            this.focusSelector = FOCUS_TITLE;
            for (const current of this.element.querySelectorAll(
                'a.lp-list-row[aria-current]',
            )) {
                current.removeAttribute('aria-current');
            }
            row.setAttribute('aria-current', 'true');
            this.#split()?.setAttribute('data-inbox-picked', 'true');
            this.element.dataset.inboxLoading = '';
        };
        this.settle = () => {
            delete this.element.dataset.inboxLoading;
            this.focusSelector = null;
        };
        this.failed = () => {
            this.settle();
            this.#syncPicked();
        };
        this.search = (event) => {
            if (event.target.querySelector(`#${SEARCH_FIELD_ID}`) !== null) {
                this.focusSelector = `#${SEARCH_FIELD_ID}`;
            }
        };
        this.remember = (event) => {
            if (event.target.id !== VIEW_FRAME) {
                return;
            }
            const list = this.element.querySelector('[data-inbox-scroll]');
            this.scrollTop = list === null ? null : list.scrollTop;
        };
        this.restore = (event) => {
            if (event.target.id === DETAIL_FRAME) {
                this.#syncPicked();
            } else if (event.target.id !== VIEW_FRAME) {
                return;
            }
            const focusSelector = this.focusSelector;
            this.settle();
            if (event.target.id === VIEW_FRAME) {
                this.#syncSearchForm(
                    event.detail?.fetchResponse?.response?.url,
                );
            }
            if (this.scrollTop !== null) {
                const list = this.element.querySelector('[data-inbox-scroll]');
                if (list !== null) {
                    list.scrollTop = this.scrollTop;
                }
                this.scrollTop = null;
            }
            if (focusSelector !== null) {
                this.element
                    .querySelector(focusSelector)
                    ?.focus({ preventScroll: true });
            }
        };
        this.element.addEventListener('click', this.pick);
        this.element.addEventListener('submit', this.search);
        document.addEventListener('turbo:before-frame-render', this.remember);
        document.addEventListener('turbo:frame-render', this.restore);
        document.addEventListener('turbo:fetch-request-error', this.failed);
        document.addEventListener('turbo:frame-missing', this.failed);
    }

    disconnect() {
        this.element.removeEventListener('click', this.pick);
        this.element.removeEventListener('submit', this.search);
        document.removeEventListener(
            'turbo:before-frame-render',
            this.remember,
        );
        document.removeEventListener('turbo:frame-render', this.restore);
        document.removeEventListener('turbo:fetch-request-error', this.failed);
        document.removeEventListener('turbo:frame-missing', this.failed);
    }

    #syncSearchForm(address) {
        const form = this.element.querySelector(SEARCH_FORM);
        if (form === null) {
            return;
        }
        let params;
        try {
            params = new URL(
                address ?? window.location.href,
                window.location.href,
            ).searchParams;
        } catch {
            return;
        }
        const field = form.querySelector(`#${SEARCH_FIELD_ID}`);
        if (field !== null && document.activeElement !== field) {
            field.value = params.get('q') ?? '';
        }
        const side = form.elements.namedItem('side');
        if (side !== null) {
            side.value =
                params.get('side') ?? side.dataset.default ?? side.value;
        }
        const type = form.elements.namedItem('type');
        if (type !== null) {
            type.value = params.get('type') ?? '';
            type.disabled = type.value === '';
        }
    }

    #split() {
        return this.element.querySelector('[data-inbox-picked]');
    }

    /** The detail frame holds the back link only while the view has a pick, so the split follows it. */
    #syncPicked() {
        const picked =
            this.element.querySelector(`#${DETAIL_FRAME} .lp-inbox-back`) !==
            null;
        this.#split()?.setAttribute('data-inbox-picked', String(picked));
    }

    #controlSelector(target) {
        const link = target.closest(`a[data-turbo-frame="${VIEW_FRAME}"]`);
        if (link === null) {
            return null;
        }
        if (link.classList.contains('lp-filter-clear')) {
            return `#${SEARCH_FIELD_ID}`;
        }
        for (const name of ['inboxSide', 'inboxType']) {
            if (link.dataset[name] !== undefined) {
                const attribute = name === 'inboxSide' ? 'side' : 'type';
                return `a[data-inbox-${attribute}="${link.dataset[name]}"]`;
            }
        }
        return null;
    }
}
