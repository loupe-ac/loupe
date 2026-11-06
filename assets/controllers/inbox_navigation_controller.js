import { Controller } from '@hotwired/stimulus';

const VIEW_FRAME = 'inbox-view';
const FOCUS_TITLE = 'h2.lp-inbox-ask__title';
const SEARCH_FIELD_ID = 'inbox-search';

/* stimulusFetch: 'eager' */
/**
 * A row reloads the whole view frame. The row the owner picked shows as
 * current at once, the page shows a spinner until the frame renders, and the
 * request list keeps its scroll across the render. Focus follows the action:
 * a row moves it to the title of the new request, and a filter, a tab or the
 * search returns it to the control that was used.
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
            this.element.dataset.inboxLoading = '';
        };
        this.settle = () => {
            delete this.element.dataset.inboxLoading;
            this.focusSelector = null;
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
            if (event.target.id !== VIEW_FRAME) {
                return;
            }
            const focusSelector = this.focusSelector;
            this.settle();
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
        document.addEventListener('turbo:fetch-request-error', this.settle);
        document.addEventListener('turbo:frame-missing', this.settle);
    }

    disconnect() {
        this.element.removeEventListener('click', this.pick);
        this.element.removeEventListener('submit', this.search);
        document.removeEventListener(
            'turbo:before-frame-render',
            this.remember,
        );
        document.removeEventListener('turbo:frame-render', this.restore);
        document.removeEventListener('turbo:fetch-request-error', this.settle);
        document.removeEventListener('turbo:frame-missing', this.settle);
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
