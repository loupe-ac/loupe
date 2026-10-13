import { Controller } from '@hotwired/stimulus';

const VIEW_FRAME = 'inbox-view';

/* stimulusFetch: 'eager' */
/** A row reloads the whole view frame, so the request list keeps its scroll across the render. */
export default class extends Controller {
    connect() {
        this.scrollTop = null;
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
        document.addEventListener('turbo:before-frame-render', this.remember);
        document.addEventListener('turbo:frame-render', this.restore);
    }

    disconnect() {
        document.removeEventListener(
            'turbo:before-frame-render',
            this.remember,
        );
        document.removeEventListener('turbo:frame-render', this.restore);
    }
}
