import { Controller } from '@hotwired/stimulus';

const PREFIX = /^\(\d+\) /;

/**
 * Keeps the open inbox total at the start of the tab title, such as "(3) Board".
 * It reads the total from the counter inside its element and adds no prefix at zero.
 */
export default class extends Controller {
    connect() {
        this.sync();
    }

    sync() {
        const counter = this.element.querySelector('[data-inbox-open-total]');
        const total = Number.parseInt(counter?.textContent ?? '', 10) || 0;
        const title = document.title.replace(PREFIX, '');

        document.title = total > 0 ? `(${total}) ${title}` : title;
    }
}
