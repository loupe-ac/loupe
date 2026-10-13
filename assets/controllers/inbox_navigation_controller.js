import { Controller } from '@hotwired/stimulus';

const VIEW_FRAME = 'inbox-view';

/* stimulusFetch: 'eager' */
/**
 * A row reloads the whole view frame. The row the owner picked shows as
 * current at once, while the frame loads, and the request list keeps its
 * scroll across the render.
 */
export default class extends Controller {
    connect() {
        this.scrollTop = null;
        this.pick = (event) => {
            const row = event.target.closest('a.lp-inbox-request');
            if (
                row === null ||
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }
            for (const current of this.element.querySelectorAll(
                'a.lp-inbox-request[aria-current]',
            )) {
                current.removeAttribute('aria-current');
            }
            row.setAttribute('aria-current', 'true');
        };
        this.remember = (event) => {
            if (event.target.id !== VIEW_FRAME) {
                return;
            }
            const list = this.element.querySelector('[data-inbox-scroll]');
            this.scrollTop = list === null ? null : list.scrollTop;
        };
        this.restore = (event) => {
            if (event.target.id !== VIEW_FRAME || this.scrollTop === null) {
                return;
            }
            const list = this.element.querySelector('[data-inbox-scroll]');
            if (list !== null) {
                list.scrollTop = this.scrollTop;
            }
            this.scrollTop = null;
        };
        this.element.addEventListener('click', this.pick);
        document.addEventListener('turbo:before-frame-render', this.remember);
        document.addEventListener('turbo:frame-render', this.restore);
    }

    disconnect() {
        this.element.removeEventListener('click', this.pick);
        document.removeEventListener(
            'turbo:before-frame-render',
            this.remember,
        );
        document.removeEventListener('turbo:frame-render', this.restore);
    }
}
