import { Controller } from '@hotwired/stimulus';

/**
 * The "Use something else?" block of the home page. It starts closed, and its
 * toggle opens and closes the form. The chips are real checkboxes, so they
 * keep their own state.
 */
export default class extends Controller {
    static targets = ['toggle', 'body'];
    static values = { open: { type: Boolean, default: false } };

    toggle() {
        this.openValue = !this.openValue;
    }

    openValueChanged() {
        this.bodyTarget.hidden = !this.openValue;
        this.toggleTarget.setAttribute('aria-expanded', String(this.openValue));
    }
}
