import { Controller } from '@hotwired/stimulus';

/**
 * Start session in the menu of an item shows the prompt that the asking agent
 * suggested, so the owner reads and edits it before the session starts.
 */
export default class extends Controller {
    static targets = ['field'];

    reveal() {
        this.fieldTarget.hidden = false;
        this.fieldTarget.querySelector('textarea')?.focus();
    }
}
