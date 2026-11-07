import { Controller } from '@hotwired/stimulus';

/**
 * Moves focus to its field when the native `<details>` element opens.
 * Closing the element, or opening one with no field, leaves the focus where it is.
 */
export default class extends Controller {
    static targets = ['field'];

    connect() {
        this.element.addEventListener('toggle', this.focusField);
    }

    disconnect() {
        this.element.removeEventListener('toggle', this.focusField);
    }

    focusField = () => {
        if (this.element.open && this.hasFieldTarget) {
            this.fieldTarget.focus();
        }
    };
}
